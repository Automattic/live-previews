<?php

namespace Automattic\LivePreviews;

use WP_Error;
use WP_Post;

/**
 * Mints a preview link and packages it for a client: the shared orchestration
 * behind every way a link is created.
 *
 * Both the REST endpoint and the create-preview-link ability call {@see mint()},
 * so a link an editor mints in the block editor and one an agent mints over MCP
 * pass through exactly the same post check, URL construction, and telemetry. The
 * two adapters cannot drift: adding a rule here changes every channel at once.
 */
final class PreviewLinkMinter {
	private PreviewLinkService $service;

	public function __construct( PreviewLinkService $service ) {
		$this->service = $service;
	}

	/**
	 * Issue a link for a post and return its shareable URL and expiry.
	 *
	 * @param int      $post_id     Post to preview.
	 * @param int      $expiration  How long the link stays valid, in seconds.
	 * @param int|null $max_uses    Maximum distinct viewers, or null for unlimited.
	 * @param string   $channel     How the link was requested (`rest`, `ability`).
	 *                              Recorded as telemetry so agent-driven previews
	 *                              are distinguishable from editor ones.
	 * @return array{url: string, expires_at: int}|WP_Error A WP_Error when the
	 *                              post does not exist.
	 */
	public function mint( int $post_id, int $expiration, ?int $max_uses, string $channel ) {
		if ( ! get_post( $post_id ) instanceof WP_Post ) {
			return new WP_Error(
				'live_previews_invalid_post',
				__( 'The post could not be found.', 'live-previews' ),
				[ 'status' => 404 ]
			);
		}

		$token = $this->service->mint( $post_id, $expiration, $max_uses, get_current_user_id() );

		// Reuse WordPress's own preview URL (adds preview=true) and carry the
		// token on it, so the gate can unlock the draft for a logged-out visitor.
		$url = get_preview_post_link( $post_id, [ PreviewGate::TOKEN_QUERY_VAR => $token->value() ] );

		// Usage metadata only — never the token, content, or PII.
		// Prefixed to `livepreviews_link_created` by the Telemetry client.
		// `is_capped` keeps `max_uses` a clean integer: an uncapped link reports
		// is_capped=false with max_uses=0 rather than a null that Tracks would
		// coerce to the string "null".
		Telemetry::get_instance()->record_event(
			'link_created',
			[
				'expiration' => $expiration,
				'is_capped'  => null !== $max_uses,
				'max_uses'   => (int) $max_uses,
				'channel'    => $channel,
			]
		);

		return [
			'url'        => (string) $url,
			'expires_at' => time() + $expiration,
		];
	}
}
