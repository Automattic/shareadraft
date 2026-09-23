<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft;

use MockPHPMailer;
use WP_UnitTestCase;

/**
 * The emailed-code round trip: request a code, redeem it once, and prove the
 * guessing and re-request budgets hold.
 *
 * @covers \Automattic\ShareADraft\RecipientVerifier
 */
class RecipientVerifierTest extends WP_UnitTestCase {
	private const EMAIL = 'legal@example.com';

	private RecipientVerifier $verifier;
	private Token $token;

	public function set_up(): void {
		parent::set_up();

		$this->verifier = new RecipientVerifier();
		$this->token    = Token::generate();

		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		$_COOKIE = [];
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function test_a_sent_code_verifies_once_and_only_once(): void {
		static::assertTrue( $this->verifier->send_code( $this->token, self::EMAIL ) );

		$code = $this->sent_code();

		static::assertTrue( $this->verifier->verify_code( $this->token, self::EMAIL, $code ) );
		static::assertFalse(
			$this->verifier->verify_code( $this->token, self::EMAIL, $code ),
			'A redeemed code is void; replaying it must fail.'
		);
	}

	public function test_a_wrong_code_fails_without_voiding_the_right_one(): void {
		$this->verifier->send_code( $this->token, self::EMAIL );

		static::assertFalse( $this->verifier->verify_code( $this->token, self::EMAIL, '000000' ) );
		static::assertTrue( $this->verifier->verify_code( $this->token, self::EMAIL, $this->sent_code() ) );
	}

	public function test_guessing_is_bounded(): void {
		$this->verifier->send_code( $this->token, self::EMAIL );

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			static::assertFalse( $this->verifier->verify_code( $this->token, self::EMAIL, 'wrong' . $attempt ) );
		}

		static::assertFalse(
			$this->verifier->verify_code( $this->token, self::EMAIL, $this->sent_code() ),
			'Once the attempt budget is spent, even the right code is refused.'
		);
	}

	public function test_code_requests_are_rate_limited_per_address(): void {
		static::assertTrue( $this->verifier->send_code( $this->token, self::EMAIL ) );
		static::assertTrue( $this->verifier->send_code( $this->token, self::EMAIL ) );
		static::assertTrue( $this->verifier->send_code( $this->token, self::EMAIL ) );

		static::assertFalse(
			$this->verifier->send_code( $this->token, self::EMAIL ),
			'A fourth request inside the window sends nothing.'
		);
	}

	public function test_a_code_is_scoped_to_its_address(): void {
		$this->verifier->send_code( $this->token, self::EMAIL );

		static::assertFalse(
			$this->verifier->verify_code( $this->token, 'other@example.com', $this->sent_code() ),
			'A code emailed to one address proves nothing about another.'
		);
	}

	public function test_a_queued_code_is_sent_only_after_the_response(): void {
		$this->verifier->queue_code( $this->token, self::EMAIL );

		// Nothing may go out while the visitor could still be timing the
		// response: the whole point of queueing is that a listed and an
		// unlisted address answer the form in the same time.
		static::assertSame( [], self::mailer()->mock_sent );

		do_action( 'shutdown' );

		static::assertCount( 1, self::mailer()->mock_sent );
		static::assertTrue(
			$this->verifier->verify_code( $this->token, self::EMAIL, $this->sent_code() ),
			'The deferred send produces a redeemable challenge.'
		);
	}

	public function test_the_email_names_the_site_and_warns_against_sharing(): void {
		$this->verifier->send_code( $this->token, self::EMAIL );

		$body = self::sent_email()->body;

		// The two checks a reviewer can hold a phishing imitation against.
		static::assertStringContainsString( (string) wp_parse_url( home_url(), PHP_URL_HOST ), $body );
		static::assertStringContainsString( 'Never share it', $body );
	}

	public function test_the_email_never_contains_the_preview_url(): void {
		$this->verifier->send_code( $this->token, self::EMAIL );

		$email = self::sent_email();

		// The email is only a code: if it carried the link too, a forwarded or
		// scanned email would hand over both factors at once.
		static::assertStringNotContainsString( $this->token->value(), $email->body );
		static::assertStringNotContainsString( $this->token->value(), $email->subject );
	}

	public function test_the_verification_cookie_round_trips(): void {
		$_COOKIE = [];
		$this->verifier->remember_verified( $this->token, 'Legal@Example.com' );

		static::assertSame(
			'legal@example.com',
			$this->verifier->verified_email( $this->token ),
			'The proven address comes back lowercased.'
		);
	}

	public function test_a_verification_is_scoped_to_its_link(): void {
		$_COOKIE = [];
		$this->verifier->remember_verified( $this->token, self::EMAIL );

		static::assertNull(
			$this->verifier->verified_email( Token::generate() ),
			'Proving an address for one link says nothing about another.'
		);
	}

	public function test_a_tampered_cookie_reads_as_unverified(): void {
		$_COOKIE = [];
		$this->verifier->remember_verified( $this->token, self::EMAIL );

		$name = 'shareadraft_recipient_' . substr( $this->token->hash(), 0, 20 );

		// Swap the proven address for another and keep the rest: the HMAC no
		// longer covers the bytes presented, so the cookie is worthless.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Reading back a value the test itself just set.
		$cookie = $_COOKIE[ $name ] ?? null;
		static::assertIsString( $cookie );

		$parts    = explode( '.', $cookie );
		$parts[1] = rtrim( strtr( base64_encode( 'attacker@example.com' ), '+/', '-_' ), '=' );

		$_COOKIE[ $name ] = implode( '.', $parts );

		static::assertNull( $this->verifier->verified_email( $this->token ) );
	}

	/**
	 * The code from the most recently sent email, as a reviewer would read it.
	 */
	private function sent_code(): string {
		$body = self::sent_email( count( self::mailer()->mock_sent ) - 1 )->body;

		static::assertSame( 1, preg_match( '/\b([0-9]{6})\b/', $body, $matches ), 'The email carries a six-digit code.' );

		return $matches[1];
	}

	private static function mailer(): MockPHPMailer {
		$mailer = tests_retrieve_phpmailer_instance();
		static::assertInstanceOf( MockPHPMailer::class, $mailer );

		return $mailer;
	}

	/**
	 * @return object{subject: string, body: string}
	 */
	private static function sent_email( int $index = 0 ): object {
		$email = self::mailer()->get_sent( $index );
		static::assertNotFalse( $email, 'An email was sent.' );

		return $email;
	}
}
