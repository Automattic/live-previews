# Changelog

All notable changes to Live Previews are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Initial release of Live Previews: safe-to-share, time- and usage-limited preview
links that let a reviewer without a WordPress account view a draft.

### Added

- Generate a safe-to-share preview link for a draft from the block editor, reusing WordPress's own preview flow so a logged-out reviewer sees the draft as it will publish. ([#9](https://github.com/Automattic/live-previews/pull/9), [#10](https://github.com/Automattic/live-previews/pull/10))
- Set how long each link lasts, from a configurable, filterable set of expiration options, including an optional effectively-indefinite lifetime. ([#10](https://github.com/Automattic/live-previews/pull/10), [#17](https://github.com/Automattic/live-previews/pull/17))
- Limit a link by the number of distinct viewers, including one-time and unlimited links; crawler and unfurler requests never spend a view. ([#11](https://github.com/Automattic/live-previews/pull/11))
- Manage a post's preview links from the editor — see each link's usage and time remaining, identify it by a token hint, and revoke it. ([#12](https://github.com/Automattic/live-previews/pull/12), [#17](https://github.com/Automattic/live-previews/pull/17))
- Show a friendly notice when a link has expired, been revoked, or been exhausted, while unknown links stay a plain 404 so drafts cannot be enumerated. ([#15](https://github.com/Automattic/live-previews/pull/15))
- Report whether the cleanup sweep for expired links is scheduled and actually running, as a Site Health check under Tools → Site Health.

### Changed

- Run cleanly on any WordPress host, not just VIP: the runtime-configuration notice and the VIP support links in contextual help now appear only on VIP-hosted sites, where they mean something. Everywhere else the plugin is silent about configuration it does not need, and contextual help points at the plugin's own support forum.
- Confine the runtime-configuration notice to the plugin's own Preview Links screen. It previously appeared on every wp-admin page.
- Treat an empty `VIP_LIVE_PREVIEWS_CONFIG` as a complete configuration. The plugin needs no data from the platform, so defining the constant is the whole signal that the integration is enabled; the notice now fires only when it is absent or unusable, and says so rather than asking for fields that do not exist.

### Removed

- The signature line the plugin printed in the site footer, and the `signature_label` configuration field that fed it. The plugin now renders nothing on the front end apart from the previewed post itself.

### Security

- Store only a hash of each token, enforce every link limit server-side, and keep drafts visible to link holders alone — preview requests are also marked no-index so a shared link cannot be indexed by search engines. ([#18](https://github.com/Automattic/live-previews/pull/18))

[Unreleased]: https://github.com/Automattic/live-previews/commits/develop
