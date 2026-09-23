<?php

namespace Automattic\ShareADraft;

use WP_Post;
use WP_Query;

/**
 * Enforces preview access at request time.
 *
 * A shareable link reuses WordPress's own preview URL (e.g. `?p=13&preview=true`)
 * with an extra `shareadraft-token` parameter. Rather than render a post page from scratch
 * on a bespoke endpoint, we hook `posts_results` — which fires after WP_Query has
 * loaded a post but *before* it drops non-public posts for logged-out visitors —
 * and, for a valid token, mark that one post public for the current query. Every
 * other request is untouched. Approach borrowed from vip-workflow-plugin PR #19.
 *
 * When a link caps the number of viewers, the gate spends one slot per distinct
 * browser. The slot ID is minted by the server and handed back in a cookie, so a
 * visitor cannot fabricate one: the previous scheme derived the cookie from the
 * token's SHA-256 hash, which every link holder can compute, and so let anyone
 * with the URL walk past a spent cap. A separate browser or a private window has
 * its own cookie jar and so claims a new slot, which is the intended behaviour.
 *
 * Automated clients (crawlers, chat-link unfurlers) are served a contentless stub
 * instead of the draft. Exempting them from the cap by user agent alone would be
 * worthless — the header is attacker-controlled — so instead the exemption is
 * made worthless to abuse: spoofing a crawler gets you less, not more.
 */
final class PreviewGate {
	public const TOKEN_QUERY_VAR = 'shareadraft-token';

	private const COOKIE_PREFIX = 'shareadraft_viewer_';

	/** Marks a request that was withheld because the client looks automated. */
	private const REASON_AUTOMATED = 'automated_client';

	/** Marks a request withheld because the site-wide toggle is off. */
	private const REASON_DISABLED = 'links_disabled';

	private PreviewLinkService $service;
	private LinkToggle $toggle;

	private RecipientVerifier $verifier;

	/** The slot ID this visitor holds, once resolved or claimed. */
	private ?string $viewer_id = null;

	/** The email this visitor has proved control of, or null. Resolved once. */
	private ?string $verified_email = null;

	/** Reason the main query's preview was withheld, for the friendly notice. */
	private ?string $denial_reason = null;

	/** The post whose preview was withheld, for the verification form. */
	private int $denied_post_id = 0;

	/** Ensures a single request claims at most one slot, however many queries run. */
	private bool $claimed_this_request = false;

	public function __construct( PreviewLinkService $service, ?RecipientVerifier $verifier = null, ?LinkToggle $toggle = null ) {
		$this->service  = $service;
		$this->verifier = $verifier ?? new RecipientVerifier();
		$this->toggle   = $toggle ?? new LinkToggle();
	}

	public function register(): void {
		add_filter( 'posts_results', [ $this, 'unlock_valid_previews' ], 10, 2 );
		add_action( 'template_redirect', [ $this, 'maybe_render_notice' ] );
	}

	/**
	 * @param mixed    $posts Posts loaded by the query (array of WP_Post on success).
	 * @param WP_Query $query The query being run.
	 * @return mixed
	 */
	public function unlock_valid_previews( $posts, WP_Query $query ) {
		if ( ! is_array( $posts ) || [] === $posts || ! $query->is_preview() ) {
			return $posts;
		}

		$token_value = $this->token_from_request();
		if ( null === $token_value ) {
			return $posts;
		}

		$token = Token::from_string( $token_value );

		if ( null === $this->viewer_id ) {
			$this->viewer_id = $this->viewer_id_from_request( $token );
		}

		if ( null === $this->verified_email ) {
			$this->verified_email = $this->verifier->verified_email( $token );
		}

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$status = get_post_status_object( $post->post_status );

			// Only unpublished posts need unlocking; public ones already render.
			if ( null !== $status && $status->public ) {
				continue;
			}

			// Leave authors and editors to WordPress's own preview: they can
			// already see the draft, so a spent link must not lock them out.
			if ( current_user_can( 'edit_post', (int) $post->ID ) ) {
				continue;
			}

			// The site-wide switch pauses every link without touching its state:
			// the row still says what it said, it just is not honoured right now.
			if ( $this->toggle->is_disabled() ) {
				$this->remember_denial( self::REASON_DISABLED );
				continue;
			}

			$post_id  = (int) $post->ID;
			$decision = $this->service->authorize( $post_id, $token, $this->viewer_id, $this->client_ip(), $this->verified_email );

			if ( ! $decision->is_allowed() ) {
				// Remember a dead-but-real link so template_redirect can explain
				// why, instead of leaving the visitor at a bare 404. Only preview
				// requests reach here, so this is the page the visitor asked for.
				$this->remember_denial( $decision->reason(), $post_id );
				continue;
			}

			// The token checks out, but an automated client never gets the draft
			// itself — only a stub, and without spending a slot.
			if ( $this->is_automated_client() ) {
				$this->remember_denial( self::REASON_AUTOMATED, $post_id );
				continue;
			}

			if ( ! $this->ensure_slot( $post_id, $token ) ) {
				// A concurrent visitor took the last slot between the decision
				// above and the write. Deny rather than let both in.
				$this->remember_denial( AccessDecision::REASON_EXHAUSTED, $post_id );
				continue;
			}

			$this->send_preview_headers();

			// Marking the post published for this query alone lets it survive
			// WP_Query's "logged-out users cannot see non-public posts" check.
			$post->post_status = 'publish';
		}

