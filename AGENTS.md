# AGENTS.md

Context for AI coding agents working in this repo. This is a fast index into the
human docs — not a second source of truth. Read this first, then the doc that
matches your task.

## What this repo is

**Live Previews**: a WordPress VIP integration that generates safe-to-share, time-
and usage-limited preview links so reviewers without a WordPress account can
review a draft. It runs as a WordPress plugin, is registered with the VIP
Integration Center through `vip-manifest.yaml`, and reads its settings from a
single VIP-provided config constant.

Write runtime code under `inc/`, keep `vip-manifest.yaml` in sync with it, and
validate with `vip-integration validate` before shipping.

## Map

| Path                                              | What lives here                                                                                            |
| ------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `live-previews.php`                         | Plugin entry file: header, guards, constants, autoloader.                                                  |
| `inc/`                                            | Runtime code (autoloaded via `inc/autoload.php`). `class-config.php`, `class-telemetry.php`, REST handlers. |
| `src/`                                            | Block-editor JavaScript, compiled into `build/` by `npm run build`.                                        |
| `fixtures/`                                       | Mock runtime configs for local dev and tests — see `fixtures/README.md`.                                   |
| `tests/unit/`, `tests/integration/`, `tests/e2e/` | PHPUnit unit (pure PHP) and integration (boot WordPress) tests, plus Playwright e2e.                       |
| `vip-manifest.yaml`                               | The handoff manifest VIP registers the integration from.                                                   |
| `vip-manifest.schema.json`                        | JSON Schema the manifest is validated against.                                                             |
| `docs/`                                           | Human docs. Start with `docs/vip-integration.md`.                                                          |
| `.wpvip/`, `.devcontainer/`, `.github/workflows/` | VIP dev-env, Codespaces, and CI.                                                                           |

## Non-negotiables

- **Do not delete the app folders.** Every top-level directory is part of a
  complete VIP application (`docs/directories.md`). Removing them breaks the plugin.
- **One config constant.** All runtime config comes from a single VIP-defined
  constant read through `inc/class-config.php`. Never read `$_ENV`, hardcode
  secrets, or add a second config source.
- **Degrade, never fatal.** Missing or invalid config must disable features and
  surface an admin notice — never a fatal. There are fixtures for this.
- **Tracks-only telemetry.** Telemetry goes through `inc/class-telemetry.php`
  (VIP Tracks API, `class_exists`-guarded). No Stats/Pixel. Never put secrets,
  raw content, emails, or credentials in event properties.
- **Match the prefix set.** Slug (`live-previews`), namespace
  (`Automattic\LivePreviews`), and constant (`VIP_LIVE_PREVIEWS_*`) are one
  consistent set. Keep them in sync if you rename anything. The telemetry prefix
  is the deliberate exception: `livepreviews_` (one word, no underscore), because
  the leading token is the Tracks *source* and must be whitelisted in nosara — do
  not "normalise" it to `live_previews_`.

## Conventions

Follow the code that is already here. When in doubt, copy the nearest existing
pattern rather than introducing a new one.

### PHP

- **Baseline:** PHP 8.2+, WordPress 6.9 / 7.0. Do not use syntax newer than 8.2.
- **Namespaces:** everything under the integration root namespace (example:
  `Automattic\LivePreviews`). One class per file, filenames
  `class-<name>.php` / `interface-<name>.php`, autoloaded at runtime by the
  first-party `inc/autoload.php` (no Composer autoloader is shipped, since the
  plugin has no runtime dependencies). Composer's classmap still autoloads `inc/`
  for local development and the test suites.
- **Coding standards:** WordPress VIP rules via PHPCS (`composer phpcs`). Escape
  output, sanitize input, use `wpdb->prepare`, add capability checks on admin
  and REST surfaces. `composer phpcbf` auto-fixes what it can.
- **Static analysis:** Psalm must stay green (`composer psalm`). Annotate types;
  the existing classes show the expected docblock style.
