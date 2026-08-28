# VIP Integration

Live Previews is a WordPress VIP integration. It follows the patterns WordPress
VIP requires of integrations: a single runtime config constant read through a
central `Config` class, graceful degradation when required config is missing, and
Tracks-only telemetry through the VIP Telemetry API.

## Running and testing locally

1. `composer install && npm ci`
2. `vip dev-env create && vip dev-env start`
3. `composer test`

> **Prerequisite:** `composer test` runs the PHPUnit unit **and** integration
> suites plus the Playwright e2e suite. The e2e half needs the `vip dev-env`
> from step 2 up and reachable — without it, `composer test` fails on the e2e
> stage. That is an environment gap, not a broken plugin; run `composer
> test:unit` (pure PHP, no dev-env needed) or `composer test:integration` for
> the WordPress-backed suite.

The integration suite also needs the WordPress test library (`WP_TESTS_DIR`);
inside the dev-env container (`vip dev-env shell`) it is preconfigured. The unit
suite needs neither WordPress nor a database.

## Build, Test, And Validate Commands

| Purpose                   | Command                                                                                                        |
| ------------------------- | -------------------------------------------------------------------------------------------------------------- |
| Install PHP dependencies  | `composer install`                                                                                             |
| Install Node dependencies | `npm ci`                                                                                                       |
| Build editor assets       | `npm run build` (compiles the block-editor script from `src/` into `build/`)                                   |
| Tests                     | `composer test`                                                                                                |
| Integration validation    | `npx @automattic/vip-integration validate` (the external conformance checker — see [manifest.md](manifest.md)) |

## Runtime Config

Config constant: `VIP_LIVE_PREVIEWS_CONFIG`

The VIP platform defines the constant (a plain PHP associative array) before
the plugin loads. All reads go through `Automattic\LivePreviews\Config`.

Required values:

- `api_base_url`: Base URL for the vendor API.
- `api_token`: Token used to authenticate vendor API requests.

Optional values:

- `signature_label`: Text rendered in the site footer signature.

Example valid local mock config:

```php
define( 'VIP_LIVE_PREVIEWS_CONFIG', [
	'api_base_url'    => 'https://api.vendor.example',
	'api_token'       => 'mock-token',
	'signature_label' => 'Live Previews (dev)',
] );
```

Example incomplete config (setup in progress — a required value is missing):

```php
define( 'VIP_LIVE_PREVIEWS_CONFIG', [
	'api_base_url' => 'https://api.vendor.example',
] );
```

With incomplete config the plugin **must not fatal**: it disables its REST API
endpoints and shows an admin notice naming the missing fields. See
[`fixtures/`](../fixtures/README.md) for all mocked states and where they are
wired in.

## Telemetry

Telemetry uses the helper in `inc/class-telemetry.php`, which wraps the VIP
Telemetry API (Tracks events only, no Stats) behind a `class_exists` guard so
environments without VIP MU plugins no-op. Event names are prefixed with
`livepreviews_` — a single word with no underscores, because the leading token is
the Tracks *source* and must be whitelisted in nosara (an underscore there would
divert events to `prod_rejects`). Never include secrets, raw content, email
addresses, or customer credentials in event properties.

| Name                            | Type   | Trigger                              | Properties                                             | Notes                                             |
| ------------------------------- | ------ | ------------------------------------ | ------------------------------------------------------ | ------------------------------------------------- |
| `livepreviews_link_created` | Tracks | A preview link is minted, via REST or the Abilities API. | `expiration`, `max_uses` (null = unlimited), `channel` (`rest` or `ability`), `plugin_version` (global) | Usage metadata only; never the token, content, or PII. |
