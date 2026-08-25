<?php

namespace Automattic\LivePreviews;

final class Plugin {
	/** @var self|null */
	private static $instance;

	// @codeCoverageIgnoreStart
	// This code is executed in bootstrap.php, before PHPUnit initializes test coverage
	public static function get_instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the plugin's hooks with WordPress.
	 *
	 * The plugin entry file calls this during load.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'init' ] );
		if ( is_admin() ) {
			add_action( 'init', [ Admin::class, 'register' ] );
		}
	}

	public function init(): void {
		// Composition root: assemble the domain graph once (no container) and
		// share it between minting (REST) and enforcement (the gate). Swapping
		// storage, clock, or policy is a one-line change here.
		$service = new PreviewLinkService(
			new PostMetaTokenRepository(),
			new AccessPolicy(),
			new SystemClock()
		);

		$rest_controller = new PreviewRestController( $service );
		add_action( 'rest_api_init', [ $rest_controller, 'register_routes' ] );

		( new PreviewGate( $service ) )->register();
		( new PublishCleanup( $service ) )->register();
		( new LinkGarbageCollector( $service ) )->register();
		( new EditorAssets() )->register();

		if ( ! Config::get_instance()->is_ready() ) {
			// An incomplete runtime config must never fatal; surface a diagnostic.
			// The preview feature itself needs no external config, so it stays on.
			add_action( 'admin_notices', [ $this, 'render_config_notice' ] );
		}

		add_action( 'wp_footer', [ $this, 'wp_footer' ] );
	}
	// @codeCoverageIgnoreEnd

	public function render_config_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$config  = Config::get_instance();
		$details = $config->is_available()
			? sprintf(
				/* translators: %s: comma-separated list of missing config fields */
				__( 'missing required fields: %s', 'live-previews' ),
				implode( ', ', $config->missing_fields() )
			)
			: sprintf(
				/* translators: %s: name of the runtime config constant */
				__( 'the %s constant is not defined', 'live-previews' ),
				Config::CONSTANT_NAME
			);

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: reason the configuration is incomplete */
					__( 'Live Previews setup is incomplete (%s). Complete the configuration in the VIP Dashboard.', 'live-previews' ),
					$details
				)
			)
		);
	}

	public function wp_footer(): void {
		$label = (string) Config::get_instance()->get( 'signature_label', 'Live Previews' );
		printf( '<p class="live-previews-signature">%s</p>', esc_html( $label ) );
	}
}
