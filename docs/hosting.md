# Hosting requirements

The plugin itself needs nothing beyond WordPress 6.9 and PHP 8.2, and runs on any host. A few things about the hosting environment are worth checking.

## Preview requests must not be served from a page cache

A preview link carries its token in the query string (`?p=13&preview=true&shareadraft-token=…`), and the gate sends `nocache_headers()`, `X-Robots-Tag: noindex`, and `Referrer-Policy: no-referrer` before rendering an unlocked draft. Any full-page cache in front of WordPress — Varnish, nginx FastCGI cache, LiteSpeed, a caching plugin, or a CDN — must respect those headers and must not serve a cached response for a URL carrying `shareadraft-token`.

Practically every cache already bypasses on `preview=true` and on unrecognised query strings, and VIP guarantees it. If a cache is misconfigured, the visible symptom is that per-viewer limits stop counting correctly, because the gate reads and sets a per-viewer cookie. The worse and quieter failure is a cached copy of an unlocked draft being served to somebody with no token at all, so it is worth confirming rather than assuming.

## IP allowlists need the true client IP

A preview link can optionally be restricted to IP ranges (set per link when generating it, plus an optional central baseline on VIP). The gate reads the visitor's address from `REMOTE_ADDR`, which is correct on WordPress VIP — the edge rewrites it to the true client IP — and on any host where PHP talks directly to the client. Behind another reverse proxy, `REMOTE_ADDR` is the proxy's address, so every visitor would fail the check (the gate fails closed rather than trusting a spoofable `X-Forwarded-For`). Such hosts should return the address from their proxy's trusted header via the `shareadraft_client_ip` filter. Links without IP ranges are unaffected either way.

Note that IP allowlisting constrains *where* a link can be opened from, not *who* opens it — VPNs, mobile networks, and carrier-grade NAT all blur it — so it layers on top of the token controls rather than replacing them.

## Recipient-bound links need working outgoing email

A preview link can optionally be bound to named reviewers. Each reviewer proves control of their email address once per browser: the gate shows a short form, emails a six-digit code to the address (only if it is on the link's list), and sets a signed cookie when the code is entered. Those code emails go through `wp_mail()`, so the host must be able to deliver mail reliably — on VIP that is the platform's managed mail path; elsewhere, an SMTP plugin or transactional mail service is strongly recommended. The `shareadraft_verification_email` filter customises the subject and body.

The verification steps — and the expired/revoked/exhausted notices — render as a standalone card carrying the site's icon and name, in the wp-login.php spirit: it belongs to the site without depending on the theme (which cannot be rendered safely on these pages). The `shareadraft_notice_content` filter adjusts the card's body; a site wanting a wholly different page can hook `wp_die_handler`.

Sites that want neither of the optional restrictions can switch them off in code — `add_filter( 'shareadraft_recipients_enabled', '__return_false' )` and/or `add_filter( 'shareadraft_ip_allowlist_enabled', '__return_false' )` — which removes the fields from the Generate modal, the Manage modal, the Preview Links screen, and the REST/ability schemas. Links that already carry a restriction remain enforced; disabling a feature only stops new links being minted with it.

## Scheduled events must run

Expired and revoked links are kept for a grace period so the gate can tell a visitor *why* their link stopped working, then removed by a daily `shareadraft_prune_links` event. If scheduled events never fire, nothing breaks for visitors — expiry is checked when a link is opened, not by the sweep — but the rows accumulate indefinitely.

There is a **Preview link cleanup** check under Tools → Site Health that reports whether the sweep is scheduled and whether it has actually run recently, so a stalled sweep is visible rather than silent.
