<?php

namespace Automattic\LivePreviews;

/**
 * Persistence boundary for issued preview links.
 *
 * The concrete implementation ships as postmeta ({@see PostMetaTokenRepository}),
 * but nothing outside this interface knows that: a custom table or external store
 * can be swapped in later without touching the domain or its tests.
 *
 * Lookups are scoped by post ID because the preview URL always carries it (it
 * reuses WordPress's own preview URL, e.g. `?p=13&preview=true`). That sidesteps
 * any need for a global token -> post reverse index.
 */
interface TokenRepository {
	/**
	 * Persist a newly issued link.
	 */
	public function save( PreviewLink $link ): void;

	/**
	 * Find the link on record for this post that the presented token unlocks, or
	 * null if none matches. Implementations must compare against the stored hash
	 * in constant time (see {@see PreviewLink::matches()}).
	 */
	public function find( int $post_id, Token $candidate ): ?PreviewLink;

	/**
	 * Every link issued for a post, for listing in the editor.
	 *
	 * @return list<PreviewLink>
	 */
	public function all_for_post( int $post_id ): array;

	/**
	 * Give a viewer a slot on an existing link, atomically.
	 *
	 * The passed link is the state the caller read; implementations must persist
	 * its {@see PreviewLink::with_viewer()} form *only if* the stored record is
	 * still in that same state, and return false otherwise. That compare-and-swap
	 * is what stops two concurrent visitors both claiming the last slot: the
	 * loser is told so and re-reads rather than silently overwriting.
	 */
	public function add_viewer( PreviewLink $link, string $viewer_id ): bool;

	/**
	 * The link on this post whose token hash matches, or null. Unlike {@see find()}
	 * this takes the hash directly, so the editor can address a link it never sees
	 * the secret for.
	 */
	public function find_by_hash( int $post_id, string $token_hash ): ?PreviewLink;

	/**
	 * Persist the revocation of an existing link. The passed link is the
	 * pre-revocation state; implementations store its {@see
	 * PreviewLink::with_revoked()} form.
	 */
	public function revoke( PreviewLink $link, int $revoked_at ): void;

	/**
	 * Delete every link for a post, live or not. Used when a post is published or
	 * trashed and its preview links become meaningless.
	 */
	public function delete_all_for_post( int $post_id ): void;

	/**
	 * Delete this post's links that stopped being usable before the cutoff,
	 * returning how many were removed.
	 *
	 * Dead links are kept for a grace period so the gate can still tell a visitor
	 * "this link expired" rather than 404; past that they are only clutter.
	 */
	public function delete_dead_for_post( int $post_id, int $dead_before, int $now ): int;

	/**
	 * A batch of post IDs that still carry links, for the garbage collector to
	 * walk. Ordered by post ID ascending and starting after the given cursor, so
	 * a long site can be swept across several cron runs without re-reading work
	 * it has already done.
	 *
	 * @return list<int>
	 */
	public function post_ids_with_links( int $after_post_id, int $limit ): array;
}
