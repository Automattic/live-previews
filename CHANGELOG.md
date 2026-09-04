# Changelog

All notable changes to Live Previews are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0-RC1] - 2026-09-04

First release candidate of Live Previews: safe-to-share, time- and
usage-limited preview links that let a reviewer without a WordPress account view
a draft. Published for internal testing ahead of 1.0.0.

Requires WordPress 6.9 or later and PHP 8.2 or later. Designed for WordPress VIP
but runs on any host.

### Added

- Generate a safe-to-share preview link for a draft from the block editor, reusing WordPress's own preview flow so a logged-out reviewer sees the draft as it will publish. ([#9](https://github.com/Automattic/live-previews/pull/9), [#10](https://github.com/Automattic/live-previews/pull/10))
- Set how long each link lasts, from a configurable, filterable set of expiration options, including an optional effectively-indefinite lifetime. ([#10](https://github.com/Automattic/live-previews/pull/10), [#17](https://github.com/Automattic/live-previews/pull/17))
- Limit a link by the number of distinct viewers, including one-time and unlimited links; crawler and unfurler requests never spend a view. ([#11](https://github.com/Automattic/live-previews/pull/11))
- Manage a post's preview links from the editor — see each link's usage and time remaining, identify it by a token hint, and revoke it. ([#12](https://github.com/Automattic/live-previews/pull/12), [#17](https://github.com/Automattic/live-previews/pull/17))
- Audit and revoke every preview link on the site from a top-level Preview Links screen, with per-page screen options and contextual help. ([#34](https://github.com/Automattic/live-previews/pull/34))
- Show a friendly notice when a link has expired, been revoked, or been exhausted, while unknown links stay a plain 404 so drafts cannot be enumerated. How much of the reason is disclosed is filterable, for sites that would rather say less. ([#15](https://github.com/Automattic/live-previews/pull/15), [#31](https://github.com/Automattic/live-previews/pull/31))
- Sweep expired links automatically after a grace period, configurable from the VIP Dashboard through the optional `dead_link_grace_period` value and overridable with the `live_previews_dead_link_grace_period` filter. It defaults to 21 days whenever the value is absent or unusable, so a blank field never means "delete links the moment they expire". ([#32](https://github.com/Automattic/live-previews/pull/32))
- Create and list preview links through the Abilities API, so MCP clients, the AI Client, and the abilities REST runner mint links under the same rules as the editor. ([#28](https://github.com/Automattic/live-previews/pull/28), [#30](https://github.com/Automattic/live-previews/pull/30))
- Report whether the cleanup sweep is scheduled and actually running, as a Site Health check under Tools → Site Health.
- Ship translatable strings with a bundled POT, so the plugin can be localised without a WordPress.org language pack.

### Security

- Store only a hash of each token, enforce every link limit server-side, and keep drafts visible to link holders alone — preview requests are also marked no-index so a shared link cannot be indexed by search engines. ([#18](https://github.com/Automattic/live-previews/pull/18))

### Notes for VIP

- The plugin needs no data from the platform. Defining `VIP_LIVE_PREVIEWS_CONFIG` at all is the whole signal that the integration is enabled, so an empty array counts as a complete configuration and no admin notice is ever shown. ([#35](https://github.com/Automattic/live-previews/pull/35))
- VIP support links in contextual help appear only on VIP-hosted sites, where VIP support can answer them; elsewhere they point at the plugin's own support channel. ([#35](https://github.com/Automattic/live-previews/pull/35))

[1.0.0-RC1]: https://github.com/Automattic/live-previews/releases/tag/1.0.0-RC1
