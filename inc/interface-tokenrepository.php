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
}
