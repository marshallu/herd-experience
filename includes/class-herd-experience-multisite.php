<?php
/**
 * Network Admin columns.
 *
 * @package HerdExperience
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add ID columns to Network Admin > Sites and Users, and Usage columns to
 * Network Admin > Plugins and Themes that list the sites using each one.
 *
 * Loaded only on multisite. Replaces those features of the Multisite
 * Enhancements plugin; every column key, class, and transient here is
 * prefixed so both can run side by side during the cutover.
 */
final class Herd_Experience_Multisite {
	/**
	 * Network Admin > Sites column key.
	 */
	const SITE_ID_COLUMN = 'herd_site_id';

	/**
	 * Network Admin > Users column key.
	 */
	const USER_ID_COLUMN = 'herd_user_id';

	/**
	 * Network Admin > Plugins and Themes column key.
	 */
	const USAGE_COLUMN = 'herd_usage';

	/**
	 * Site transient: plugin file => IDs of sites that activated it.
	 */
	const PLUGIN_USAGE_TRANSIENT = 'herd_experience_plugin_usage';

	/**
	 * Site transient: `active` and `parent`, each theme slug => site IDs.
	 */
	const THEME_USAGE_TRANSIENT = 'herd_experience_theme_usage';

	/**
	 * How long the usage caches live. Activations, theme switches, and new
	 * or deleted sites clear them sooner; this catches anything else.
	 */
	const USAGE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Sites read per query when building the usage caches.
	 */
	const BATCH_SIZE = 100;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		add_filter( 'wpmu_blogs_columns', array( __CLASS__, 'add_site_id_column' ) );
		add_action( 'manage_sites_custom_column', array( __CLASS__, 'render_site_id_column' ), 10, 2 );
		add_filter( 'manage_users-network_columns', array( __CLASS__, 'add_user_id_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_user_id_column' ), 10, 3 );

		add_filter( 'manage_plugins-network_columns', array( __CLASS__, 'add_plugin_usage_column' ) );
		add_action( 'manage_plugins_custom_column', array( __CLASS__, 'render_plugin_usage_column' ), 10, 2 );
		add_filter( 'manage_themes-network_columns', array( __CLASS__, 'add_theme_usage_column' ) );
		add_action( 'manage_themes_custom_column', array( __CLASS__, 'render_theme_usage_column' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'add_styles' ) );

