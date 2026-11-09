<?php
/**
 * Admin bar cleanup.
 *
 * @package HerdExperience
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remove the WordPress logo menu from the admin bar for every user.
 */
final class Herd_Experience_Admin_Bar {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		// After wp_admin_bar_wp_menu (priority 10) adds the node.
		add_action( 'admin_bar_menu', array( __CLASS__, 'remove_wp_logo' ), 11 );
	}

	/**
	 * Remove the WordPress logo menu.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	public static function remove_wp_logo( $wp_admin_bar ) {
		$wp_admin_bar->remove_node( 'wp-logo' );
	}
}
