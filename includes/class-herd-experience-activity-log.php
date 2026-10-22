<?php
/**
 * Activity log storage.
 *
 * @package HerdExperience
 */

defined( 'ABSPATH' ) || exit;

/**
 * Store activity log entries in a custom table.
 *
 * One table serves the whole network (`{base_prefix}herd_activity_log`,
 * keyed by blog_id) rather than one per site, since www.marshall.edu has
 * hundreds of sites. Entries are pruned daily after the retention period.
 *
 * Define HERD_EXPERIENCE_DISABLE_ACTIVITY_LOG as true to turn logging off.
 */
final class Herd_Experience_Activity_Log {
	/**
	 * Schema version; bump when the CREATE TABLE statement changes.
	 */
	const DB_VERSION = '1';

	/**
	 * Network option that stores the installed schema version.
	 */
	const DB_VERSION_OPTION = 'herd_experience_activity_log_db_version';

	/**
	 * Cron hook that prunes old entries.
	 */
	const PRUNE_HOOK = 'herd_experience_activity_log_prune';

	/**
	 * Rows deleted per prune query.
	 */
	const PRUNE_BATCH = 1000;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule_prune' ) );
		add_action( self::PRUNE_HOOK, array( __CLASS__, 'prune' ) );
	}

	/**
	 * Whether logging is on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return ! ( defined( 'HERD_EXPERIENCE_DISABLE_ACTIVITY_LOG' ) && HERD_EXPERIENCE_DISABLE_ACTIVITY_LOG );
	}

	/**
	 * Get the table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->base_prefix . 'herd_activity_log';
	}

	/**
	 * Create or update the table when the schema version changes.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( self::DB_VERSION === get_site_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta needs two spaces after PRIMARY KEY and one column per line.
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_login varchar(60) NOT NULL DEFAULT '',
				action varchar(64) NOT NULL DEFAULT '',
				object_type varchar(32) NOT NULL DEFAULT '',
				object_id varchar(191) NOT NULL DEFAULT '',
				summary text NOT NULL,
				details longtext NOT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY blog_id (blog_id,id),
				KEY user_login (user_login,id),
				KEY action (action,id)
			) {$charset};"
		);

		update_site_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Record an entry.
	 *
	 * @param string $action  Machine name, e.g. `user_role_changed`.
	 * @param string $summary Human-readable description.
	 * @param array  $args {
	 *     Optional.
	 *
	 *     @type string      $object_type What changed: user, option, plugin, theme, site, post.
	 *     @type string|int  $object_id   Its ID or name.
	 *     @type array       $details     Extra data, stored as JSON.
	 *     @type int         $blog_id     Site ID; 0 for network-wide events. Default current site.
	 *     @type WP_User|int $user        Who did it. Default the current user.
	 * }
	 * @return void
	 */
	public static function log( $action, $summary, $args = array() ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		$args = wp_parse_args(
			$args,
			array(
				'object_type' => '',
				'object_id'   => '',
				'details'     => array(),
				'blog_id'     => get_current_blog_id(),
				'user'        => null,
			)
		);

		$entry = array(
			'created_at'  => current_time( 'mysql', true ),
			'blog_id'     => (int) $args['blog_id'],
			'action'      => substr( (string) $action, 0, 64 ),
			'object_type' => substr( (string) $args['object_type'], 0, 32 ),
			'object_id'   => substr( (string) $args['object_id'], 0, 191 ),
			'summary'     => (string) $summary,
			'details'     => $args['details'] ? (string) wp_json_encode( $args['details'] ) : '',
		) + self::get_actor( $args['user'] );

		/**
		 * Filters an entry before it is saved. Return false to skip it.
		 *
		 * @param array|false $entry Entry columns.
		 */
		$entry = apply_filters( 'herd_experience_activity_log_entry', $entry );

		if ( ! is_array( $entry ) ) {
			return;
		}

		self::maybe_install();

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Writing to the plugin's own table.
		$wpdb->insert( self::table(), $entry );
	}

	/**
	 * Work out who performed the action.
	 *
	 * @param WP_User|int|null $user Explicit user, if known.
	 * @return array{user_id: int, user_login: string}
	 */
	private static function get_actor( $user ) {
		if ( is_numeric( $user ) ) {
			$user = get_userdata( (int) $user );
		}

		if ( ! $user instanceof WP_User && is_user_logged_in() ) {
			$user = wp_get_current_user();
		}

		if ( $user instanceof WP_User ) {
			return array(
				'user_id'    => $user->ID,
				'user_login' => $user->user_login,
			);
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$login = 'wp-cli';
		} elseif ( wp_doing_cron() ) {
			$login = 'cron';
		} else {
			$login = '';
		}

		return array(
			'user_id'    => 0,
			'user_login' => $login,
		);
	}

	/**
	 * Query entries, newest first.
	 *
	 * @param array $args {
	 *     Optional.
	 *
	 *     @type int|null $blog_id    Limit to a site (0 = network events). Default all.
	 *     @type string   $user_login Limit to a user.
	 *     @type string   $action     Limit to an action.
	 *     @type int      $per_page   Default 50.
	 *     @type int      $page       Default 1.
	 * }
	 * @return array{items: object[], total: int}
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'blog_id'    => null,
				'user_login' => '',
				'action'     => '',
				'per_page'   => 50,
				'page'       => 1,
			)
		);

		// Start with a placeholder so every query goes through prepare().
		$where  = array( '1 = %d' );
		$params = array( 1 );

		if ( null !== $args['blog_id'] ) {
			$where[]  = 'blog_id = %d';
			$params[] = (int) $args['blog_id'];
		}

		if ( '' !== $args['user_login'] ) {
			$where[]  = 'user_login = %s';
			$params[] = $args['user_login'];
		}

		if ( '' !== $args['action'] ) {
			$where[]  = 'action = %s';
			$params[] = $args['action'];
		}

		$table    = self::table();
		$where    = implode( ' AND ', $where );
		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Admin-only reads of the plugin's own table; $where holds only placeholders.
		$items = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) )
		);
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params )
		);
		// phpcs:enable

		return array(
			'items' => (array) $items,
			'total' => $total,
		);
	}

	/**
	 * Distinct actions in the log, for the filter dropdown.
	 *
	 * @return string[]
	 */
	public static function get_actions() {
		global $wpdb;

		$actions = wp_cache_get( 'actions', 'herd_experience_activity_log' );

		if ( false === $actions ) {
			$table = self::table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table, no user input; cached below.
			$actions = $wpdb->get_col( "SELECT DISTINCT action FROM {$table} ORDER BY action" );
			wp_cache_set( 'actions', $actions, 'herd_experience_activity_log', HOUR_IN_SECONDS );
		}

		return (array) $actions;
	}

	/**
	 * Schedule the daily prune on the main site.
	 *
	 * @return void
	 */
	public static function schedule_prune() {
		if ( is_main_site() && ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Delete entries older than the retention period.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;

		/**
		 * Filters how many days entries are kept.
		 *
		 * @param int $days Default 365.
		 */
		$days   = max( 1, (int) apply_filters( 'herd_experience_activity_log_retention_days', 365 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$table  = self::table();

		// Small batches keep each DELETE short on the primary; cap the run at 50k rows.
		for ( $i = 0; $i < 50; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Pruning the plugin's own table.
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s LIMIT %d", $cutoff, self::PRUNE_BATCH ) );

			if ( self::PRUNE_BATCH !== $deleted ) {
				break;
			}
		}
	}
}
