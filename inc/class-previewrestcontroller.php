<?php

namespace Automattic\LivePreviews;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST endpoint the editor calls to mint a preview link.
 *
 * Instance-based and injected with {@see PreviewLinkService}, so the same service
 * graph assembled in the composition root backs both minting (here) and
 * enforcement ({@see PreviewGate}).
 */
final class PreviewRestController {
	public const NAMESPACE = 'live-previews/v1';
	public const ROUTE     = '/preview-links';

	private PreviewLinkService $service;

	public function __construct( PreviewLinkService $service ) {
		$this->service = $service;
	}

	/**
	 * Allowed link lifetimes, shown in the editor dropdown and enforced here.
	 * Source of truth for both the UI and validation.
	 *
	 * @return list<array{seconds: int, label: string}>
	 */
	public static function expiration_options(): array {
		return [
			[
				'seconds' => HOUR_IN_SECONDS,
				'label'   => __( '1 hour', 'live-previews' ),
			],
			[
				'seconds' => 8 * HOUR_IN_SECONDS,
				'label'   => __( '8 hours', 'live-previews' ),
			],
			[
				'seconds' => DAY_IN_SECONDS,
				'label'   => __( '24 hours', 'live-previews' ),
			],
			[
				'seconds' => WEEK_IN_SECONDS,
				'label'   => __( '7 days', 'live-previews' ),
			],
		];
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_link' ],
				'permission_callback' => [ $this, 'can_create_link' ],
				'args'                => [
					'post_id'      => [
						'required' => true,
						'type'     => 'integer',
					],
					'expiration'   => [
						'required' => true,
						'type'     => 'integer',
						'enum'     => self::allowed_expirations(),
					],
					'one_time_use' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);
	}

	/**
	 * A link may be minted only by someone who can edit the target post.
	 */
	public function can_create_link( WP_REST_Request $request ): bool {
		return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_link( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! get_post( $post_id ) instanceof WP_Post ) {
			return new WP_Error(
				'live_previews_invalid_post',
				__( 'The post could not be found.', 'live-previews' ),
				[ 'status' => 404 ]
			);
		}

		$expiration   = (int) $request->get_param( 'expiration' );
		$one_time_use = (bool) $request->get_param( 'one_time_use' );

		$token = $this->service->mint( $post_id, $expiration, $one_time_use, get_current_user_id() );

		// Reuse WordPress's own preview URL (adds preview=true) and carry the
		// token on it, so the gate can unlock the draft for a logged-out visitor.
		$url = get_preview_post_link( $post_id, [ PreviewGate::TOKEN_QUERY_VAR => $token->value() ] );

		// Usage metadata only — never the token, content, or PII.
		Telemetry::get_instance()->record_event(
			'preview_link_created',
			[
				'expiration'   => $expiration,
				'one_time_use' => $one_time_use,
			]
		);

		return rest_ensure_response(
			[
				'url'        => $url,
				'expires_at' => time() + $expiration,
			]
		);
	}

	/**
	 * @return list<int>
	 */
	private static function allowed_expirations(): array {
		return array_map(
			/** @param array{seconds: int, label: string} $option */
			static fn ( array $option ): int => $option['seconds'],
			self::expiration_options()
		);
	}
}
