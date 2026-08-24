<?php

namespace Automattic\LivePreviews;

/**
 * Postmeta-backed {@see TokenRepository}.
 *
 * Each issued link is one hidden postmeta row on its post, so a post can carry
 * several live links at once (mirroring how the editor lets an author generate
 * more than one). Lookups load a single post's meta (already cached by WordPress)
 * and match by hash, so there is no cross-post query.
 *
 * This is the only class that knows links live in postmeta. Swapping in a custom
 * table later means writing one more TokenRepository and changing a single line
 * in the composition root; the domain and its tests do not move.
 */
final class PostMetaTokenRepository implements TokenRepository {
	/**
	 * Hidden meta key (leading underscore) so links never show in the Custom
	 * Fields UI.
	 */
	public const META_KEY = '_live_previews_token';

	/** Storage schema version, to allow safe format changes later. */
	private const VERSION = 1;

	public function save( PreviewLink $link ): void {
		add_post_meta( $link->post_id(), self::META_KEY, $this->to_array( $link ) );
	}

	public function find( int $post_id, Token $candidate ): ?PreviewLink {
		foreach ( $this->all_for_post( $post_id ) as $link ) {
			if ( $link->matches( $candidate ) ) {
				return $link;
			}
		}

		return null;
	}

	public function all_for_post( int $post_id ): array {
		$links = [];

		/** @var mixed $row */
		foreach ( get_post_meta( $post_id, self::META_KEY, false ) as $row ) {
			if ( is_array( $row ) ) {
				/** @var array<string, mixed> $row */
				$links[] = $this->from_array( $post_id, $row );
			}
		}

		return $links;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function to_array( PreviewLink $link ): array {
		return [
			'version'      => self::VERSION,
			'token_hash'   => $link->token_hash(),
			'expires_at'   => $link->expires_at(),
			'one_time_use' => $link->is_one_time_use(),
			'created_by'   => $link->created_by(),
			'created_at'   => $link->created_at(),
			'used_at'      => $link->used_at(),
			'revoked_at'   => $link->revoked_at(),
		];
	}

	/**
	 * Rebuild a link from stored data, tolerating missing or malformed keys: a
	 * corrupt row must degrade to an unusable (expired-looking) link, never fatal.
	 *
	 * @param array<string, mixed> $row
	 */
	private function from_array( int $post_id, array $row ): PreviewLink {
		return new PreviewLink(
			$post_id,
			isset( $row['token_hash'] ) && is_string( $row['token_hash'] ) ? $row['token_hash'] : '',
			isset( $row['expires_at'] ) ? (int) $row['expires_at'] : 0,
			! empty( $row['one_time_use'] ),
			isset( $row['created_by'] ) ? (int) $row['created_by'] : 0,
			isset( $row['created_at'] ) ? (int) $row['created_at'] : 0,
			isset( $row['used_at'] ) ? (int) $row['used_at'] : null,
			isset( $row['revoked_at'] ) ? (int) $row['revoked_at'] : null
		);
	}
}
