<?php

namespace Automattic\LivePreviews;

/**
 * Decides whether a presented token may view a post, given the link on record
 * and the current time.
 *
 * This is the extensibility seam for the whole feature. Each milestone plugs a
 * new rule in here (expiry today; one-time use and revocation next), and every
 * rule is a pure branch that can be exhaustively unit-tested. The class has no
 * side effects: consuming a one-time link is a separate command, run once per
 * request by the caller, not here, so that a page whose render fires several
 * queries cannot burn its own link mid-load.
 */
final class AccessPolicy {
	/**
	 * @param PreviewLink|null $link  The link on record for the post, or null if
	 *                                no link matched the presented token.
	 * @param int              $now   Current Unix timestamp.
	 */
	public function decide( ?PreviewLink $link, int $now ): AccessDecision {
		if ( null === $link ) {
			return AccessDecision::deny( AccessDecision::REASON_NOT_FOUND );
		}

		if ( $link->is_revoked() ) {
			return AccessDecision::deny( AccessDecision::REASON_REVOKED );
		}

		if ( $link->is_expired( $now ) ) {
			return AccessDecision::deny( AccessDecision::REASON_EXPIRED );
		}

		if ( $link->is_one_time_use() && $link->is_used() ) {
			return AccessDecision::deny( AccessDecision::REASON_USED );
		}

		return AccessDecision::allow();
	}
}
