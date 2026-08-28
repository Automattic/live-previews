<?php

namespace Automattic\LivePreviews;

use WP_Error;

/**
 * Registers preview-link functionality with WordPress's Abilities API, so the
 * same capability the editor uses is available to machines: MCP clients, the
 * WordPress AI Client, and the generic abilities REST runner.
 *
 * The driving use case is drafting with a local agent — the agent can mint a
 * shareable preview without a human switching to the block editor. The ability
 * is a sibling adapter to {@see PreviewRestController}: both call the same
 * {@see PreviewLinkMinter}, so the two entry points cannot diverge.
 *
 * @see https://developer.wordpress.org/apis/abilities/
 */
final class PreviewAbilities {
	/** Ability category slug grouping this plugin's abilities. */
	public const CATEGORY = 'live-previews';

	/** Fully-qualified name of the create-link ability. */
	public const CREATE_LINK = 'live-previews/create-preview-link';

	private PreviewLinkMinter $minter;

	public function __construct( PreviewLinkMinter $minter ) {
		$this->minter = $minter;
	}

	/**
	 * Hook registration onto the Abilities API's init actions.
	 *
	 * The API lands in WordPress 6.9 — the plugin's minimum — so the guard is
	 * belt-and-braces: on any environment without it, the plugin loads cleanly
	 * and simply registers no abilities rather than fataling.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'Live Previews', 'live-previews' ),
				'description' => __( 'Create and manage pre-publish preview links.', 'live-previews' ),
			]
		);
	}

	public function register_abilities(): void {
		wp_register_ability(
			self::CREATE_LINK,
			[
				'label'               => __( 'Create preview link', 'live-previews' ),
				'description'         => __( 'Issues a shareable link that lets a logged-out reviewer view a draft before it is published. Returns the preview URL and the timestamp it expires. The URL contains a secret token, so treat the result as sensitive.', 'live-previews' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'post_id' ],
					'properties' => [
						'post_id'    => [
							'type'        => 'integer',
							'description' => __( 'ID of the draft to generate a preview link for.', 'live-previews' ),
						],
						'expiration' => [
							'type'        => 'integer',
							// Mirrors the REST endpoint's accepted lifetimes so both
							// channels honour the same (filterable) set.
							'enum'        => PreviewRestController::allowed_expirations(),
							'default'     => PreviewRestController::default_expiration(),
							'description' => __( 'How long the link stays valid, in seconds.', 'live-previews' ),
						],
						'max_uses'   => [
							'type'        => [ 'integer', 'null' ],
							'default'     => null,
							'minimum'     => 1,
							'maximum'     => PreviewRestController::MAX_USES_LIMIT,
							'description' => __( 'Maximum number of distinct viewers, or null for unlimited.', 'live-previews' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'url'        => [
							'type'        => 'string',
							'description' => __( 'The shareable preview URL, carrying the secret token.', 'live-previews' ),
						],
						'expires_at' => [
							'type'        => 'integer',
							'description' => __( 'Unix timestamp when the link expires.', 'live-previews' ),
						],
					],
				],
				'execute_callback'    => [ $this, 'create_link' ],
				'permission_callback' => [ $this, 'can_create_link' ],
				'meta'                => [
					// One flag exposes the ability to REST, MCP, and the AI Client.
					'public'      => true,
					'annotations' => [
						// Writes a token row, but only ever adds — never removes or
						// mutates existing state — and each call yields a new link.
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
				],
			]
		);
	}

	/**
	 * Only someone who can edit the target post may mint a link for it — the
	 * same gate the REST endpoint applies.
	 *
	 * Input is not guaranteed to be schema-validated when a caller runs the
	 * permission check on its own, so read it defensively.
	 *
	 * @param mixed $input The ability input.
	 */
	public function can_create_link( $input ): bool {
		$post_id = is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * @param mixed $input The schema-validated ability input.
	 * @return array{url: string, expires_at: int}|WP_Error
	 */
	public function create_link( $input ) {
		$input = is_array( $input ) ? $input : [];

		$post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$expiration = isset( $input['expiration'] ) ? (int) $input['expiration'] : PreviewRestController::default_expiration();
		$max_uses   = isset( $input['max_uses'] ) ? (int) $input['max_uses'] : null;

		return $this->minter->mint( $post_id, $expiration, $max_uses, 'ability' );
	}
}
