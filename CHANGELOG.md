# Changelog

All notable changes to Live Previews are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

First release of Live Previews: safe-to-share, time- and usage-limited preview
links that let a reviewer without a WordPress account view a draft.

Requires WordPress 6.9 or later and PHP 8.2 or later. Designed for WordPress VIP
but runs on any host.

### Added

- Generate a safe-to-share preview link for a draft from the block editor, reusing WordPress's own preview flow so a logged-out reviewer sees the draft as it will publish. ([#9](https://github.com/Automattic/live-previews/pull/9), [#10](https://github.com/Automattic/live-previews/pull/10))
- Set how long each link lasts, from a configurable, filterable set of expiration options, including an optional effectively-indefinite lifetime. The editor pre-selects 8 hours, changeable with the `live_previews_default_expiration` filter. ([#10](https://github.com/Automattic/live-previews/pull/10), [#17](https://github.com/Automattic/live-previews/pull/17))
- Limit a link by the number of distinct viewers, including one-time and unlimited links; crawler and unfurler requests never spend a view. ([#11](https://github.com/Automattic/live-previews/pull/11))
- Manage a post's preview links from the editor — see each link's usage and time remaining, identify it by a token hint, and revoke it. ([#12](https://github.com/Automattic/live-previews/pull/12), [#17](https://github.com/Automattic/live-previews/pull/17))
- Audit and revoke every preview link on the site from a top-level Preview Links screen, with per-page screen options and contextual help. ([#34](https://github.com/Automattic/live-previews/pull/34))
- Revoke preview links in bulk: filter the Preview Links screen to one creator and revoke everything they made, or — as an administrator — revoke every link on the site in one guarded action. Links a user created are revoked automatically when their account is deleted, other offboarding flows can trigger the same sweep through the `live_previews_revoke_user_links` action, and `live_previews_revoked_user_links` fires afterwards for audit logging. Sweeps run in bounded batches and finish in the background on large sites. ([#46](https://github.com/Automattic/live-previews/pull/46))
- Temporarily disable all preview links with a reversible, administrator-only switch on the Preview Links screen — the first response to a suspected leak. Nothing is revoked: links keep their own expiry and usage and resume working when re-enabled. While disabled, the admin screen banners who disabled links and when, the editor's Generate and Manage modals warn that links will not work, and visitors see a "temporarily disabled" notice. ([#46](https://github.com/Automattic/live-previews/pull/46))
- Show a friendly notice when a link has expired, been revoked, or been exhausted, while unknown links stay a plain 404 so drafts cannot be enumerated. How much of the reason is disclosed is filterable, for sites that would rather say less. ([#15](https://github.com/Automattic/live-previews/pull/15), [#31](https://github.com/Automattic/live-previews/pull/31))
- Sweep expired and revoked links automatically after a retention period, so a reviewer returning to a stale link is told why it stopped working rather than seeing a 404. The period is set from the VIP Dashboard through the optional `dead_link_grace_period` value and overridden by the `live_previews_dead_link_grace_period` filter, falling back to 21 days whenever the value is absent or unusable — a blank field never means "delete links the moment they expire". ([#32](https://github.com/Automattic/live-previews/pull/32))
- Create and list preview links through the Abilities API, so MCP clients, the AI Client, and the abilities REST runner mint links under the same rules as the editor. ([#28](https://github.com/Automattic/live-previews/pull/28), [#30](https://github.com/Automattic/live-previews/pull/30))
- Manage preview links from the shell with WP-CLI: `wp live-previews create`, `list`, `revoke`, and `prune` share the same rules and telemetry as every other surface, so developers, scripts, and terminal-based agents can mint, audit, and kill links without wp-admin — including `revoke --all` when a URL leaks and `prune --grace=0` for immediate cleanup. Each command is pinned by a Behat feature test against a real WordPress. ([#49](https://github.com/Automattic/live-previews/pull/49))
- Restrict where a preview link can be opened from with an optional IP allowlist: per-link CIDR ranges (IPv4 and IPv6) set when generating the link, unioned with an optional central baseline set once in the VIP Dashboard through the `ip_allowlist` value. A link must pass both the token checks and the IP check; with no ranges anywhere, behaviour is unchanged. Visitors outside the allowlist see a plain 404, learning nothing about the draft. Per-link ranges are shown in the editor's Manage modal and on the Preview Links screen. ([#45](https://github.com/Automattic/live-previews/pull/45))
- Report whether the cleanup sweep is scheduled and actually running, as a Site Health check under Tools → Site Health.
- Ship translatable strings with a bundled POT, so the plugin can be localised without a WordPress.org language pack.

### Security

- Store only a hash of each token, enforce every link limit server-side, and keep drafts visible to link holders alone — preview requests are also marked no-index so a shared link cannot be indexed by search engines. ([#18](https://github.com/Automattic/live-previews/pull/18))

### Notes for VIP

- Every value in `VIP_LIVE_PREVIEWS_CONFIG` is optional. Defining the constant is what enables the integration; the plugin reads only `dead_link_grace_period` and `ip_allowlist`, and it runs on its built-in defaults without them. ([#35](https://github.com/Automattic/live-previews/pull/35))
- VIP support links in contextual help appear only on VIP-hosted sites, where VIP support can answer them; elsewhere they point at the plugin's own support channel. ([#35](https://github.com/Automattic/live-previews/pull/35))

[Unreleased]: https://github.com/Automattic/live-previews/commits/develop
