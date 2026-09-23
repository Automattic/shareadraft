# WP-CLI commands

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

While preview links are [temporarily disabled site-wide](managing-links.md), `create` and `list` warn — on STDERR, so `--porcelain` and formatted output stay clean — that links will not work until an administrator re-enables them, matching the editor's Generate and Manage modals.
