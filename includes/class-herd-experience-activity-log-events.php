<?php
/**
 * Activity log events.
 *
 * @package HerdExperience
 */

defined( 'ABSPATH' ) || exit;

/**
 * Record user, role, option, theme, plugin, site, and content changes.
 *
 * Values are logged only for options; user changes record which fields
 * changed, not their contents.
 */
final class Herd_Experience_Activity_Log_Events {
	/**
	 * Users whose sign-in was already logged this request.
	 *
	 * @var int[]
	 */
	private static $logged_in = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		if ( ! Herd_Experience_Activity_Log::is_enabled() ) {
			return;
		}

		// Users.
		add_action( 'user_register', array( __CLASS__, 'user_register' ), 10, 2 );
		add_action( 'profile_update', array( __CLASS__, 'profile_update' ), 10, 3 );
		add_action( 'set_user_role', array( __CLASS__, 'set_user_role' ), 10, 3 );
		add_action( 'deleted_user', array( __CLASS__, 'deleted_user' ), 10, 3 );
		add_action( 'add_user_to_blog', array( __CLASS__, 'add_user_to_blog' ), 10, 3 );
		add_action( 'remove_user_from_blog', array( __CLASS__, 'remove_user_from_blog' ), 10, 2 );
		add_action( 'granted_super_admin', array( __CLASS__, 'granted_super_admin' ) );
		add_action( 'revoked_super_admin', array( __CLASS__, 'revoked_super_admin' ) );
		// MU Auth signs users in with wp_set_auth_cookie(), which never fires wp_login.
		add_action( 'set_auth_cookie', array( __CLASS__, 'set_auth_cookie' ), 10, 4 );

		// Sites.
		add_action( 'wp_initialize_site', array( __CLASS__, 'site_created' ), 100 );
		add_action( 'wp_delete_site', array( __CLASS__, 'site_deleted' ) );
		add_action( 'wp_update_site', array( __CLASS__, 'site_updated' ), 10, 2 );

		// Settings.
		add_action( 'updated_option', array( __CLASS__, 'updated_option' ), 10, 3 );
		add_action( 'added_option', array( __CLASS__, 'added_option' ), 10, 2 );
		add_action( 'update_site_option', array( __CLASS__, 'update_site_option' ), 10, 3 );

