<?php

namespace Automattic\ShareADraft;

/**
 * Postmeta-backed {@see TokenRepository}.
 *
 * Each issued link is one hidden postmeta row on its post, so a post can carry
 * several live links at once (mirroring how the editor lets an author generate
 * more than one). Lookups load a single post's meta (already cached by WordPress)
 * and match by hash, so there is no cross-post query on the request path.
 *
 * This is the only class that knows links live in postmeta. Swapping in a custom
 * table later means writing one more TokenRepository and changing a single line
 * in the composition root; the domain and its tests do not move.
 */
final class PostMetaTokenRepository implements TokenRepository {
	/**
	 * Hidden meta key (leading underscore) so links never show in the Custom
	 * Fields UI.
	 */
	public const META_KEY = '_shareadraft_token';

	/**
	 * Storage schema version.
	 *
	 * 1: a `use_count` integer.
	 * 2: a `viewers` list of opaque slot IDs, so slots are server-issued and
	 *    writes are idempotent. Version 1 rows are read as anonymous slots (see
	 *    {@see PostMetaTokenRepository::from_array()}). Version 2 rows may also
	 *    carry an optional `allowed_ips` list of CIDR strings and an optional
	 *    `recipients` list of lowercased emails, each omitted entirely when
	 *    empty (see {@see PostMetaTokenRepository::to_array()}); a row without
	 *    either key reads as unrestricted, so no version bump was needed.
	 */
	private const VERSION = 2;

	public function save( PreviewLink $link ): void {
		add_post_meta( $link->post_id(), self::META_KEY, $this->to_array( $link ) );
	}

	public function find( int $post_id, Token $candidate ): ?PreviewLink {
		foreach ( $this->all_for_post( $post_id ) as $link ) {
			if ( $link->matches( $candidate ) ) {
				return $link;
			}
		}

		return null;
	}

