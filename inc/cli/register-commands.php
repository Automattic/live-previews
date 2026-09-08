<?php

namespace Automattic\LivePreviews\Cli;

use Automattic\LivePreviews\LinkGarbageCollector;
use Automattic\LivePreviews\LinkToggle;
use Automattic\LivePreviews\PreviewLinkMinter;
use Automattic\LivePreviews\PreviewLinkService;
use WP_CLI;

// Required explicitly rather than autoloaded: the plugin's first-party
// autoloader deliberately maps only the flat inc/ directory, and these classes
// are only ever needed on a WP-CLI invocation, which passes through here.
require_once __DIR__ . '/class-commandnamespace.php';
require_once __DIR__ . '/class-createcommand.php';
require_once __DIR__ . '/class-listcommand.php';
require_once __DIR__ . '/class-prunecommand.php';
require_once __DIR__ . '/class-revokecommand.php';

/**
 * Register every `wp live-previews` command.
 *
 * Called from the composition root with the same service graph that backs the
 * REST endpoint, the abilities, and the admin table, so a link minted or
 * revoked from the shell obeys exactly the same rules. Instances (not class
 * names) are handed to WP-CLI because the commands take their collaborators
 * through the constructor, which WP-CLI could not build itself.
 */
function register_commands( PreviewLinkService $service, PreviewLinkMinter $minter, LinkGarbageCollector $collector, LinkToggle $toggle ): void {
	// Declares the namespace the subcommands sit in, so `wp live-previews`
	// itself has a description.
	WP_CLI::add_command( 'live-previews', CommandNamespace::class );

	WP_CLI::add_command( 'live-previews create', new CreateCommand( $minter, $toggle ) );
	WP_CLI::add_command( 'live-previews list', new ListCommand( $service, $toggle ) );
	WP_CLI::add_command( 'live-previews prune', new PruneCommand( $collector ) );
	WP_CLI::add_command( 'live-previews revoke', new RevokeCommand( $service ) );
}