		// Code.
		add_action( 'activated_plugin', array( __CLASS__, 'activated_plugin' ), 10, 2 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'deactivated_plugin' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'switch_theme' ), 10, 3 );

		// Content.
		add_action( 'trashed_post', array( __CLASS__, 'trashed_post' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'untrashed_post' ) );
		add_action( 'delete_post', array( __CLASS__, 'delete_post' ), 10, 2 );
	}

	/**
	 * Describe a user as "login (#ID)".
	 *
	 * @param WP_User|int $user User or ID.
	 * @return string
	 */
	private static function describe_user( $user ) {
		$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );

		return $user ? sprintf( '%s (#%d)', $user->user_login, $user->ID ) : '#' . (int) $user;
	}

	/**
	 * Describe a site as its URL path, e.g. "/academics/".
	 *
	 * @param int $blog_id Site ID.
	 * @return string
	 */
	private static function describe_site( $blog_id ) {
		$site = get_site( $blog_id );

		return $site ? $site->domain . $site->path : '#' . (int) $blog_id;
	}

	/**
	 * Shorten a value for display.
	 *
	 * @param mixed $value Option value.
	 * @return string
	 */
	private static function format_value( $value ) {
		if ( is_bool( $value ) ) {
			$value = $value ? 'true' : 'false';
		} elseif ( ! is_scalar( $value ) ) {
			$value = (string) wp_json_encode( $value );
		}

		$value = (string) $value;

		return strlen( $value ) > 200 ? substr( $value, 0, 197 ) . '...' : $value;
	}

	/**
	 * A user was created.
	 *
	 * @param int   $user_id  User ID.
	 * @param array $userdata Data passed to wp_insert_user().
	 * @return void
	 */
	public static function user_register( $user_id, $userdata = array() ) {
		$role = empty( $userdata['role'] ) ? '' : $userdata['role'];

		Herd_Experience_Activity_Log::log(
			'user_created',
			sprintf( 'Created user %s%s.', self::describe_user( $user_id ), $role ? " with role {$role}" : '' ),
			array(
				'object_type' => 'user',
				'object_id'   => $user_id,
			)
		);
	}

	/**
	 * A user's profile changed.
	 *
	 * @param int     $user_id  User ID.
	 * @param WP_User $old_user User before the change.
	 * @param array   $userdata Data passed to wp_insert_user().
	 * @return void
	 */
	public static function profile_update( $user_id, $old_user, $userdata = array() ) {
		$changed = array();

		foreach ( (array) $userdata as $key => $value ) {
			if ( isset( $old_user->data->$key ) && is_scalar( $value ) && (string) $old_user->data->$key !== (string) $value ) {
				$changed[] = 'user_pass' === $key ? 'password' : $key;
			}
		}

		if ( ! $changed ) {
			return;
		}

		Herd_Experience_Activity_Log::log(
			'user_updated',
			sprintf( 'Updated user %s: %s.', self::describe_user( $user_id ), implode( ', ', $changed ) ),
			array(
				'object_type' => 'user',
				'object_id'   => $user_id,
				'details'     => array( 'fields' => $changed ),
			)
		);
	}

	/**
	 * A user's role changed on a site.
	 *
	 * @param int      $user_id   User ID.
	 * @param string   $role      New role.
	 * @param string[] $old_roles Previous roles.
	 * @return void
	 */
	public static function set_user_role( $user_id, $role, $old_roles ) {
		// No previous roles means the user is new to the site; that is logged elsewhere.
		if ( empty( $old_roles ) ) {
			return;
		}

		Herd_Experience_Activity_Log::log(
			'user_role_changed',
			sprintf( 'Changed role of %s from %s to %s.', self::describe_user( $user_id ), implode( ', ', $old_roles ), $role ? $role : '(none)' ),
			array(
				'object_type' => 'user',
				'object_id'   => $user_id,
				'details'     => array(
					'from' => array_values( $old_roles ),
					'to'   => $role,
				),
			)
		);
	}

	/**
	 * A user was deleted (from the network on multisite).
	 *
	 * @param int          $user_id  User ID.
	 * @param int|null     $reassign User their content went to.
	 * @param WP_User|null $user     Deleted user.
	 * @return void
	 */
	public static function deleted_user( $user_id, $reassign = null, $user = null ) {
		$who = $user instanceof WP_User ? sprintf( '%s (#%d)', $user->user_login, $user->ID ) : '#' . (int) $user_id;

		Herd_Experience_Activity_Log::log(
			'user_deleted',
			sprintf( 'Deleted user %s%s.', $who, $reassign ? ' and reassigned their content to ' . self::describe_user( $reassign ) : '' ),
			array(
				'object_type' => 'user',
				'object_id'   => $user_id,
				'blog_id'     => is_multisite() ? 0 : get_current_blog_id(),
			)
		);
	}

	/**
	 * A user was added to a site.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role.
	 * @param int    $blog_id Site ID.
	 * @return void
	 */
	public static function add_user_to_blog( $user_id, $role, $blog_id ) {
		Herd_Experience_Activity_Log::log(
			'user_added_to_site',
			sprintf( 'Added %s to %s as %s.', self::describe_user( $user_id ), self::describe_site( $blog_id ), $role ),
			array(
				'object_type' => 'user',
				'object_id'   => $user_id,
				'blog_id'     => $blog_id,
			)
		);
	}

	/**
	 * A user was removed from a site.
	 *
	 * @param int $user_id User ID.
	 * @param int $blog_id Site ID.
	 * @return void
	 */
	public static function remove_user_from_blog( $user_id, $blog_id ) {
		Herd_Experience_Activity_Log::log(
			'user_removed_from_site',
			sprintf( 'Removed %s from %s.', self::describe_user( $user_id ), self::describe_site( $blog_id ) ),
			array(
				'object_type' => 'user',
				'object_id'   => $user_id,
				'blog_id'     => $blog_id,
			)
		);
	}

	/**
	 * A user became a Super Admin.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function granted_super_admin( $user_id ) {
		Herd_Experience_Activity_Log::log(
			'super_admin_granted',
			sprintf( 'Granted Super Admin to %s.', self::describe_user( $user_id ) ),
			array(
				'object_type' => 'user',
				'object_id'   => $user_id,
				'blog_id'     => 0,
			)
		);
	}

	/**
	 * A user stopped being a Super Admin.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function revoked_super_admin( $user_id ) {
		Herd_Experience_Activity_Log::log(
			'super_admin_revoked',
			sprintf( 'Revoked Super Admin from %s.', self::describe_user( $user_id ) ),
			array(
				'object_type' => 'user',
				'object_id'   => $user_id,
				'blog_id'     => 0,
			)
		);
	}

	/**
	 * A user signed in (SSO or password).
	 *
	 * @param string $auth_cookie Cookie value.
	 * @param int    $expire      Cookie expiry.
	 * @param int    $expiration  Session expiry.
	 * @param int    $user_id     User ID.
	 * @return void
	 */
	public static function set_auth_cookie( $auth_cookie, $expire, $expiration, $user_id ) {
		// The cookie is set for the current user again when they change their own password.
		if ( in_array( $user_id, self::$logged_in, true ) || ( get_current_user_id() === (int) $user_id && did_action( 'profile_update' ) ) ) {
			return;
		}

		self::$logged_in[] = $user_id;

		Herd_Experience_Activity_Log::log(
			'user_logged_in',
			sprintf( '%s signed in.', self::describe_user( $user_id ) ),
			array(
				'object_type' => 'user',
				'object_id'   => $user_id,
				'user'        => $user_id,
			)
		);
	}

	/**
	 * A site was created.
	 *
	 * @param WP_Site $site New site.
	 * @return void
	 */
	public static function site_created( $site ) {
		Herd_Experience_Activity_Log::log(
			'site_created',
			sprintf( 'Created site %s.', $site->domain . $site->path ),
			array(
				'object_type' => 'site',
				'object_id'   => $site->blog_id,
				'blog_id'     => $site->blog_id,
			)
		);
	}

	/**
	 * A site was deleted.
	 *
	 * @param WP_Site $site Deleted site.
	 * @return void
	 */
	public static function site_deleted( $site ) {
		Herd_Experience_Activity_Log::log(
			'site_deleted',
			sprintf( 'Deleted site %s.', $site->domain . $site->path ),
			array(
				'object_type' => 'site',
				'object_id'   => $site->blog_id,
				'blog_id'     => $site->blog_id,
			)
		);
	}

	/**
	 * A site's status flags or address changed.
	 *
	 * @param WP_Site $new_site Site after the change.
	 * @param WP_Site $old_site Site before the change.
	 * @return void
	 */
	public static function site_updated( $new_site, $old_site ) {
		$changes = array();

		foreach ( array( 'domain', 'path', 'public', 'archived', 'mature', 'spam', 'deleted' ) as $field ) {
			if ( (string) $old_site->$field !== (string) $new_site->$field ) {
				$changes[ $field ] = array( $old_site->$field, $new_site->$field );
			}
		}

		if ( ! $changes ) {
			return;
		}

		$parts = array();

		foreach ( $changes as $field => $values ) {
			$parts[] = sprintf( '%s %s → %s', $field, $values[0], $values[1] );
		}

		Herd_Experience_Activity_Log::log(
			'site_updated',
			sprintf( 'Updated site %s: %s.', $old_site->domain . $old_site->path, implode( '; ', $parts ) ),
			array(
				'object_type' => 'site',
				'object_id'   => $new_site->blog_id,
				'blog_id'     => $new_site->blog_id,
				'details'     => $changes,
			)
		);
	}

	/**
	 * Site options to log.
	 *
	 * @return string[]
	 */
	private static function get_logged_options() {
		/**
		 * Filters the site options whose changes are logged, with values.
		 *
		 * @param string[] $options Option names.
		 */
		return (array) apply_filters(
			'herd_experience_activity_log_options',
			array(
				'admin_email',
				'blog_public',
				'blogdescription',
				'blogname',
				'default_role',
				'home',
				'page_for_posts',
				'page_on_front',
				'permalink_structure',
				'show_on_front',
				'siteurl',
				'timezone_string',
				'upload_path',
				'upload_url_path',
				'users_can_register',
				'WPLANG',
				Herd_Experience_Comments::SITE_OPTION,
				Herd_Experience_Rest_Api::OPTION,
			)
		);
	}

	/**
	 * Network options to log.
	 *
	 * @return string[]
	 */
	private static function get_logged_network_options() {
		/**
		 * Filters the network options whose changes are logged, with values.
		 *
		 * @param string[] $options Option names.
		 */
		return (array) apply_filters(
			'herd_experience_activity_log_network_options',
			array(
				'add_new_users',
				'admin_email',
				'allowedthemes',
				'banned_email_domains',
				'blog_upload_space',
				'fileupload_maxk',
				'illegal_names',
				'limited_email_domains',
				'registration',
				'upload_filetypes',
				Herd_Experience_Comments::NETWORK_OPTION,
			)
		);
	}

	/**
	 * A logged site option changed.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Previous value.
	 * @param mixed  $value     New value.
	 * @return void
	 */
	public static function updated_option( $option, $old_value, $value ) {
		if ( ! in_array( $option, self::get_logged_options(), true ) ) {
			return;
		}

		Herd_Experience_Activity_Log::log(
			'option_updated',
			sprintf( 'Changed %s from "%s" to "%s".', $option, self::format_value( $old_value ), self::format_value( $value ) ),
			array(
				'object_type' => 'option',
				'object_id'   => $option,
			)
		);
	}

	/**
	 * A logged site option was set for the first time.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return void
	 */
	public static function added_option( $option, $value ) {
		if ( ! in_array( $option, self::get_logged_options(), true ) ) {
			return;
		}

		Herd_Experience_Activity_Log::log(
			'option_updated',
			sprintf( 'Set %s to "%s".', $option, self::format_value( $value ) ),
			array(
				'object_type' => 'option',
				'object_id'   => $option,
			)
		);
	}

	/**
	 * A logged network option changed.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $value     New value.
	 * @param mixed  $old_value Previous value.
	 * @return void
	 */
	public static function update_site_option( $option, $value, $old_value ) {
		if ( ! in_array( $option, self::get_logged_network_options(), true ) ) {
			return;
		}

		Herd_Experience_Activity_Log::log(
			'network_option_updated',
			sprintf( 'Changed network setting %s from "%s" to "%s".', $option, self::format_value( $old_value ), self::format_value( $value ) ),
			array(
				'object_type' => 'option',
				'object_id'   => $option,
				'blog_id'     => 0,
			)
		);
	}

	/**
	 * A plugin was activated.
	 *
	 * @param string $plugin       Plugin file.
	 * @param bool   $network_wide Whether network-activated.
	 * @return void
	 */
	public static function activated_plugin( $plugin, $network_wide ) {
		Herd_Experience_Activity_Log::log(
			'plugin_activated',
			sprintf( 'Activated plugin %s%s.', $plugin, $network_wide ? ' network-wide' : '' ),
			array(
				'object_type' => 'plugin',
				'object_id'   => $plugin,
				'blog_id'     => $network_wide ? 0 : get_current_blog_id(),
			)
		);
	}

	/**
	 * A plugin was deactivated.
	 *
	 * @param string $plugin       Plugin file.
	 * @param bool   $network_wide Whether network-deactivated.
	 * @return void
	 */
	public static function deactivated_plugin( $plugin, $network_wide ) {
		Herd_Experience_Activity_Log::log(
			'plugin_deactivated',
			sprintf( 'Deactivated plugin %s%s.', $plugin, $network_wide ? ' network-wide' : '' ),
			array(
				'object_type' => 'plugin',
				'object_id'   => $plugin,
				'blog_id'     => $network_wide ? 0 : get_current_blog_id(),
			)
		);
	}

	/**
	 * The theme was switched.
	 *
	 * @param string   $new_name  New theme name.
	 * @param WP_Theme $new_theme New theme.
	 * @param WP_Theme $old_theme Previous theme.
	 * @return void
	 */
	public static function switch_theme( $new_name, $new_theme, $old_theme ) {
		Herd_Experience_Activity_Log::log(
			'theme_switched',
			sprintf( 'Switched theme from %s to %s.', $old_theme instanceof WP_Theme ? $old_theme->get( 'Name' ) : '(unknown)', $new_name ),
			array(
				'object_type' => 'theme',
				'object_id'   => $new_theme->get_stylesheet(),
			)
		);
	}

	/**
	 * Whether a post's changes should be logged.
	 *
	 * @param WP_Post|null $post Post.
	 * @return bool
	 */
	private static function is_logged_post( $post ) {
		if ( ! $post instanceof WP_Post || in_array( $post->post_status, array( 'auto-draft', 'inherit' ), true ) ) {
			return false;
		}

		return 'attachment' === $post->post_type || is_post_type_viewable( $post->post_type );
	}

	/**
	 * Log a content change.
	 *
	 * @param string  $action Action.
	 * @param string  $verb   Past-tense verb.
	 * @param WP_Post $post   Post.
	 * @return void
	 */
	private static function log_post( $action, $verb, $post ) {
		$type  = get_post_type_object( $post->post_type );
		$label = $type ? $type->labels->singular_name : $post->post_type;

		Herd_Experience_Activity_Log::log(
			$action,
			sprintf( '%s %s "%s" (#%d).', $verb, strtolower( $label ), $post->post_title, $post->ID ),
			array(
				'object_type' => 'post',
				'object_id'   => $post->ID,
			)
		);
	}

	/**
	 * A post was moved to the trash.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function trashed_post( $post_id ) {
		$post = get_post( $post_id );

		if ( self::is_logged_post( $post ) ) {
			self::log_post( 'post_trashed', 'Trashed', $post );
		}
	}

	/**
	 * A post was restored from the trash.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function untrashed_post( $post_id ) {
		$post = get_post( $post_id );

		if ( self::is_logged_post( $post ) ) {
			self::log_post( 'post_restored', 'Restored', $post );
		}
	}

	/**
	 * A post is about to be permanently deleted.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public static function delete_post( $post_id, $post = null ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );

		if ( self::is_logged_post( $post ) ) {
			self::log_post( 'post_deleted', 'Permanently deleted', $post );
		}
	}
}