- **No new runtime dependencies** without a strong reason — the plugin ships with
  what VIP already provides.

### Config

Read config only through `inc/class-config.php`. It reads the single
VIP-provided constant, validates it, and exposes `is_ready()` /
`missing_fields()` style accessors. New settings are added by:

1. declaring the field in `vip-manifest.yaml` (and its schema), and
2. reading it through `Config` — never touching the constant directly elsewhere.

Whatever you add to `Config`, keep the graceful-degradation contract: with
missing or invalid config the plugin disables the affected behaviour and shows an
admin notice; it never fatals. Fixtures in `fixtures/` cover the valid,
incomplete, and invalid states — wire new cases in there.

### Running off VIP

The plugin has to work as an ordinary WordPress plugin on any host, not just on
VIP, so anything platform-specific is gated:

- **Platform-only surfaces** (the config notice, VIP support links) go behind
  `Automattic\LivePreviews\Platform::is_vip()`. Off VIP the config constant is
  *expected* to be absent, so warning about it there is noise, not a diagnostic.
- **Platform-only APIs** (VIP Telemetry, the Abilities API) go behind
  `class_exists()` / `function_exists()` and no-op when absent.
- **Nothing renders on the front end, and nothing nags site-wide.** No footer
  signature, no branding, and no `admin_notices` outside the plugin's own screen
  (`PreviewLinksAdminPage::SCREEN_ID`).
- Do not assume anything the platform guarantees but a standalone host does not
  — object caching, cron actually firing, or preview requests bypassing a page
  cache. Where the plugin depends on one, make the dependency observable
  (see `inc/class-sitehealth.php`) or document it in the README.

### Telemetry

Record events through `inc/class-telemetry.php` only. It wraps the VIP Tracks
API behind a `class_exists` guard so non-VIP environments no-op. Event names are
prefixed with the integration's snake_case name. Properties carry usage metadata
only — never secrets, request payloads, emails, or credentials. Declare every
event in the `telemetry` section of `vip-manifest.yaml`.

### Tests

- Unit tests (pure PHP, no WordPress) in `tests/unit/`, integration tests (boot
  WordPress) in `tests/integration/`, e2e in `tests/e2e/` (Playwright). Add tests
  with the code that needs them; mirror the structure of the existing suites.
- The graceful-degradation behavior above is behavioral — prove it with a test,
  not just a static guard.

### Manifest

`vip-manifest.yaml` must stay in sync with the code: the constant name, the
plugin folder/entry file/namespace, every config `key` the plugin reads, and the
telemetry events it records. The schema (`vip-manifest.schema.json`) rejects
unknown keys, so a typo fails validation — run `vip-integration validate` after
editing it. See `docs/manifest.md` for the field-by-field guide.

## Commands

Details live in `docs/vip-integration.md`.

### Set up

```sh
composer install        # PHP dependencies
npm ci                  # Node dependencies (Playwright)
```

### Local environment

The e2e suite needs a running VIP dev-env:

```sh
vip dev-env create
vip dev-env start
```

### Test, lint, analyze

```sh
composer test            # unit + integration + Playwright e2e (e2e needs the dev-env above)
composer test:unit       # fast unit suite only (pure PHP, no WordPress)
composer test:integration # integration suite only (boots WordPress)
composer test:e2e        # Playwright only
composer phpcs          # WordPress VIP coding standards
composer phpcbf         # auto-fix what PHPCS can
composer psalm          # static analysis
```

### Validate the integration

Conformance is checked by the external `vip-integration` CLI (not a composer
script in this repo). Run it from the repo root:

```sh
npx @automattic/vip-integration validate            # human report
npx @automattic/vip-integration validate --format json   # for CI
```

It exits non-zero when the integration is not conformant, so it can gate CI.
Among other rules it validates `vip-manifest.yaml` against
`vip-manifest.schema.json` — see `docs/manifest.md`.
