<?php

namespace Automattic\LivePreviews\Cli;

use Automattic\LivePreviews\PreviewLinkMinter;
use Automattic\LivePreviews\PreviewRestController;
use WP_CLI;
use WP_Error;

/**
 * The `wp live-previews create` command.
 *
 * A thin adapter over {@see PreviewLinkMinter}, the same orchestration behind
 * the REST endpoint and the create-preview-link ability, so a link minted from
 * the shell passes exactly the same validation and records the same telemetry
 * (with `cli` as its channel). CLI output is deliberately untranslated, as is
 * conventional for WP-CLI commands. Behaviour is pinned by
 * features/create.feature.
 */
final class CreateCommand {
	private PreviewLinkMinter $minter;

	public function __construct( PreviewLinkMinter $minter ) {
		$this->minter = $minter;
	}

	/**
	 * Create a preview link for a post.
	 *
	 * Prints the shareable URL. The URL carries the secret token — the only
	 * moment it exists in plaintext — so treat the output as sensitive.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The post to create a preview link for.
	 *
	 * [--expiration=<seconds>]
	 * : How long the link stays valid, in seconds. Must be one of the allowed
	 * lifetimes (3600, 28800, 86400, or 604800 unless the site filters
	 * `live_previews_expiration_options`). Defaults to the site's default
	 * lifetime (8 hours unless filtered).
	 *
	 * [--max-uses=<count>]
	 * : Maximum number of distinct viewers, between 1 and 1000. Defaults to
	 * unlimited.
	 *
	 * [--allowed-ips=<ranges>]
	 * : Comma-separated IP addresses or CIDR ranges (IPv4 or IPv6) the link may
	 * be opened from. Defaults to no IP restriction.
	 *
	 * [--porcelain]
	 * : Output just the preview URL.
	 *
	 * ## EXAMPLES
	 *
	 *     # Create a link with the default lifetime.
	 *     $ wp live-previews create 123
	 *     https://example.com/?p=123&preview=true&lp-token=…
	 *     Success: Link expires 2026-09-08 17:00:00 UTC.
	 *
	 *     # A single-viewer link that lasts an hour, for use in a script.
	 *     $ wp live-previews create 123 --expiration=3600 --max-uses=1 --porcelain
	 *
	 * @when after_wp_load
	 *
	 * @param string[]                  $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$post_id = (int) ( $args[0] ?? 0 );

		$expiration = isset( $assoc_args['expiration'] )
			? (int) $assoc_args['expiration']
			: PreviewRestController::default_expiration();

		$allowed = PreviewRestController::allowed_expirations();

		if ( ! in_array( $expiration, $allowed, true ) ) {
			WP_CLI::error(
				sprintf(
					'%d is not an allowed lifetime. Allowed values (in seconds): %s.',
					$expiration,
					implode( ', ', $allowed )
				)
			);
			return;
		}

		$max_uses = null;

		if ( isset( $assoc_args['max-uses'] ) ) {
			$max_uses = (int) $assoc_args['max-uses'];

			if ( $max_uses < 1 || $max_uses > PreviewRestController::MAX_USES_LIMIT ) {
				WP_CLI::error(
					sprintf( '--max-uses must be between 1 and %d.', PreviewRestController::MAX_USES_LIMIT )
				);
				return;
			}
		}

		$allowed_ips = [];

		if ( isset( $assoc_args['allowed-ips'] ) && is_string( $assoc_args['allowed-ips'] ) ) {
			// The minter validates each range; splitting is all that happens here.
			$allowed_ips = array_values(
				array_filter( array_map( 'trim', explode( ',', $assoc_args['allowed-ips'] ) ) )
			);
		}

		$result = $this->minter->mint( $post_id, $expiration, $max_uses, 'cli', $allowed_ips );

		if ( $result instanceof WP_Error ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::line( $result['url'] );

		if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			WP_CLI::success( sprintf( 'Link expires %s UTC.', gmdate( 'Y-m-d H:i:s', $result['expires_at'] ) ) );
		}
	}
}
