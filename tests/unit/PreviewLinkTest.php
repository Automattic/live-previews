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

		$link = PreviewLink::issue( 13, $token, 2000, false, 1, 1000 );

		static::assertSame( $token->hash(), $link->token_hash() );
		static::assertStringNotContainsString( 'secret-value', $link->token_hash() );
	}

	public function test_matches_only_the_issuing_token(): void {
		$link = PreviewLink::issue( 13, Token::from_string( 'right' ), 2000, false, 1, 1000 );

		static::assertTrue( $link->matches( Token::from_string( 'right' ) ) );
		static::assertFalse( $link->matches( Token::from_string( 'wrong' ) ) );
	}

	public function test_expiry_is_inclusive_of_the_expiry_second(): void {
		$link = PreviewLink::issue( 13, Token::generate(), 2000, false, 1, 1000 );

		static::assertFalse( $link->is_expired( 1999 ) );
		static::assertTrue( $link->is_expired( 2000 ), 'A link is expired at its expiry second.' );
		static::assertTrue( $link->is_expired( 2001 ) );
	}

	public function test_a_fresh_link_is_neither_used_nor_revoked(): void {
		$link = PreviewLink::issue( 13, Token::generate(), 2000, true, 1, 1000 );

		static::assertFalse( $link->is_used() );
		static::assertFalse( $link->is_revoked() );
	}

	public function test_used_and_revoked_timestamps_flip_the_flags(): void {
		$link = new PreviewLink( 13, 'hash', 2000, true, 1, 1000, 1500, 1600 );

		static::assertTrue( $link->is_used() );
		static::assertTrue( $link->is_revoked() );
	}
}
