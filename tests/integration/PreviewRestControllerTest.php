<?php
declare(strict_types = 1);

namespace Automattic\ShareADraft;

use Automattic\VIP\Telemetry\Telemetry as VIP_Telemetry;
use Spy_REST_Server;
use WP_REST_Request;
use WP_REST_Server;
use WP_Test_REST_TestCase;

/**
 * @covers \Automattic\ShareADraft\PreviewRestController
 */
class PreviewRestControllerTest extends WP_Test_REST_TestCase {
	private const ROUTE = '/' . PreviewRestController::NAMESPACE . PreviewRestController::ROUTE;

	/**
	 * @global WP_REST_Server|null $wp_rest_server
	 */
	public function setUp(): void {
		/** @var WP_REST_Server $wp_rest_server */
		global $wp_rest_server;

		parent::setUp();

		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$service = new PreviewLinkService(
			new PostMetaTokenRepository(),
			new AccessPolicy(),
			new SystemClock()
		);
		( new PreviewRestController( $service, new PreviewLinkMinter( $service ) ) )->register_routes();
	}

	/**
	 * @global WP_REST_Server|null $wp_rest_server
	 */
	public function tearDown(): void {
		/** @var WP_REST_Server $wp_rest_server */
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tearDown();
	}

	public function test_the_route_is_registered(): void {
		/** @var WP_REST_Server $wp_rest_server */
		global $wp_rest_server;

		static::assertArrayHasKey( self::ROUTE, $wp_rest_server->get_routes() );
	}

	public function test_an_editor_mints_a_link_carrying_a_token(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = $this->create_link( $post_id, 8 * HOUR_IN_SECONDS );

		static::assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();
		static::assertStringContainsString( 'preview=true', (string) $data['url'] );
		static::assertStringContainsString( PreviewGate::TOKEN_QUERY_VAR . '=', (string) $data['url'] );
		static::assertGreaterThan( time(), (int) $data['expires_at'] );
	}

	public function test_minting_records_a_tracks_event(): void {
		VIP_Telemetry::$events = [];

		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		static::assertSame( 200, $this->create_link( $post_id, 8 * HOUR_IN_SECONDS, 5 )->get_status() );

		static::assertCount( 1, VIP_Telemetry::$events );
		$event = VIP_Telemetry::$events[0];

		// Source token + event name resolve to `shareadraft_link_created`.
		static::assertSame( 'shareadraft_', $event['prefix'] );
		static::assertSame( 'link_created', $event['event'] );

		// Usage metadata only — never the token, content, or PII.
		static::assertSame( 8 * HOUR_IN_SECONDS, $event['properties']['expiration'] );
		static::assertTrue( $event['properties']['is_capped'] );
		static::assertSame( 5, $event['properties']['max_uses'] );
		static::assertSame( 'rest', $event['properties']['channel'], 'A REST-minted link is tagged with the rest channel.' );
		static::assertArrayNotHasKey( 'url', $event['properties'] );
		static::assertArrayNotHasKey( 'token', $event['properties'] );
	}

	public function test_minting_an_uncapped_link_reports_a_clean_integer(): void {
		VIP_Telemetry::$events = [];

		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		static::assertSame( 200, $this->create_link( $post_id, HOUR_IN_SECONDS )->get_status() );

		static::assertCount( 1, VIP_Telemetry::$events );
		$properties = VIP_Telemetry::$events[0]['properties'];

		// No cap: is_capped is false and max_uses is 0, never a null.
		static::assertFalse( $properties['is_capped'] );
		static::assertSame( 0, $properties['max_uses'] );
	}

	public function test_a_user_without_edit_rights_is_forbidden(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		static::assertSame( 403, $this->create_link( $post_id, HOUR_IN_SECONDS )->get_status() );
	}

	public function test_an_unlisted_expiration_is_rejected(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		// 42 seconds is not one of the offered options.
		static::assertSame( 400, $this->create_link( $post_id, 42 )->get_status() );
	}

	public function test_a_capped_link_is_accepted(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		static::assertSame( 200, $this->create_link( $post_id, HOUR_IN_SECONDS, 5 )->get_status() );
	}

	public function test_a_zero_use_cap_is_rejected(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		static::assertSame( 400, $this->create_link( $post_id, HOUR_IN_SECONDS, 0 )->get_status() );
	}

	public function test_a_link_can_carry_an_ip_allowlist(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = $this->create_link( $post_id, HOUR_IN_SECONDS, null, [ '203.0.113.0/24', '2001:db8::/32' ] );

		static::assertSame( 200, $response->get_status() );
		static::assertSame(
			[ '203.0.113.0/24', '2001:db8::/32' ],
			( new PostMetaTokenRepository() )->all_for_post( $post_id )[0]->allowed_ips()
		);
	}

	public function test_an_invalid_ip_range_is_rejected_not_silently_dropped(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = $this->create_link( $post_id, HOUR_IN_SECONDS, null, [ '203.0.113.0/24', 'office' ] );

		// The author must be told, or they would believe a restriction exists.
		static::assertSame( 400, $response->get_status() );
		static::assertCount( 0, ( new PostMetaTokenRepository() )->all_for_post( $post_id ) );
	}

