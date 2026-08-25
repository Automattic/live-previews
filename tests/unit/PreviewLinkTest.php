<?php

declare(strict_types = 1);

namespace Automattic\LivePreviews\Tests;

use Automattic\LivePreviews\PreviewLink;
use Automattic\LivePreviews\Token;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Automattic\LivePreviews\PreviewLink
 */
final class PreviewLinkTest extends TestCase {
	public function test_issue_stores_the_token_hash_not_the_plaintext(): void {
		$token = Token::from_string( 'secret-value' );

		$link = PreviewLink::issue( 13, $token, 2000, null, 1, 1000 );

		self::assertSame( $token->hash(), $link->token_hash() );
		self::assertStringNotContainsString( 'secret-value', $link->token_hash() );
	}

	public function test_matches_only_the_issuing_token(): void {
		$link = PreviewLink::issue( 13, Token::from_string( 'right' ), 2000, null, 1, 1000 );

		self::assertTrue( $link->matches( Token::from_string( 'right' ) ) );
		self::assertFalse( $link->matches( Token::from_string( 'wrong' ) ) );
	}

	public function test_expiry_is_inclusive_of_the_expiry_second(): void {
		$link = PreviewLink::issue( 13, Token::generate(), 2000, null, 1, 1000 );

		self::assertFalse( $link->is_expired( 1999 ) );
		self::assertTrue( $link->is_expired( 2000 ), 'A link is expired at its expiry second.' );
		self::assertTrue( $link->is_expired( 2001 ) );
	}

	public function test_a_fresh_link_is_neither_exhausted_nor_revoked(): void {
		$link = PreviewLink::issue( 13, Token::generate(), 2000, 5, 1, 1000 );

		self::assertSame( 0, $link->use_count() );
		self::assertFalse( $link->is_exhausted() );
		self::assertFalse( $link->is_revoked() );
	}

	public function test_an_unlimited_link_is_never_exhausted(): void {
		$link = new PreviewLink( 13, 'hash', 2000, null, 1, 1000, 9999 );

		self::assertFalse( $link->is_exhausted() );
	}

	public function test_a_link_is_exhausted_once_uses_reach_the_cap(): void {
		$at_cap = new PreviewLink( 13, 'hash', 2000, 5, 1, 1000, 5 );
		$below  = new PreviewLink( 13, 'hash', 2000, 5, 1, 1000, 4 );

		self::assertTrue( $at_cap->is_exhausted() );
		self::assertFalse( $below->is_exhausted() );
	}

	public function test_recording_a_use_increments_a_copy(): void {
		$link = new PreviewLink( 13, 'hash', 2000, 5, 1, 1000, 2 );

		$next = $link->with_recorded_use();

		self::assertSame( 2, $link->use_count(), 'Original is unchanged.' );
		self::assertSame( 3, $next->use_count() );
	}

	public function test_revoking_stamps_a_copy(): void {
		$link = new PreviewLink( 13, 'hash', 2000, 5, 1, 1000, 2 );

		$revoked = $link->with_revoked( 1500 );

		self::assertFalse( $link->is_revoked(), 'Original is unchanged.' );
		self::assertTrue( $revoked->is_revoked() );
		self::assertSame( 1500, $revoked->revoked_at() );
		self::assertSame( 2, $revoked->use_count(), 'Other fields are preserved.' );
	}
}
