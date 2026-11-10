<?php
/**
 * Dashboard widget cleanup.
 *
 * @package HerdExperience
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remove unwanted widgets from the admin Dashboard.
 */
final class Herd_Experience_Dashboard {
	/**
	 * Dashboard widget IDs to remove.
	 *
	 * @var string[]
	 */
	const WIDGETS = array(
		'dashboard_site_health',      // Site Health Status.
		'wpcom_daily_writing_prompt', // Daily Writing Prompt (Jetpack).
		'dashboard_quick_press',      // Quick Draft.
		'dashboard_primary',          // WordPress Events and News.
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		// Late, so widgets plugins such as Jetpack add are registered first.
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'remove_widgets' ), 999 );
	}

	/**
	 * Remove the widgets from every Dashboard column.
	 *
	 * @return void
	 */
	public static function remove_widgets() {
		foreach ( self::WIDGETS as $widget ) {
			foreach ( array( 'normal', 'side', 'column3', 'column4' ) as $context ) {
				remove_meta_box( $widget, 'dashboard', $context );
			}
		}
	}
}