		return $posts;
	}

	/**
	 * Make sure this visitor holds a slot on the link, claiming one if needed.
	 *
	 * Returns false only when there was no slot left to claim, which the caller
	 * must treat as a denial.
	 */
	private function ensure_slot( int $post_id, Token $token ): bool {
		if ( $this->claimed_this_request ) {
			return true;
		}

		// A cookie that merely looks like a slot ID is not one. Only the link
		// knows which IDs it issued, so ask it rather than trusting the shape.
		if ( $this->service->holds_slot( $post_id, $token, $this->viewer_id ) ) {
			return true;
		}

		$viewer_id = $this->service->claim_slot( $post_id, $token, $this->client_ip(), $this->verified_email );

		if ( null === $viewer_id ) {
			return false;
		}

		$this->claimed_this_request = true;
		$this->viewer_id            = $viewer_id;
		$this->remember_viewer( $token, $viewer_id );

		return true;
	}

	/**
	 * Record why the main preview was withheld, but only for reasons that are
	 * safe to state. An unknown or wrong token is left to 404 exactly as a
	 * missing post would, so nobody can probe which draft IDs exist.
	 */
	private function remember_denial( string $reason, int $post_id = 0 ): void {
		$explainable = [
			AccessDecision::REASON_EXPIRED,
			AccessDecision::REASON_REVOKED,
			AccessDecision::REASON_EXHAUSTED,
			AccessDecision::REASON_EMAIL_UNVERIFIED,
			self::REASON_AUTOMATED,
			self::REASON_DISABLED,
		];

		if ( in_array( $reason, $explainable, true ) ) {
			$this->denial_reason  = $reason;
			$this->denied_post_id = $post_id;
		}
	}

	/**
	 * Show a friendly page when a real preview link could not be served.
	 */
	public function maybe_render_notice(): void {
		$reason = $this->denial_reason;

		if ( null === $reason ) {
			return;
		}

		$this->send_preview_headers();

		// An unfurler poking a recipient-bound link gets the same contentless
		// stub as any other automated client, not the verification form.
		if ( AccessDecision::REASON_EMAIL_UNVERIFIED === $reason && $this->is_automated_client() ) {
			$reason = self::REASON_AUTOMATED;
		}

		if ( self::REASON_AUTOMATED === $reason ) {
			// A neutral 200 so a chat unfurl renders a tidy card, with none of
			// the draft's title, excerpt, or image in it.
			NoticePage::render(
				__( 'Private preview link', 'shareadraft' ),
				sprintf( '<p>%s</p>', esc_html__( 'This is a private preview link. Open it in a browser to view the draft.', 'shareadraft' ) ),
				200
			);
		}

		if ( AccessDecision::REASON_EMAIL_UNVERIFIED === $reason ) {
			$this->handle_verification();
		}

		$generic = __( 'This preview link is no longer available.', 'shareadraft' );

		$specific = [
			AccessDecision::REASON_EXPIRED   => __( 'This preview link has expired.', 'shareadraft' ),
			AccessDecision::REASON_REVOKED   => __( 'This preview link has been revoked.', 'shareadraft' ),
			AccessDecision::REASON_EXHAUSTED => __( 'This preview link has reached its viewing limit.', 'shareadraft' ),
			self::REASON_DISABLED            => __( 'Preview links are temporarily disabled on this site.', 'shareadraft' ),
		];

		/**
		 * Filters whether the visitor is told *why* a preview link stopped working
		 * (expired, revoked, or viewing limit reached), or sees a single generic
		 * message instead.
		 *
		 * Naming the reason is safe: only a visitor already presenting a valid
		 * token for this exact post reaches this page, so it is almost always a
		 * genuine recipient whom the reason helps. The choice is therefore about
		 * tone, not security. Return false to collapse every reason to one message;
		 * the passed reason lets a callback hide only some (e.g. reveal expiry but
		 * not revocation).
		 *
		 * @param bool   $disclose Whether to state the specific reason. Default true.
		 * @param string $reason   Machine reason, one of AccessDecision::REASON_EXPIRED,
		 *                         REASON_REVOKED, REASON_EXHAUSTED, or 'links_disabled'.
		 */
		$disclose = (bool) apply_filters( 'shareadraft_disclose_denial_reason', true, $reason );

		$message = $disclose
			? ( $specific[ $reason ] ?? $generic )
			: $generic;

		// While the site-wide switch is off, a fresh link would not work either,
		// so "ask for a new link" would send the visitor on a pointless errand.
		$advice = $disclose && self::REASON_DISABLED === $reason
			? __( 'Please try again later.', 'shareadraft' )
			: __( 'Ask the author to share a new preview link.', 'shareadraft' );

		NoticePage::render(
			__( 'Preview unavailable', 'shareadraft' ),
			sprintf(
				'<p>%s</p><p>%s</p>',
				esc_html( $message ),
				esc_html( $advice )
			),
			410
		);
	}

	/**
	 * The email-verification interstitial for a recipient-bound link: ask for
	 * an address, email a code to it if it is a listed reviewer, and swap the
	 * code for the signed cookie that unlocks the preview. Never returns — every
	 * path ends in wp_die() or a redirect.
	 *
	 * The flow deliberately answers a listed and an unlisted address
	 * identically, so this form cannot be used to probe who is on a link's
	 * reviewer list; only a listed address actually receives mail.
	 */
	private function handle_verification(): void {
		$token_value = $this->token_from_request();

		if ( null === $token_value ) {
			// Unreachable in practice: this denial is only recorded for a
			// presented token. Fall back to the generic email form copy.
			$this->render_email_form();
		}

		$token = Token::from_string( (string) $token_value );

		$nonce_ok = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] )
			&& false !== wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'shareadraft_verify' );

		$action = isset( $_POST['shareadraft-verify-action'] ) && is_string( $_POST['shareadraft-verify-action'] )
			? sanitize_key( wp_unslash( $_POST['shareadraft-verify-action'] ) )
			: '';

		$email = isset( $_POST['shareadraft-email'] ) && is_string( $_POST['shareadraft-email'] )
			? sanitize_email( wp_unslash( $_POST['shareadraft-email'] ) )
			: '';

		if ( $nonce_ok && 'request-code' === $action && false !== is_email( $email ) ) {
			// Only a listed reviewer on a live link generates mail; everyone
			// gets the identical next page — and in the same time, because the
			// send happens after this response has gone out (see queue_code()).
			if ( $this->service->is_recipient( $this->denied_post_id, $token, $email ) ) {
				$this->verifier->queue_code( $token, $email );
			}

			$this->render_code_form( $email );
		}

		if ( $nonce_ok && 'verify-code' === $action ) {
			$code = isset( $_POST['shareadraft-code'] ) && is_string( $_POST['shareadraft-code'] )
				? sanitize_text_field( wp_unslash( $_POST['shareadraft-code'] ) )
				: '';

			if ( '' !== $email && '' !== $code && $this->verifier->verify_code( $token, $email, $code ) ) {
				$this->verifier->remember_verified( $token, $email );

				// Redirect back to the same preview URL as a GET, so the page
				// loads with the fresh cookie and a refresh cannot re-post.
				// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REQUEST_URI__ -- Uncached preview request (unique token query string + nocache headers); redirecting to the URL just requested.
				$target = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
					? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
					: home_url( '/' );
				wp_safe_redirect( $target, 303 );
				exit;
			}

			$this->render_code_form(
				$email,
				__( 'That code did not match or has expired. Check it, or reload this page to request a new one.', 'shareadraft' )
			);
		}

		$this->render_email_form();
	}

	/**
	 * Step one: ask which address the visitor claims to be. Served with a 200 —
	 * this is the page working as designed, not an error. Ends the request.
	 */
	private function render_email_form(): void {
		$html = sprintf(
			'<p>%s</p><form method="post">%s<input type="hidden" name="shareadraft-verify-action" value="request-code" /><p><label for="shareadraft-email">%s</label><input type="email" name="shareadraft-email" id="shareadraft-email" required autocomplete="email" /></p><p><button type="submit" class="button-primary">%s</button></p></form>',
			esc_html__( 'This preview is for named reviewers. Enter your email address and, if it is on the reviewer list, we will send you a verification code.', 'shareadraft' ),
			wp_nonce_field( 'shareadraft_verify', '_wpnonce', false, false ),
			esc_html__( 'Email address', 'shareadraft' ),
			esc_html__( 'Email me a code', 'shareadraft' )
		);

		NoticePage::render( __( 'Verify your email', 'shareadraft' ), $html, 200 );
	}

	/**
	 * Step two: swap the emailed code for access. The copy stays neutral about
	 * whether mail was actually sent — see {@see PreviewGate::handle_verification()}.
	 * Ends the request.
	 */
	private function render_code_form( string $email, string $error = '' ): void {
		$html = sprintf(
			'<p>%s</p>%s<form method="post">%s<input type="hidden" name="shareadraft-verify-action" value="verify-code" /><input type="hidden" name="shareadraft-email" value="%s" /><p><label for="shareadraft-code">%s</label><input type="text" name="shareadraft-code" id="shareadraft-code" class="shareadraft-code" required inputmode="numeric" autocomplete="one-time-code" maxlength="6" /></p><p><button type="submit" class="button-primary">%s</button></p></form>',
			esc_html__( 'If that address is on the reviewer list, we have emailed it a verification code. Enter the code below.', 'shareadraft' ),
			'' === $error ? '' : sprintf( '<p role="alert"><strong>%s</strong></p>', esc_html( $error ) ),
			wp_nonce_field( 'shareadraft_verify', '_wpnonce', false, false ),
			esc_attr( $email ),
			esc_html__( 'Verification code', 'shareadraft' ),
			esc_html__( 'Verify', 'shareadraft' )
		);

		NoticePage::render( __( 'Verify your email', 'shareadraft' ), $html, 200 );
	}

	/**
	 * Keep an unlocked draft out of caches, search indexes, and Referer headers.
	 *
	 * The gate hands non-public content to an anonymous visitor, so none of the
	 * defaults WordPress applies to a published post are right here. Without
	 * `X-Robots-Tag` a crawler that finds a shared URL can index the draft;
	 * without the referrer policy the token itself leaks in the Referer of every
	 * cross-origin asset the page loads.
	 */
	private function send_preview_headers(): void {
		add_filter( 'wp_robots', 'wp_robots_no_robots' );

		if ( headers_sent() ) {
			return;
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true );
		header( 'Referrer-Policy: no-referrer', true );
	}

	/**
	 * The raw token from the request URL, or null if absent. It is a bearer secret,
	 * not a form submission, so there is no nonce to verify: validity is proven by
	 * matching the stored hash, and access is read-only.
	 */
	private function token_from_request(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bearer preview token, validated by hash match; no state change.
		if ( ! isset( $_GET[ self::TOKEN_QUERY_VAR ] ) || ! is_scalar( $_GET[ self::TOKEN_QUERY_VAR ] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bearer preview token, validated by hash match; no state change.
		$raw = sanitize_text_field( wp_unslash( (string) $_GET[ self::TOKEN_QUERY_VAR ] ) );

		return '' === $raw ? null : $raw;
	}

	/**
	 * The visitor's true client IP, or null when it cannot be resolved (which
	 * fails closed for any link carrying an IP allowlist).
	 *
	 * `REMOTE_ADDR` is used because on the VIP platform the edge rewrites it to
	 * the true client address before PHP runs — it is the trusted source there,
	 * where `X-Forwarded-For` is attacker-appendable and must not be read. A
	 * host behind a different reverse proxy (where `REMOTE_ADDR` is the proxy)
	 * can supply its own trusted source through the filter.
	 */
	private function client_ip(): ?string {
		// phpcs:disable WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- On VIP the edge rewrites REMOTE_ADDR to the true client IP (unlike X-Forwarded-For it is not client-spoofable there), the value is validated with FILTER_VALIDATE_IP below, and preview requests are never page-cached (unique token query string + nocache headers).
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		// phpcs:enable

		/**
		 * Filters the client IP checked against a preview link's IP allowlist.
		 *
		 * Defaults to `REMOTE_ADDR`, which is correct on WordPress VIP and on any
		 * host where PHP talks directly to the client. A site behind another
		 * reverse proxy should return the address from its proxy's trusted
		 * header here — and never a raw `X-Forwarded-For`, which visitors can
		 * spoof.
		 *
		 * @param string $remote_addr The client IP, or '' if unknown.
		 */
		$ip = apply_filters( 'shareadraft_client_ip', $remote_addr );

		if ( ! is_string( $ip ) || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		return $ip;
	}

	/**
	 * The cookie that carries a slot ID for this link.
	 *
	 * The *name* is derived from the token hash so a browser can hold slots on
	 * several links at once; it is deliberately not a secret, since the browser
	 * has to know which cookie to send. The security lives in the value.
	 */
	private function cookie_name( Token $token ): string {
		return self::COOKIE_PREFIX . substr( $token->hash(), 0, 20 );
	}

	/**
	 * The slot ID this visitor presented, or null.
	 *
	 * Whether it is genuine is not decided here: the link itself is the authority
	 * (see {@see PreviewLink::holds_slot()}), so a forged value simply fails to
	 * match and the visitor is treated as new.
	 */
	private function viewer_id_from_request( Token $token ): ?string {
		$name = $this->cookie_name( $token );

		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Preview requests are never page-cached (unique token query string + preview=true, and we send nocache headers), so reading a per-viewer cookie is reliable.
		if ( ! isset( $_COOKIE[ $name ] ) ) {
			return null;
		}

		// A visitor controls their own cookies, and a name ending in `[]` makes
		// PHP hand back an array, so this is not guaranteed to be a string.
		/** @var mixed $raw_cookie */
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read raw on purpose so the type can be checked first; sanitized three lines below.
		$raw_cookie = $_COOKIE[ $name ];

		if ( ! is_string( $raw_cookie ) ) {
			return null;
		}

		$raw = sanitize_text_field( wp_unslash( $raw_cookie ) );

		// Slot IDs are hex, so anything else is junk and not worth comparing.
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $raw ) ? $raw : null;
	}

	/**
	 * Hand the slot ID back to this browser so a return visit is recognised.
	 * Scoped to a week, comfortably longer than the longest offered lifetime.
	 */
	private function remember_viewer( Token $token, string $viewer_id ): void {
		$name = $this->cookie_name( $token );

		if ( ! headers_sent() ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- Preview requests carry a unique token query string and preview=true and are sent with nocache headers, so they are never page-cached; per-viewer cookie logic is reliable here.
			setcookie(
				$name,
				$viewer_id,
				[
					'expires'  => time() + WEEK_IN_SECONDS,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				]
			);
		}

		// Reflect it immediately so a second query in this request sees it.
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Uncached preview request; see above.
		$_COOKIE[ $name ] = $viewer_id;
	}

	/**
	 * Whether the request looks like a crawler or chat-link unfurler.
	 *
	 * These are served a stub rather than the draft, so an unknown or absent user
	 * agent is treated as automated: withholding content from an odd-looking
	 * client is the safe way to be wrong. Because the stub is all a "crawler"
	 * gets, there is nothing to win by spoofing one of these strings.
	 */
	private function is_automated_client(): bool {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- Only read on uncached preview requests, to decide whether to serve the draft or a stub.
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( '' === $user_agent ) {
			return true;
		}

		$default_pattern = '/bot|crawler|spider|slack|facebookexternalhit|whatsapp|telegram|discord|twitterbot|linkedinbot|embedly|preview|feedfetcher|pinterest/i';

		/**
		 * Filters the user-agent pattern used to spot crawlers and link unfurlers,
		 * which are served a contentless stub instead of the draft.
		 *
		 * @param string $default_pattern Regular expression tested against the UA string.
		 */
		$pattern = apply_filters( 'shareadraft_bot_user_agent_pattern', $default_pattern );

		if ( ! is_string( $pattern ) || '' === $pattern ) {
			return false;
		}

		return 1 === preg_match( $pattern, $user_agent );
	}
}
