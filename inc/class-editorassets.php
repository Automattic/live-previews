<?php

namespace Automattic\LivePreviews;

/**
 * Enqueues the block-editor script that adds the "Generate preview link" panel.
 *
 * The script is built by @wordpress/scripts into build/. If it has not been
 * built yet, enqueuing is skipped rather than fatal, so the plugin still loads
 * cleanly in an unbuilt checkout.
 */
final class EditorAssets {
	private const HANDLE = 'live-previews-editor';

	public function register(): void {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		$base       = plugin_dir_path( VIP_LIVE_PREVIEWS_FILE );
		$asset_file = $base . 'build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		/** @var mixed $asset */
		$asset = require $asset_file;
		if ( ! is_array( $asset ) ) {
			return;
		}

		/** @var list<string> $dependencies */
		$dependencies = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] )
			? $asset['dependencies']
			: [];
		$version      = isset( $asset['version'] ) && is_string( $asset['version'] )
			? $asset['version']
			: VIP_LIVE_PREVIEWS_VERSION;

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'build/index.js', VIP_LIVE_PREVIEWS_FILE ),
			$dependencies,
			$version,
			true
		);

		wp_set_script_translations( self::HANDLE, 'live-previews' );

		// Hand the editor the same expiration options the endpoint validates.
		$data = wp_json_encode(
			[
				'expirationOptions' => PreviewRestController::expiration_options(),
				'defaultExpiration' => 8 * HOUR_IN_SECONDS,
			]
		);

		if ( false !== $data ) {
			wp_add_inline_script( self::HANDLE, 'window.livePreviews = ' . $data . ';', 'before' );
		}
	}
}
