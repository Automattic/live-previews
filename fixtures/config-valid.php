<?php
/**
 * Fully configured example of the VIP_LIVE_PREVIEWS_CONFIG runtime config
 * constant: every value the Integration Center offers, filled in.
 *
 * The retention period is deliberately not the built-in 21-day default, so it
 * is obvious when the platform value is the one being used.
 *
 * Mock values only — never put real credentials in fixtures.
 */

return [
	// 7 days, in seconds.
	'dead_link_grace_period' => 604800,
];
