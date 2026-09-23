# Abilities (MCP and AI clients)

The same operations are registered with the WordPress [Abilities API](https://developer.wordpress.org/apis/abilities/), so MCP clients (via the [MCP Adapter](https://github.com/WordPress/mcp-adapter)), the WordPress AI Client, and the abilities REST runner drive them under the same rules and permission checks as every other surface. All are `public`, in the `shareadraft` category:

| Ability | What it does | Needs |
| ------- | ------------ | ----- |
| `shareadraft/create-preview-link` | Mint a link for a post (expiration, max uses, IP ranges, named reviewers) and return the shareable URL. | `edit_post` |
| `shareadraft/list-preview-links` | List a post's live links, or — without `post_id` — every live link on the site, optionally filtered by `created_by`. Returns usage, expiry, and a token hint, never the URL. | `edit_post` / `edit_others_posts` |
| `shareadraft/revoke-preview-link` | Revoke one link, a post's links (`all` with `post_id`), a creator's links (`created_by`), or every live link on the site (bare `all`). | `edit_post` / `edit_others_posts` / `manage_options` |
| `shareadraft/prune-preview-links` | Delete expired and revoked links past their retention period; `grace: 0` deletes every dead link immediately. | `manage_options` |
| `shareadraft/set-preview-links-enabled` | Flip the site-wide switch: pause every link, or let them work again. | `manage_options` |
| `shareadraft/get-preview-links-status` | Read whether links currently work, and who paused them when they do not. | `edit_posts` |
