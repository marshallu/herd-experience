<?php
/**
 * Hide nagging admin notices.
 *
 * @package HerdExperience
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hide plugin upsells, setup nags, and settings that don't apply on VIP.
 */
final class Herd_Experience_Admin_Notices {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'add_styles' ) );
	}

	/**
	 * Get the selectors to hide.
	 *
	 * @return string[]
	 */
	private static function get_selectors() {
		/**
		 * Filters the admin elements hidden with `display: none`.
		 *
		 * @param string[] $selectors CSS selectors.
		 */
		return (array) apply_filters(
			'herd_experience_hidden_admin_selectors',
			array(
				'.welcome-panel',
				'.try-gutenberg-panel-content',
				'#akismet-privacy-notice-admin-notice',
				'#ll-warning',
				'.wll-update-notice-newsletter',
				'#yoast-indexation-warning',
				'.jp-wpcom-connect__container',
				'#wp-admin-bar-wp-mail-smtp-menu',
				// VIP deploys plugin updates from code, so background updates can't apply.
				'.gform-settings-section-section_enable_background_updates',
			)
		);
	}

	/**
	 * Hide the selected elements.
	 *
	 * @return void
	 */
	public static function add_styles() {
		$selectors = array_filter( array_map( 'trim', self::get_selectors() ) );

		if ( ! $selectors ) {
			return;
		}

		wp_add_inline_style( 'common', wp_strip_all_tags( implode( ', ', $selectors ) ) . ' { display: none !important; }' );
	}
}
