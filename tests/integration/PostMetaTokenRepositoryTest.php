<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft;

use WP_UnitTestCase;

/**
 * The cross-post listing queries that back the site-wide admin table.
 *
 * @covers \Automattic\ShareADraft\PostMetaTokenRepository
 */
class PostMetaTokenRepositoryTest extends WP_UnitTestCase {
	private PostMetaTokenRepository $repository;

	public function set_up(): void {
		parent::set_up();

		$this->repository = new PostMetaTokenRepository();
	}

	public function test_counts_links_across_every_post(): void {
		$first  = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$second = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->save_link( $first, 'aaaa' );
		$this->save_link( $first, 'bbbb' );
		$this->save_link( $second, 'cccc' );

		static::assertSame( 3, $this->repository->count_links() );
	}

	public function test_pages_links_newest_first(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		// Saved oldest to newest; the table shows newest first (meta_id descending).
		$this->save_link( $post, 'aaaa' );
		$this->save_link( $post, 'bbbb' );
		$this->save_link( $post, 'cccc' );

		$first_page = $this->repository->page_of_links( 0, 2 );

		static::assertCount( 2, $first_page );
		static::assertSame( 'cccc', $first_page[0]->token_hint() );
		static::assertSame( 'bbbb', $first_page[1]->token_hint() );

		$second_page = $this->repository->page_of_links( 2, 2 );

		static::assertCount( 1, $second_page );
		static::assertSame( 'aaaa', $second_page[0]->token_hint() );
	}

	public function test_hydrates_the_stored_fields(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->repository->save(
			new PreviewLink( $post, 'a-hash', 2000, 5, 7, 1000, [], null, 'ab12' )
		);

		$links = $this->repository->page_of_links( 0, 10 );

		static::assertCount( 1, $links );
		static::assertSame( $post, $links[0]->post_id() );
		static::assertSame( 'a-hash', $links[0]->token_hash() );
		static::assertSame( 5, $links[0]->max_uses() );
		static::assertSame( 7, $links[0]->created_by() );
		static::assertSame( 'ab12', $links[0]->token_hint() );
	}

	public function test_round_trips_the_ip_allowlist(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->repository->save(
			new PreviewLink( $post, 'a-hash', 2000, null, 1, 1000, [], null, 'ab12', [ '203.0.113.0/24', '2001:db8::/32' ] )
		);

		static::assertSame(
			[ '203.0.113.0/24', '2001:db8::/32' ],
			$this->repository->all_for_post( $post )[0]->allowed_ips()
		);
	}

	public function test_a_row_stored_before_the_allowlist_existed_is_still_revocable(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		// A pre-allowlist row has no allowed_ips key at all. The revoke write is
		// a compare-and-swap against the exact stored array, so the rebuilt link
		// must serialise back to the same bytes or the revoke silently no-ops.
		add_post_meta(
			$post,
			PostMetaTokenRepository::META_KEY,
			[
				'version'    => 2,
				'token_hash' => 'legacy-hash',
				'expires_at' => time() + HOUR_IN_SECONDS,
				'max_uses'   => null,
				'created_by' => 1,
				'created_at' => time() - HOUR_IN_SECONDS,
				'viewers'    => [],
				'revoked_at' => null,
				'token_hint' => 'ab12',
			]
		);

		$link = $this->repository->find_by_hash( $post, 'legacy-hash' );
		static::assertNotNull( $link );
		static::assertSame( [], $link->allowed_ips() );

		$this->repository->revoke( $link, time() );

		$reloaded = $this->repository->find_by_hash( $post, 'legacy-hash' );
		static::assertNotNull( $reloaded );
		static::assertTrue( $reloaded->is_revoked() );
	}

	public function test_revokes_every_live_link_on_a_post(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->save_link( $post, 'aaaa' );
		$this->save_link( $post, 'bbbb' );

		static::assertSame( 2, $this->repository->revoke_all_for_post( $post, 1234 ) );

		foreach ( $this->repository->all_for_post( $post ) as $link ) {
			static::assertSame( 1234, $link->revoked_at() );
		}

		// Idempotent: already-revoked links are not counted again.
		static::assertSame( 0, $this->repository->revoke_all_for_post( $post, 5678 ) );
	}

	public function test_revokes_only_one_creators_links_on_a_post(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->save_link( $post, 'aaaa', 7 );
		$this->save_link( $post, 'bbbb', 8 );

		static::assertSame( 1, $this->repository->revoke_by_creator_for_post( $post, 7, 1234 ) );

		foreach ( $this->repository->all_for_post( $post ) as $link ) {
			static::assertSame( 7 === $link->created_by(), $link->is_revoked() );
		}
	}

	/**
	 * The creator filter matches against the serialised row in SQL, so prove it
	 * against real postmeta — including that user 1 does not match user 11.
	 */
	public function test_filters_the_listing_by_creator(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->save_link( $post, 'aaaa', 1 );
		$this->save_link( $post, 'bbbb', 11 );
		$this->save_link( $post, 'cccc', 11 );

		static::assertSame( 1, $this->repository->count_links( 1 ) );
		static::assertSame( 2, $this->repository->count_links( 11 ) );
		static::assertSame( 3, $this->repository->count_links() );

		$links = $this->repository->page_of_links( 0, 10, 11 );

		static::assertCount( 2, $links );
		foreach ( $links as $link ) {
			static::assertSame( 11, $link->created_by() );
		}
	}

	public function test_is_empty_without_links(): void {
		static::assertSame( 0, $this->repository->count_links() );
		static::assertSame( [], $this->repository->page_of_links( 0, 10 ) );
	}

	public function test_recipients_round_trip_and_survive_a_revoke(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = Token::generate();

		$this->repository->save(
			PreviewLink::issue( $post_id, $token, time() + HOUR_IN_SECONDS, null, 1, time(), [], [ 'legal@example.com' ] )
		);

		$stored = $this->repository->find( $post_id, $token );
		static::assertNotNull( $stored );
		static::assertSame( [ 'legal@example.com' ], $stored->recipients() );

		// The revoke write is a compare-and-swap on the serialised row, so the
		// recipients key has to round-trip byte-for-byte for it to match.
		$this->repository->revoke( $stored, time() );

		$revoked = $this->repository->find( $post_id, $token );
		static::assertNotNull( $revoked );
		static::assertTrue( $revoked->is_revoked() );
		static::assertSame( [ 'legal@example.com' ], $revoked->recipients() );
	}

	private function save_link( int $post_id, string $hint, int $created_by = 1 ): void {
		$this->repository->save(
			new PreviewLink(
				$post_id,
				Token::generate()->hash(),
				time() + HOUR_IN_SECONDS,
				null,
				$created_by,
				time(),
				[],
				null,
				$hint
			)
		);
	}
}
