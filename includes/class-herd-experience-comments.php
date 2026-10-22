<?php
/**
 * Disable comments.
 *
 * @package HerdExperience
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turn off comments, pings, and their UI while keeping Block Notes working.
 *
 * Whether comments are disabled is decided per site:
 *
 * - The HERD_EXPERIENCE_DISABLE_COMMENTS constant, when defined, wins.
 * - Otherwise the site option: `yes` (disabled), `no` (allowed), or
 *   `default`, which follows the network option on multisite.
 * - Both options default to disabled.
 *
 * Block Notes (WordPress 6.9+) are comments of type `note` that use the
 * edit_post capability, so they are allowed through every filter here.
 */
final class Herd_Experience_Comments {
	/**
	 * Network option (multisite): `yes` or `no`.
	 */
	const NETWORK_OPTION = 'herd_experience_disable_comments_network';

	/**
	 * Site option: `default`, `yes`, or `no`.
	 */
	const SITE_OPTION = 'herd_experience_disable_comments';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		// Late enough for themes and plugins to filter herd_experience_disable_comments.
		add_action( 'after_setup_theme', array( __CLASS__, 'maybe_disable' ), 1 );
	}

	/**
	 * Register the suppression hooks when comments are disabled on the current site.
	 *
	 * @return void
	 */
	public static function maybe_disable() {
		if ( ! self::is_disabled() ) {
			return;
		}

		// Late, so post types registered by themes and plugins are included.
		add_action( 'init', array( __CLASS__, 'remove_post_type_support' ), 100 );

		// Front end.
		add_filter( 'comments_open', '__return_false', 20 );
		add_filter( 'pings_open', '__return_false', 20 );
		add_filter( 'comments_array', array( __CLASS__, 'filter_comments_array' ), 20 );
		add_filter( 'comments_pre_query', array( __CLASS__, 'filter_comments_pre_query' ), 10, 2 );
		add_filter( 'feed_links_show_comments_feed', '__return_false' );
		add_filter( 'feed_links_extra_show_post_comments_feed', '__return_false' );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_comment_feed' ) );
		add_action( 'widgets_init', array( __CLASS__, 'remove_comment_widget' ), 1 );
		add_filter( 'allowed_block_types_all', array( __CLASS__, 'remove_comment_blocks' ), PHP_INT_MAX );

		// APIs.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'restrict_rest_comments' ), 10, 3 );
		add_filter( 'xmlrpc_methods', array( __CLASS__, 'remove_xmlrpc_pingback' ) );

		// Admin.
		add_action( 'admin_menu', array( __CLASS__, 'remove_menu_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'admin_init' ) );
		add_action( 'wp_before_admin_bar_render', array( __CLASS__, 'remove_admin_bar_node' ) );
		add_action( 'admin_head-index.php', array( __CLASS__, 'hide_glance_comment_count' ) );
		add_filter( 'manage_posts_columns', array( __CLASS__, 'remove_comments_column' ) );
		add_filter( 'manage_pages_columns', array( __CLASS__, 'remove_comments_column' ) );
	}

	/**
	 * Whether the constant overrides the settings.
	 *
	 * @return bool
	 */
	public static function is_locked() {
		return defined( 'HERD_EXPERIENCE_DISABLE_COMMENTS' );
	}

	/**
	 * Whether comments are disabled network-wide (always true off multisite).
	 *
	 * @return bool
	 */
	public static function is_network_disabled() {
		return ! is_multisite() || 'no' !== get_site_option( self::NETWORK_OPTION, 'yes' );
	}

	/**
	 * Get the current site's setting.
	 *
	 * @return string `default`, `yes`, or `no`. Single sites never return `default`.
	 */
	public static function get_site_setting() {
		$setting = get_option( self::SITE_OPTION, is_multisite() ? 'default' : 'yes' );

		if ( ! in_array( $setting, array( 'default', 'yes', 'no' ), true ) ) {
			$setting = 'default';
		}

		return ( 'default' === $setting && ! is_multisite() ) ? 'yes' : $setting;
	}

	/**
	 * Whether comments are disabled on the current site.
	 *
	 * @return bool
	 */
	public static function is_disabled() {
		if ( self::is_locked() ) {
			$disabled = (bool) HERD_EXPERIENCE_DISABLE_COMMENTS;
		} else {
			$setting  = self::get_site_setting();
			$disabled = 'default' === $setting ? self::is_network_disabled() : 'yes' === $setting;
		}

		/**
		 * Filters whether comments are disabled on the current site.
		 *
		 * @param bool $disabled Whether comments are disabled.
		 */
		return (bool) apply_filters( 'herd_experience_disable_comments', $disabled );
	}

	/**
	 * Comment types that keep working while comments are disabled.
	 *
	 * @return string[]
	 */
	public static function get_allowed_types() {
		/**
		 * Filters the comment types that bypass the disabled comments.
		 *
		 * @param string[] $types Comment types. Default `note` (Block Notes).
		 */
		$types = apply_filters( 'herd_experience_disable_comments_allowed_types', array( 'note' ) );

		return is_array( $types ) ? $types : array();
	}

	/**
	 * Remove comment and trackback support from every post type.
	 *
	 * @return void
	 */
	public static function remove_post_type_support() {
		foreach ( get_post_types() as $post_type ) {
			if ( post_type_supports( $post_type, 'comments' ) ) {
				remove_post_type_support( $post_type, 'comments' );
				remove_post_type_support( $post_type, 'trackbacks' );
			}
		}
	}

	/**
	 * Hide existing comments on the front end, keeping allowed types.
	 *
	 * @param WP_Comment[] $comments Comments for the post.
	 * @return WP_Comment[]
	 */
	public static function filter_comments_array( $comments ) {
		$allowed = self::get_allowed_types();

		return array_values(
			array_filter(
				(array) $comments,
				static function ( $comment ) use ( $allowed ) {
					return in_array( $comment->comment_type, $allowed, true );
				}
			)
		);
	}

	/**
	 * Short-circuit comment queries unless they ask for a specific type.
	 *
	 * Untyped queries (widgets, counts, the dashboard) return nothing. Queries
	 * that name an allowed type, or `comment` explicitly, still run so Block
	 * Notes and code that deliberately reads comments keep working.
	 *
	 * @param array|int|null   $comment_data Null to run the query.
	 * @param WP_Comment_Query $query        Query.
	 * @return array|int|null
	 */
	public static function filter_comments_pre_query( $comment_data, $query ) {
		$queryable = array_merge( self::get_allowed_types(), array( 'comment' ) );
		$requested = array();

		foreach ( array( 'type', 'type__in' ) as $var ) {
			if ( ! empty( $query->query_vars[ $var ] ) ) {
				$requested = array_merge( $requested, (array) $query->query_vars[ $var ] );
			}
		}

		if ( array_intersect( $requested, $queryable ) ) {
			return $comment_data;
		}

		return empty( $query->query_vars['count'] ) ? array() : 0;
	}

	/**
	 * Send comment feed requests to the homepage.
	 *
	 * @return void
	 */
	public static function redirect_comment_feed() {
		if ( is_comment_feed() ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
	}

	/**
	 * Unregister the Recent Comments widget.
	 *
	 * @return void
	 */
	public static function remove_comment_widget() {
		unregister_widget( 'WP_Widget_Recent_Comments' );
	}

	/**
	 * Remove the comment blocks from the inserter.
	 *
	 * @param bool|string[] $allowed Allowed block types, or true for all.
	 * @return bool|string[]
	 */
	public static function remove_comment_blocks( $allowed ) {
		if ( false === $allowed ) {
			return $allowed;
		}

		/**
		 * Filters the comment blocks to remove.
		 *
		 * @param string[] $blocks Block names.
		 */
		$disallowed = (array) apply_filters(
			'herd_experience_disable_comments_disallowed_blocks',
			array(
				'core/comment-author-name',
				'core/comment-content',
				'core/comment-date',
				'core/comment-edit-link',
				'core/comment-reply-link',
				'core/comment-template',
				'core/comments',
				'core/comments-pagination',
				'core/comments-pagination-next',
				'core/comments-pagination-numbers',
				'core/comments-pagination-previous',
				'core/comments-title',
				'core/post-comments',
				'core/post-comments-form',
				'core/latest-comments',
			)
		);

		if ( ! is_array( $allowed ) ) {
			$allowed = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );
		}

		return array_values( array_diff( $allowed, $disallowed ) );
	}

	/**
	 * Limit the REST comments endpoints to allowed types.
	 *
	 * The editor reads and writes Block Notes through /wp/v2/comments with
	 * `type=note`; every other request gets the same 404 as a missing route.
	 *
	 * @param mixed           $result  Response to replace the requested one with, or null.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request being dispatched.
	 * @return mixed
	 */
	public static function restrict_rest_comments( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result;
		}

		$route = untrailingslashit( $request->get_route() );

		if ( '/wp/v2/comments' !== $route && ! str_starts_with( $route, '/wp/v2/comments/' ) ) {
			return $result;
		}

		$type = $request->get_param( 'type' );

		// Updates and deletes of a single note don't send `type`, so check the stored comment.
		if ( preg_match( '#^/wp/v2/comments/(\d+)$#', $route, $matches ) ) {
			$comment = get_comment( (int) $matches[1] );
			$type    = $comment ? $comment->comment_type : null;
		}

		if ( in_array( $type, self::get_allowed_types(), true ) ) {
			return $result;
		}

		return new WP_Error(
			'rest_no_route',
			__( 'No route was found matching the URL and request method.', 'herd-experience' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Remove the pingback methods from XML-RPC.
	 *
	 * @param array $methods XML-RPC methods.
	 * @return array
	 */
	public static function remove_xmlrpc_pingback( $methods ) {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );

		return $methods;
	}

	/**
	 * Remove the Comments menu.
	 *
	 * @return void
	 */
	public static function remove_menu_page() {
		remove_menu_page( 'edit-comments.php' );
	}

	/**
	 * Block the comment screens and remove comment meta boxes and widgets.
	 *
	 * @return void
	 */
	public static function admin_init() {
		global $pagenow;

		if ( in_array( $pagenow, array( 'edit-comments.php', 'comment.php', 'options-discussion.php' ), true ) ) {
			wp_safe_redirect( admin_url() );
			exit;
		}

		remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );

		foreach ( get_post_types() as $post_type ) {
			remove_meta_box( 'commentsdiv', $post_type, 'normal' );
			remove_meta_box( 'commentstatusdiv', $post_type, 'normal' );
		}
	}

	/**
	 * Remove the Comments node from the admin bar.
	 *
	 * @return void
	 */
	public static function remove_admin_bar_node() {
		global $wp_admin_bar;

		$wp_admin_bar->remove_menu( 'comments' );
	}

	/**
	 * Hide the comment counts in the At a Glance dashboard widget.
	 *
	 * @return void
	 */
	public static function hide_glance_comment_count() {
		echo '<style>#dashboard_right_now .comment-count, #dashboard_right_now .comment-mod-count { display: none; }</style>';
	}

	/**
	 * Remove the comments column from post list tables.
	 *
	 * @param array $columns List table columns.
	 * @return array
	 */
	public static function remove_comments_column( $columns ) {
		unset( $columns['comments'] );

		return $columns;
	}
}
