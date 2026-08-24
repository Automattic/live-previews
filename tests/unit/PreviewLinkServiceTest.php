<?php

declare(strict_types = 1);

namespace Automattic\LivePreviews\Tests;

use Automattic\LivePreviews\AccessDecision;
use Automattic\LivePreviews\AccessPolicy;
use Automattic\LivePreviews\PreviewLinkService;
use Automattic\LivePreviews\Token;
use Automattic\LivePreviews\Tests\Support\FrozenClock;
use Automattic\LivePreviews\Tests\Support\InMemoryTokenRepository;
use PHPUnit\Framework\TestCase;

/**
 * Round-trips through the application service against an in-memory repository and
 * a frozen clock: mint a link, then prove who may and may not use it and when.
 *
 * @covers \Automattic\LivePreviews\PreviewLinkService
 */
final class PreviewLinkServiceTest extends TestCase {
	private const NOW     = 1000;
	private const POST_ID = 13;

	private InMemoryTokenRepository $repository;
	private FrozenClock $clock;
	private PreviewLinkService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->repository = new InMemoryTokenRepository();
		$this->clock      = new FrozenClock( self::NOW );
		$this->service    = new PreviewLinkService( $this->repository, new AccessPolicy(), $this->clock );
	}

	public function test_a_minted_token_authorizes_its_post(): void {
		$token = $this->service->mint( self::POST_ID, 3600, false, 1 );

		static::assertTrue( $this->service->authorize( self::POST_ID, $token )->is_allowed() );
	}

	public function test_the_minted_secret_is_not_persisted_in_plaintext(): void {
		$token = $this->service->mint( self::POST_ID, 3600, false, 1 );

		$stored = $this->repository->all_for_post( self::POST_ID );

		static::assertCount( 1, $stored );
		static::assertNotSame( $token->value(), $stored[0]->token_hash() );
		static::assertSame( $token->hash(), $stored[0]->token_hash() );
	}

	public function test_an_unknown_token_is_denied(): void {
		$this->service->mint( self::POST_ID, 3600, false, 1 );

		$decision = $this->service->authorize( self::POST_ID, Token::from_string( 'not-the-token' ) );

		static::assertFalse( $decision->is_allowed() );
		static::assertSame( AccessDecision::REASON_NOT_FOUND, $decision->reason() );
	}

	public function test_a_token_does_not_authorize_a_different_post(): void {
		$token = $this->service->mint( self::POST_ID, 3600, false, 1 );

		static::assertFalse( $this->service->authorize( 99, $token )->is_allowed() );
	}

	public function test_a_link_stops_working_once_its_ttl_elapses(): void {
		$token = $this->service->mint( self::POST_ID, 3600, false, 1 );

		$this->clock->advance( 3600 );

		$decision = $this->service->authorize( self::POST_ID, $token );

		static::assertFalse( $decision->is_allowed() );
		static::assertSame( AccessDecision::REASON_EXPIRED, $decision->reason() );
	}

	public function test_authorize_does_not_consume_the_link(): void {
		$token = $this->service->mint( self::POST_ID, 3600, true, 1 );

		// Two visits in the same instant: a pure decision must not burn the link.
		static::assertTrue( $this->service->authorize( self::POST_ID, $token )->is_allowed() );
		static::assertTrue( $this->service->authorize( self::POST_ID, $token )->is_allowed() );
	}
}
