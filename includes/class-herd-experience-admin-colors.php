<?php
/**
 * Marshall admin color scheme.
 *
 * @package HerdExperience
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register a Marshall admin color scheme and use it for every user.
 */
final class Herd_Experience_Admin_Colors {
	/**
	 * Color scheme key, used in the user's `admin_color` option and the
	 * `admin-color-{key}` body class.
	 */
	const SCHEME = 'marshall';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		add_action( 'admin_init', array( __CLASS__, 'register_scheme' ) );
		add_filter( 'get_user_option_admin_color', array( __CLASS__, 'filter_user_scheme' ) );
	}

	/**
	 * Whether every user gets the Marshall scheme.
	 *
	 * @return bool
	 */
	private static function is_forced() {
		/**
		 * Filters whether every user gets the Marshall scheme.
		 *
		 * When false, Marshall is one more choice under Users > Profile and
		 * core's Default stays the default.
		 *
		 * @param bool $is_forced Default true.
		 */
		return (bool) apply_filters( 'herd_experience_admin_colors_force', true );
	}

	/**
	 * Register the scheme, and hide the Users > Profile picker when forced.
	 *
	 * @return void
	 */
	public static function register_scheme() {
		wp_admin_css_color(
			self::SCHEME,
			__( 'Marshall', 'herd-experience' ),
			plugins_url( 'assets/admin-colors/marshall/colors.css', __DIR__ ),
			array( '#131716', '#028833', '#00ac3e', '#08cd4f' ),
			array(
				'base'    => '#f1f3f2',
				'focus'   => '#ffffff',
				'current' => '#ffffff',
			)
		);

		// Without a callback, user-edit.php drops the whole Administration Color Scheme row.
		if ( self::is_forced() ) {
			remove_action( 'admin_color_scheme_picker', 'admin_color_scheme_picker' );
		}
	}

	/**
	 * Use Marshall whatever scheme the user has saved.
	 *
	 * @param string|false $scheme The user's color scheme.
	 * @return string|false
	 */
	public static function filter_user_scheme( $scheme ) {
		return self::is_forced() ? self::SCHEME : $scheme;
	}
}
