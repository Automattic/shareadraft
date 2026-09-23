# Share a Draft on WordPress VIP

Share a Draft runs on any WordPress host, but on [WordPress VIP](https://wpvip.com/) it is also available as an integration, enabled for a site from the VIP Dashboard. This page covers what is different there.

## Hosting requirements are already met

The [hosting requirements](hosting.md) that need checking elsewhere are taken care of by the platform:

- **Page caching.** VIP's edge cache never serves cached copies of preview requests, so each visitor reaches WordPress and every limit on a link is enforced.
- **Visitor IP addresses.** VIP's edge network passes on each visitor's real address, so [IP restrictions](hosting.md#ip-restrictions-need-the-visitors-real-ip-address) work without any extra code.
- **Email.** Verification codes for named reviewers go out through VIP's managed email delivery.
- **Scheduled events.** VIP runs scheduled events reliably, so expired and revoked links are cleaned up on time.

## Settings from the VIP Dashboard

Enabling the integration makes two optional settings available in the VIP Dashboard. Share a Draft works without either of them.

- **Expired link retention.** How long an expired or revoked link is kept, so a reviewer who returns to it is told why it stopped working. The default is 21 days. A site can still change it in code with the [`shareadraft_dead_link_grace_period` filter](customizing.md#keep-expired-and-revoked-links-for-more-or-less-time), which has the final say.
- **Trusted IP ranges.** Addresses or ranges, such as your office networks, that every preview link accepts. Each link can add its own ranges when it is created, and a visitor matching any of them is let in, so these central ranges can only widen where links open, never narrow it. The Preview Links screen and the editor show which ranges apply.

## Usage statistics

On VIP, Share a Draft records a usage event each time a link is created, through VIP's telemetry service, to help VIP understand how the feature is used. The event describes the link (its lifetime, whether it has a usage limit, how it was created, and whether it uses IP ranges or named reviewers), but never includes the link itself, the draft's content, IP addresses, or reviewers' email addresses. Nothing is recorded from local development environments. The `shareadraft_record_telemetry` filter can switch recording off, or on for a local environment.

Off VIP, no usage statistics are recorded at all.

## Help and support

On VIP, the contextual help on the Preview Links screen links to VIP's documentation and support team. Elsewhere, it points to the plugin's [support forum](https://wordpress.org/support/plugin/shareadraft/).

## For developers

How the plugin reads its VIP configuration, records usage statistics, and is registered with VIP is covered in [the VIP integration guide](vip-integration.md) and [the handoff manifest guide](manifest.md).
