<?php

declare(strict_types = 1);

namespace Automattic\LivePreviews\Tests;

use Automattic\LivePreviews\AccessDecision;
use Automattic\LivePreviews\AccessPolicy;
use Automattic\LivePreviews\PreviewLink;
use Automattic\LivePreviews\Token;
use PHPUnit\Framework\TestCase;

/**
 * The access rules, exhaustively. This is the class that grows a branch per
 * milestone, so its truth table is the feature's safety net.
 *
 * @covers \Automattic\LivePreviews\AccessPolicy
 * @covers \Automattic\LivePreviews\AccessDecision
 */
final class AccessPolicyTest extends TestCase {
	private const NOW = 1000;

	private AccessPolicy $policy;

	protected function setUp(): void {
		parent::setUp();
		$this->policy = new AccessPolicy();
	}

	public function test_a_missing_link_is_denied_as_not_found(): void {
		$decision = $this->policy->decide( null, self::NOW );

		static::assertFalse( $decision->is_allowed() );
		static::assertSame( AccessDecision::REASON_NOT_FOUND, $decision->reason() );
	}

	public function test_a_live_link_is_allowed(): void {
		$link = $this->link( [ 'expires_at' => self::NOW + 100 ] );

		$decision = $this->policy->decide( $link, self::NOW );

		static::assertTrue( $decision->is_allowed() );
		static::assertSame( AccessDecision::REASON_ALLOWED, $decision->reason() );
	}

	public function test_an_expired_link_is_denied(): void {
		$link = $this->link( [ 'expires_at' => self::NOW - 1 ] );

		$decision = $this->policy->decide( $link, self::NOW );

		static::assertFalse( $decision->is_allowed() );
		static::assertSame( AccessDecision::REASON_EXPIRED, $decision->reason() );
	}

	public function test_a_revoked_link_is_denied_even_before_expiry(): void {
		$link = $this->link( [ 'expires_at' => self::NOW + 100, 'revoked_at' => self::NOW - 10 ] );

		$decision = $this->policy->decide( $link, self::NOW );

		static::assertFalse( $decision->is_allowed() );
		static::assertSame( AccessDecision::REASON_REVOKED, $decision->reason() );
	}

	public function test_a_used_one_time_link_is_denied(): void {
		$link = $this->link(
			[ 'expires_at' => self::NOW + 100, 'one_time_use' => true, 'used_at' => self::NOW - 10 ]
		);

		$decision = $this->policy->decide( $link, self::NOW );

		static::assertFalse( $decision->is_allowed() );
		static::assertSame( AccessDecision::REASON_USED, $decision->reason() );
	}

	public function test_a_used_multi_visit_link_is_still_allowed(): void {
		$link = $this->link(
			[ 'expires_at' => self::NOW + 100, 'one_time_use' => false, 'used_at' => self::NOW - 10 ]
		);

		static::assertTrue( $this->policy->decide( $link, self::NOW )->is_allowed() );
	}

	public function test_revocation_takes_precedence_over_expiry(): void {
		$link = $this->link( [ 'expires_at' => self::NOW - 1, 'revoked_at' => self::NOW - 10 ] );

		static::assertSame(
			AccessDecision::REASON_REVOKED,
			$this->policy->decide( $link, self::NOW )->reason()
		);
	}

	/**
	 * @param array{expires_at?: int, one_time_use?: bool, used_at?: int|null, revoked_at?: int|null} $overrides
	 */
	private function link( array $overrides ): PreviewLink {
		return new PreviewLink(
			13,
			Token::generate()->hash(),
			$overrides['expires_at'] ?? self::NOW + 100,
			$overrides['one_time_use'] ?? false,
			1,
			self::NOW - 100,
			$overrides['used_at'] ?? null,
			$overrides['revoked_at'] ?? null
		);
	}
}
