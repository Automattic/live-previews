<?php
/**
 * Plugin Name: Live Previews
 * Plugin URI: https://wpvip.com
 * Description: Generate safe-to-share, time- and usage-limited preview links so reviewers without a WordPress account can review a draft. Hardens the existing Preview Links.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Author: Automattic
 * Author URI: https://wpvip.com
 * License: MIT
 * Text Domain: live-previews
 */

use Automattic\LivePreviews\LinkGarbageCollector;
use Automattic\LivePreviews\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'VIP_LIVE_PREVIEWS_LOADED' ) ) {
	return;
}

define( 'VIP_LIVE_PREVIEWS_LOADED', true );
define( 'VIP_LIVE_PREVIEWS_VERSION', '1.0.0' );
define( 'VIP_LIVE_PREVIEWS_FILE', __FILE__ );

require_once __DIR__ . '/inc/autoload.php';

register_deactivation_hook( __FILE__, [ LinkGarbageCollector::class, 'unschedule' ] );

Plugin::get_instance()->register();
