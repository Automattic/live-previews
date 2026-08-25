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
		$token = $this->service->mint( self::POST_ID, 3600, null, 1 );

		self::assertTrue( $this->service->authorize( self::POST_ID, $token )->is_allowed() );
	}

	public function test_the_minted_secret_is_not_persisted_in_plaintext(): void {
		$token = $this->service->mint( self::POST_ID, 3600, null, 1 );

		$stored = $this->repository->all_for_post( self::POST_ID );

		self::assertCount( 1, $stored );
		self::assertNotSame( $token->value(), $stored[0]->token_hash() );
		self::assertSame( $token->hash(), $stored[0]->token_hash() );
	}

	public function test_an_unknown_token_is_denied(): void {
		$this->service->mint( self::POST_ID, 3600, null, 1 );

		$decision = $this->service->authorize( self::POST_ID, Token::from_string( 'not-the-token' ) );

		self::assertFalse( $decision->is_allowed() );
		self::assertSame( AccessDecision::REASON_NOT_FOUND, $decision->reason() );
	}

	public function test_a_token_does_not_authorize_a_different_post(): void {
		$token = $this->service->mint( self::POST_ID, 3600, null, 1 );

		self::assertFalse( $this->service->authorize( 99, $token )->is_allowed() );
	}

	public function test_a_link_stops_working_once_its_ttl_elapses(): void {
		$token = $this->service->mint( self::POST_ID, 3600, null, 1 );

		$this->clock->advance( 3600 );

		$decision = $this->service->authorize( self::POST_ID, $token );

		self::assertFalse( $decision->is_allowed() );
		self::assertSame( AccessDecision::REASON_EXPIRED, $decision->reason() );
	}

	public function test_authorize_does_not_consume_the_link(): void {
		$token = $this->service->mint( self::POST_ID, 3600, 1, 1 );

		// Two decisions in the same instant: a pure query must not burn the link.
		self::assertTrue( $this->service->authorize( self::POST_ID, $token )->is_allowed() );
		self::assertTrue( $this->service->authorize( self::POST_ID, $token )->is_allowed() );
	}

	public function test_recording_visits_exhausts_a_capped_link(): void {
		$token = $this->service->mint( self::POST_ID, 3600, 2, 1 );

		$this->service->record_visit( self::POST_ID, $token );
		self::assertTrue( $this->service->authorize( self::POST_ID, $token )->is_allowed() );

		$this->service->record_visit( self::POST_ID, $token );
		$decision = $this->service->authorize( self::POST_ID, $token );

		self::assertFalse( $decision->is_allowed() );
		self::assertSame( AccessDecision::REASON_EXHAUSTED, $decision->reason() );
	}

	public function test_a_counted_viewer_still_gets_in_after_exhaustion(): void {
		$token = $this->service->mint( self::POST_ID, 3600, 1, 1 );

		$this->service->record_visit( self::POST_ID, $token );

		self::assertFalse( $this->service->authorize( self::POST_ID, $token )->is_allowed() );
		self::assertTrue( $this->service->authorize( self::POST_ID, $token, true )->is_allowed() );
	}

	public function test_revoking_a_link_denies_it(): void {
		$token = $this->service->mint( self::POST_ID, 3600, null, 1 );
		$hash  = $this->repository->all_for_post( self::POST_ID )[0]->token_hash();

		self::assertTrue( $this->service->revoke( self::POST_ID, $hash ) );

		$decision = $this->service->authorize( self::POST_ID, $token );
		self::assertFalse( $decision->is_allowed() );
		self::assertSame( AccessDecision::REASON_REVOKED, $decision->reason() );
	}

	public function test_revoking_is_reported_false_when_nothing_matches(): void {
		self::assertFalse( $this->service->revoke( self::POST_ID, str_repeat( 'a', 64 ) ) );
	}

	public function test_revoking_an_already_revoked_link_is_reported_false(): void {
		$this->service->mint( self::POST_ID, 3600, null, 1 );
		$hash = $this->repository->all_for_post( self::POST_ID )[0]->token_hash();

		self::assertTrue( $this->service->revoke( self::POST_ID, $hash ) );
		self::assertFalse( $this->service->revoke( self::POST_ID, $hash ) );
	}

	public function test_minting_keeps_expired_links(): void {
		// An expired link is deliberately retained so the gate can still explain
		// it. Cleanup happens on publish, not on every mint.
		$this->service->mint( self::POST_ID, 10, null, 1 );
		$this->clock->advance( 20 );

		$this->service->mint( self::POST_ID, 3600, null, 1 );

		self::assertCount( 2, $this->repository->all_for_post( self::POST_ID ) );
	}

	public function test_discard_all_forgets_every_link(): void {
		$this->service->mint( self::POST_ID, 3600, null, 1 );
		$this->service->mint( self::POST_ID, 3600, null, 1 );

		$this->service->discard_all( self::POST_ID );

		self::assertCount( 0, $this->repository->all_for_post( self::POST_ID ) );
	}
}
