<?php

namespace Automattic\LivePreviews\Cli;

use WP_CLI\Dispatcher\CommandNamespace as WpCliCommandNamespace;

/**
 * Create and manage pre-publish preview links.
 *
 * Each subcommand registers itself, which leaves WP-CLI to invent the
 * `live-previews` namespace containing them — and an invented namespace has no
 * description. Declaring it here gives `wp live-previews` and `wp help
 * live-previews` a summary of their own.
 */
final class CommandNamespace extends WpCliCommandNamespace {
}