		// The option hooks fire on every site, and also catch silent changes
		// such as core deactivating a plugin whose file is gone.
		add_action( 'update_option_active_plugins', array( __CLASS__, 'flush_plugin_usage' ) );
		add_action( 'update_option_stylesheet', array( __CLASS__, 'flush_theme_usage' ) );
		add_action( 'update_option_template', array( __CLASS__, 'flush_theme_usage' ) );
		// After core creates the site's tables (priority 10).
		add_action( 'wp_initialize_site', array( __CLASS__, 'flush_usage' ), 100 );
		add_action( 'wp_delete_site', array( __CLASS__, 'flush_usage' ) );
	}

	/**
	 * Add the ID column to Network Admin > Sites.
	 *
	 * @param string[] $columns Column headers.
	 * @return string[]
	 */
	public static function add_site_id_column( $columns ) {
		$columns[ self::SITE_ID_COLUMN ] = __( 'ID', 'herd-experience' );

		return $columns;
	}

	/**
	 * Print a site's ID.
	 *
	 * @param string $column_name Column key.
	 * @param int    $blog_id     Site ID.
	 * @return void
	 */
	public static function render_site_id_column( $column_name, $blog_id ) {
		if ( self::SITE_ID_COLUMN === $column_name ) {
			echo absint( $blog_id );
		}
	}

	/**
	 * Add the ID column to Network Admin > Users.
	 *
	 * @param string[] $columns Column headers.
	 * @return string[]
	 */
	public static function add_user_id_column( $columns ) {
		$columns[ self::USER_ID_COLUMN ] = __( 'ID', 'herd-experience' );

		return $columns;
	}

	/**
	 * Return a user's ID for the ID column.
	 *
	 * @param string $output      Column output.
	 * @param string $column_name Column key.
	 * @param int    $user_id     User ID.
	 * @return string
	 */
	public static function render_user_id_column( $output, $column_name, $user_id ) {
		return self::USER_ID_COLUMN === $column_name ? (string) absint( $user_id ) : $output;
	}

	/**
	 * Add the Usage column to Network Admin > Plugins.
	 *
	 * Must-use plugins and drop-ins always run everywhere, so their tabs
	 * don't get one.
	 *
	 * @param string[] $columns Column headers.
	 * @return string[]
	 */
	public static function add_plugin_usage_column( $columns ) {
		global $status;

		if ( in_array( $status, array( 'mustuse', 'dropins' ), true ) ) {
			return $columns;
		}

		$columns[ self::USAGE_COLUMN ] = __( 'Usage', 'herd-experience' );

		return $columns;
	}

	/**
	 * Add the Usage column to Network Admin > Themes.
	 *
	 * @param string[] $columns Column headers.
	 * @return string[]
	 */
	public static function add_theme_usage_column( $columns ) {
		$columns[ self::USAGE_COLUMN ] = __( 'Usage', 'herd-experience' );

		return $columns;
	}

	/**
	 * Print where a plugin is active.
	 *
	 * @param string $column_name Column key.
	 * @param string $plugin_file Plugin path relative to the plugins directory.
	 * @return void
	 */
	public static function render_plugin_usage_column( $column_name, $plugin_file ) {
		if ( self::USAGE_COLUMN !== $column_name ) {
			return;
		}

		// Checked live, not cached: network activation is a single site option.
		if ( is_plugin_active_for_network( $plugin_file ) ) {
			esc_html_e( 'Network activated', 'herd-experience' );
			return;
		}

		$usage = self::get_plugin_usage();

		if ( empty( $usage[ $plugin_file ] ) ) {
			esc_html_e( 'Not active on any site', 'herd-experience' );
			return;
		}

		/* translators: %s: number of sites. */
		self::render_site_list( _n_noop( 'Active on %s site', 'Active on %s sites', 'herd-experience' ), $usage[ $plugin_file ], 'plugins.php' );
	}

	/**
	 * Print where a theme is active, or used as the parent of the active theme.
	 *
	 * @param string $column_name Column key.
	 * @param string $stylesheet  Theme directory name.
	 * @return void
	 */
	public static function render_theme_usage_column( $column_name, $stylesheet ) {
		if ( self::USAGE_COLUMN !== $column_name ) {
			return;
		}

		$usage = self::get_theme_usage();

		if ( empty( $usage['active'][ $stylesheet ] ) && empty( $usage['parent'][ $stylesheet ] ) ) {
			esc_html_e( 'Not active on any site', 'herd-experience' );
			return;
		}

		if ( ! empty( $usage['active'][ $stylesheet ] ) ) {
			/* translators: %s: number of sites. */
			self::render_site_list( _n_noop( 'Active on %s site', 'Active on %s sites', 'herd-experience' ), $usage['active'][ $stylesheet ], 'themes.php' );
		}

		if ( ! empty( $usage['parent'][ $stylesheet ] ) ) {
			/* translators: %s: number of sites. */
			self::render_site_list( _n_noop( 'Parent theme on %s site', 'Parent theme on %s sites', 'herd-experience' ), $usage['parent'][ $stylesheet ], 'themes.php' );
		}
	}

	/**
	 * Print a collapsed list of sites under a count.
	 *
	 * Links go straight to each site's admin, built from its domain and path
	 * so no site has to be switched to.
	 *
	 * @param array  $summary  Singular and plural summary from _n_noop(), with %s for the count.
	 * @param int[]  $site_ids Site IDs, not empty.
	 * @param string $page     Admin page to link to on each site.
	 * @return void
	 */
	private static function render_site_list( $summary, array $site_ids, $page ) {
		// One query for the whole list instead of one per site.
		_prime_site_caches( $site_ids, false );

		$sites = array_filter( array_map( 'get_site', $site_ids ) );
		?>
		<details class="herd-usage">
			<summary><?php echo esc_html( sprintf( translate_nooped_plural( $summary, count( $sites ), 'herd-experience' ), number_format_i18n( count( $sites ) ) ) ); ?></summary>
			<ul>
				<?php foreach ( $sites as $site ) : ?>
					<li>
						<a href="<?php echo esc_url( set_url_scheme( 'http://' . $site->domain . $site->path . 'wp-admin/' . $page, 'admin' ) ); ?>"><?php echo esc_html( $site->domain . $site->path ); ?></a>
						<?php foreach ( self::get_site_states( $site ) as $state ) : ?>
							<span class="herd-usage-state"><?php echo esc_html( $state ); ?></span>
						<?php endforeach; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	/**
	 * Get labels for a site that isn't live.
	 *
	 * @param WP_Site $site Site.
	 * @return string[]
	 */
	private static function get_site_states( WP_Site $site ) {
		$states = array();

		if ( '1' === (string) $site->archived ) {
			$states[] = __( 'Archived', 'herd-experience' );
		}
		if ( '1' === (string) $site->spam ) {
			$states[] = __( 'Spam', 'herd-experience' );
		}
		if ( '1' === (string) $site->deleted ) {
			$states[] = __( 'Deactivated', 'herd-experience' );
		}

		return $states;
	}

	/**
	 * Get the sites each plugin is active on.
	 *
	 * @return array<string, int[]> Plugin file => site IDs.
	 */
	private static function get_plugin_usage() {
		$usage = get_site_transient( self::PLUGIN_USAGE_TRANSIENT );

		if ( is_array( $usage ) ) {
			return $usage;
		}

		$usage = array();

		foreach ( self::get_site_options( array( 'active_plugins' ) ) as $site_id => $options ) {
			foreach ( (array) maybe_unserialize( $options['active_plugins'] ?? '' ) as $plugin_file ) {
				if ( is_string( $plugin_file ) && '' !== $plugin_file ) {
					$usage[ $plugin_file ][] = $site_id;
				}
			}
		}

		set_site_transient( self::PLUGIN_USAGE_TRANSIENT, $usage, self::USAGE_TTL );

		return $usage;
	}

	/**
	 * Get the sites each theme is active on, and the sites each theme is the
	 * parent of the active theme on.
	 *
	 * @return array{active: array<string, int[]>, parent: array<string, int[]>}
	 */
	private static function get_theme_usage() {
		$usage = get_site_transient( self::THEME_USAGE_TRANSIENT );

		if ( is_array( $usage ) ) {
			return $usage;
		}

		$usage = array(
			'active' => array(),
			'parent' => array(),
		);

		foreach ( self::get_site_options( array( 'stylesheet', 'template' ) ) as $site_id => $options ) {
			$stylesheet = (string) ( $options['stylesheet'] ?? '' );
			$template   = (string) ( $options['template'] ?? '' );

			if ( '' !== $stylesheet ) {
				$usage['active'][ $stylesheet ][] = $site_id;
			}
			if ( '' !== $template && $template !== $stylesheet ) {
				$usage['parent'][ $template ][] = $site_id;
			}
		}

		set_site_transient( self::THEME_USAGE_TRANSIENT, $usage, self::USAGE_TTL );

		return $usage;
	}

	/**
	 * Read raw options from every site on the current network.
	 *
	 * Reads each site's options table directly, BATCH_SIZE sites per query,
	 * instead of get_blog_option(), which switches to every site and keeps
	 * each site's autoloaded options in memory for the rest of the request.
	 * Option filters don't run. Values are read as binary so a UNION across
	 * tables with different collations doesn't fail.
	 *
	 * @param string[] $option_names Option names.
	 * @return array<int, array<string, string>> Site ID => option name => raw value.
	 */
	private static function get_site_options( array $option_names ) {
		global $wpdb;

		$options = array();
		$offset  = 0;

		do {
			$site_ids = get_sites(
				array(
					'fields'                 => 'ids',
					'network_id'             => get_current_network_id(),
					'number'                 => self::BATCH_SIZE,
					'offset'                 => $offset,
					'orderby'                => 'id',
					'order'                  => 'ASC',
					'update_site_meta_cache' => false,
				)
			);

			$queries = array();

			foreach ( $site_ids as $site_id ) {
				$queries[] = $wpdb->prepare(
					'SELECT %d AS blog_id, CAST(option_name AS BINARY) AS option_name, CAST(option_value AS BINARY) AS option_value FROM %i WHERE option_name IN (' . implode( ',', array_fill( 0, count( $option_names ), '%s' ) ) . ')',
					array_merge( array( $site_id, $wpdb->get_blog_prefix( $site_id ) . 'options' ), $option_names )
				);
			}

			if ( $queries ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Each part is prepared above; the result is cached in a site transient.
				$rows = (array) $wpdb->get_results( implode( ' UNION ALL ', $queries ) );

				foreach ( $rows as $row ) {
					$options[ (int) $row->blog_id ][ $row->option_name ] = $row->option_value;
				}
			}

			$offset  += self::BATCH_SIZE;
			$has_more = count( $site_ids ) === self::BATCH_SIZE;
		} while ( $has_more );

		return $options;
	}

	/**
	 * Clear the plugin usage cache.
	 *
	 * @return void
	 */
	public static function flush_plugin_usage() {
		self::delete_usage_transient( self::PLUGIN_USAGE_TRANSIENT );
	}

	/**
	 * Clear the theme usage cache.
	 *
	 * @return void
	 */
	public static function flush_theme_usage() {
		self::delete_usage_transient( self::THEME_USAGE_TRANSIENT );
	}

	/**
	 * Delete a usage transient.
	 *
	 * Site creation (wpmu_create_blog()) turns on wp_installing() for the
	 * rest of the request, and while it's on the transient functions only
	 * touch the object cache. Without a persistent object cache the stored
	 * copy would survive site creation, so delete it too.
	 *
	 * @param string $transient Transient name.
	 * @return void
	 */
	private static function delete_usage_transient( $transient ) {
		delete_site_transient( $transient );

		if ( wp_installing() && ! wp_using_ext_object_cache() ) {
			delete_site_option( '_site_transient_' . $transient );
			delete_site_option( '_site_transient_timeout_' . $transient );
		}
	}

	/**
	 * Clear both usage caches.
	 *
	 * @return void
	 */
	public static function flush_usage() {
		self::flush_plugin_usage();
		self::flush_theme_usage();
	}

	/**
	 * Size the new columns and style the site lists.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function add_styles( $hook_suffix ) {
		if ( ! is_network_admin() || ! in_array( $hook_suffix, array( 'sites.php', 'users.php', 'plugins.php', 'themes.php' ), true ) ) {
			return;
		}

		wp_add_inline_style(
			'list-tables',
			'.column-herd_site_id, .column-herd_user_id { width: 4em; }'
			. ' .column-herd_usage { width: 20%; }'
			. ' .herd-usage summary { cursor: pointer; }'
			. ' .herd-usage ul { margin: 4px 0 8px 1.5em; list-style: disc; }'
			. ' .herd-usage li { margin-bottom: 2px; }'
			. ' .herd-usage-state { color: #646970; font-style: italic; }'
		);
	}
}
