# Config fixtures

On the VIP platform, runtime configuration is injected as a single PHP
constant (`VIP_LIVE_PREVIEWS_CONFIG`) holding a plain associative array,
defined **before** the plugin is loaded. These fixtures mock that constant for
local development and automated testing.

The constant carries no data today: defining it is how the platform says this
integration is enabled for the site. There is correspondingly little to mock —
the states that matter are "defined and usable" and "not usable at all".

| Fixture | State it simulates |
| --- | --- |
| `config-valid.php` | What the platform defines today: present, and empty. The plugin reports ready. |
| `config-invalid.php` | Constant holds a non-array value. Exercises the `is_array()` guard. |
| `config-local.php` | Optional, **git-ignored** local override — see below. |

A third state, the constant never being defined at all, needs no fixture:
`ConfigTest` covers it by constructing `Config` with `null`.

## Local overrides (`config-local.php`)

To test with values you don't want committed (real-ish tokens, a staging API
URL, experiments), copy any fixture to `fixtures/config-local.php` and edit it:

```sh
cp fixtures/config-valid.php fixtures/config-local.php
```

When it exists, the dev-env plugin loader uses it instead of
`config-valid.php`. It is listed in `.gitignore`, so it never ends up in a
commit.

## Where they are used

- **Local development:** [`.wpvip/plugin-loader.php`](../.wpvip/plugin-loader.php)
  defines the constant from `config-local.php` when present, otherwise
  `config-valid.php`, before loading the plugin — mirroring how VIP injects
  config in production. Swap the fixture there to observe other states.
- **Integration tests:** [`tests/integration/bootstrap.php`](../tests/integration/bootstrap.php)
  defines the constant from `config-valid.php`; `ConfigTest` constructs
  `Config` directly to cover every state.

Never put real production secrets in fixtures — including `config-local.php`;
git-ignored is not encrypted.
