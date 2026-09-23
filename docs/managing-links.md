# Managing preview links

Every preview link can be revoked on its own, and administrators can also pause every link on the site at once, revoke links in bulk, and make sure links do not outlive the people who created them. This page covers each of those. Everything here can also be done from the shell with [WP-CLI](wp-cli.md), or by AI assistants through the [Abilities API](abilities.md).

## Revoking a link

A revoked link stops working immediately. There are two places to do it:

- **In the block editor.** Choose **Manage preview links** in the draft's Share a Draft panel to see each of its links, with how often it has been used and when it expires, and revoke any of them.
- **On the Preview Links screen.** Every link on the site is listed under **Preview Links** in the admin menu, with the post it belongs to, who created it, its usage, reviewers, IP restrictions, and expiry. Revoke a link from its row, or select several and use the **Revoke** bulk action.

A reviewer who opens a revoked link is told it has been revoked, rather than seeing a bare "not found". Links are also discarded automatically when their draft is published or moved to the trash.

## Pausing every link

If you suspect a link has leaked but do not know which one, an administrator can switch off every preview link on the site at once, with the toggle at the top of the Preview Links screen.

Pausing does not change any link. While links are paused, none of them work, and new links cannot be used either. When you switch them back on, each link works exactly as it did before: its expiry, its usage limit, and its reviewers are untouched, and links that expired in the meantime stay expired. That makes pausing safe to use on suspicion, and safe to undo after a false alarm, unlike revoking everything, which would mean creating and resending every link people are still using.

While links are paused, the Preview Links screen shows who paused them and when, the block editor warns anyone creating or managing links that they will not work, and reviewers who open a link are told that preview links are temporarily disabled on the site.

## Revoking in bulk

To revoke more than one page of links at once, select the checkbox at the top of the table to select the page. If there are more links than the page shows, a **Select all** option extends the selection across every page. Choose **Revoke** from the bulk actions to revoke the whole selection.

To revoke everything one person created, click their name in the **Created by** column first, so the table shows only their links, then select all. Revoking every link on the whole site, for a confirmed leak, is limited to administrators.

Bulk revoking works through links in batches. On a site with a very large number of shared posts, the remainder is finished in the background within a few minutes, and links not yet reached keep working until then.

## When someone leaves

When a user account is deleted, every link that person created is revoked automatically.

Changing someone's role deliberately does not revoke their links: moving an editor to author should not necessarily cut off reviews already under way. If your process should revoke links on other events, trigger the `shareadraft_revoke_user_links` action from them:

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

Once all of a user's links have been revoked, however that was triggered, the `shareadraft_revoked_user_links` action runs with the user's ID, how many links were revoked, and the ID of the user who started it (0 when it happened automatically). Use it to record offboarding in an audit log:

```php
add_action( 'shareadraft_revoked_user_links', function ( int $user_id, int $count, int $actor ): void {
	// For example, send to your audit log.
}, 10, 3 );
```

For other ways to adjust how links behave, see [customizing Share a Draft](customizing.md).
