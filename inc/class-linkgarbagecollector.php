<?php

namespace Automattic\LivePreviews;

/**
 * Prunes preview links that are finished with.
 *
 * Expired and revoked links are kept on purpose for a while: the gate reads them
 * to tell a visitor "this link has expired" rather than dumping them at a 404,
 * which is worth a lot when a reviewer's link goes stale mid-review. Past that
 * grace period they are just rows, one per link, on every post that ever had one.
 *
 * Sweeping them needs the one thing the repository deliberately avoids on the
 * request path: a cross-post query. Doing it here keeps that cost on a cron job,
 * in bounded batches, walking a post-ID cursor so a large site is swept across
 * several runs instead of one query that times out.
 */
final class LinkGarbageCollector {
	public const HOOK = 'live_previews_prune_links';

	/** Where the last sweep got to, so the next run resumes rather than restarts. */
	private const CURSOR_OPTION = 'live_previews_gc_cursor';

	/** Posts examined per run. Small enough to finish well inside a cron slot. */
	private const BATCH_SIZE = 100;

	/**
	 * How long a dead link is kept so the gate can still explain it. Long enough
	 * to cover a reviewer coming back to a stale link after a fortnight off.
	 */
	private const DEFAULT_GRACE = 21 * DAY_IN_SECONDS;

	private PreviewLinkService $service;

	public function __construct( PreviewLinkService $service ) {
		$this->service = $service;
	}

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );

		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Remove the scheduled sweep. Called on deactivation so an uninstalled plugin
	 * does not leave a cron entry firing into nothing.
	 *
	 * @param bool $_network_wide Passed by register_deactivation_hook; unused.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature is dictated by register_deactivation_hook, which passes the network flag.
	public static function unschedule( bool $_network_wide = false ): void {
		wp_clear_scheduled_hook( self::HOOK );
		delete_option( self::CURSOR_OPTION );
	}

	/**
	 * Sweep one batch of posts, and queue another run if there is more to do.
	 *
	 * @return int Links deleted in this batch.
	 */
	public function run(): int {
		$cursor   = (int) get_option( self::CURSOR_OPTION, 0 );
		$post_ids = $this->service->post_ids_with_links( $cursor, self::BATCH_SIZE );

		if ( [] === $post_ids ) {
			// Swept to the end; start from the top on the next scheduled run.
			delete_option( self::CURSOR_OPTION );

			return 0;
		}

		$grace   = $this->grace_period();
		$deleted = 0;

		foreach ( $post_ids as $post_id ) {
			$deleted += $this->service->prune_dead( $post_id, $grace );
		}

		update_option( self::CURSOR_OPTION, end( $post_ids ), false );

		if ( count( $post_ids ) === self::BATCH_SIZE && false === wp_next_scheduled( self::HOOK ) ) {
			// A full batch means there is probably more; continue shortly rather
			// than waiting a day per hundred posts.
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}

		return $deleted;
	}

	private function grace_period(): int {
		/**
		 * Filters how long an expired or revoked preview link is kept before the
		 * garbage collector deletes it. Until then the gate can still tell a
		 * visitor why their link stopped working.
		 *
		 * @param int $grace_seconds Retention period in seconds (21 days).
		 */
		$grace = (int) apply_filters( 'live_previews_dead_link_grace_period', self::DEFAULT_GRACE );

		return max( 0, $grace );
	}
}
