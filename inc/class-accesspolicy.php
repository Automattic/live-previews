<?php

namespace Automattic\LivePreviews;

/**
 * Decides whether a presented token may view a post, given the link on record
 * and the current time.
 *
 * This is the extensibility seam for the whole feature. Each milestone plugs a
 * new rule in here (expiry, viewer cap, revocation), and every rule is a pure
 * branch that can be exhaustively unit-tested. The class has no side effects:
 * counting a viewer is a separate command, run once per request by the caller,
 * not here, so that a page whose render fires several queries cannot burn its
 * own link mid-load.
 */
final class AccessPolicy {
	/**
	 * @param PreviewLink|null $link                  The link on record for the
	 *                                                post, or null if no link
	 *                                                matched the presented token.
	 * @param int              $now                   Current Unix timestamp.
	 * @param bool             $viewer_already_counted Whether this viewer has
	 *                                                already been counted against
	 *                                                the link (e.g. a return
	 *                                                visit). Such a viewer holds
	 *                                                a slot, so the exhaustion cap
	 *                                                does not lock them out.
	 */
	public function decide( ?PreviewLink $link, int $now, bool $viewer_already_counted = false ): AccessDecision {
		if ( null === $link ) {
			return AccessDecision::deny( AccessDecision::REASON_NOT_FOUND );
		}

		if ( $link->is_revoked() ) {
			return AccessDecision::deny( AccessDecision::REASON_REVOKED );
		}

		if ( $link->is_expired( $now ) ) {
			return AccessDecision::deny( AccessDecision::REASON_EXPIRED );
		}

		if ( $link->is_exhausted() && ! $viewer_already_counted ) {
			return AccessDecision::deny( AccessDecision::REASON_EXHAUSTED );
		}

		return AccessDecision::allow();
	}
}
