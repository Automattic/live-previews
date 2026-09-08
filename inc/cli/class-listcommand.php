<?php

namespace Automattic\LivePreviews\Cli;

use Automattic\LivePreviews\PreviewLink;
use Automattic\LivePreviews\PreviewLinkPresenter;
use Automattic\LivePreviews\PreviewLinkService;
use WP_CLI;
use WP_CLI\Formatter;
use WP_Post;

/**
 * The `wp live-previews list` command.
 *
 * Reads through {@see PreviewLinkService} and shapes rows with
 * {@see PreviewLinkPresenter}, the same pair behind the REST listing and the
 * list-preview-links ability, so every surface shows the same links — live ones
 * only, and never the token itself (it is not stored; only a short hint is).
 * Behaviour is pinned by features/list.feature.
 */
final class ListCommand {
	/** Links fetched per page when walking the site-wide listing. */
	private const PAGE_SIZE = 100;

	private PreviewLinkService $service;

	public function __construct( PreviewLinkService $service ) {
		$this->service = $service;
	}

	/**
	 * List live preview links, for one post or the whole site.
	 *
	 * ## OPTIONS
	 *
	 * [<post-id>]
	 * : The post whose links to list. Omit to list every live link on the site,
	 * newest first.
	 *
	 * [--field=<field>]
	 * : Print one field for each link.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields to show. Available fields: post_id, id,
	 * token_hint, created_at, expires_at, expires_in, use_count, max_uses,
	 * allowed_ips.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - count
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List a post's live links.
	 *     $ wp live-previews list 123
	 *
	 *     # Every live link on the site, as JSON.
	 *     $ wp live-previews list --format=json
	 *
	 *     # Just the token hint, e.g. to feed `wp live-previews revoke`.
	 *     $ wp live-previews list 123 --field=token_hint
	 *
	 * @when after_wp_load
	 *
	 * @param string[]                  $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$post_id = isset( $args[0] ) ? (int) $args[0] : null;

		if ( null !== $post_id && ! get_post( $post_id ) instanceof WP_Post ) {
			WP_CLI::error( 'The post could not be found.' );
			return;
		}

		$links = null === $post_id
			? $this->all_links()
			: $this->service->list_for_post( $post_id );

		$now   = time();
		$items = [];

		foreach ( $links as $link ) {
			// One link at a time through the shared presenter, so the CLI keeps
			// exactly its field set (and its live-links-only rule) while adding
			// the post ID the per-post surfaces do not need.
			$presented = PreviewLinkPresenter::present_live_links( [ $link ], $now );

			if ( [] === $presented ) {
				continue;
			}

			$row = $presented[0];

			$items[] = [
				'post_id'     => $link->post_id(),
				'id'          => $row['id'],
				'token_hint'  => $row['token_hint'],
				'created_at'  => gmdate( 'Y-m-d H:i:s', $row['created_at'] ),
				'expires_at'  => gmdate( 'Y-m-d H:i:s', $row['expires_at'] ),
				'expires_in'  => human_time_diff( $now, $row['expires_at'] ),
				'use_count'   => $row['use_count'],
				'max_uses'    => $row['max_uses'],
				'allowed_ips' => implode( ',', $row['allowed_ips'] ),
			];
		}

		$format = isset( $assoc_args['format'] ) && is_string( $assoc_args['format'] )
			? $assoc_args['format']
			: 'table';

		if ( [] === $items && 'table' === $format ) {
			WP_CLI::log( 'No preview links found.' );
			return;
		}

		$default_fields = [ 'token_hint', 'created_at', 'expires_at', 'expires_in', 'use_count', 'max_uses', 'allowed_ips' ];

		if ( null === $post_id ) {
			array_unshift( $default_fields, 'post_id' );
		}

		$formatter = new Formatter( $assoc_args, $default_fields );
		$formatter->display_items( $items );
	}

	/**
	 * Every link on the site, walked page by page so an unbounded listing never
	 * turns into one unbounded query.
	 *
	 * @return list<PreviewLink>
	 */
	private function all_links(): array {
		$links  = [];
		$offset = 0;

		do {
			$page       = $this->service->page_of_links( $offset, self::PAGE_SIZE );
			$page_count = count( $page );
			$links      = [ ...$links, ...$page ];
			$offset    += self::PAGE_SIZE;
		} while ( self::PAGE_SIZE === $page_count );

		return $links;
	}
}
