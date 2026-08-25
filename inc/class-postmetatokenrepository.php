<?php

namespace Automattic\LivePreviews;

/**
 * Postmeta-backed {@see TokenRepository}.
 *
 * Each issued link is one hidden postmeta row on its post, so a post can carry
 * several live links at once (mirroring how the editor lets an author generate
 * more than one). Lookups load a single post's meta (already cached by WordPress)
 * and match by hash, so there is no cross-post query on the request path.
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

	/**
	 * Storage schema version.
	 *
	 * 1: a `use_count` integer.
	 * 2: a `viewers` list of opaque slot IDs, so slots are server-issued and
	 *    writes are idempotent. Version 1 rows are read as anonymous slots (see
	 *    {@see PostMetaTokenRepository::from_array()}).
	 */
	private const VERSION = 2;

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
	 * Compare-and-swap the viewer onto the stored row.
	 *
	 * Passing the pre-read state as `$prev_value` makes WordPress emit
	 * `UPDATE ... WHERE meta_value = <old>`, which MySQL evaluates under a row
	 * lock. A concurrent request that already claimed the slot will have changed
	 * `meta_value`, so this update matches nothing and returns false — telling the
	 * caller to re-read rather than clobbering the winner's write.
	 */
	public function add_viewer( PreviewLink $link, string $viewer_id ): bool {
		if ( $link->holds_slot( $viewer_id ) ) {
			// Already holds a slot; nothing to persist.
			return true;
		}

		// update_post_meta() falls back to *adding* a row when the key has none
		// left, so a claim racing publish or trash cleanup could resurrect the
		// link it just deleted. Re-reading first closes most of that window; a
		// row-keyed conditional UPDATE would close it completely, which is the
		// case for moving this store to its own table.
		if ( null === $this->find_by_hash( $link->post_id(), $link->token_hash() ) ) {
			return false;
		}

		return (bool) update_post_meta(
			$link->post_id(),
			self::META_KEY,
			$this->to_array( $link->with_viewer( $viewer_id ) ),
			$this->to_array( $link )
		);
	}

	public function find_by_hash( int $post_id, string $token_hash ): ?PreviewLink {
		foreach ( $this->all_for_post( $post_id ) as $link ) {
			if ( hash_equals( $link->token_hash(), $token_hash ) ) {
				return $link;
			}
		}

		return null;
	}

	public function revoke( PreviewLink $link, int $revoked_at ): void {
		update_post_meta(
			$link->post_id(),
			self::META_KEY,
			$this->to_array( $link->with_revoked( $revoked_at ) ),
			$this->to_array( $link )
		);
	}

	public function delete_all_for_post( int $post_id ): void {
		delete_post_meta( $post_id, self::META_KEY );
	}

	public function delete_dead_for_post( int $post_id, int $dead_before, int $now ): int {
		$deleted = 0;

		foreach ( $this->all_for_post( $post_id ) as $link ) {
			$dead_since = $link->dead_since( $now );

			if ( null === $dead_since || $dead_since >= $dead_before ) {
				continue;
			}

			if ( delete_post_meta( $post_id, self::META_KEY, $this->to_array( $link ) ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	public function post_ids_with_links( int $after_post_id, int $limit ): array {
		/** @var \wpdb $wpdb */
		global $wpdb;

		/**
		 * Indexed on `meta_key` and never run on a page request — only from the
		 * garbage-collection cron, in bounded batches, walking a `post_id` cursor.
		 * Caching the result would be pointless (it changes as we delete) and
		 * harmful (it is a large, single-use list).
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched cron sweep over an indexed meta_key; see above.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT %d",
				self::META_KEY,
				$after_post_id,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function to_array( PreviewLink $link ): array {
		return [
			'version'    => self::VERSION,
			'token_hash' => $link->token_hash(),
			'expires_at' => $link->expires_at(),
			'max_uses'   => $link->max_uses(),
			'created_by' => $link->created_by(),
			'created_at' => $link->created_at(),
			'viewers'    => $link->viewers(),
			'revoked_at' => $link->revoked_at(),
			'token_hint' => $link->token_hint(),
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
			isset( $row['max_uses'] ) && null !== $row['max_uses'] ? (int) $row['max_uses'] : null,
			isset( $row['created_by'] ) ? (int) $row['created_by'] : 0,
			isset( $row['created_at'] ) ? (int) $row['created_at'] : 0,
			$this->viewers_from_row( $row ),
			isset( $row['revoked_at'] ) ? (int) $row['revoked_at'] : null,
			isset( $row['token_hint'] ) && is_string( $row['token_hint'] ) ? $row['token_hint'] : ''
		);
	}

	/**
	 * The slots held on a stored link.
	 *
	 * A version 1 row recorded only how many slots were spent, not who held them.
	 * Those are rebuilt as placeholders that no cookie can match: the cap is still
	 * honoured, and a viewer counted under the old scheme has to claim a fresh
	 * slot rather than inherit one. Stricter, which is the safe direction.
	 *
	 * @param array<string, mixed> $row
	 * @return list<string>
	 */
	private function viewers_from_row( array $row ): array {
		if ( isset( $row['viewers'] ) && is_array( $row['viewers'] ) ) {
			$viewers = [];

			/** @var mixed $viewer */
			foreach ( $row['viewers'] as $viewer ) {
				if ( is_string( $viewer ) && '' !== $viewer ) {
					$viewers[] = $viewer;
				}
			}

			return $viewers;
		}

		$legacy_count = isset( $row['use_count'] ) ? max( 0, (int) $row['use_count'] ) : 0;
		$viewers      = [];

		for ( $index = 0; $index < $legacy_count; $index++ ) {
			$viewers[] = 'legacy-slot-' . $index;
		}

		return $viewers;
	}
}
