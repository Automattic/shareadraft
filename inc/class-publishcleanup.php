<?php

namespace Automattic\ShareADraft;

use WP_Post;

/**
 * Deletes a post's preview links once they stop making sense.
 *
 * Publishing makes them redundant: the post is public, so the links only clutter
 * storage. Trashing makes them dangerous: `trash` is a non-public status, so
 * without this the gate would happily keep unlocking a binned post for anyone
 * still holding a link. Authors reasonably read "move to bin" as "retract it",
 * and the links have to honour that.
 */
final class PublishCleanup {
	/**
	 * Statuses that end a post's preview-link life. Draft, pending, and future
	 * are all still work in progress, so their links survive.
	 */
	private const TERMINAL_STATUSES = [ 'publish', 'trash' ];

	private PreviewLinkService $service;

	public function __construct( PreviewLinkService $service ) {
		$this->service = $service;
	}

	public function register(): void {
		add_action( 'transition_post_status', [ $this, 'on_transition' ], 10, 3 );
	}

	/**
	 * @param string  $new_status The post's new status.
	 * @param string  $old_status The post's previous status.
	 * @param WP_Post $post       The post being transitioned.
	 */
	public function on_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( $new_status === $old_status ) {
			return;
		}

		if ( in_array( $new_status, self::TERMINAL_STATUSES, true ) ) {
			$this->service->discard_all( (int) $post->ID );
		}
	}
}
