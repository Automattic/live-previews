<?php

defined( 'ABSPATH' ) || die();

if ( ! defined( 'WP_TESTS_DOMAIN' ) && function_exists( 'wpcom_vip_load_plugin' ) ) {
	if ( ! defined( 'VIP_LIVE_PREVIEWS_CONFIG' ) ) {
		// Mirror the VIP platform: runtime config is defined before the plugin loads.
		// A git-ignored fixtures/config-local.php overrides the committed fixture —
		// handy for local secrets and experiments (see fixtures/README.md).
		$vip_live_previews_fixtures = WP_CONTENT_DIR . '/plugins/live-previews/fixtures';
		define(
			'VIP_LIVE_PREVIEWS_CONFIG',
			file_exists( $vip_live_previews_fixtures . '/config-local.php' )
				? require $vip_live_previews_fixtures . '/config-local.php'
				: require $vip_live_previews_fixtures . '/config-valid.php'
		);
		unset( $vip_live_previews_fixtures );
	}

	wpcom_vip_load_plugin( 'live-previews/live-previews.php' );
}
