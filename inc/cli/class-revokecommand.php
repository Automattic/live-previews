<?php

namespace Automattic\LivePreviews\Cli;

use Automattic\LivePreviews\PreviewLink;
use Automattic\LivePreviews\PreviewLinkService;
use WP_CLI;

/**
 * The `wp live-previews revoke` command.
 *
 * Revokes through {@see PreviewLinkService::revoke()}, the same path as the
 * editor and the admin table, so a revoked link leaves the tombstone the gate
 * needs to tell a visitor "this link was revoked" rather than a bare 404.
 * `--all` is the incident-response lever: it kills every live link on the post
 * in one command. Behaviour is pinned by features/revoke.feature.
 */
final class RevokeCommand {
	private PreviewLinkService $service;

	public function __construct( PreviewLinkService $service ) {
		$this->service = $service;
	}

	/**
	 * Revoke a post's preview link, or all of them.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The post whose link to revoke.
	 *
	 * [<link>]
	 * : The link to revoke: a token hint as shown by `wp live-previews list`, or a full link id.
	 *
	 * [--all]
	 * : Revoke every live link for the post.
	 *
	 * ## EXAMPLES
	 *
	 *     # Revoke the link whose token hint is "ab3f".
	 *     $ wp live-previews revoke 123 ab3f
	 *     Success: Revoked 1 preview link.
	 *
	 *     # A preview URL leaked: kill every live link on the post.
	 *     $ wp live-previews revoke 123 --all
	 *     Success: Revoked 2 preview links.
	 *
	 * @when after_wp_load
	 *
	 * @param string[]                  $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$post_id    = (int) ( $args[0] ?? 0 );
		$identifier = isset( $args[1] ) ? (string) $args[1] : '';
		$all        = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );

		if ( $all && '' !== $identifier ) {
			WP_CLI::error( 'Specify either a link or --all, not both.' );
			return;
		}

		if ( ! $all && '' === $identifier ) {
			WP_CLI::error( 'Specify the link to revoke (a token hint or full id), or --all.' );
			return;
		}

		$links = $this->service->list_for_post( $post_id );

		if ( $all ) {
			$this->revoke_all( $post_id, $links );
			return;
		}

		$matches = self::matching_links( $links, $identifier );

		if ( [] === $matches ) {
			WP_CLI::error( sprintf( 'No preview link matches "%s".', $identifier ) );
			return;
		}

		if ( count( $matches ) > 1 ) {
			WP_CLI::error(
				sprintf(
					'"%s" matches more than one link; use one of these full ids instead: %s.',
					$identifier,
					implode( ', ', array_map( static fn ( PreviewLink $link ): string => $link->token_hash(), $matches ) )
				)
			);
			return;
		}

		$this->service->revoke( $post_id, $matches[0]->token_hash() );

		WP_CLI::success( 'Revoked 1 preview link.' );
	}

	/**
	 * The not-yet-revoked links matching a token hint or full token hash.
	 *
	 * A hint is only a few characters, so two links can share one; every match
	 * is returned and the caller decides what ambiguity means. Pure, so the
	 * resolution rules are pinned by a unit test without WP-CLI or WordPress.
	 *
	 * @param list<PreviewLink> $links      Every link issued for the post.
	 * @param string            $identifier A token hint or a full token hash.
	 * @return list<PreviewLink>
	 */
	public static function matching_links( array $links, string $identifier ): array {
		$matches = [];

		foreach ( $links as $link ) {
			if ( $link->is_revoked() ) {
				continue;
			}

			if ( $link->token_hash() === $identifier || $link->token_hint() === $identifier ) {
				$matches[] = $link;
			}
		}

		return $matches;
	}

	/**
	 * Revoke every live link on the post. Dead links (already revoked or
	 * expired) are left alone: there is nothing usable to kill, and keeping
	 * their state untouched preserves what the gate tells a returning visitor.
	 *
	 * @param list<PreviewLink> $links Every link issued for the post.
	 */
	private function revoke_all( int $post_id, array $links ): void {
		$now     = time();
		$revoked = 0;

		foreach ( $links as $link ) {
			if ( $link->is_dead( $now ) ) {
				continue;
			}

			if ( $this->service->revoke( $post_id, $link->token_hash() ) ) {
				++$revoked;
			}
		}

		WP_CLI::success(
			1 === $revoked ? 'Revoked 1 preview link.' : sprintf( 'Revoked %d preview links.', $revoked )
		);
	}
}
