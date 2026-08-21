<?php
/**
 * Plugin Name: Live Previews
 * Description: Reference implementation of a WordPress VIP partner integration, built from the VIP Integrations Starter Kit.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Author: Automattic
 * License: MIT
 * Text Domain: live-previews
 */

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

require_once __DIR__ . '/vendor/autoload.php';

Plugin::get_instance()->register();
