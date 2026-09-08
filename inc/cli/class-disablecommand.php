<?php

namespace Automattic\LivePreviews\Cli;

use Automattic\LivePreviews\LinkToggle;
use WP_CLI;

/**
 * The `wp live-previews disable` command.
 *
 * Flips the same site-wide switch as the admin page's toggle slider, through
 * the shared {@see LinkToggle}. Disabling is a pause, not a revocation: every
 * link keeps its own state and simply stops working until re-enabled, which
 * makes it the first response to a suspected leak. Behaviour is pinned by
 * features/toggle.feature.
 */
final class DisableCommand {
	private LinkToggle $toggle;

	public function __construct( LinkToggle $toggle ) {
		$this->toggle = $toggle;
	}

	/**
	 * Stop every preview link working until links are re-enabled.
	 *
	 * A reversible pause: no link is revoked or deleted, and `wp live-previews enable` restores each one exactly as it was. Who flipped the switch and when is recorded, for incident response.
	 *
	 * ## EXAMPLES
	 *
	 *     # A preview URL may have leaked: pause everything while investigating.
	 *     $ wp live-previews disable
	 *     Success: Preview links disabled.
	 *
	 * @when after_wp_load
	 */
	public function __invoke(): void {
		if ( $this->toggle->is_disabled() ) {
			WP_CLI::success( 'Preview links are already disabled.' );
			return;
		}

		$this->toggle->disable();

		WP_CLI::success( 'Preview links disabled.' );
	}
}
