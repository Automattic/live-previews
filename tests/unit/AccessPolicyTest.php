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

		self::assertFalse( $decision->is_allowed() );
		self::assertSame( AccessDecision::REASON_NOT_FOUND, $decision->reason() );
	}

	public function test_a_live_link_is_allowed(): void {
		$link = $this->link( [ 'expires_at' => self::NOW + 100 ] );

		$decision = $this->policy->decide( $link, self::NOW );

		self::assertTrue( $decision->is_allowed() );
		self::assertSame( AccessDecision::REASON_ALLOWED, $decision->reason() );
	}

	public function test_an_expired_link_is_denied(): void {
		$link = $this->link( [ 'expires_at' => self::NOW - 1 ] );

		$decision = $this->policy->decide( $link, self::NOW );

		self::assertFalse( $decision->is_allowed() );
		self::assertSame( AccessDecision::REASON_EXPIRED, $decision->reason() );
	}

	public function test_a_revoked_link_is_denied_even_before_expiry(): void {
		$link = $this->link( [
			'expires_at' => self::NOW + 100,
			'revoked_at' => self::NOW - 10,
		] );

		$decision = $this->policy->decide( $link, self::NOW );

		self::assertFalse( $decision->is_allowed() );
		self::assertSame( AccessDecision::REASON_REVOKED, $decision->reason() );
	}

	public function test_an_exhausted_link_is_denied(): void {
		$link = $this->link( [
			'expires_at' => self::NOW + 100,
			'max_uses'   => 3,
			'viewers'    => self::slots( 3 ),
		] );

		$decision = $this->policy->decide( $link, self::NOW );

		self::assertFalse( $decision->is_allowed() );
		self::assertSame( AccessDecision::REASON_EXHAUSTED, $decision->reason() );
	}

	public function test_a_below_cap_link_is_allowed(): void {
		$link = $this->link( [
			'expires_at' => self::NOW + 100,
			'max_uses'   => 3,
			'viewers'    => self::slots( 2 ),
		] );

		self::assertTrue( $this->policy->decide( $link, self::NOW )->is_allowed() );
	}

	public function test_an_unlimited_link_is_allowed_however_many_uses(): void {
		$link = $this->link( [
			'expires_at' => self::NOW + 100,
			'max_uses'   => null,
			'viewers'    => self::slots( 999 ),
		] );

		self::assertTrue( $this->policy->decide( $link, self::NOW )->is_allowed() );
	}

	public function test_a_slot_holder_is_not_locked_out_by_exhaustion(): void {
		$link = $this->link( [
			'expires_at' => self::NOW + 100,
			'max_uses'   => 3,
			'viewers'    => self::slots( 3 ),
		] );

		// A returning viewer holds one of the spent slots, so must still get in.
		self::assertTrue( $this->policy->decide( $link, self::NOW, true )->is_allowed() );
	}

	public function test_an_expired_link_is_denied_even_for_a_counted_viewer(): void {
		$link = $this->link( [
			'expires_at' => self::NOW - 1,
			'max_uses'   => 3,
			'viewers'    => self::slots( 1 ),
		] );

		self::assertSame(
			AccessDecision::REASON_EXPIRED,
			$this->policy->decide( $link, self::NOW, true )->reason()
		);
	}

	public function test_revocation_takes_precedence_over_expiry(): void {
		$link = $this->link( [
			'expires_at' => self::NOW - 1,
			'revoked_at' => self::NOW - 10,
		] );

		self::assertSame(
			AccessDecision::REASON_REVOKED,
			$this->policy->decide( $link, self::NOW )->reason()
		);
	}

	public function test_no_ranges_anywhere_means_no_ip_restriction(): void {
		$link = $this->link( [] );

		// Even an unresolvable client IP is fine when nothing is restricted.
		self::assertTrue( $this->policy->decide( $link, self::NOW, false, null )->is_allowed() );
		self::assertTrue( $this->policy->decide( $link, self::NOW, false, '203.0.113.7' )->is_allowed() );
	}

	public function test_a_client_inside_a_per_link_range_is_allowed(): void {
		$link = $this->link( [ 'allowed_ips' => [ '203.0.113.0/24' ] ] );

		self::assertTrue( $this->policy->decide( $link, self::NOW, false, '203.0.113.7' )->is_allowed() );
	}

	public function test_a_client_outside_the_per_link_range_is_denied(): void {
		$link = $this->link( [ 'allowed_ips' => [ '203.0.113.0/24' ] ] );

		$decision = $this->policy->decide( $link, self::NOW, false, '198.51.100.7' );

		self::assertFalse( $decision->is_allowed() );
		self::assertSame( AccessDecision::REASON_IP_BLOCKED, $decision->reason() );
	}

	public function test_central_ranges_apply_to_a_link_without_its_own(): void {
		$policy = new AccessPolicy( [ '198.51.100.0/24' ] );
		$link   = $this->link( [] );

		self::assertTrue( $policy->decide( $link, self::NOW, false, '198.51.100.7' )->is_allowed() );
		self::assertFalse( $policy->decide( $link, self::NOW, false, '203.0.113.7' )->is_allowed() );
	}

	public function test_central_and_per_link_ranges_are_a_union(): void {
		$policy = new AccessPolicy( [ '198.51.100.0/24' ] );
		$link   = $this->link( [ 'allowed_ips' => [ '203.0.113.0/24' ] ] );

		// Matching either source is enough: per-link ranges widen, never narrow.
		self::assertTrue( $policy->decide( $link, self::NOW, false, '198.51.100.7' )->is_allowed() );
		self::assertTrue( $policy->decide( $link, self::NOW, false, '203.0.113.7' )->is_allowed() );
		self::assertFalse( $policy->decide( $link, self::NOW, false, '192.0.2.1' )->is_allowed() );
	}

	public function test_an_unresolvable_ip_fails_closed_when_ranges_exist(): void {
		$link = $this->link( [ 'allowed_ips' => [ '203.0.113.0/24' ] ] );

		self::assertSame(
			AccessDecision::REASON_IP_BLOCKED,
			$this->policy->decide( $link, self::NOW, false, null )->reason()
		);
	}

	public function test_the_ip_check_is_absolute_even_for_a_slot_holder(): void {
		$link = $this->link( [
			'allowed_ips' => [ '203.0.113.0/24' ],
			'max_uses'    => 3,
			'viewers'     => self::slots( 1 ),
		] );

		self::assertFalse( $this->policy->decide( $link, self::NOW, true, '198.51.100.7' )->is_allowed() );
	}

	public function test_a_blocked_ip_learns_nothing_about_expiry(): void {
		$link = $this->link( [
			'allowed_ips' => [ '203.0.113.0/24' ],
			'expires_at'  => self::NOW - 1,
		] );

		// IP wins over expiry, so the gate 404s instead of explaining the link.
		self::assertSame(
			AccessDecision::REASON_IP_BLOCKED,
			$this->policy->decide( $link, self::NOW, false, '198.51.100.7' )->reason()
		);
	}

	/**
	 * @param array{expires_at?: int, max_uses?: int|null, viewers?: list<string>, revoked_at?: int|null, allowed_ips?: list<string>} $overrides
	 */
	private function link( array $overrides ): PreviewLink {
		return new PreviewLink(
			13,
			Token::generate()->hash(),
			$overrides['expires_at'] ?? self::NOW + 100,
			array_key_exists( 'max_uses', $overrides ) ? $overrides['max_uses'] : null,
			1,
			self::NOW - 100,
			$overrides['viewers'] ?? [],
			$overrides['revoked_at'] ?? null,
			'',
			$overrides['allowed_ips'] ?? []
		);
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
