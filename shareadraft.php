<?php
/*
Plugin Name: Share a Draft
Plugin URI: http://wordpress.org/plugins/shareadraft/
Description: Share private preview links to your drafts
Author: Nikolay Bachiyski, Automattic
Version: 1.7
Author URI: https://extrapolate.me/
Text Domain: shareadraft
Domain Path: /languages
*/

if ( ! class_exists( 'Share_a_Draft' ) ) :
	class Share_a_Draft {
		var $admin_options_name = 'ShareADraft_options';
		var $shared_post = null;
		var $admin_options = array();
		var $user_options = array();

		function __construct() {
			add_action( 'init', array( $this, 'init' ) );
		}

		function init() {
			global $current_user;
			add_action( 'admin_menu', array( $this, 'add_admin_pages' ) );
			add_filter( 'the_posts', array( $this, 'the_posts_intercept' ), 10, 2 );
			add_filter( 'posts_results', array( $this, 'posts_results_intercept' ), 10, 2 );

			$this->admin_options = $this->get_admin_options();
			$this->admin_options = $this->clear_expired( $this->admin_options );
			$this->user_options = array();
			if ( $current_user->ID > 0 && isset( $this->admin_options[ $current_user->ID ] ) ) {
				$this->user_options = $this->admin_options[ $current_user->ID ];
			}
			$this->save_admin_options();
			load_plugin_textdomain( 'shareadraft', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

			if ( isset( $_GET['page'] ) && $_GET['page'] === plugin_basename( __FILE__ ) ) {
				$this->admin_page_init();
			}
		}

		function admin_page_init() {
			wp_enqueue_script( 'jquery' );
			wp_enqueue_script( 'wp-a11y' );
			add_action( 'admin_head', array( $this, 'print_admin_css' ) );
			add_action( 'admin_head', array( $this, 'print_admin_js' ) );
		}

		function get_admin_options() {
			$saved_options = get_option( $this->admin_options_name );
			return is_array( $saved_options )? $saved_options : array();
		}

		function save_admin_options() {
			global $current_user;
			if ( $current_user->ID > 0 ) {
				$this->admin_options[ $current_user->ID ] = $this->user_options;
			}
			update_option( $this->admin_options_name, $this->admin_options );
		}

		function clear_expired( $all_options ) {
			$all = array();
			// Orphan cleanup calls get_post() per share, so only run it in the
			// admin — not on every front-end request. Expired shares are always
			// dropped because can_view() relies on it to stop serving lapsed links.
			$prune_orphans = is_admin();
			foreach ( $all_options as $user_id => $options ) {
				$shared = array();
				if ( ! isset( $options['shared'] ) || ! is_array( $options['shared'] ) ) {
					continue;
				}
				foreach ( $options['shared'] as $share ) {
					if ( $share['expires'] < time() ) {
						continue;
					}
					// Drop shares whose post has since been deleted — a dead link.
					if ( $prune_orphans && ! get_post( $share['id'] ) ) {
						continue;
					}
					$shared[] = $share;
				}
				$options['shared'] = $shared;
				$all[ $user_id ] = $options;
			}
			return $all;
		}

		function add_admin_pages() {
			add_submenu_page( 'edit.php', __( 'Share a Draft', 'shareadraft' ), __( 'Share a Draft', 'shareadraft' ),
			'edit_posts', __FILE__, array( $this, 'output_existing_menu_sub_admin_page' ) );
		}

		function calculate_seconds( $params ) {
			$exp = 60;
			$multiply = 60;
			if ( isset( $params['expires'] ) && ( $e = intval( $params['expires'] ) ) ) {
				$exp = $e;
			}
			$mults = array(
				'm' => MINUTE_IN_SECONDS,
				'h' => HOUR_IN_SECONDS,
				'd' => DAY_IN_SECONDS,
				'w' => WEEK_IN_SECONDS,
			);
			if ( isset( $params['measure'] ) && isset( $mults[ $params['measure'] ] ) ) {
				$multiply = $mults[ $params['measure'] ];
			}
			return $exp * $multiply;
		}

		function process_new_share( $params ) {
			global $current_user;
			if ( isset( $params['post_id'] ) ) {
				$p = get_post( $params['post_id'] );
				if ( ! $p ) {
					return __( 'There is no such post!', 'shareadraft' );
				}
				if ( 'publish' === get_post_status( $p ) ) {
					return __( 'The post is published!', 'shareadraft' );
				}
				if ( ! current_user_can( 'edit_post', $p->ID ) ) {
					return __( 'Sorry, you are not allowed to share posts you can’t edit.', 'shareadraft' );
				}
				$this->user_options['shared'][] = array(
					'id' => $p->ID,
					'expires' => time() + $this->calculate_seconds( $params ),
					'key' => uniqid( 'baba' . $p->ID . '_' ),
				);
				$this->save_admin_options();
			}
		}

		function process_delete( $params ) {
			if ( ! isset( $params['key'] ) ||
			! isset( $this->user_options['shared'] ) ||
			! is_array( $this->user_options['shared'] ) ) {
				return '';
			}
			$shared = array();
			foreach ( $this->user_options['shared'] as $share ) {
				if ( $share['key'] === $params['key'] ) {
					if ( ! current_user_can( 'edit_post', $share['id'] ) ) {
						return __( 'Sorry, you are not allowed to share posts you can’t edit.', 'shareadraft' );
					}
					continue;
				}
				$shared[] = $share;
			}
			$this->user_options['shared'] = $shared;
			$this->save_admin_options();
		}

		function process_extend( $params ) {
			if ( ! isset( $params['key'] ) ||
			! isset( $this->user_options['shared'] ) ||
			! is_array( $this->user_options['shared'] ) ) {
				return '';
			}
			$shared = array();
			foreach ( $this->user_options['shared'] as $share ) {
				if ( $share['key'] === $params['key'] ) {
					if ( ! current_user_can( 'edit_post', $share['id'] ) ) {
						return __( 'Sorry, you are not allowed to share posts you can’t edit.', 'shareadraft' );
					}
					$share['expires'] += $this->calculate_seconds( $params );
				}
				$shared[] = $share;
			}
			$this->user_options['shared'] = $shared;
			$this->save_admin_options();
		}

		function get_drafts() {
			global $current_user;
			$unpublished_statuses = array( 'pending', 'draft', 'future', 'private' );
			$my_unpublished = get_posts( array(
				'post_status' => $unpublished_statuses,
				'author' => $current_user->ID,
				// some environments, like WordPress.com hook on those filters
				// for an extra caching layer
				'suppress_filters' => false,
			) );
			$others_unpublished = get_posts( array(
				'post_status' => $unpublished_statuses,
				'author' => -$current_user->ID,
				'suppress_filters' => false,
				'perm' => 'editable',
			) );
			$draft_groups = array(
			array(
				'label' => __( 'My unpublished posts:', 'shareadraft' ),
				'posts' => $my_unpublished,
			),
			array(
				'label' => __( 'Others’ unpubilshed posts:', 'shareadraft' ),
				'posts' => $others_unpublished,
			),
			);
			return $draft_groups;
		}

		function get_shared() {
			if ( ! isset( $this->user_options['shared'] ) || ! is_array( $this->user_options['shared'] ) ) {
				return array();
			}
			return $this->user_options['shared'];
		}

		function friendly_delta( $s ) {
			$m = (int) ( $s / MINUTE_IN_SECONDS );
			$h = (int) ( $s / HOUR_IN_SECONDS );
			$free_m = (int) ( ( $s - $h * HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );
			$d = (int) ( $s / DAY_IN_SECONDS );
			$free_h = (int) ( ( $s - $d * DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
			if ( $m < 1 ) {
				$res = array();
			} elseif ( $h < 1 ) {
				$res = array( $m );
			} elseif ( $d < 1 ) {
				$res = array( $free_m, $h );
			} else {
				$res = array( $free_m, $free_h, $d );
			}
			$names = array();
			// Skip zero-valued units, so we get "1 day" rather than "1 day, 0 hours, 0 minutes".
			if ( ! empty( $res[0] ) ) {
				$names[] = sprintf( _n( '%d minute', '%d minutes', $res[0], 'shareadraft' ), $res[0] );
			}
			if ( ! empty( $res[1] ) ) {
				$names[] = sprintf( _n( '%d hour', '%d hours', $res[1], 'shareadraft' ), $res[1] );
			}
			if ( ! empty( $res[2] ) ) {
				$names[] = sprintf( _n( '%d day', '%d days', $res[2], 'shareadraft' ), $res[2] );
			}
			if ( empty( $names ) ) {
				return __( 'less than a minute', 'shareadraft' );
			}
			return implode( ', ', array_reverse( $names ) );
		}

		function output_existing_menu_sub_admin_page() {
			$msg = '';
			if ( isset( $_POST['shareadraft_submit'] ) ) {
				check_admin_referer( 'shareadraft-new-share' );
				$msg = $this->process_new_share( $_POST );
			} elseif ( isset( $_POST['action'] ) && $_POST['action'] === 'extend' ) {
				check_admin_referer( 'shareadraft-extend' );
				$msg = $this->process_extend( $_POST );
			} elseif ( isset( $_GET['action'] ) && $_GET['action'] === 'delete' ) {
				check_admin_referer( 'shareadraft-delete' );
				$msg = $this->process_delete( $_GET );
			}
			$draft_groups = $this->get_drafts();
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php _e( 'Share a Draft', 'shareadraft' ); ?></h1>
		<a href="#" id="shareadraft-add-toggle" class="page-title-action" aria-expanded="false" aria-controls="shareadraft-add"><?php _e( 'Add draft link', 'shareadraft' ); ?></a>
		<hr class="wp-header-end">
<?php 	if ( $msg ) :?>
		<div id="message" class="updated fade"><?php echo $msg; ?></div>
<?php 	endif;?>
		<div id="shareadraft-add" class="shareadraft-add-section" style="display: none;">
		<h3><?php _e( 'Add draft link', 'shareadraft' ); ?></h3>
		<form id="shareadraft-share" action="" method="post">
		<p>
			<select id="shareadraft-postid" name="post_id">
			<option value=""><?php _e( 'Choose a draft', 'shareadraft' ); ?></option>
<?php
foreach ( $draft_groups as $draft_group ) :
	if ( $draft_group['posts'] ) :
?>
	<option value="" disabled="disabled"></option>
	<option value="" disabled="disabled"><?php echo $draft_group['label']; ?></option>
<?php
foreach ( $draft_group['posts'] as $draft ) :
	if ( empty( $draft->post_title ) ) {
		continue;
	}
?>
<option value="<?php echo $draft->ID?>"><?php echo esc_html( $draft->post_title ); ?></option>
<?php
		endforeach;
	endif;
		endforeach;
?>
			</select>
		</p>
		<p>
			<input type="submit" class="button" name="shareadraft_submit"
				value="<?php echo esc_attr__( 'Share it', 'shareadraft' ); ?>" />
			<?php _e( 'for', 'shareadraft' ); ?>
			<?php echo $this->tmpl_measure_select(); ?>
		</p>
		<?php wp_nonce_field( 'shareadraft-new-share' ); ?>
		</form>
		</div>
		<h3><?php _e( 'Shareable drafts', 'shareadraft' ); ?></h3>
		<table class="widefat">
			<thead>
			<tr>
				<th><?php _e( 'Post ID', 'shareadraft' ); ?></th>
				<th><?php _e( 'Title', 'shareadraft' ); ?></th>
				<th><?php _e( 'Link', 'shareadraft' ); ?></th>
				<th><?php _e( 'Expires in', 'shareadraft' ); ?></th>
				<th colspan="2" class="actions"><?php _e( 'Actions', 'shareadraft' ); ?></th>
			</tr>
			</thead>
			<tbody>
<?php
		$s = $this->get_shared();
foreach ( $s as $share ) :
	$p = get_post( $share['id'] );
	$friendly_delta = $this->friendly_delta( $share['expires'] - time() );
	$iso_expires = date_i18n( 'c', $share['expires'] );
	$delete_url = 'edit.php?page=' . plugin_basename( __FILE__ ) . '&action=delete&key=' . $share['key'];
	$nonced_delete_url = wp_nonce_url( $delete_url, 'shareadraft-delete' );
	// get_shared() has already pruned shares whose post was deleted; this is
	// just a safety net in case the post vanished mid-request.
	if ( ! $p ) {
		continue;
	}
	$url = get_bloginfo( 'url' ) . '/?p=' . $p->ID . '&shareadraft=' . $share['key'];
?>
<tr>
<td data-colname="<?php echo esc_attr__( 'Post ID', 'shareadraft' ); ?>"><?php echo $p->ID; ?></td>
<td data-colname="<?php echo esc_attr__( 'Title', 'shareadraft' ); ?>"><?php echo esc_html( $p->post_title ); ?></td>
<td data-colname="<?php echo esc_attr__( 'Link', 'shareadraft' ); ?>">
	<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $url ); ?></a>
	<a href="#" class="shareadraft-copy" data-shareadraft-url="<?php echo esc_url( $url ); ?>"
		title="<?php echo esc_attr__( 'Copy link to clipboard', 'shareadraft' ); ?>"
		aria-label="<?php echo esc_attr__( 'Copy link to clipboard', 'shareadraft' ); ?>"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg></a>
	<span class="shareadraft-copied"><?php esc_html_e( 'Copied!', 'shareadraft' ); ?></span>
</td>
<td data-colname="<?php echo esc_attr__( 'Expires in', 'shareadraft' ); ?>"><time title="<?php echo $iso_expires; ?>" datetime="<?php echo $iso_expires; ?>"><?php echo $friendly_delta; ?></time></td>
<td class="actions" data-colname="<?php echo esc_attr__( 'Actions', 'shareadraft' ); ?>">
	<a class="shareadraft-extend edit" id="shareadraft-extend-link-<?php echo $share['key']; ?>"
		href="javascript:shareadraft.toggle_extend( '<?php echo $share['key']; ?>' );">
			<?php _e( 'Extend', 'shareadraft' ); ?>
	</a>
</td>
<td class="actions">
	<a class="delete" href="<?php echo esc_url( $nonced_delete_url ); ?>"><?php _e( 'Delete', 'shareadraft' ); ?></a>
</td>
</tr>
<tr class="shareadraft-extend-row" id="shareadraft-extend-form-<?php echo $share['key']; ?>">
<td colspan="6">
	<form class="shareadraft-extend" action="" method="post">
		<input type="hidden" name="action" value="extend" />
		<input type="hidden" name="key" value="<?php echo $share['key']; ?>" />
		<label for="shareadraft-extend-expires-<?php echo $share['key']; ?>"><?php _e( 'Extend by', 'shareadraft' ); ?></label>
		<?php echo $this->tmpl_measure_select( 'shareadraft-extend-expires-' . $share['key'] ); ?>
		<input type="submit" class="button button-primary" name="shareadraft_extend_submit"
			value="<?php echo esc_attr__( 'Extend', 'shareadraft' ); ?>" />
		<a class="shareadraft-extend-cancel"
			href="javascript:shareadraft.cancel_extend( '<?php echo $share['key']; ?>' );">
			<?php _e( 'Cancel', 'shareadraft' ); ?>
		</a>
		<?php wp_nonce_field( 'shareadraft-extend' ); ?>
	</form>
</td>
</tr>
<?php
		endforeach;
if ( empty( $s ) ) :
?>
<tr>
<td colspan="6"><?php _e( 'No shared drafts!', 'shareadraft' ); ?></td>
</tr>
<?php
		endif;
?>
			</tbody>
		</table>
		</div>
<?php
		}

		function can_view( $post_id ) {
			if ( ! isset( $_GET['shareadraft'] ) || ! is_array( $this->admin_options ) ) {
				return false;
			}
			foreach ( $this->admin_options as $option ) {
				if ( ! is_array( $option ) || ! isset( $option['shared'] ) ) {
					continue;
				}
				$shares = $option['shared'];
				foreach ( $shares as $share ) {
					// Cast both sides: stored ids and ids coming back from the query
					// are not guaranteed to agree on int vs. string.
					if ( (int) $share['id'] === (int) $post_id && $share['key'] === $_GET['shareadraft'] ) {
						return true;
					}
				}
			}
			return false;
		}

		function posts_results_intercept( $posts, $query = null ) {
			// Only ever act on the main front-end query. Block themes run many
			// secondary queries (template parts, patterns) that must be left alone.
			if ( ! $query || ! $query->is_main_query() ) {
				return $posts;
			}
			if ( 1 !== count( $posts ) ) {
				return $posts;
			}
			$post = $posts[0];
			$status = get_post_status( $post );
			if ( 'publish' !== $status && $this->can_view( $post->ID ) ) {
				$this->shared_post = $post;
			}
			return $posts;
		}

		function the_posts_intercept( $posts, $query = null ) {
			// See posts_results_intercept(): never inject into secondary queries,
			// or the shared post leaks into template-part lookups and breaks them.
			if ( ! $query || ! $query->is_main_query() ) {
				return $posts;
			}
			if ( empty( $posts ) && ! is_null( $this->shared_post ) ) {
				return array( $this->shared_post );
			} else {
				$this->shared_post = null;
				return $posts;
			}
		}

		function tmpl_measure_select( $expires_id = '' ) {
			$mins = __( 'minutes', 'shareadraft' );
			$hours = __( 'hours', 'shareadraft' );
			$days = __( 'days', 'shareadraft' );
			$weeks = __( 'weeks', 'shareadraft' );
			$id_attr = $expires_id ? ' id="' . esc_attr( $expires_id ) . '"' : '';
			return <<<SELECT
			<input name="expires"$id_attr type="text" value="2" size="4"/>
			<select name="measure">
				<option value="m">$mins</option>
				<option value="h">$hours</option>
				<option value="d">$days</option>
				<option value="w" selected>$weeks</option>
			</select>
SELECT;
		}

		function print_admin_css() {
	?>
	<style type="text/css">
		a.shareadraft-extend, a.shareadraft-extend-cancel { display: none; }
		tr.shareadraft-extend-row { display: none; }
		tr.shareadraft-extend-row.is-open { display: table-row; }
		tr.shareadraft-extend-row td { background: #f6f7f7; padding: 12px 10px; }
		form.shareadraft-extend { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 0; }
		form.shareadraft-extend label { font-weight: 600; }
		th.actions, td.actions { text-align: center; }
		table.widefat td a { padding: 2px; }
		a.shareadraft-copy { text-decoration: none; vertical-align: middle; margin-left: 6px; }
		a.shareadraft-copy svg { vertical-align: middle; position: relative; top: -2px; }
		span.shareadraft-copied { margin-left: 6px; font-size: 11px; color: #268e26; visibility: hidden; }
		span.shareadraft-copied.is-visible { visibility: visible; }
		#shareadraft-share input, #shareadraft-share select { vertical-align: middle; }
		@media screen and (max-width: 782px) {
			/* Stack the shared-drafts table into labelled cards on small screens. */
			table.widefat { border: none; box-shadow: none; background: transparent; }
			table.widefat thead { display: none; }
			table.widefat tr { display: block; margin-bottom: 1em; border: 1px solid #c3c4c7; background: #fff; }
			table.widefat td { display: block; width: auto; text-align: left; border: none; border-bottom: 1px solid #f0f0f1; padding: 8px 10px; }
			table.widefat tr td:last-child { border-bottom: none; }
			table.widefat td.actions { text-align: left; }
			table.widefat td[data-colname]::before {
				content: attr(data-colname);
				display: block;
				font-weight: 600;
				margin-bottom: 2px;
			}
			table.widefat td a[href^="http"] { word-break: break-all; }
			a.shareadraft-copy { margin-left: 0; }
			/* Higher specificity than 'table.widefat tr { display: block }' above,
			   so the extend row stays collapsed until toggled open. */
			table.widefat tr.shareadraft-extend-row { display: none; }
			table.widefat tr.shareadraft-extend-row.is-open { display: block; }
		}
	</style>
	<?php
		}

		function print_admin_js() {
	?>
	<script type="text/javascript">
	//<![CDATA[
	( function( $ ) {
		$( function() {
			$( 'a.shareadraft-extend' ).show();
			$( 'a.shareadraft-extend-cancel' ).show();
			$( 'a.shareadraft-extend-cancel' ).css( 'display', 'inline' );

			$( document ).on( 'click', 'a.shareadraft-copy', function( e ) {
				e.preventDefault();
				var $link = $( this );
				var url = $link.attr( 'data-shareadraft-url' );
				var confirm = function() {
					var $msg = $link.siblings( 'span.shareadraft-copied' );
					$msg.addClass( 'is-visible' );
					if ( window.wp && window.wp.a11y && window.wp.a11y.speak ) {
						window.wp.a11y.speak( '<?php echo esc_js( __( 'Copied!', 'shareadraft' ) ); ?>' );
					}
					window.setTimeout( function() { $msg.removeClass( 'is-visible' ); }, 2000 );
				};
				if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
					window.navigator.clipboard.writeText( url ).then( confirm );
				} else {
					// Fallback for browsers without the async Clipboard API (e.g. non-HTTPS contexts).
					var $tmp = $( '<textarea>' ).val( url ).css( { position: 'fixed', top: 0, left: '-9999px' } ).appendTo( 'body' );
					$tmp[0].select();
					try { document.execCommand( 'copy' ); } catch ( err ) {}
					$tmp.remove();
					confirm();
				}
			} );

			$( '#shareadraft-add-toggle' ).on( 'click', function( e ) {
				e.preventDefault();
				var $toggle = $( this );
				var expanded = $toggle.attr( 'aria-expanded' ) === 'true';
				$toggle.attr( 'aria-expanded', expanded ? 'false' : 'true' );
				$( '#shareadraft-add' ).slideToggle( 'fast', function() {
					if ( ! expanded ) {
						$( '#shareadraft-postid' ).trigger( 'focus' );
					}
				} );
			} );
		} );
		window.shareadraft = {
			toggle_extend: function( key ) {
				$( '#shareadraft-extend-form-'+key ).addClass( 'is-open' );
				$( '#shareadraft-extend-link-'+key ).hide();
				$( '#shareadraft-extend-form-'+key+' input[name="expires"]' ).focus();
			},
			cancel_extend: function( key ) {
				$( '#shareadraft-extend-form-'+key ).removeClass( 'is-open' );
				$( '#shareadraft-extend-link-'+key ).show();
			}
		};
	} )( jQuery );
	//]]>
	</script>
	<?php
		}
	}
endif;

if ( class_exists( 'Share_a_Draft' ) ) {
	$__share_a_draft = new Share_a_Draft();
}
