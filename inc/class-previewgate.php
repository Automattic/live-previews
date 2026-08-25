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
 *
 * When a link caps the number of viewers, the gate counts one use per distinct
 * human. "Human" is approximated by a per-link cookie (so refreshes and asset
 * requests in the same browser do not re-count) and by excluding known link
 * unfurlers and crawlers (so pasting a link into chat does not silently spend a
 * use). A separate browser or a private window has its own cookie jar and so
 * counts as a new viewer, which is the intended behaviour.
 */
final class PreviewGate {
	public const TOKEN_QUERY_VAR = 'lp-token';

	private const COOKIE_PREFIX = 'lp_seen_';

	private PreviewLinkService $service;

	/** Ensures a single request counts at most one use, however many queries run. */
	private bool $counted_this_request = false;

	/** Reason the main query's preview was denied, for the friendly notice. */
	private ?string $denial_reason = null;

	public function __construct( PreviewLinkService $service ) {
		$this->service = $service;
	}

	public function register(): void {
		add_filter( 'posts_results', [ $this, 'unlock_valid_previews' ], 10, 2 );
		add_action( 'template_redirect', [ $this, 'maybe_render_expired_notice' ] );
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

		$token           = Token::from_string( $token_value );
		$already_counted = $this->viewer_already_counted( $token );

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$status = get_post_status_object( $post->post_status );

			// Only unpublished posts need unlocking; public ones already render.
			if ( $status && $status->public ) {
				continue;
			}

			// Leave authors and editors to WordPress's own preview: they can
			// already see the draft, so a spent link must not lock them out.
			if ( current_user_can( 'edit_post', (int) $post->ID ) ) {
				continue;
			}

			$decision = $this->service->authorize( (int) $post->ID, $token, $already_counted );

			if ( ! $decision->is_allowed() ) {
				// Remember a dead-but-real link so template_redirect can explain
				// why, instead of leaving the visitor at a bare 404. Only preview
				// requests reach here, so this is the page the visitor asked for.
				$this->remember_denial( $decision->reason() );
				continue;
			}

			// Marking the post published for this query alone lets it survive
			// WP_Query's "logged-out users cannot see non-public posts" check.
			$post->post_status = 'publish';

			$this->count_new_viewer( (int) $post->ID, $token, $already_counted );
		}

		return $posts;
	}

	/**
	 * Record why the main preview was denied, but only for links that once
	 * existed. An unknown or wrong token is left to 404 exactly as a missing
	 * post would, so nobody can probe which draft IDs exist.
	 */
	private function remember_denial( string $reason ): void {
		$explainable = [
			AccessDecision::REASON_EXPIRED,
			AccessDecision::REASON_REVOKED,
			AccessDecision::REASON_EXHAUSTED,
		];

		if ( in_array( $reason, $explainable, true ) ) {
			$this->denial_reason = $reason;
		}
	}

	/**
	 * Show a friendly page when a real preview link is no longer usable.
	 */
	public function maybe_render_expired_notice(): void {
		if ( null === $this->denial_reason ) {
			return;
		}

		$messages = [
			AccessDecision::REASON_EXPIRED   => __( 'This preview link has expired.', 'live-previews' ),
			AccessDecision::REASON_REVOKED   => __( 'This preview link has been revoked.', 'live-previews' ),
			AccessDecision::REASON_EXHAUSTED => __( 'This preview link has reached its viewing limit.', 'live-previews' ),
		];

		$message = $messages[ $this->denial_reason ] ?? __( 'This preview link is no longer available.', 'live-previews' );

		wp_die(
			sprintf(
				'<p>%s</p><p>%s</p>',
				esc_html( $message ),
				esc_html__( 'Ask the author to share a new preview link.', 'live-previews' )
			),
			esc_html__( 'Preview unavailable', 'live-previews' ),
			[ 'response' => 410 ]
		);
	}

	/**
	 * Count this viewer once, unless they have already been counted, are a known
	 * bot, or a use has already been counted earlier in the same request.
	 */
	private function count_new_viewer( int $post_id, Token $token, bool $already_counted ): void {
		if ( $already_counted || $this->counted_this_request || $this->is_bot() ) {
			return;
		}

		$this->counted_this_request = true;
		$this->service->record_visit( $post_id, $token );
		$this->remember_viewer( $token );
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

	private function cookie_name( Token $token ): string {
		return self::COOKIE_PREFIX . substr( $token->hash(), 0, 20 );
	}

	private function viewer_already_counted( Token $token ): bool {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Preview requests are never page-cached (unique token query string + preview=true), so reading a per-viewer cookie is reliable.
		return isset( $_COOKIE[ $this->cookie_name( $token ) ] );
	}

	/**
	 * Drop a cookie so this browser is not counted again for this link. Scoped to
	 * a week, comfortably longer than the longest offered link lifetime.
	 */
	private function remember_viewer( Token $token ): void {
		$name = $this->cookie_name( $token );

		if ( ! headers_sent() ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- Preview requests carry a unique token query string and preview=true, so they are never page-cached; per-viewer cookie logic is reliable here.
			setcookie(
				$name,
				'1',
				[
					'expires'  => time() + WEEK_IN_SECONDS,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				]
			);
		}

		// Reflect it immediately so a second query in this request sees it.
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Uncached preview request; see above.
		$_COOKIE[ $name ] = '1';
	}

	/**
	 * Whether the request looks like an automated crawler or chat-link unfurler,
	 * which should be allowed to render a preview card but never spend a use.
	 */
	private function is_bot(): bool {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- Only read on uncached preview requests, to skip counting crawlers and link unfurlers.
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( '' === $user_agent ) {
			return true;
		}

		$default_pattern = '/bot|crawler|spider|slack|facebookexternalhit|whatsapp|telegram|discord|twitterbot|linkedinbot|embedly|preview|feedfetcher|pinterest/i';

		/**
		 * Filters the user-agent pattern used to spot crawlers and link unfurlers
		 * that must not consume a preview-link use.
		 *
		 * @param string $default_pattern Regular expression tested against the UA string.
		 */
		$pattern = apply_filters( 'live_previews_bot_user_agent_pattern', $default_pattern );

		if ( ! is_string( $pattern ) || '' === $pattern ) {
			return false;
		}

		return 1 === preg_match( $pattern, $user_agent );
	}
}
