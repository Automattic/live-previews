<?php

declare(strict_types = 1);

namespace Automattic\LivePreviews\Tests;

use Automattic\LivePreviews\Cli\RevokeCommand;
use Automattic\LivePreviews\PreviewLink;
use PHPUnit\Framework\TestCase;

/**
 * The rules for resolving the link a `wp live-previews revoke` caller named —
 * the one piece of real logic in the CLI adapters. Everything else the command
 * does is pass-through, pinned behaviourally by features/revoke.feature.
 *
 * @covers \Automattic\LivePreviews\Cli\RevokeCommand
 */
final class RevokeCommandTest extends TestCase {
	private const HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	public function test_matches_a_link_by_its_token_hint(): void {
		$link = $this->link( self::HASH_A, 'ab3f' );

		self::assertSame( [ $link ], RevokeCommand::matching_links( [ $link ], 'ab3f' ) );
	}

	public function test_matches_a_link_by_its_full_hash(): void {
		$link = $this->link( self::HASH_A, 'ab3f' );

		self::assertSame( [ $link ], RevokeCommand::matching_links( [ $link ], self::HASH_A ) );
	}

	public function test_returns_every_link_sharing_an_ambiguous_hint(): void {
		$first  = $this->link( self::HASH_A, 'ab3f' );
		$second = $this->link( self::HASH_B, 'ab3f' );

		self::assertSame( [ $first, $second ], RevokeCommand::matching_links( [ $first, $second ], 'ab3f' ) );
	}

	public function test_a_full_hash_stays_unambiguous_even_when_hints_collide(): void {
		$first  = $this->link( self::HASH_A, 'ab3f' );
		$second = $this->link( self::HASH_B, 'ab3f' );

		self::assertSame( [ $second ], RevokeCommand::matching_links( [ $first, $second ], self::HASH_B ) );
	}

	public function test_ignores_links_already_revoked(): void {
		$revoked = $this->link( self::HASH_A, 'ab3f', 500 );

		self::assertSame( [], RevokeCommand::matching_links( [ $revoked ], 'ab3f' ) );
	}

	public function test_a_partial_hint_matches_nothing(): void {
		$link = $this->link( self::HASH_A, 'ab3f' );

		self::assertSame( [], RevokeCommand::matching_links( [ $link ], '3f' ) );
		self::assertSame( [], RevokeCommand::matching_links( [ $link ], '' ) );
	}

	private function link( string $token_hash, string $token_hint, ?int $revoked_at = null ): PreviewLink {
		return new PreviewLink(
			10,
			$token_hash,
			2000,
			null,
			1,
			1000,
			[],
			$revoked_at,
			$token_hint
		);
	}
}
