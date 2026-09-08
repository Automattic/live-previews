<?php

namespace Automattic\LivePreviews\Cli;

use Automattic\LivePreviews\LinkToggle;
use WP_CLI;

/**
 * The `wp live-previews enable` command.
 *
 * The other half of the site-wide switch {@see DisableCommand} flips, through
 * the shared {@see LinkToggle}. Re-enabling lets every link work again, each
 * according to its own state — nothing needs re-minting or re-sharing.
 * Behaviour is pinned by features/toggle.feature.
 */
final class EnableCommand {
	private LinkToggle $toggle;

	public function __construct( LinkToggle $toggle ) {
		$this->toggle = $toggle;
	}

	/**
	 * Let preview links work again after being disabled site-wide.
	 *
	 * ## EXAMPLES
	 *
	 *     # The leak was a false alarm: let every link work again.
	 *     $ wp live-previews enable
	 *     Success: Preview links enabled.
	 *
	 * @when after_wp_load
	 */
	public function __invoke(): void {
		if ( ! $this->toggle->is_disabled() ) {
			WP_CLI::success( 'Preview links are already enabled.' );
			return;
		}

		$this->toggle->enable();

		WP_CLI::success( 'Preview links enabled.' );
	}
}
