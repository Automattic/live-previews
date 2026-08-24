<?php

namespace Automattic\LivePreviews;

use WP_Post;

/**
 * Deletes a post's preview links once it is published.
 *
 * A public post is viewable by anyone, so its preview links are meaningless and
 * only clutter storage. Rather than leave them to be pruned lazily, discard them
 * outright the moment the post becomes public.
 */
final class PublishCleanup {
	private PreviewLinkService $service;

	public function __construct( PreviewLinkService $service ) {
		$this->service = $service;
	}

	public function register(): void {
		add_action( 'transition_post_status', [ $this, 'on_transition' ], 10, 3 );
	}

	/**
	 * @param string  $new_status The post's new status.
	 * @param string  $old_status The post's previous status.
	 * @param WP_Post $post       The post being transitioned.
	 */
	public function on_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			$this->service->discard_all( (int) $post->ID );
		}
	}
}
