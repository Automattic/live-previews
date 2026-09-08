<?php

declare(strict_types = 1);

namespace Automattic\LivePreviews\Tests\Behat;

use Automattic\BehatWpEnv\WpEnvFeatureContext;
use RuntimeException;

/**
 * Behat context for the `wp live-previews` feature tests.
 *
 * Extends the shared wp-env context, which provides the run/try/STDOUT/STDERR
 * steps and executes every command inside the wp-env tests-cli container.
 */
final class FeatureContext extends WpEnvFeatureContext {
	/**
	 * Whether the plugin has been activated during this run. Activation
	 * survives the between-scenario resets (they clear posts, users and
	 * caches, not active plugins), so it is done once rather than per scenario.
	 *
	 * @var bool
	 */
	private static $plugin_activated = false;

	/**
	 * The plugin's directory name inside the container.
	 *
	 * wp-env mounts the checkout under its directory basename, which is
	 * `live-previews` in CI but can differ locally (e.g. in a git worktree),
	 * so it is derived rather than hard-coded.
	 */
	protected function get_plugin_slug(): string {
		return basename( dirname( __DIR__, 2 ) );
	}

	/**
	 * Set up a clean WordPress installation with Live Previews activated.
	 *
	 * @Given a WP installation with the Live Previews plugin
	 */
	public function given_a_wp_installation_with_plugin(): void {
		$this->given_a_wp_installation();

		if ( self::$plugin_activated ) {
			return;
		}

		$this->run_wp_cli_command( 'plugin activate ' . $this->get_plugin_slug(), false );

		if ( 0 !== $this->exit_code ) {
			throw new RuntimeException( 'Failed to activate plugin: ' . $this->output );
		}

		self::$plugin_activated = true;
	}
}
