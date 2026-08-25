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
		$link = new PreviewLink( 13, 'hash', 2000, null, 1, 1000, self::slots( 9999 ) );

		self::assertFalse( $link->is_exhausted() );
	}

	public function test_a_link_is_exhausted_once_slots_reach_the_cap(): void {
		$at_cap = new PreviewLink( 13, 'hash', 2000, 5, 1, 1000, self::slots( 5 ) );
		$below  = new PreviewLink( 13, 'hash', 2000, 5, 1, 1000, self::slots( 4 ) );

		self::assertTrue( $at_cap->is_exhausted() );
		self::assertFalse( $below->is_exhausted() );
	}

	public function test_adding_a_viewer_spends_a_slot_on_a_copy(): void {
		$link = new PreviewLink( 13, 'hash', 2000, 5, 1, 1000, self::slots( 2 ) );

		$next = $link->with_viewer( 'fresh-viewer' );

		self::assertSame( 2, $link->use_count(), 'Original is unchanged.' );
		self::assertSame( 3, $next->use_count() );
		self::assertTrue( $next->holds_slot( 'fresh-viewer' ) );
	}

	public function test_a_link_only_recognises_slots_it_issued(): void {
		$link = new PreviewLink( 13, 'hash', 2000, 5, 1, 1000, [ 'issued-id' ] );

		self::assertTrue( $link->holds_slot( 'issued-id' ) );
		self::assertFalse( $link->holds_slot( 'made-up-id' ) );
		self::assertFalse( $link->holds_slot( '' ), 'A blank ID must never pass.' );
	}

	public function test_re_adding_a_known_viewer_does_not_spend_another_slot(): void {
		$link = new PreviewLink( 13, 'hash', 2000, 5, 1, 1000, [ 'issued-id' ] );

		// A retried write must be idempotent, or a retry would cost two slots.
		self::assertSame( 1, $link->with_viewer( 'issued-id' )->use_count() );
	}

	public function test_dead_since_reports_when_a_link_stopped_working(): void {
		$live    = new PreviewLink( 13, 'hash', 2000, null, 1, 1000 );
		$expired = new PreviewLink( 13, 'hash', 2000, null, 1, 1000 );
		$revoked = new PreviewLink( 13, 'hash', 9000, null, 1, 1000, [], 1500 );

		self::assertNull( $live->dead_since( 1999 ) );
		self::assertSame( 2000, $expired->dead_since( 2500 ) );
		self::assertSame( 1500, $revoked->dead_since( 2500 ) );
	}

	public function test_issue_captures_the_token_tail_as_a_hint(): void {
		$link = PreviewLink::issue( 13, Token::from_string( 'abcdefgh0000wxyz' ), 2000, null, 1, 1000 );

		self::assertSame( 'wxyz', $link->token_hint() );
	}

	public function test_revoking_stamps_a_copy(): void {
		$link = new PreviewLink( 13, 'hash', 2000, 5, 1, 1000, self::slots( 2 ) );

		$revoked = $link->with_revoked( 1500 );

		self::assertFalse( $link->is_revoked(), 'Original is unchanged.' );
		self::assertTrue( $revoked->is_revoked() );
		self::assertSame( 1500, $revoked->revoked_at() );
		self::assertSame( 2, $revoked->use_count(), 'Other fields are preserved.' );
	}

	/**
	 * A given number of distinct, already-issued slot IDs.
	 *
	 * @return list<string>
	 */
	private static function slots( int $count ): array {
		$slots = [];

		for ( $index = 0; $index < $count; $index++ ) {
			$slots[] = 'viewer-' . $index;
		}

		return $slots;
	}
}
