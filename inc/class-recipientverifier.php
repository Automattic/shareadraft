<?php

namespace Automattic\ShareADraft;

/**
 * Proves a preview visitor controls one of a link's recipient addresses, via a
 * short emailed code.
 *
 * A code was chosen over an emailed magic link deliberately: corporate mail
 * scanners (Outlook SafeLinks and friends) prefetch links in inbound mail and
 * would consume a magic link before the human saw it, and a code is typed into
 * the browser that asked for it, so verification lands on the right device by
 * construction.
 *
 * The verifier never touches the link record. Challenges are keyed by the
 * token's hash and the address, stored hashed with a bounded number of
 * attempts; success is remembered in a signed, stateless cookie — an HMAC over
 * (token hash, email, expiry) under the site's auth salt, so there is no
 * session table and nothing here to garbage-collect beyond transient expiry.
 * The cookie only ever *identifies* the visitor's proven address; whether that
 * address may still view is re-decided by {@see AccessPolicy} on every
 * request, against the link's current recipient list, expiry, and revocation —
 * which is why the cookie's own lifetime can be a lazy constant rather than
 * tracking the link's.
 */
final class RecipientVerifier {
	/** Digits in a verification code. */
	private const CODE_DIGITS = 6;

	/** How long a sent code stays redeemable. */
	public const CODE_TTL = 10 * MINUTE_IN_SECONDS;

	/** Wrong guesses allowed before the challenge is voided. */
	private const MAX_ATTEMPTS = 5;

	/** Codes one address can request per window, so the form cannot spam a reviewer. */
	private const MAX_REQUESTS = 3;

	/** The window the request cap applies over. */
	private const REQUEST_WINDOW = 15 * MINUTE_IN_SECONDS;

	/**
	 * Verification cookie lifetime. A backstop only — comfortably longer than
	 * the longest offered link lifetime (matching the slot cookie's reasoning);
	 * the link's own expiry is enforced by the policy on every request.
	 */
	private const COOKIE_TTL = WEEK_IN_SECONDS;

	private const COOKIE_PREFIX    = 'shareadraft_recipient_';
	private const CHALLENGE_PREFIX = 'shareadraft_otp_';
	private const REQUESTS_PREFIX  = 'shareadraft_otp_req_';

