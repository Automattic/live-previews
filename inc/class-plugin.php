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
	}

	public function init(): void {
		// Composition root: assemble the domain graph once (no container) and
		// share it between minting (REST) and enforcement (the gate). Swapping
		// storage, clock, or policy is a one-line change here.
		$clock   = new SystemClock();
		$service = new PreviewLinkService(
			new PostMetaTokenRepository(),
			new AccessPolicy(),
			$clock
		);
		$minter  = new PreviewLinkMinter( $service );

		$rest_controller = new PreviewRestController( $service, $minter );
		add_action( 'rest_api_init', [ $rest_controller, 'register_routes' ] );

		( new PreviewGate( $service ) )->register();
		( new PublishCleanup( $service ) )->register();
		( new LinkGarbageCollector( $service ) )->register();
		( new EditorAssets() )->register();

		// Site-wide audit + revoke table for editors.
		( new PreviewLinksAdminPage( $service, $clock ) )->register();

		// Expose link creation to MCP, the AI Client, and the abilities REST
		// runner. Shares the same minter as the REST endpoint above.
		( new PreviewAbilities( $service, $minter ) )->register();

		// Surfaces whether the cleanup sweep is actually running, which is the
		// one part of the plugin that depends on cron firing.
		( new SiteHealth( $clock ) )->register();

		if ( $this->should_warn_about_config() ) {
			// An incomplete runtime config must never fatal; surface a diagnostic.
			// The preview feature itself needs no external config, so it stays on.
			add_action( 'admin_notices', [ $this, 'render_config_notice' ] );
		}
	}
	// @codeCoverageIgnoreEnd

	/**
	 * Whether to warn about the runtime configuration.
	 *
	 * Only on VIP. The config constant is injected by the VIP Dashboard, so off
	 * platform it is *expected* to be absent — there is no dashboard to go and
	 * complete, and previews work without it. Warning everywhere would mean every
	 * standalone site carrying a permanent notice about a setting it cannot set
	 * and does not need.
	 */
	private function should_warn_about_config(): bool {
		return Platform::is_vip() && ! Config::get_instance()->is_ready();
	}

	public function render_config_notice(): void {
		$screen = get_current_screen();

		// Only on our own screen. This is our housekeeping, not something to
		// interrupt someone editing a post or updating plugins with.
		if ( ! $screen instanceof \WP_Screen || PreviewLinksAdminPage::SCREEN_ID !== $screen->id ) {
			return;
		}

		if ( ! $this->should_warn_about_config() || ! current_user_can( 'manage_options' ) ) {
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
}