	public function test_listing_includes_the_ip_allowlist(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->create_link( $post_id, HOUR_IN_SECONDS, null, [ '203.0.113.0/24' ] );

		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_query_params( [ 'post_id' => $post_id ] );
		$data = (array) rest_do_request( $request )->get_data();

		static::assertSame( [ '203.0.113.0/24' ], $data[0]['allowed_ips'] );
	}

	public function test_listing_returns_live_links_only(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->create_link( $post_id, HOUR_IN_SECONDS, 5 );

		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_query_params( [ 'post_id' => $post_id ] );
		$response = rest_do_request( $request );

		static::assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();
		static::assertCount( 1, $data );
		static::assertSame( 5, $data[0]['max_uses'] );
		static::assertSame( 0, $data[0]['use_count'] );
		static::assertArrayHasKey( 'id', $data[0] );
		static::assertSame( 4, strlen( (string) $data[0]['token_hint'] ), 'A 4-char token hint identifies the link.' );
	}

	public function test_expiration_options_are_filterable(): void {
		$callback = static fn (): array => [
			[
				'seconds' => 123,
				'label'   => 'Custom',
			],
		];
		add_filter( 'shareadraft_expiration_options', $callback );
		$options  = PreviewRestController::expiration_options();
		remove_filter( 'shareadraft_expiration_options', $callback );

		static::assertSame( 123, $options[0]['seconds'] );
	}

	public function test_default_expiration_is_filterable(): void {
		$callback = static fn (): int => 42;
		add_filter( 'shareadraft_default_expiration', $callback );
		$default  = PreviewRestController::default_expiration();
		remove_filter( 'shareadraft_default_expiration', $callback );

		static::assertSame( 42, $default );
	}

	public function test_a_link_can_be_revoked_and_then_denied(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->create_link( $post_id, HOUR_IN_SECONDS );

		$id = ( new PostMetaTokenRepository() )->all_for_post( $post_id )[0]->token_hash();

		$revoke = new WP_REST_Request( 'DELETE', self::ROUTE . '/' . $id );
		$revoke->set_query_params( [ 'post_id' => $post_id ] );
		static::assertSame( 200, rest_do_request( $revoke )->get_status() );

		// It no longer appears in the live list.
		$list = new WP_REST_Request( 'GET', self::ROUTE );
		$list->set_query_params( [ 'post_id' => $post_id ] );
		static::assertCount( 0, (array) rest_do_request( $list )->get_data() );
	}

	public function test_revoking_an_unknown_link_is_a_404(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'DELETE', self::ROUTE . '/' . str_repeat( 'a', 64 ) );
		$request->set_query_params( [ 'post_id' => $post_id ] );

		static::assertSame( 404, rest_do_request( $request )->get_status() );
	}

	public function test_listing_is_forbidden_without_edit_rights(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_query_params( [ 'post_id' => $post_id ] );

		static::assertSame( 403, rest_do_request( $request )->get_status() );
	}

	/**
	 * @param list<string> $allowed_ips
	 */
	private function create_link( int $post_id, int $expiration, ?int $max_uses = null, array $allowed_ips = [], array $recipients = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_body_params(
			[
				'post_id'     => $post_id,
				'expiration'  => $expiration,
				'max_uses'    => $max_uses,
				'allowed_ips' => $allowed_ips,
				'recipients'  => $recipients,
			]
		);

		return rest_do_request( $request );
	}

	public function test_recipients_are_normalised_and_persisted(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = $this->create_link( $post_id, 8 * HOUR_IN_SECONDS, null, [], [ ' Legal@Example.COM ', 'agency@example.org' ] );

		static::assertSame( 200, $response->get_status() );
		static::assertSame(
			[ 'legal@example.com', 'agency@example.org' ],
			( new PostMetaTokenRepository() )->all_for_post( $post_id )[0]->recipients()
		);
	}

	public function test_an_invalid_recipient_is_rejected_not_dropped(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = $this->create_link( $post_id, 8 * HOUR_IN_SECONDS, null, [], [ 'not-an-email' ] );

		static::assertSame( 400, $response->get_status() );
		static::assertSame( [], ( new PostMetaTokenRepository() )->all_for_post( $post_id ) );
	}

	public function test_recipients_are_refused_while_the_feature_is_disabled(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		add_filter( 'shareadraft_recipients_enabled', '__return_false' );

		try {
			// Even with the argument gone from the schema, a hand-built request
			// can still smuggle the parameter in; the minter must say no.
			$response = $this->create_link( $post_id, 8 * HOUR_IN_SECONDS, null, [], [ 'legal@example.com' ] );
		} finally {
			remove_filter( 'shareadraft_recipients_enabled', '__return_false' );
		}

		static::assertSame( 400, $response->get_status() );
		static::assertSame( [], ( new PostMetaTokenRepository() )->all_for_post( $post_id ) );
	}

	public function test_ip_ranges_are_refused_while_the_feature_is_disabled(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		add_filter( 'shareadraft_ip_allowlist_enabled', '__return_false' );

		try {
			$response = $this->create_link( $post_id, 8 * HOUR_IN_SECONDS, null, [ '203.0.113.0/24' ] );
		} finally {
			remove_filter( 'shareadraft_ip_allowlist_enabled', '__return_false' );
		}

		static::assertSame( 400, $response->get_status() );
		static::assertSame( [], ( new PostMetaTokenRepository() )->all_for_post( $post_id ) );
	}
}
