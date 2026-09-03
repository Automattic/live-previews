<?php
/**
 * Local wp-env only.
 *
 * On the VIP platform the runtime configuration constant is injected before the
 * plugin loads. wp-env has no VIP dashboard, so this mu-plugin mirrors that
 * injection with harmless dev values, letting the plugin report "ready" while you
 * review progress locally. It is never shipped or activated in production.
 *
 * @package live-previews-wp-env
 */

if ( ! defined( 'VIP_LIVE_PREVIEWS_CONFIG' ) ) {
	define(
		'VIP_LIVE_PREVIEWS_CONFIG',
		[
			'api_base_url' => 'https://api.vendor.example',
			'api_token'    => 'local-dev-token',
		]
	);
}
