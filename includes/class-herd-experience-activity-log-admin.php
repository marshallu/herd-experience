<?php
/**
 * Activity log screen.
 *
 * @package HerdExperience
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add the Activity Log screen.
 *
 * Multisite: a top-level Network Admin menu for Super Admins, covering
 * every site. Single site: Tools > Activity Log for administrators.
 */
final class Herd_Experience_Activity_Log_Admin {
	/**
	 * Admin page slug.
	 */
	const PAGE = 'herd-activity-log';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		if ( ! Herd_Experience_Activity_Log::is_enabled() ) {
			return;
		}

		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( __CLASS__, 'add_network_page' ) );
		} else {
			add_action( 'admin_menu', array( __CLASS__, 'add_site_page' ) );
		}
	}

	/**
	 * Add the Network Admin page.
	 *
	 * @return void
	 */
	public static function add_network_page() {
		add_menu_page(
			__( 'Activity Log', 'herd-experience' ),
			__( 'Activity Log', 'herd-experience' ),
			'manage_network_options',
			self::PAGE,
			array( __CLASS__, 'render_page' ),
			'dashicons-list-view',
			25
		);
	}

	/**
	 * Add the Tools page on single sites.
	 *
	 * @return void
	 */
	public static function add_site_page() {
		add_management_page(
			__( 'Activity Log', 'herd-experience' ),
			__( 'Activity Log', 'herd-experience' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render_page() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once __DIR__ . '/class-herd-experience-activity-log-list-table.php';

		Herd_Experience_Activity_Log::maybe_install();

		$table = new Herd_Experience_Activity_Log_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Activity Log', 'herd-experience' ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: %d: number of days entries are kept. */
					esc_html__( 'Changes to users, roles, sites, settings, plugins, themes, and deleted content. Entries are kept for %d days.', 'herd-experience' ),
					(int) apply_filters( 'herd_experience_activity_log_retention_days', 365 )
				);
				?>
			</p>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}
}
