# Disabling links, bulk revocation, and offboarding

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