	public function all_for_post( int $post_id ): array {
		$links = [];

		$rows = get_post_meta( $post_id, self::META_KEY, false );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				/** @var array<string, mixed> $row */
				$links[] = $this->from_array( $post_id, $row );
			}
		}

		return $links;
	}

	/**
	 * Compare-and-swap the viewer onto the stored row.
	 *
	 * Passing the pre-read state as `$prev_value` makes WordPress emit
	 * `UPDATE ... WHERE meta_value = <old>`, which MySQL evaluates under a row
	 * lock. A concurrent request that already claimed the slot will have changed
	 * `meta_value`, so this update matches nothing and returns false — telling the
	 * caller to re-read rather than clobbering the winner's write.
	 */
	public function add_viewer( PreviewLink $link, string $viewer_id ): bool {
		if ( $link->holds_slot( $viewer_id ) ) {
			// Already holds a slot; nothing to persist.
			return true;
		}

		// update_post_meta() falls back to *adding* a row when the key has none
		// left, so a claim racing publish or trash cleanup could resurrect the
		// link it just deleted. Re-reading first closes most of that window; a
		// row-keyed conditional UPDATE would close it completely, which is the
		// case for moving this store to its own table.
		if ( null === $this->find_by_hash( $link->post_id(), $link->token_hash() ) ) {
			return false;
		}

		return (bool) update_post_meta(
			$link->post_id(),
			self::META_KEY,
			$this->to_array( $link->with_viewer( $viewer_id ) ),
			$this->to_array( $link )
		);
	}

	public function find_by_hash( int $post_id, string $token_hash ): ?PreviewLink {
		foreach ( $this->all_for_post( $post_id ) as $link ) {
			if ( hash_equals( $link->token_hash(), $token_hash ) ) {
				return $link;
			}
		}

		return null;
	}

	public function revoke( PreviewLink $link, int $revoked_at ): void {
		update_post_meta(
			$link->post_id(),
			self::META_KEY,
			$this->to_array( $link->with_revoked( $revoked_at ) ),
			$this->to_array( $link )
		);
	}

	public function revoke_all_for_post( int $post_id, int $revoked_at ): int {
		return $this->revoke_matching( $post_id, null, $revoked_at );
	}

	public function revoke_by_creator_for_post( int $post_id, int $created_by, int $revoked_at ): int {
		return $this->revoke_matching( $post_id, $created_by, $revoked_at );
	}

	/**
	 * Stamp `revoked_at` on this post's not-yet-revoked links, optionally only
	 * those a given user created. Each write is conditional on the pre-read row
	 * (see {@see add_viewer()}), so a link that changes concurrently is simply
	 * not counted rather than clobbered.
	 */
	private function revoke_matching( int $post_id, ?int $created_by, int $revoked_at ): int {
		$revoked = 0;

		foreach ( $this->all_for_post( $post_id ) as $link ) {
			if ( $link->is_revoked() ) {
				continue;
			}

			if ( null !== $created_by && $link->created_by() !== $created_by ) {
				continue;
			}

			$updated = update_post_meta(
				$post_id,
				self::META_KEY,
				$this->to_array( $link->with_revoked( $revoked_at ) ),
				$this->to_array( $link )
			);

			if ( false !== $updated ) {
				++$revoked;
			}
		}

		return $revoked;
	}

	public function delete_all_for_post( int $post_id ): void {
		delete_post_meta( $post_id, self::META_KEY );
	}

	public function delete_dead_for_post( int $post_id, int $dead_before, int $now ): int {
		$deleted = 0;

		foreach ( $this->all_for_post( $post_id ) as $link ) {
			$dead_since = $link->dead_since( $now );

			if ( null === $dead_since || $dead_since >= $dead_before ) {
				continue;
			}

			if ( delete_post_meta( $post_id, self::META_KEY, $this->to_array( $link ) ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	public function post_ids_with_links( int $after_post_id, int $limit ): array {
		/** @var \wpdb $wpdb */
		global $wpdb;

		/**
		 * Indexed on `meta_key` and never run on a page request — only from the
		 * garbage-collection cron, in bounded batches, walking a `post_id` cursor.
		 * Caching the result would be pointless (it changes as we delete) and
		 * harmful (it is a large, single-use list).
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched cron sweep over an indexed meta_key; see above.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT post_id FROM %i WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT %d',
				$wpdb->postmeta,
				self::META_KEY,
				$after_post_id,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	public function page_of_links( int $offset, int $limit, ?int $created_by = null ): array {
		/** @var \wpdb $wpdb */
		global $wpdb;

		/**
		 * Indexed on `meta_key` and run only from the editor-gated admin table,
		 * never on a page request. Not cached: it must reflect a revocation made
		 * moments earlier on the same screen, and each page is a large single-use
		 * read.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin audit table over an indexed meta_key; see above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- created_by_clause() returns a fragment prepared with its own placeholder.
				"SELECT post_id, meta_value FROM %i WHERE meta_key = %s{$this->created_by_clause( $created_by )} ORDER BY meta_id DESC LIMIT %d OFFSET %d", // @phpstan-ignore argument.type (The interpolated fragment is prepared with its own placeholder.)
				$wpdb->postmeta,
				self::META_KEY,
				$limit,
				$offset
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$links = [];

		foreach ( $rows as $row ) {
			$post_id = isset( $row['post_id'] ) && is_scalar( $row['post_id'] ) ? (int) $row['post_id'] : 0;
			$raw     = isset( $row['meta_value'] ) && is_string( $row['meta_value'] ) ? $row['meta_value'] : '';

			$stored = maybe_unserialize( $raw );

			if ( is_array( $stored ) ) {
				/** @var array<string, mixed> $stored */
				$links[] = $this->from_array( $post_id, $stored );
			}
		}

		return $links;
	}

	public function count_links( ?int $created_by = null ): int {
		/** @var \wpdb $wpdb */
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin audit table over an indexed meta_key; see page_of_links().
		$count = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- created_by_clause() returns a fragment prepared with its own placeholder.
				"SELECT COUNT(*) FROM %i WHERE meta_key = %s{$this->created_by_clause( $created_by )}", // @phpstan-ignore argument.type (The interpolated fragment is prepared with its own placeholder.)
				$wpdb->postmeta,
				self::META_KEY
			)
		);

		return (int) $count;
	}

	/**
	 * A prepared `AND meta_value LIKE ...` fragment matching links a given user
	 * created, or an empty string for no filter.
	 *
	 * `created_by` lives inside the serialised row, so this matches its exact
	 * serialised form (`"created_by";i:<id>;`) — a substring this class alone
	 * writes, via {@see to_array()}, always as an int. It is a stopgap the admin
	 * screen alone pays for; an indexed `created_by` column is the custom-table
	 * upgrade when scale demands it.
	 */
	private function created_by_clause( ?int $created_by ): string {
		if ( null === $created_by ) {
			return '';
		}

		/** @var \wpdb $wpdb */
		global $wpdb;

		return (string) $wpdb->prepare(
			' AND meta_value LIKE %s',
			'%' . $wpdb->esc_like( '"created_by";i:' . $created_by . ';' ) . '%'
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function to_array( PreviewLink $link ): array {
		$row = [
			'version'    => self::VERSION,
			'token_hash' => $link->token_hash(),
			'expires_at' => $link->expires_at(),
			'max_uses'   => $link->max_uses(),
			'created_by' => $link->created_by(),
			'created_at' => $link->created_at(),
			'viewers'    => $link->viewers(),
			'revoked_at' => $link->revoked_at(),
			'token_hint' => $link->token_hint(),
		];

		// Omitted (not stored as []) when empty, deliberately: the
		// compare-and-swap writes in add_viewer() and revoke() match on this
		// exact serialised array, so rows written before the key existed must
		// keep round-tripping byte-for-byte or they become unrevokable.
		if ( $link->has_ip_restriction() ) {
			$row['allowed_ips'] = $link->allowed_ips();
		}

		// Same omit-when-empty rule as allowed_ips, for the same CAS reason.
		if ( [] !== $link->recipients() ) {
			$row['recipients'] = $link->recipients();
		}

		return $row;
	}

	/**
	 * Rebuild a link from stored data, tolerating missing or malformed keys: a
	 * corrupt row must degrade to an unusable (expired-looking) link, never fatal.
	 *
	 * @param array<string, mixed> $row
	 */
	private function from_array( int $post_id, array $row ): PreviewLink {
		return new PreviewLink(
			$post_id,
			isset( $row['token_hash'] ) && is_string( $row['token_hash'] ) ? $row['token_hash'] : '',
			isset( $row['expires_at'] ) && is_numeric( $row['expires_at'] ) ? (int) $row['expires_at'] : 0,
			isset( $row['max_uses'] ) && is_numeric( $row['max_uses'] ) ? (int) $row['max_uses'] : null,
			isset( $row['created_by'] ) && is_numeric( $row['created_by'] ) ? (int) $row['created_by'] : 0,
			isset( $row['created_at'] ) && is_numeric( $row['created_at'] ) ? (int) $row['created_at'] : 0,
			$this->viewers_from_row( $row ),
			isset( $row['revoked_at'] ) && is_numeric( $row['revoked_at'] ) ? (int) $row['revoked_at'] : null,
			isset( $row['token_hint'] ) && is_string( $row['token_hint'] ) ? $row['token_hint'] : '',
			IpAllowlist::sanitize( $row['allowed_ips'] ?? [] ),
			$this->recipients_from_row( $row )
		);
	}

	/**
	 * The recipient emails on a stored link, tolerating junk: anything that is
	 * not a non-empty string is dropped, and casing is normalised so the
	 * case-insensitive match in {@see PreviewLink::is_recipient()} holds even
	 * for a row edited by hand.
	 *
	 * @param array<string, mixed> $row
	 * @return list<string>
	 */
	private function recipients_from_row( array $row ): array {
		if ( ! isset( $row['recipients'] ) || ! is_array( $row['recipients'] ) ) {
			return [];
		}

		$recipients = [];

		foreach ( $row['recipients'] as $recipient ) {
			if ( is_string( $recipient ) && '' !== $recipient ) {
				$recipients[] = strtolower( $recipient );
			}
		}

		return $recipients;
	}

	/**
	 * The slots held on a stored link.
	 *
	 * A version 1 row recorded only how many slots were spent, not who held them.
	 * Those are rebuilt as placeholders that no cookie can match: the cap is still
	 * honoured, and a viewer counted under the old scheme has to claim a fresh
	 * slot rather than inherit one. Stricter, which is the safe direction.
	 *
	 * @param array<string, mixed> $row
	 * @return list<string>
	 */
	private function viewers_from_row( array $row ): array {
		if ( isset( $row['viewers'] ) && is_array( $row['viewers'] ) ) {
			$viewers = [];

			foreach ( $row['viewers'] as $viewer ) {
				if ( is_string( $viewer ) && '' !== $viewer ) {
					$viewers[] = $viewer;
				}
			}

			return $viewers;
		}

		$legacy_count = isset( $row['use_count'] ) && is_numeric( $row['use_count'] ) ? max( 0, (int) $row['use_count'] ) : 0;
		$viewers      = [];

		for ( $index = 0; $index < $legacy_count; $index++ ) {
			$viewers[] = 'legacy-slot-' . $index;
		}

		return $viewers;
	}
}
