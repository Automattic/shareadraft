# VIP Integration

Share a Draft is a WordPress VIP integration. It follows the patterns WordPress
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
| Regenerate translations   | `composer i18n` (needs WP-CLI, and a current `npm run build`)                                                  |
| Integration validation    | `npx @automattic/vip-integration validate` (the external conformance checker — see [manifest.md](manifest.md)) |

## Runtime Config

Config constant: `VIP_SHAREADRAFT_CONFIG`

The VIP platform defines the constant (a plain PHP associative array) before the
plugin loads. All reads go through `Automattic\ShareADraft\Config`.

**Nothing in the constant is required.** Defining it *at all* is the signal that
matters: it is how the platform says this integration is enabled for the site.
Preview links work with nothing in it, so an empty array is a complete, valid
configuration.

Optional values:

- `dead_link_grace_period`: how long an expired or revoked link is kept, in
  seconds, so a reviewer returning to a stale link is told why it stopped
  working rather than seeing a "not found" page. Defaults to 21 days when it is
  absent or unusable, and the `shareadraft_dead_link_grace_period` filter
  still overrides whatever the platform sends.
- `ip_allowlist`: IP addresses or CIDR ranges (IPv4 or IPv6, comma- or
  newline-separated) that every preview link accepts — a shared baseline of
  trusted networks, set once. Each link can add further ranges of its own when
  it is generated; the gate allows a request when the client IP matches *any*
  range in the combined set (a union, so per-link ranges widen access, never
  narrow it). Absent or empty means links carry no IP restriction beyond what
  they set individually — the pre-allowlist behaviour. Unusable entries are
  silently dropped rather than half-applied.

Example valid config:

```php
define( 'VIP_SHAREADRAFT_CONFIG', [
	'dead_link_grace_period' => 604800, // 7 days.
	'ip_allowlist'           => '203.0.113.0/24, 2001:db8::/32',
] );
```

Example incomplete config (setup in progress — the customer has opened the field
but not filled it in, so it arrives blank):

```php
define( 'VIP_SHAREADRAFT_CONFIG', [
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

Share a Draft is an ordinary WordPress plugin and works on any host, so anything
that only makes sense on VIP is gated behind
`Automattic\ShareADraft\Platform::is_vip()` — which looks for
`VIP_GO_APP_ENVIRONMENT` or `WPCOM_IS_VIP_ENV`, and is filterable through
`shareadraft_is_vip_platform`. Today that decides one thing: whether the
contextual help on the Preview Links screen points at VIP documentation and
support, or at the plugin's own support forum. Platform *APIs* are gated
separately, by `class_exists()` / `function_exists()`, so an environment without
VIP MU plugins simply no-ops.

## Telemetry

Telemetry uses the helper in `inc/class-telemetry.php`, which wraps the VIP
Telemetry API (Tracks events only, no Stats) behind a `class_exists` guard so
environments without VIP MU plugins no-op. Event names are prefixed with
`shareadraft_` — a single word with no underscores, because the leading token is
the Tracks *source* and must be whitelisted in nosara (an underscore there would
divert events to `prod_rejects`). Never include secrets, raw content, email
addresses, or customer credentials in event properties.

| Name                            | Type   | Trigger                              | Properties                                             | Notes                                             |
| ------------------------------- | ------ | ------------------------------------ | ------------------------------------------------------ | ------------------------------------------------- |
| `shareadraft_link_created` | Tracks | A preview link is minted, via REST or the Abilities API. | `expiration`, `max_uses` (null = unlimited), `channel` (`rest` or `ability`), `plugin_version` (global) | Usage metadata only; never the token, content, or PII. |

## Translations

Translations ship inside the plugin, in `languages/`, rather than coming from
translate.wordpress.org. Two consequences follow, and both are easy to undo by
accident:

- `Plugin::init()` calls `load_plugin_textdomain()`. Without it WordPress only
  looks in `wp-content/languages/plugins/` and the bundled catalogues are
  ignored.
- `EditorAssets` passes the plugin's `languages/` directory as the third
  argument to `wp_set_script_translations()`, for the same reason.

`composer i18n` regenerates `languages/shareadraft.pot` and splits any
translated `.po` files into the JSON catalogues the editor script loads. It
scans `build/`, not `src/`: WordPress derives each JSON filename from a hash of
the *enqueued* script path, so the POT references have to point at
`build/index.js`. Run `npm run build` first, or the POT will describe a stale
script.

## Cutting a release

`.github/workflows/release.yml` runs on any pushed tag, wherever that tag lives,
and publishes a GitHub Release with a ready-to-install ZIP attached. A tag
containing a hyphen (`1.0.0-RC1`, `1.1.0-rc.2`) is published as a pre-release,
so internal test builds can be cut from a `release/*` branch without touching
`main`.

1. Branch from `develop`: `git checkout -b release/1.0.0-RC1`.
2. Update `CHANGELOG.md` — the workflow uses the entry matching the tag as the
   release notes, and falls back to auto-generated notes if it finds none.
3. Bump the version in all four places: the `Version:` header and the
   `VIP_SHAREADRAFT_VERSION` constant in `shareadraft.php`, `package.json`,
   and `release.plugin_version` in `vip-manifest.yaml`. The workflow fails the
   release if the first two disagree with the tag.
4. Run `npm run build`, then `composer i18n`, and commit the results. This
   order matters: the POT takes its `Project-Id-Version` from the plugin
   header, so regenerating it before step 3 stamps the previous version on
   the catalogue.
5. Push the branch, then tag its head: `git tag -s 1.0.0-RC1 -m "1.0.0-RC1"`
   and `git push origin 1.0.0-RC1`.

What ends up in the ZIP is controlled by `.distignore`: `shareadraft.php`,
`inc/`, `build/`, `languages/`, `vip-manifest.yaml`, `LICENSE`, `README.md`,
and `CHANGELOG.md`, unpacked under a single `shareadraft/` directory. There is no
`vendor/` — `inc/autoload.php` resolves the plugin's own classes, and there are
no runtime Composer dependencies. **Add a new development-only file to
`.distignore` when you add it to the repo**, or it ships.
