<?php

namespace Automattic\LivePreviews;

use WP_Error;
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

	/**
	 * Upper bound on a link's viewer cap, guarding against absurd values. The
	 * editor offers a free number input, so this is validated server-side.
	 */
	public const MAX_USES_LIMIT = 1000;

	private PreviewLinkService $service;
	private PreviewLinkMinter $minter;

	public function __construct( PreviewLinkService $service, PreviewLinkMinter $minter ) {
		$this->service = $service;
		$this->minter  = $minter;
	}

	/**
	 * Allowed link lifetimes, shown in the editor dropdown and enforced here.
	 * Source of truth for both the UI and validation.
	 *
	 * The default set is deliberately time-limited; a site that wants longer or
	 * never-expiring links can add options through the filter (e.g. a very large
	 * number of seconds for an effectively indefinite link).
	 *
	 * @return list<array{seconds: int, label: string}>
	 */
	public static function expiration_options(): array {
		$options = [
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

		/**
		 * Filters the link-lifetime options offered in the editor and accepted by
		 * the endpoint.
		 *
		 * @param list<array{seconds: int, label: string}> $options Ordered options.
		 */
		/** @var mixed $filtered */
		$filtered = apply_filters( 'live_previews_expiration_options', $options );

		if ( ! is_array( $filtered ) || [] === $filtered ) {
			return $options;
		}

		/** @var list<array{seconds: int, label: string}> $filtered */
		return $filtered;
	}

	/**
	 * The lifetime pre-selected in the editor, in seconds.
	 */
	public static function default_expiration(): int {
		/**
		 * Filters the preview link lifetime pre-selected in the editor.
		 *
		 * @param int $default Default lifetime in seconds (8 hours).
		 */
		return (int) apply_filters( 'live_previews_default_expiration', 8 * HOUR_IN_SECONDS );
	}

	public function register_routes(): void {
		$post_id_arg = [
			'post_id' => [
				'required' => true,
				'type'     => 'integer',
			],
		];

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_link' ],
					'permission_callback' => [ $this, 'can_manage_links' ],
					'args'                => $post_id_arg + [
						'expiration' => [
							'required' => true,
							'type'     => 'integer',
							'enum'     => self::allowed_expirations(),
						],
						'max_uses'   => [
							// Null (or omitted) means unlimited; otherwise a positive
							// integer up to the guard limit.
							'type'    => [ 'integer', 'null' ],
							'default' => null,
							'minimum' => 1,
							'maximum' => self::MAX_USES_LIMIT,
						],
					],
				],
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_links' ],
					'permission_callback' => [ $this, 'can_manage_links' ],
					'args'                => $post_id_arg,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/(?P<id>[a-f0-9]{64})',
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'revoke_link' ],
				'permission_callback' => [ $this, 'can_manage_links' ],
				'args'                => $post_id_arg,
			]
		);
	}

	/**
	 * Links may be managed only by someone who can edit the target post.
	 */
	public function can_manage_links( WP_REST_Request $request ): bool {
		return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
	}

	public function list_links( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response(
			PreviewLinkPresenter::present_live_links(
				$this->service->list_for_post( (int) $request->get_param( 'post_id' ) ),
				time()
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function revoke_link( WP_REST_Request $request ) {
		$post_id    = (int) $request->get_param( 'post_id' );
		$token_hash = (string) $request->get_param( 'id' );

		if ( ! $this->service->revoke( $post_id, $token_hash ) ) {
			return new WP_Error(
				'live_previews_link_not_found',
				__( 'No matching preview link to revoke.', 'live-previews' ),
				[ 'status' => 404 ]
			);
		}

		return rest_ensure_response( [ 'revoked' => true ] );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_link( WP_REST_Request $request ) {
		/** @var mixed $max_uses_param */
		$max_uses_param = $request->get_param( 'max_uses' );
		$max_uses       = null === $max_uses_param ? null : (int) $max_uses_param;

		// A WP_Error from the minter (e.g. a missing post) passes straight
		// through: rest_ensure_response() returns it unchanged and the REST
		// server renders it with its status.
		return rest_ensure_response(
			$this->minter->mint(
				(int) $request->get_param( 'post_id' ),
				(int) $request->get_param( 'expiration' ),
				$max_uses,
				'rest'
			)
		);
	}

	/**
	 * The link lifetimes (in seconds) the plugin accepts, derived from the
	 * filterable {@see expiration_options()}. Shared with the abilities layer so
	 * REST and MCP honour the same set.
	 *
	 * @return list<int>
	 */
	public static function allowed_expirations(): array {
		return array_map(
			/** @param array{seconds: int, label: string} $option */
			static fn ( array $option ): int => $option['seconds'],
			self::expiration_options()
		);
	}
}
