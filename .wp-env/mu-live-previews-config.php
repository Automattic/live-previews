<?php
/**
 * Local wp-env only.
 *
 * On the VIP platform the runtime configuration constant is injected before the
 * plugin loads. wp-env has no VIP dashboard, so this mu-plugin mirrors that
 * injection, letting the plugin report "ready" while you review progress
 * locally. It is never shipped or activated in production.
 *
 * Nothing in it is required: defining the constant is the signal that matters,
 * and every value it can carry falls back to a built-in default.
 *
 * @package live-previews-wp-env
 */

if ( ! defined( 'VIP_LIVE_PREVIEWS_CONFIG' ) ) {
	define(
		'VIP_LIVE_PREVIEWS_CONFIG',
		[
			// 7 days, in seconds — not the 21-day default, so it is obvious when
			// the injected value is the one in use.
			'dead_link_grace_period' => 604800,
		]
	);
}