	/**
	 * Email a fresh code for this link to the address, replacing any code still
	 * outstanding.
	 *
	 * The caller is responsible for only asking on behalf of a listed recipient
	 * (see {@see PreviewLinkService::is_recipient()}); this method's own guard
	 * is the per-address request cap. Returns false when the cap said no or the
	 * mail could not be handed off — callers must show the same neutral message
	 * either way, so the form never confirms which addresses are listed.
	 */
	/**
	 * Send a code, but only after the response has left for the client.
	 *
	 * The email form answers a listed and an unlisted address with identical
	 * copy, and an unlisted address sends nothing — but sending inline would
	 * still leak membership through response *time*, since only a listed
	 * address pays for the transient writes and the SMTP handoff. Deferring
	 * the send to shutdown, and flushing the response first where the SAPI
	 * allows it, makes both paths answer alike. It also means the reviewer
	 * sees the "code sent" page before the mail handshake rather than after.
	 */
	public function queue_code( Token $token, string $email ): void {
		add_action(
			'shutdown',
			function () use ( $token, $email ): void {
				// On PHP-FPM (the VIP platform among others) this flushes the
				// response and closes the connection, so the work below is
				// invisible to a visitor timing the form. Elsewhere the
				// deferral alone still narrows the gap to end-of-request work.
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
				}

				$this->send_code( $token, $email );
			}
		);
	}

	public function send_code( Token $token, string $email ): bool {
		$email = strtolower( $email );
		$now   = time();

		if ( ! $this->under_request_cap( $token, $email, $now ) ) {
			return false;
		}

		$code = str_pad( (string) random_int( 0, 10 ** self::CODE_DIGITS - 1 ), self::CODE_DIGITS, '0', STR_PAD_LEFT );

		set_transient(
			$this->challenge_key( $token, $email ),
			[
				// Hashed at rest, like the token itself: a peek at the options
				// table must not hand over a working code.
				'code_hash'  => $this->hmac( $code ),
				'attempts'   => 0,
				// Authoritative expiry lives in the payload; the transient TTL
				// is only storage cleanup, since re-saving the attempt counter
				// resets the TTL clock.
				'expires_at' => $now + self::CODE_TTL,
			],
			self::CODE_TTL
		);

		$subject = sprintf(
			/* translators: 1: site name, 2: the verification code. */
			__( '[%1$s] %2$s is your preview access code', 'shareadraft' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$code
		);

		// Naming the domain, and saying nobody will ever ask for the code, are
		// the two checks a reviewer can apply to a phishing imitation of this
		// email — which, unlike the real thing, has to carry a link somewhere.
		$message = sprintf(
			/* translators: 1: the verification code, 2: the site's domain, 3: number of minutes the code stays valid. */
			__(
				'Enter this code on %2$s to open the preview you were invited to review: %1$s

The code is valid for %3$d minutes and only works on the page where you requested it. Never share it with anyone — nobody legitimate will ask you for it. If you were not expecting this email, you can ignore it.',
				'shareadraft'
			),
			$code,
			(string) wp_parse_url( home_url(), PHP_URL_HOST ),
			self::CODE_TTL / MINUTE_IN_SECONDS
		);

		/**
		 * Filters the verification-code email before it is sent, for sites that
		 * want their own wording or branding. The code itself is embedded in
		 * both strings, so a callback replacing them wholesale must interpolate
		 * the passed code.
		 *
		 * @param array{subject: string, message: string} $mail  Subject and plain-text body.
		 * @param string                                  $email The recipient address.
		 * @param string                                  $code  The verification code.
		 */
		/** @var mixed $mail */
		$mail = apply_filters(
			'shareadraft_verification_email',
			[
				'subject' => $subject,
				'message' => $message,
			],
			$email,
			$code
		);

		if ( is_array( $mail ) && isset( $mail['subject'], $mail['message'] ) && is_string( $mail['subject'] ) && is_string( $mail['message'] ) ) {
			$subject = $mail['subject'];
			$message = $mail['message'];
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One short code email per human request, capped by the per-address window above; nothing bulk. On VIP wp_mail already routes through the platform's managed mail path.
		return wp_mail( $email, $subject, $message );
	}

	/**
	 * Redeem a code. True voids the challenge and means the visitor has proved
	 * control of the address; the caller should follow with
	 * {@see RecipientVerifier::remember_verified()}. A wrong guess burns one of
	 * a small number of attempts, after which the challenge is voided and the
	 * visitor must request a fresh code.
	 */
	public function verify_code( Token $token, string $email, string $code ): bool {
		$email = strtolower( $email );
		$key   = $this->challenge_key( $token, $email );

		$challenge = get_transient( $key );

		if (
			! is_array( $challenge )
			|| ! isset( $challenge['code_hash'], $challenge['attempts'], $challenge['expires_at'] )
			|| ! is_string( $challenge['code_hash'] )
			|| ! is_numeric( $challenge['attempts'] )
			|| ! is_numeric( $challenge['expires_at'] )
			|| time() >= (int) $challenge['expires_at']
		) {
			delete_transient( $key );

			return false;
		}

		if ( (int) $challenge['attempts'] >= self::MAX_ATTEMPTS ) {
			delete_transient( $key );

			return false;
		}

		if ( hash_equals( $challenge['code_hash'], $this->hmac( $code ) ) ) {
			delete_transient( $key );

			return true;
		}

		$challenge['attempts'] = (int) $challenge['attempts'] + 1;
		set_transient( $key, $challenge, self::CODE_TTL );

		return false;
	}

	/**
	 * Hand this browser the signed cookie that says "this visitor has proved
	 * control of this address, for this link".
	 */
	public function remember_verified( Token $token, string $email ): void {
		$email   = strtolower( $email );
		$expires = time() + self::COOKIE_TTL;
		$name    = $this->cookie_name( $token );
		$value   = implode(
			'.',
			[
				(string) $expires,
				$this->encode_email( $email ),
				$this->signature( $token, $email, $expires ),
			]
		);

		if ( ! headers_sent() ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- Preview requests carry a unique token query string and are sent with nocache headers, so they are never page-cached; see PreviewGate.
			setcookie(
				$name,
				$value,
				[
					'expires'  => $expires,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				]
			);
		}

		// Reflect it immediately so the redirect target of this same request
		// (or a second query) sees the verified state.
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Uncached preview request; see above.
		$_COOKIE[ $name ] = $value;
	}

	/**
	 * The address this browser has proved control of for this link, or null.
	 *
	 * Forgery is not possible without the site's auth salt: the value carries
	 * an HMAC over (token hash, email, expiry), verified in constant time.
	 * Whether the proven address is still on the link's recipient list is
	 * deliberately not decided here — that belongs to {@see AccessPolicy}, so
	 * removing a recipient locks them out on their next request.
	 */
	public function verified_email( Token $token ): ?string {
		$name = $this->cookie_name( $token );

		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Uncached preview request; see PreviewGate.
		if ( ! isset( $_COOKIE[ $name ] ) ) {
			return null;
		}

		// Read raw: the value is authenticated by HMAC below, and sanitising
		// first could alter the exact bytes the signature covers. A cookie
		// name ending in `[]` makes PHP hand back an array, hence the check.
		/** @var mixed $raw_cookie */
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- HMAC-verified before any use; a tampered value simply fails the signature.
		$raw_cookie = $_COOKIE[ $name ];

		if ( ! is_string( $raw_cookie ) ) {
			return null;
		}

		$parts = explode( '.', $raw_cookie );

		if ( 3 !== count( $parts ) ) {
			return null;
		}

		[ $expires_raw, $email_encoded, $signature ] = $parts;

		$expires = (int) $expires_raw;
		$email   = $this->decode_email( $email_encoded );

		if ( null === $email || time() >= $expires ) {
			return null;
		}

		if ( ! hash_equals( $this->signature( $token, $email, $expires ), $signature ) ) {
			return null;
		}

		return $email;
	}

	/**
	 * Whether the address may request another code right now. Counts within a
	 * fixed window whose start is stored in the payload, so re-saving the
	 * counter cannot stretch the window.
	 */
	private function under_request_cap( Token $token, string $email, int $now ): bool {
		$key = self::REQUESTS_PREFIX . $this->challenge_suffix( $token, $email );

		$window    = get_transient( $key );
		$count     = 0;
		$resets_at = $now + self::REQUEST_WINDOW;

		if (
			is_array( $window )
			&& isset( $window['count'], $window['resets_at'] )
			&& is_numeric( $window['count'] )
			&& is_numeric( $window['resets_at'] )
			&& $now < (int) $window['resets_at']
		) {
			$count     = (int) $window['count'];
			$resets_at = (int) $window['resets_at'];
		}

		if ( $count >= self::MAX_REQUESTS ) {
			return false;
		}

		set_transient(
			$key,
			[
				'count'     => $count + 1,
				'resets_at' => $resets_at,
			],
			self::REQUEST_WINDOW
		);

		return true;
	}

	private function challenge_key( Token $token, string $email ): string {
		return self::CHALLENGE_PREFIX . $this->challenge_suffix( $token, $email );
	}

	/**
	 * Keys a challenge to (link, address) without putting either in an option
	 * name: a hash prefix identifies the link (as the gate's cookie name does)
	 * and the address is hashed to a fixed, option-name-safe length.
	 */
	private function challenge_suffix( Token $token, string $email ): string {
		return substr( $token->hash(), 0, 20 ) . '_' . md5( $email );
	}

	/**
	 * Like the gate's slot cookie, the name identifies the link (so one browser
	 * can hold verifications for several links); the security lives in the
	 * signed value.
	 */
	private function cookie_name( Token $token ): string {
		return self::COOKIE_PREFIX . substr( $token->hash(), 0, 20 );
	}

	private function signature( Token $token, string $email, int $expires ): string {
		return $this->hmac( $token->hash() . '|' . $email . '|' . $expires );
	}

	private function hmac( string $data ): string {
		return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
	}

	/**
	 * Emails travel base64url-encoded inside the cookie so the `.` separators
	 * and `=` padding of the raw address cannot break parsing.
	 */
	private function encode_email( string $email ): string {
		return rtrim( strtr( base64_encode( $email ), '+/', '-_' ), '=' );
	}

	private function decode_email( string $encoded ): ?string {
		$decoded = base64_decode( strtr( $encoded, '-_', '+/' ), true );

		return false === $decoded || '' === $decoded ? null : $decoded;
	}
}
