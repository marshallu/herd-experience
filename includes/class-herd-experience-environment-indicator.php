<?php
/**
 * Admin bar environment indicator.
 *
 * @package HerdExperience
 */

defined( 'ABSPATH' ) || exit;

/**
 * Show the current environment as a colored label in the admin bar.
 */
final class Herd_Experience_Environment_Indicator {
	/**
	 * Admin bar node ID.
	 */
	const NODE = 'herd-environment';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		// Before wp_admin_bar_wp_menu (priority 10) so the label sits first.
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_node' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'add_styles' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'add_styles' ) );
	}

	/**
	 * Get the current environment name.
	 *
	 * VIP defines VIP_GO_APP_ENVIRONMENT on hosted environments and `local`
	 * in vip dev-env; elsewhere fall back to wp_get_environment_type().
	 *
	 * @return string
	 */
	public static function get_environment() {
		$environment = defined( 'VIP_GO_APP_ENVIRONMENT' ) ? (string) VIP_GO_APP_ENVIRONMENT : wp_get_environment_type();

		/**
		 * Filters the environment name shown in the admin bar.
		 *
		 * @param string $environment Environment name.
		 */
		return sanitize_key( apply_filters( 'herd_experience_environment', $environment ) );
	}

	/**
	 * Get the background and text colors for the current environment.
	 *
	 * Production is red because that is the one to be careful on. Any
	 * environment without its own entry uses `default`.
	 *
	 * @return array{background: string, text: string}
	 */
	private static function get_colors() {
		/**
		 * Filters the label colors, keyed by environment name.
		 *
		 * @param array<string, array{background: string, text: string}> $colors Colors.
		 */
		$colors = (array) apply_filters(
			'herd_experience_environment_colors',
			array(
				'production' => array(
					'background' => '#b32d2e',
					'text'       => '#ffffff',
				),
				'local'      => array(
					'background' => '#007017',
					'text'       => '#ffffff',
				),
				'default'    => array(
					'background' => '#dba617',
					'text'       => '#1d2327',
				),
			)
		);

		return $colors[ self::get_environment() ] ?? $colors['default'];
	}

	/**
	 * Add the environment label to the admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	public static function add_node( $wp_admin_bar ) {
		$wp_admin_bar->add_node(
			array(
				'id'    => self::NODE,
				'title' => esc_html( self::get_environment() ),
				'meta'  => array(
					'title' => __( 'Current environment', 'herd-experience' ),
				),
			)
		);
	}

	/**
	 * Color the label.
	 *
	 * @return void
	 */
	public static function add_styles() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$colors   = self::get_colors();
		$selector = '#wpadminbar #wp-admin-bar-' . self::NODE;

		$css = sprintf(
			'%1$s > .ab-item, %1$s:hover > .ab-item { background: %2$s; color: %3$s; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }'
			. ' @media screen and (max-width: 782px) { %1$s { display: block; } %1$s > .ab-item { padding: 0 8px; font-size: 12px; } }',
			$selector,
			sanitize_hex_color( $colors['background'] ),
			sanitize_hex_color( $colors['text'] )
		);

		wp_add_inline_style( 'admin-bar', $css );
	}
}
