<?php

namespace Automattic\LivePreviews;

/**
 * Composition root: assembles the object graph and registers its hooks.
 *
 * The one config value read here is the optional `ip_allowlist` key: central
 * CIDR ranges set once in the VIP Dashboard that apply to every preview link,
 * unioned with each link's own ranges. It is read defensively — absent, empty,
 * or malformed all mean "no central ranges" — so the plugin never depends on
 * the Dashboard side existing.
 */
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
		// Translations ship inside the plugin rather than coming from
		// translate.wordpress.org, so the plugin's own languages/ directory has
		// to be registered explicitly. PHP strings resolve from here; the
		// editor script's JSON catalogues are pointed at the same directory in
		// EditorAssets.
		load_plugin_textdomain(
			'live-previews',
			false,
			dirname( plugin_basename( VIP_LIVE_PREVIEWS_FILE ) ) . '/languages'
		);

		// Central IP ranges from the VIP Dashboard, if any. Untrusted customer
		// input: anything unusable is dropped rather than half-applied.
		$central_ip_ranges = IpAllowlist::sanitize( Config::get_instance()->get( 'ip_allowlist' ) );

		// Composition root: assemble the domain graph once (no container) and
		// share it between minting (REST) and enforcement (the gate). Swapping
		// storage, clock, or policy is a one-line change here.
		$clock   = new SystemClock();
		$service = new PreviewLinkService(
			new PostMetaTokenRepository(),
			new AccessPolicy( $central_ip_ranges ),
			$clock
		);
		$minter  = new PreviewLinkMinter( $service );

		$rest_controller = new PreviewRestController( $service, $minter );
		add_action( 'rest_api_init', [ $rest_controller, 'register_routes' ] );

		( new PreviewGate( $service ) )->register();
		( new PublishCleanup( $service ) )->register();
		( new LinkGarbageCollector( $service ) )->register();
		( new EditorAssets( [] !== $central_ip_ranges ) )->register();

		// Bulk revocation: the break-glass sweep, offboarding on user deletion,
		// and the customer-facing revoke-user-links action.
		$revoker = new BulkLinkRevoker( $service );
		$revoker->register();

		// Site-wide audit + revoke table for editors.
		( new PreviewLinksAdminPage( $service, $clock, $revoker, $central_ip_ranges ) )->register();

		// Expose link creation to MCP, the AI Client, and the abilities REST
		// runner. Shares the same minter as the REST endpoint above.
		( new PreviewAbilities( $service, $minter ) )->register();

		// Surfaces whether the cleanup sweep is actually running, which is the
		// one part of the plugin that depends on cron firing.
		( new SiteHealth( $clock ) )->register();
	}
	// @codeCoverageIgnoreEnd
}
