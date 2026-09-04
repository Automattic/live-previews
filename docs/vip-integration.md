# VIP Integration

Live Previews is a WordPress VIP integration. It follows the patterns WordPress
VIP requires of integrations: a single runtime config constant read through a
central `Config` class, graceful degradation when that config is missing or
unusable, and Tracks-only telemetry through the VIP Telemetry API. It is also an ordinary
WordPress plugin that runs on any host: everything VIP-specific is gated, so off
platform it is simply absent.

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

The VIP platform defines the constant (a plain PHP associative array) before the
plugin loads. All reads go through `Automattic\LivePreviews\Config`.

**Nothing in the constant is required.** Defining it *at all* is the signal that
matters: it is how the platform says this integration is enabled for the site.
Preview links work with nothing in it, so an empty array is a complete, valid
configuration.

Optional values:

- `dead_link_grace_period`: how long an expired or revoked link is kept, in
  seconds, so a reviewer returning to a stale link is told why it stopped
  working rather than seeing a "not found" page. Defaults to 21 days when it is
  absent or unusable, and the `live_previews_dead_link_grace_period` filter
  still overrides whatever the platform sends.

Example valid config:

```php
define( 'VIP_LIVE_PREVIEWS_CONFIG', [
	'dead_link_grace_period' => 604800, // 7 days.
] );
```

Example incomplete config (setup in progress — the customer has opened the field
but not filled it in, so it arrives blank):

```php
define( 'VIP_LIVE_PREVIEWS_CONFIG', [
	'dead_link_grace_period' => '',
] );
```

A blank or nonsensical value **must not be taken at face value**: the retention
period falls back to its 21-day default rather than deleting links the moment
they expire. A constant that is absent entirely, or holds something that is not
an array at all, **must not fatal**. `Config` reports it through
`is_available()` / `is_ready()` and the plugin carries on; the preview feature
needs nothing from the platform, so it keeps working regardless. Nothing warns
about it, because on VIP the state is unreachable: enabling the integration in
the Dashboard is what both loads the plugin and defines the constant, so a
running plugin has a config by definition. See
[`fixtures/`](../fixtures/README.md) for the mocked states and where they are
wired in.

`Config::REQUIRED_FIELDS` is empty, and the validation around it stays ready for
the first value the plugin genuinely *cannot* work without. Adding one means
declaring it in both `Config::REQUIRED_FIELDS` and the `runtime_config` section
of `vip-manifest.yaml`, the latter being what puts the field in front of the
customer in the VIP Dashboard. Never declare a field nothing reads: a field in
the manifest becomes a box in front of a customer, and if no code reads it, that
box is a lie.

## Running off VIP

Live Previews is an ordinary WordPress plugin and works on any host, so anything
that only makes sense on VIP is gated behind
`Automattic\LivePreviews\Platform::is_vip()` — which looks for
`VIP_GO_APP_ENVIRONMENT` or `WPCOM_IS_VIP_ENV`, and is filterable through
`live_previews_is_vip_platform`. Today that decides one thing: whether the
contextual help on the Preview Links screen points at VIP documentation and
support, or at the plugin's own support forum. Platform *APIs* are gated
separately, by `class_exists()` / `function_exists()`, so an environment without
VIP MU plugins simply no-ops.

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
