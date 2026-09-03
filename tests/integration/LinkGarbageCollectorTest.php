<?php
declare(strict_types = 1);

namespace Automattic\LivePreviews;

use WP_UnitTestCase;

/**
 * The cron sweep that stops dead links accumulating as postmeta forever.
 *
 * @covers \Automattic\LivePreviews\LinkGarbageCollector
 */
class LinkGarbageCollectorTest extends WP_UnitTestCase {
	private PostMetaTokenRepository $repository;
	private PreviewLinkService $service;
	private LinkGarbageCollector $collector;

	public function set_up(): void {
		parent::set_up();

		$this->repository = new PostMetaTokenRepository();
		$this->service    = new PreviewLinkService(
			$this->repository,
			new AccessPolicy(),
			new SystemClock()
		);
		$this->collector  = new LinkGarbageCollector( $this->service );
	}

	public function tear_down(): void {
		LinkGarbageCollector::unschedule();
		remove_all_filters( 'live_previews_dead_link_grace_period' );
		parent::tear_down();
	}

	public function test_it_deletes_links_dead_beyond_the_grace_period(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->save_link( $post_id, time() - 90 * DAY_IN_SECONDS );

		static::assertSame( 1, $this->collector->run() );
		static::assertCount( 0, $this->repository->all_for_post( $post_id ) );
	}

	public function test_it_keeps_a_recently_expired_link_so_the_gate_can_explain_it(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->save_link( $post_id, time() - HOUR_IN_SECONDS );

		static::assertSame( 0, $this->collector->run() );
		static::assertCount( 1, $this->repository->all_for_post( $post_id ) );
	}

	public function test_it_keeps_live_links(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		static::assertSame( 0, $this->collector->run() );
		static::assertCount( 1, $this->repository->all_for_post( $post_id ) );
	}

	public function test_it_deletes_a_long_revoked_link(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = Token::generate();

		$this->repository->save(
			new PreviewLink(
				$post_id,
				$token->hash(),
				time() + YEAR_IN_SECONDS,
				null,
				1,
				time() - 200 * DAY_IN_SECONDS,
				[],
				time() - 90 * DAY_IN_SECONDS
			)
		);

		static::assertSame( 1, $this->collector->run() );
	}

	public function test_the_grace_period_is_filterable(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->save_link( $post_id, time() - 2 * HOUR_IN_SECONDS );

		add_filter( 'live_previews_dead_link_grace_period', static fn (): int => HOUR_IN_SECONDS );

		static::assertSame( 1, $this->collector->run() );
	}

	public function test_it_sweeps_across_posts(): void {
		$first  = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$second = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->save_link( $first, time() - 90 * DAY_IN_SECONDS );
		$this->save_link( $second, time() - 90 * DAY_IN_SECONDS );

		static::assertSame( 2, $this->collector->run() );
	}

	public function test_registering_schedules_a_daily_sweep(): void {
		$this->collector->register();

		static::assertNotFalse( wp_next_scheduled( LinkGarbageCollector::HOOK ) );
		static::assertSame( 'daily', wp_get_schedule( LinkGarbageCollector::HOOK ) );
	}

	public function test_unscheduling_clears_the_event(): void {
		$this->collector->register();
		LinkGarbageCollector::unschedule();

		static::assertFalse( wp_next_scheduled( LinkGarbageCollector::HOOK ) );
		static::assertNull( LinkGarbageCollector::last_run() );
	}

	/**
	 * Site Health reads this to tell "the sweep is scheduled" apart from "the
	 * sweep is actually running".
	 */
	public function test_a_run_is_stamped_even_when_there_is_nothing_to_sweep(): void {
		static::assertNull( LinkGarbageCollector::last_run() );

		$before = time();
		static::assertSame( 0, $this->collector->run() );

		$last_run = LinkGarbageCollector::last_run();
		static::assertNotNull( $last_run );
		static::assertGreaterThanOrEqual( $before, $last_run );
	}

	/**
	 * Persist a link that expired at the given moment.
	 */
	private function save_link( int $post_id, int $expires_at ): void {
		$this->repository->save(
			new PreviewLink(
				$post_id,
				Token::generate()->hash(),
				$expires_at,
				null,
				1,
				$expires_at - HOUR_IN_SECONDS
			)
		);
	}
}
