# Changelog

All notable changes to Share a Draft are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

First release of Share a Draft: safe-to-share, time- and usage-limited preview
links that let a reviewer without a WordPress account view a draft.

Requires WordPress 6.9 or later and PHP 8.2 or later. Designed for WordPress VIP
but runs on any host.

### Added

- Generate a safe-to-share preview link for a draft from the block editor, reusing WordPress's own preview flow so a logged-out reviewer sees the draft as it will publish. ([#9](https://github.com/Automattic/live-previews/pull/9), [#10](https://github.com/Automattic/live-previews/pull/10))
- Set how long each link lasts, from a configurable, filterable set of expiration options, including an optional effectively-indefinite lifetime. The editor pre-selects 8 hours, changeable with the `shareadraft_default_expiration` filter. ([#10](https://github.com/Automattic/live-previews/pull/10), [#17](https://github.com/Automattic/live-previews/pull/17))
- Limit a link by the number of distinct viewers, including one-time and unlimited links; crawler and unfurler requests never spend a view. ([#11](https://github.com/Automattic/live-previews/pull/11))
- Manage a post's preview links from the editor — see each link's usage and time remaining, identify it by a token hint, and revoke it. ([#12](https://github.com/Automattic/live-previews/pull/12), [#17](https://github.com/Automattic/live-previews/pull/17))
- Audit and revoke every preview link on the site from a top-level Preview Links screen, with per-page screen options and contextual help. ([#34](https://github.com/Automattic/live-previews/pull/34))
- Revoke preview links in bulk: filter the Preview Links screen to one creator and revoke everything they made, or — as an administrator — revoke every link on the site in one guarded action. Links a user created are revoked automatically when their account is deleted, other offboarding flows can trigger the same sweep through the `shareadraft_revoke_user_links` action, and `shareadraft_revoked_user_links` fires afterwards for audit logging. Sweeps run in bounded batches and finish in the background on large sites. ([#46](https://github.com/Automattic/live-previews/pull/46))
- Temporarily disable all preview links with a reversible, administrator-only switch on the Preview Links screen — the first response to a suspected leak. Nothing is revoked: links keep their own expiry and usage and resume working when re-enabled. While disabled, the admin screen banners who disabled links and when, the editor's Generate and Manage modals warn that links will not work, and visitors see a "temporarily disabled" notice. ([#46](https://github.com/Automattic/live-previews/pull/46))
- Show a friendly notice when a link has expired, been revoked, or been exhausted, while unknown links stay a plain 404 so drafts cannot be enumerated. How much of the reason is disclosed is filterable, for sites that would rather say less. The notices — and the reviewer verification steps — render as a branded standalone card with the site's icon and name rather than a bare error screen, adjustable through the `shareadraft_notice_content` filter. ([#15](https://github.com/Automattic/live-previews/pull/15), [#31](https://github.com/Automattic/live-previews/pull/31))
- Sweep expired and revoked links automatically after a retention period, so a reviewer returning to a stale link is told why it stopped working rather than seeing a 404. The period is set from the VIP Dashboard through the optional `dead_link_grace_period` value and overridden by the `shareadraft_dead_link_grace_period` filter, falling back to 21 days whenever the value is absent or unusable — a blank field never means "delete links the moment they expire". ([#32](https://github.com/Automattic/live-previews/pull/32))
- Create and list preview links through the Abilities API, so MCP clients, the AI Client, and the abilities REST runner mint links under the same rules as the editor. ([#28](https://github.com/Automattic/live-previews/pull/28), [#30](https://github.com/Automattic/live-previews/pull/30))
- Manage preview links fully from MCP clients and the AI Client: abilities now also cover listing every link on the site (optionally by creator), revoking at four scopes (one link, a post's links, a creator's links for offboarding, or every link on the site), pruning dead links, flipping the site-wide switch, and a read-only status check — each behind the same capability its admin-page counterpart requires. ([#57](https://github.com/Automattic/live-previews/pull/57))
- Manage preview links from the shell with WP-CLI: `wp shareadraft create`, `list`, `revoke`, and `prune` share the same rules and telemetry as every other surface, so developers, scripts, and terminal-based agents can mint, audit, and kill links without wp-admin — including `revoke --all` when a URL leaks and `prune --grace=0` for immediate cleanup. `list` shows who created each link, matching the admin table's Created by column. While preview links are temporarily disabled site-wide, `create` and `list` warn that links will not work, matching the editor's modals. Each command is pinned by a Behat feature test against a real WordPress. The shell has since reached full parity with the Preview Links screen: `disable`/`enable` flip the site-wide switch, `revoke --created-by` sweeps one person's links, a bare `revoke --all` is the confirmed break-glass revoke-everything, and `list --created-by` mirrors the admin table's creator filter. ([#49](https://github.com/Automattic/live-previews/pull/49), [#55](https://github.com/Automattic/live-previews/pull/55), [#57](https://github.com/Automattic/live-previews/pull/57))
- Restrict where a preview link can be opened from with an optional IP allowlist: per-link CIDR ranges (IPv4 and IPv6) set when generating the link, unioned with an optional central baseline set once in the VIP Dashboard through the `ip_allowlist` value. A link must pass both the token checks and the IP check; with no ranges anywhere, behaviour is unchanged. Visitors outside the allowlist see a plain 404, learning nothing about the draft. Per-link ranges are shown in the editor's Manage modal and on the Preview Links screen. ([#45](https://github.com/Automattic/live-previews/pull/45))
- Bind a preview link to named reviewers by email. A bound link asks the visitor for their address, emails a six-digit code (only ever to an address the author listed, and rate-limited), and unlocks the draft once the code is entered — so the link works for the people it was issued to, not for anyone it gets forwarded to. Verification is remembered per browser with a signed cookie, and revoking the link or removing a reviewer locks them out immediately. Reviewers are shown in the editor's Manage modal, on the Preview Links screen, and in `wp shareadraft list`; every minting surface can bind them (the Generate modal, REST, the Abilities API, and `wp shareadraft create --recipients`), and the code email is customisable with the `shareadraft_verification_email` filter.
- Switch either optional restriction off in code — `shareadraft_recipients_enabled` and `shareadraft_ip_allowlist_enabled` filters — for sites that never want them, removing the fields from the editor modals, the Preview Links screen, and the REST/ability schemas. Existing restricted links remain enforced; only minting new ones is stopped.
- Report whether the cleanup sweep is scheduled and actually running, as a Site Health check under Tools → Site Health.
- Ship translatable strings with a bundled POT, so the plugin can be localised without a WordPress.org language pack.

### Security

- Store only a hash of each token, enforce every link limit server-side, and keep drafts visible to link holders alone — preview requests are also marked no-index so a shared link cannot be indexed by search engines. ([#18](https://github.com/Automattic/live-previews/pull/18))
- Send reviewer verification codes only after the response has been flushed to the visitor, so a listed and an unlisted address answer the email form in the same time and response timing cannot enumerate a link's reviewer list. The code email also names the site's domain and warns never to share the code, giving reviewers concrete checks against phishing imitations. ([#63](https://github.com/Automattic/live-previews/pull/63))

### Notes for VIP

- Every value in `VIP_SHAREADRAFT_CONFIG` is optional. Defining the constant is what enables the integration; the plugin reads only `dead_link_grace_period` and `ip_allowlist`, and it runs on its built-in defaults without them. ([#35](https://github.com/Automattic/live-previews/pull/35))
- VIP support links in contextual help appear only on VIP-hosted sites, where VIP support can answer them; elsewhere they point at the plugin's own support channel. ([#35](https://github.com/Automattic/live-previews/pull/35))

[Unreleased]: https://github.com/Automattic/shareadraft/commits/develop
