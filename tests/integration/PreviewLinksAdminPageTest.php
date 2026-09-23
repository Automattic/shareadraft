<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft;

use WP_UnitTestCase;

/**
 * The revoke actions behind the site-wide admin table: a nonce-checked row
 * action and a nonce-checked bulk action, both writing revocation to the link.
 *
 * @covers \Automattic\ShareADraft\PreviewLinksAdminPage
 */
class PreviewLinksAdminPageTest extends WP_UnitTestCase {
	private PostMetaTokenRepository $repository;
	private PreviewLinkService $service;
	private PreviewLinksAdminPage $page;

	public function set_up(): void {
		parent::set_up();

		$this->repository = new PostMetaTokenRepository();
		$this->service    = new PreviewLinkService( $this->repository, new AccessPolicy(), new SystemClock() );
		$this->page       = new PreviewLinksAdminPage( $this->service, new SystemClock(), new BulkLinkRevoker( $this->service ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	public function tear_down(): void {
		unset(
			$_GET['action'],
			$_GET['post'],
			$_GET['token'],
			$_GET['creator'],
			$_GET['_wpnonce'],
			$_REQUEST['action'],
			$_REQUEST['_wpnonce'],
			$_POST['action'],
			$_POST['links'],
			$_POST['shareadraft_enabled'],
			$_POST['shareadraft_all'],
			$_POST['_wpnonce']
		);

		set_current_screen( 'front' );
		BulkLinkRevoker::unschedule();

		parent::tear_down();
	}

	public function test_an_ordinary_view_carries_no_revoke(): void {
		static::assertNull( $this->page->process_request() );
	}

	/**
	 * The per-row column shows only each link's own ranges, so the central
	 * baseline has to be stated once above the table — otherwise a table full
	 * of dashes reads as "no IP restrictions" on a site where the Dashboard
	 * restricts every link.
	 */
	public function test_central_ip_ranges_are_stated_above_the_table(): void {
		$page = new PreviewLinksAdminPage( $this->service, new SystemClock(), new BulkLinkRevoker( $this->service ), null, [ '203.0.113.0/24', '2001:db8::/32' ] );

		$output = $this->rendered( $page );

		static::assertStringContainsString( 'VIP Dashboard', $output );
		static::assertStringContainsString( '203.0.113.0/24', $output );
		static::assertStringContainsString( '2001:db8::/32', $output );
	}

	public function test_no_central_line_renders_without_configured_ranges(): void {
		static::assertStringNotContainsString( 'VIP Dashboard', $this->rendered( $this->page ) );
	}

	private function rendered( PreviewLinksAdminPage $page ): string {
		// The list table needs the admin screen machinery the test bootstrap
		// does not load by default.
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( PreviewLinksAdminPage::SCREEN_ID );

		ob_start();
		$page->render();

		return (string) ob_get_clean();
	}

	public function test_a_row_action_revokes_one_link(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, get_current_user_id() );
		$hash = $this->repository->all_for_post( $post_id )[0]->token_hash();

		$nonce                = wp_create_nonce( 'shareadraft_revoke_' . $post_id . '_' . $hash );
		$_GET['action']       = 'revoke';
		$_GET['post']         = (string) $post_id;
		$_GET['token']        = $hash;
		$_GET['_wpnonce']     = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		static::assertSame( 1, $this->page->process_request() );

		$link = $this->repository->find_by_hash( $post_id, $hash );
		static::assertNotNull( $link );
		static::assertTrue( $link->is_revoked() );
	}

	public function test_a_bulk_action_revokes_every_selected_link(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		$selected = [];
		foreach ( $this->repository->all_for_post( $post_id ) as $link ) {
			$selected[] = $post_id . ':' . $link->token_hash();
		}

		$nonce                = wp_create_nonce( 'bulk-' . PreviewLinksListTable::PLURAL );
		$_REQUEST['action']   = 'revoke';
		$_POST['action']      = 'revoke';
		$_POST['links']       = $selected;
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		static::assertSame( 2, $this->page->process_request() );

		foreach ( $this->repository->all_for_post( $post_id ) as $link ) {
			static::assertTrue( $link->is_revoked() );
		}
	}

	/**
	 * Select-all-across-pages on a creator-filtered view sweeps everything
	 * that person created, site-wide, within the table's own capability.
	 */
	public function test_select_all_on_a_creator_filter_revokes_everything_they_created(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 7 );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 8 );

		$this->submit_bulk_revoke( true );
		$_GET['creator'] = '7';

		static::assertSame( 1, $this->page->process_request() );

		foreach ( $this->repository->all_for_post( $post_id ) as $link ) {
			static::assertSame( 7 === $link->created_by(), $link->is_revoked() );
		}
	}

	public function test_unfiltered_select_all_revokes_every_link_for_an_administrator(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 7 );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 8 );

		$this->submit_bulk_revoke( true );

		static::assertSame( 2, $this->page->process_request() );

		foreach ( $this->repository->all_for_post( $post_id ) as $link ) {
			static::assertTrue( $link->is_revoked() );
		}
	}

	/**
	 * Unfiltered select-all is the break-glass "revoke everything", whose
	 * blast radius exceeds the table's own gate: editor is not enough.
	 */
	public function test_an_editor_cannot_select_all_links_site_wide(): void {
		$this->submit_bulk_revoke( true );

		$this->expectException( \WPDieException::class );

		$this->page->process_request();
	}

	/**
	 * Simulate the table's bulk-revoke submission, optionally upgraded by the
	 * "select all across pages" offer.
	 */
	private function submit_bulk_revoke( bool $select_all ): void {
		$nonce                = wp_create_nonce( 'bulk-' . PreviewLinksListTable::PLURAL );
		$_REQUEST['action']   = 'revoke';
		$_POST['action']      = 'revoke';
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		if ( $select_all ) {
			$_POST['shareadraft_all'] = '1';
		}
	}

	public function test_an_administrator_can_disable_and_enable_links(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$toggle = new LinkToggle();

		// The slider submits the new state: no checkbox means "disabled".
		$this->submit_toggle( false );
		static::assertSame( 'disabled', $this->page->process_toggle() );
		static::assertTrue( $toggle->is_disabled() );

		$this->submit_toggle( true );
		static::assertSame( 'enabled', $this->page->process_toggle() );
		static::assertFalse( $toggle->is_disabled() );
	}

	public function test_an_ordinary_view_carries_no_toggle(): void {
		static::assertNull( $this->page->process_toggle() );
	}

	/**
	 * The switch silently stops every link on the site working, so an editor's
	 * capability is not enough to flip it.
	 */
	public function test_an_editor_cannot_disable_links(): void {
		$this->submit_toggle( false );

		$this->expectException( \WPDieException::class );

		$this->page->process_toggle();
	}

	/**
	 * Simulate the toggle slider's form submission asking for the given state.
	 */
	private function submit_toggle( bool $enabled ): void {
		$nonce                = wp_create_nonce( 'shareadraft_toggle_links' );
		$_POST['action']      = 'toggle_links';
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		if ( $enabled ) {
			$_POST['shareadraft_enabled'] = '1';
		} else {
			unset( $_POST['shareadraft_enabled'] );
		}
	}
}
