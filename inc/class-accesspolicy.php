<?php

namespace Automattic\LivePreviews;

/**
 * Decides whether a presented token may view a post, given the link on record
 * and the current time.
 *
 * This is the extensibility seam for the whole feature. Each milestone plugs a
 * new rule in here (expiry, viewer cap, revocation), and every rule is a pure
 * branch that can be exhaustively unit-tested. The class has no side effects:
 * spending a slot is a separate command, run once per request by the caller,
 * not here, so that a page whose render fires several queries cannot burn its
 * own link mid-load.
 *
 * Order matters. Revocation and expiry are absolute and are checked first, so
 * holding a slot never resurrects a link the author killed or one that simply
 * ran out of time. Only the viewer cap is relaxed for an existing slot-holder.
 */
final class AccessPolicy {
	/**
	 * @param PreviewLink|null $link             The link on record for the post,
	 *                                           or null if no link matched the
	 *                                           presented token.
	 * @param int              $now              Current Unix timestamp.
	 * @param bool             $viewer_holds_slot Whether this visitor presented a
	 *                                           slot ID the link actually issued.
	 *                                           Such a viewer already occupies a
	 *                                           slot, so the exhaustion cap does
	 *                                           not lock them out on a revisit.
	 *                                           Callers must verify the ID
	 *                                           against the link before passing
	 *                                           true — see
	 *                                           {@see PreviewLink::holds_slot()}.
	 */
	public function decide( ?PreviewLink $link, int $now, bool $viewer_holds_slot = false ): AccessDecision {
		if ( null === $link ) {
			return AccessDecision::deny( AccessDecision::REASON_NOT_FOUND );
		}

		if ( $link->is_revoked() ) {
			return AccessDecision::deny( AccessDecision::REASON_REVOKED );
		}

		if ( $link->is_expired( $now ) ) {
			return AccessDecision::deny( AccessDecision::REASON_EXPIRED );
		}

		if ( $link->is_exhausted() && ! $viewer_holds_slot ) {
			return AccessDecision::deny( AccessDecision::REASON_EXHAUSTED );
		}

		return AccessDecision::allow();
	}
}
