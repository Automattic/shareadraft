# Share a Draft

Drafts in WordPress are visible only to people who can edit them. When a colleague, client, or reviewer without an account needs to see a draft before it is published, Share a Draft gives you a link for exactly that: safe to share, and limited in time and use. It hardens the existing Preview Links.

Share a Draft is an ordinary WordPress plugin and works on any host, with nothing to configure. It is also packaged as a WordPress VIP integration: on VIP it reads optional settings from a VIP-provided constant, records Tracks telemetry, and is registered with the VIP Integrations Center through the [handoff manifest](/docs/manifest.md). Each of those is gated behind a platform check (`Automattic\ShareADraft\Platform::is_vip()`) or a `class_exists()` guard, so off VIP they are simply absent — no notices, no fatals, and no VIP branding on the site. See [/docs/vip-integration.md](/docs/vip-integration.md) for the operational details, and check conformance with the [`vip-integration`](https://github.com/Automattic/integration) CLI (`npx @automattic/vip-integration validate`).

The repository ships fully configured VIP local and cloud development environments along with unit tests, end-to-end tests, static analysis, and linting.

## Upgrading from Share a Draft 1.x

Share a Draft 2.0 is a rewrite. Links made with 1.x keep working until they expire, and their owners can review and delete them under **Posts → Share a Draft (Old)**, which only appears while they have one. 1.x links cannot be extended, and new links are made from the block editor's Share a Draft panel. Support for 1.x links is removed in 2.1.0.

## Changelog

Every release, back to 0.2, is listed in [CHANGELOG.md](https://github.com/Automattic/shareadraft/blob/main/CHANGELOG.md).

## WP-CLI commands

Everything the editor and the Preview Links screen can do with preview links is also available from the shell, under `wp shareadraft`. The commands share the exact service graph behind the editor, REST, and the Abilities API, so a link minted or revoked here obeys the same rules and records the same telemetry (with `cli` as its channel). Run `wp help shareadraft <command>` for full options and examples.

| Command | What it does |
| ------- | ------------ |
| `wp shareadraft create <post-id>` | Create a link and print its shareable URL (the one moment the secret token exists in plaintext). `--expiration=<seconds>`, `--max-uses=<count>`, `--allowed-ips=<ranges>`, and `--recipients=<emails>` mirror the editor's options; `--porcelain` prints just the URL for scripts. |
| `wp shareadraft list [<post-id>]` | List a post's live links, or every live link on the site when no post is given. `--created-by=<user>` narrows the site-wide listing to one creator, like the admin table's filter. Supports `--format=table\|csv\|json\|count\|yaml`, `--fields=`, and `--field=`. |
| `wp shareadraft revoke [<post-id>] [<link>]` | Revoke one link (a token hint from `list`, or a full link id), a post's live links (`<post-id> --all`), everything one user created (`--created-by=<user>`, for offboarding), or every live link on the site (a bare `--all`, the break-glass lever — it asks for confirmation unless `--yes`). |
| `wp shareadraft disable` / `enable` | Pause every preview link site-wide, or let them work again — the same reversible switch as the Preview Links screen. Nothing is revoked; each link resumes according to its own state. |
| `wp shareadraft prune` | Delete expired and revoked links past their retention period, on demand rather than waiting for the daily sweep. `--grace=0` deletes every dead link immediately. |

There is deliberately no `update` command: a link's token, expiry, and limits are fixed when it is minted, so "editing" a link means revoking it and creating a new one.

Because WP-CLI runs without a logged-in user, links minted from the shell are attributed to no one unless the global [`--user=`](https://make.wordpress.org/cli/handbook/references/config/#global-parameters) flag says otherwise.

While preview links are [temporarily disabled site-wide](#disabling-links-bulk-revocation-and-offboarding), `create` and `list` warn — on STDERR, so `--porcelain` and formatted output stay clean — that links will not work until an administrator re-enables them, matching the editor's Generate and Manage modals.

## Abilities (MCP and AI clients)

The same operations are registered with the WordPress [Abilities API](https://developer.wordpress.org/apis/abilities/), so MCP clients (via the [MCP Adapter](https://github.com/WordPress/mcp-adapter)), the WordPress AI Client, and the abilities REST runner drive them under the same rules and permission checks as every other surface. All are `public`, in the `shareadraft` category:

| Ability | What it does | Needs |
| ------- | ------------ | ----- |
| `shareadraft/create-preview-link` | Mint a link for a post (expiration, max uses, IP ranges, named reviewers) and return the shareable URL. | `edit_post` |
| `shareadraft/list-preview-links` | List a post's live links, or — without `post_id` — every live link on the site, optionally filtered by `created_by`. Returns usage, expiry, and a token hint, never the URL. | `edit_post` / `edit_others_posts` |
| `shareadraft/revoke-preview-link` | Revoke one link, a post's links (`all` with `post_id`), a creator's links (`created_by`), or every live link on the site (bare `all`). | `edit_post` / `edit_others_posts` / `manage_options` |
| `shareadraft/prune-preview-links` | Delete expired and revoked links past their retention period; `grace: 0` deletes every dead link immediately. | `manage_options` |
| `shareadraft/set-preview-links-enabled` | Flip the site-wide switch: pause every link, or let them work again. | `manage_options` |
| `shareadraft/get-preview-links-status` | Read whether links currently work, and who paused them when they do not. | `edit_posts` |

## Hosting requirements

The plugin itself needs nothing beyond WordPress 6.9 and PHP 8.2, and runs on any host. Two things about the hosting environment are worth checking.

### Preview requests must not be served from a page cache

A preview link carries its token in the query string (`?p=13&preview=true&shareadraft-token=…`), and the gate sends `nocache_headers()`, `X-Robots-Tag: noindex`, and `Referrer-Policy: no-referrer` before rendering an unlocked draft. Any full-page cache in front of WordPress — Varnish, nginx FastCGI cache, LiteSpeed, a caching plugin, or a CDN — must respect those headers and must not serve a cached response for a URL carrying `shareadraft-token`.

Practically every cache already bypasses on `preview=true` and on unrecognised query strings, and VIP guarantees it. If a cache is misconfigured, the visible symptom is that per-viewer limits stop counting correctly, because the gate reads and sets a per-viewer cookie. The worse and quieter failure is a cached copy of an unlocked draft being served to somebody with no token at all, so it is worth confirming rather than assuming.

### IP allowlists need the true client IP

A preview link can optionally be restricted to IP ranges (set per link when generating it, plus an optional central baseline on VIP). The gate reads the visitor's address from `REMOTE_ADDR`, which is correct on WordPress VIP — the edge rewrites it to the true client IP — and on any host where PHP talks directly to the client. Behind another reverse proxy, `REMOTE_ADDR` is the proxy's address, so every visitor would fail the check (the gate fails closed rather than trusting a spoofable `X-Forwarded-For`). Such hosts should return the address from their proxy's trusted header via the `shareadraft_client_ip` filter. Links without IP ranges are unaffected either way.

Note that IP allowlisting constrains *where* a link can be opened from, not *who* opens it — VPNs, mobile networks, and carrier-grade NAT all blur it — so it layers on top of the token controls rather than replacing them.

### Recipient-bound links need working outgoing email

A preview link can optionally be bound to named reviewers. Each reviewer proves control of their email address once per browser: the gate shows a short form, emails a six-digit code to the address (only if it is on the link's list), and sets a signed cookie when the code is entered. Those code emails go through `wp_mail()`, so the host must be able to deliver mail reliably — on VIP that is the platform's managed mail path; elsewhere, an SMTP plugin or transactional mail service is strongly recommended. The `shareadraft_verification_email` filter customises the subject and body.

The verification steps — and the expired/revoked/exhausted notices — render as a standalone card carrying the site's icon and name, in the wp-login.php spirit: it belongs to the site without depending on the theme (which cannot be rendered safely on these pages). The `shareadraft_notice_content` filter adjusts the card's body; a site wanting a wholly different page can hook `wp_die_handler`.

Sites that want neither of the optional restrictions can switch them off in code — `add_filter( 'shareadraft_recipients_enabled', '__return_false' )` and/or `add_filter( 'shareadraft_ip_allowlist_enabled', '__return_false' )` — which removes the fields from the Generate modal, the Manage modal, the Preview Links screen, and the REST/ability schemas. Links that already carry a restriction remain enforced; disabling a feature only stops new links being minted with it.

### Scheduled events must run

Expired and revoked links are kept for a grace period so the gate can tell a visitor *why* their link stopped working, then removed by a daily `shareadraft_prune_links` event. If scheduled events never fire, nothing breaks for visitors — expiry is checked when a link is opened, not by the sweep — but the rows accumulate indefinitely.

There is a **Preview link cleanup** check under Tools → Site Health that reports whether the sweep is scheduled and whether it has actually run recently, so a stalled sweep is visible rather than silent.

## Disabling links, bulk revocation, and offboarding

Beyond revoking a single link, the Preview Links screen offers a site-wide switch and two bulk actions, and a hook wires revocation into user offboarding.

- **Disable all preview links (reversible pause).** Administrators (`manage_options`) can temporarily disable every preview link with the toggle next to the table's bulk actions. Nothing is revoked: each link keeps its own expiry and usage, no link works while disabled, and links that are still valid resume working when re-enabled. This is the first response to a *suspected* leak — free to flip on suspicion and free to flip back on a false alarm, where revoking everything would force re-minting and re-sharing every in-flight link. While disabled, the Preview Links screen shows a banner recording who disabled links and when, and the editor's Generate and Manage modals warn that links (including newly generated ones) will not work until re-enabled. Visitors see a "temporarily disabled" notice, subject to the same `shareadraft_disclose_denial_reason` filter as other reasons.
- **Revoke in bulk, by creator or site-wide.** Tick the header checkbox to select the page; if more links exist than the page shows, a "Select all" offer extends the selection across every page — the whole site, or one person's links if you first clicked their name in the **Created by** column (useful when someone leaves). The ordinary Revoke bulk action then covers the whole selection. Site-wide select-all — the break-glass "revoke everything" for a confirmed leak — is limited to administrators.

Both act in bounded batches; on a site with a very large number of shared posts the sweep finishes in the background within a few minutes, and links on not-yet-swept posts keep working until their batch is reached.

**When a user account is deleted** (`deleted_user`), their links are revoked automatically. Role changes deliberately do not revoke automatically — demoting an editor to author should not necessarily kill in-flight reviews — but you can wire any hook to the supported `shareadraft_revoke_user_links` action:

```php
// Revoke a user's preview links when they lose edit access.
add_action( 'set_user_role', function ( int $user_id, string $role ): void {
	if ( ! in_array( $role, [ 'administrator', 'editor', 'author' ], true ) ) {
		do_action( 'shareadraft_revoke_user_links', $user_id );
	}
}, 10, 2 );

// Multisite: revoke when a user is removed from this site.
add_action( 'remove_user_from_blog', function ( int $user_id ): void {
	do_action( 'shareadraft_revoke_user_links', $user_id );
} );
```

After a user's links have all been revoked, `shareadraft_revoked_user_links` fires with the user's ID, how many links were revoked, and who initiated it (0 when system-initiated), so you can log offboarding for audit purposes:

```php
add_action( 'shareadraft_revoked_user_links', function ( int $user_id, int $count, int $actor ): void {
	// e.g. send to your audit log.
}, 10, 3 );
```

## Technology

These are the tools we use on a day-to-day basis to ensure code quality on the WordPress VIP platform.

### Unit and integration tests

We use [PHPUnit 9](https://phpunit.de/index.html) for both suites. The fast unit tests (pure PHP, no WordPress) live in [/tests/unit](tests/unit/), and the WordPress-booting integration tests live in [/tests/integration](tests/integration/).

### End-to-end tests

For end-to-end tests we use [Playwright](https://playwright.dev/). The specs live in [/tests/e2e](/tests/e2e).

### WP-CLI feature tests

Each `wp shareadraft` command is pinned by a [Behat](https://behat.org/) feature file in [/features](/features), run against a real WordPress in [wp-env](https://www.npmjs.com/package/@wordpress/env) via [automattic/behat-wp-env-context](https://packagist.org/packages/automattic/behat-wp-env-context). Start the environment with `composer prepare-behat-tests`, then run `composer behat` (`composer behat-rerun` repeats only the scenarios that failed).

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
| `behat.yml`                            | Behat feature tests for the `wp shareadraft` WP-CLI commands, against a real wp-env.                      |
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

4. Build the JavaScript assets (the `build/` directory is not committed; without this step the editor panel and parts of the Preview Links screen are silently absent, though an admin notice will remind you):

```sh
npm run build
```

5. Create and start a WPVIP local development instance:

```sh
vip dev-env create
vip dev-env start
```

6. Write code, write tests. Or the other way around! `composer test` runs both suites (the e2e half needs the dev-env from the previous step running — see [/docs/vip-integration.md](/docs/vip-integration.md)).

📝 For convenience, this repository contains a [vip-dev-env.yml](/.wpvip/vip-dev-env.yml) configuration file; tweak it to your needs. For a more in-depth guide to VIP local development environments, see [our documentation site](https://docs.wpvip.com/vip-local-development-environment/create/).

## Cloud-based development

We support GitHub Codespaces. There are no set-up steps: on the first start the codespace takes a few minutes to build, after which you have a working environment. You can use either the web-based editor or local VS Code.
