<?php
/**
 * Local wp-env only.
 *
 * On the VIP platform the runtime configuration constant is injected before the
 * plugin loads. wp-env has no VIP dashboard, so this mu-plugin mirrors that
 * injection, letting the plugin report "ready" while you review progress
 * locally. It is never shipped or activated in production.
 *
 * The constant carries no data: defining it is the whole signal. Add keys here
 * to mirror a future platform-provided value.
 *
 * @package live-previews-wp-env
 */

if ( ! defined( 'VIP_LIVE_PREVIEWS_CONFIG' ) ) {
	define( 'VIP_LIVE_PREVIEWS_CONFIG', [] );
}
