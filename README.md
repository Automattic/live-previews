# Live Previews

Live Previews generates safe-to-share, time- and usage-limited preview links so reviewers without a WordPress account can review a draft. It hardens the existing Preview Links.

Live Previews is an ordinary WordPress plugin and works on any host, with nothing to configure. It is also packaged as a WordPress VIP integration: on VIP it reads optional settings from a VIP-provided constant, records Tracks telemetry, and is registered with the VIP Integrations Center through the [handoff manifest](/docs/manifest.md). Each of those is gated behind a platform check (`Automattic\LivePreviews\Platform::is_vip()`) or a `class_exists()` guard, so off VIP they are simply absent — no notices, no fatals, and no VIP branding on the site. See [/docs/vip-integration.md](/docs/vip-integration.md) for the operational details, and check conformance with the [`vip-integration`](https://github.com/Automattic/integration) CLI (`npx @automattic/vip-integration validate`).

The repository ships fully configured VIP local and cloud development environments along with unit tests, end-to-end tests, static analysis, and linting.

## Hosting requirements

The plugin itself needs nothing beyond WordPress 6.9 and PHP 8.2, and runs on any host. Two things about the hosting environment are worth checking.

### Preview requests must not be served from a page cache

A preview link carries its token in the query string (`?p=13&preview=true&lp-token=…`), and the gate sends `nocache_headers()`, `X-Robots-Tag: noindex`, and `Referrer-Policy: no-referrer` before rendering an unlocked draft. Any full-page cache in front of WordPress — Varnish, nginx FastCGI cache, LiteSpeed, a caching plugin, or a CDN — must respect those headers and must not serve a cached response for a URL carrying `lp-token`.

Practically every cache already bypasses on `preview=true` and on unrecognised query strings, and VIP guarantees it. If a cache is misconfigured, the visible symptom is that per-viewer limits stop counting correctly, because the gate reads and sets a per-viewer cookie. The worse and quieter failure is a cached copy of an unlocked draft being served to somebody with no token at all, so it is worth confirming rather than assuming.

### Scheduled events must run

Expired and revoked links are kept for a grace period so the gate can tell a visitor *why* their link stopped working, then removed by a daily `live_previews_prune_links` event. If scheduled events never fire, nothing breaks for visitors — expiry is checked when a link is opened, not by the sweep — but the rows accumulate indefinitely.

There is a **Preview link cleanup** check under Tools → Site Health that reports whether the sweep is scheduled and whether it has actually run recently, so a stalled sweep is visible rather than silent.

## Technology

These are the tools we use on a day-to-day basis to ensure code quality on the WordPress VIP platform.

### Unit and integration tests

We use [PHPUnit 9](https://phpunit.de/index.html) for both suites. The fast unit tests (pure PHP, no WordPress) live in [/tests/unit](tests/unit/), and the WordPress-booting integration tests live in [/tests/integration](tests/integration/).

### End-to-end tests

For end-to-end tests we use [Playwright](https://playwright.dev/). The specs live in [/tests/e2e](/tests/e2e).

### Static analysis

[Psalm](https://psalm.dev/) is a free & open-source static analysis tool that helps identify problems in the code. For it to work properly you will need to annotate the PHP code; see [/inc](/inc) for examples.

### Linting and coding standards

Linting and coding standards are powered by [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) (PHPCS) along with the WordPress VIP and WordPress core rulesets. For more information see the [linting doc](/docs/linting.md).

### GitHub Actions

CI runs on every push and pull request:

| Workflow                               | What it does                                                                                                |
| -------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| `unit-tests.yml`                       | Fast PHPUnit unit suite (pure PHP, no WordPress) across the PHP baseline (8.2–8.5).                         |
| `integration-tests.yml`                | PHPUnit integration suite across the VIP platform baseline (PHP 8.2–8.5 × WordPress 6.9.x/latest, single site and multisite). |
| `e2e.yml`                              | Playwright end-to-end tests against a real `vip dev-env` (WordPress 6.9 and 7.0).                           |
| `lint.yml`                             | PHPCS with the WordPress VIP rulesets.                                                                      |
| `static-code-analysis.yml`             | Psalm static analysis.                                                                                      |
| `codeql.yml` / `dependency-review.yml` | Security scanning of code and dependency changes.                                                           |

## Repository structure

⚠️ The repository contains several folders that together constitute a complete WordPress VIP application; they should not be removed. A brief description of each is available in [/docs/directories.md](/docs/directories.md).

For more on how our codebase is structured, see https://docs.wpvip.com/technical-references/vip-codebase/.

## Local installation and development

You will need the following tools installed: [Composer](https://getcomposer.org/), [Node.js](https://nodejs.org/en) (which includes NPM), [Docker](https://www.docker.com/), and the [VIP-CLI](https://docs.wpvip.com/vip-cli/).

📝 While we usually recommend Docker Desktop, we understand it may not be possible for every organization. This project is compatible with alternative container runtimes like Colima and Rancher Desktop. For details see [our documentation](https://docs.wpvip.com/vip-local-development-environment/requirements/#Alternatives-to-Docker-Desktop).

Once the prerequisites are installed:

1. Clone the repository and change into its directory.
2. Install Composer dependencies:

```sh
composer install
```

3. Install Node.js dependencies:

```sh
npm i
```

4. Create and start a WPVIP local development instance:

```sh
vip dev-env create
vip dev-env start
```

5. Write code, write tests. Or the other way around! `composer test` runs both suites (the e2e half needs the dev-env from the previous step running — see [/docs/vip-integration.md](/docs/vip-integration.md)).

📝 For convenience, this repository contains a [vip-dev-env.yml](/.wpvip/vip-dev-env.yml) configuration file; tweak it to your needs. For a more in-depth guide to VIP local development environments, see [our documentation site](https://docs.wpvip.com/vip-local-development-environment/create/).

## Cloud-based development

We support GitHub Codespaces. There are no set-up steps: on the first start the codespace takes a few minutes to build, after which you have a working environment. You can use either the web-based editor or local VS Code.
