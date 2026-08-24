<?php

namespace Automattic\LivePreviews;

use WP_Post;
use WP_Query;

/**
 * Enforces preview access at request time.
 *
 * A shareable link reuses WordPress's own preview URL (e.g. `?p=13&preview=true`)
 * with an extra `lp-token` parameter. Rather than render a post page from scratch
 * on a bespoke endpoint, we hook `posts_results` — which fires after WP_Query has
 * loaded a post but *before* it drops non-public posts for logged-out visitors —
 * and, for a valid token, mark that one post public for the current query. Every
 * other request is untouched. Approach borrowed from vip-workflow-plugin PR #19.
 */
final class PreviewGate {
	public const TOKEN_QUERY_VAR = 'lp-token';

	private PreviewLinkService $service;

	public function __construct( PreviewLinkService $service ) {
		$this->service = $service;
	}

	public function register(): void {
		add_filter( 'posts_results', [ $this, 'unlock_valid_previews' ], 10, 2 );
	}

	/**
	 * @param mixed    $posts Posts loaded by the query (array of WP_Post on success).
	 * @param WP_Query $query The query being run.
	 * @return mixed
	 */
	public function unlock_valid_previews( $posts, WP_Query $query ) {
		if ( ! is_array( $posts ) || [] === $posts || ! $query->is_preview() ) {
			return $posts;
		}

		$token_value = $this->token_from_request();
		if ( null === $token_value ) {
			return $posts;
		}

		$token = Token::from_string( $token_value );

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$status = get_post_status_object( $post->post_status );

			// Only unpublished posts need unlocking; public ones already render.
			if ( $status && $status->public ) {
				continue;
			}

			if ( $this->service->authorize( $post->ID, $token )->is_allowed() ) {
				// Marking the post published for this query alone lets it survive
				// WP_Query's "logged-out users cannot see non-public posts" check.
				$post->post_status = 'publish';
			}
		}

		return $posts;
	}

	/**
	 * The raw token from the request URL, or null if absent. It is a bearer secret,
	 * not a form submission, so there is no nonce to verify: validity is proven by
	 * matching the stored hash, and access is read-only.
	 */
	private function token_from_request(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bearer preview token, validated by hash match; no state change.
		if ( ! isset( $_GET[ self::TOKEN_QUERY_VAR ] ) || ! is_scalar( $_GET[ self::TOKEN_QUERY_VAR ] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bearer preview token, validated by hash match; no state change.
		$raw = sanitize_text_field( wp_unslash( (string) $_GET[ self::TOKEN_QUERY_VAR ] ) );

		return '' === $raw ? null : $raw;
	}
}
