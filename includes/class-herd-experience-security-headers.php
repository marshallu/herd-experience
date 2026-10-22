<?php
/**
 * Front-end security headers.
 *
 * @package HerdExperience
 */

defined( 'ABSPATH' ) || exit;

/**
 * Send baseline security headers on front-end responses.
 *
 * WordPress already sends X-Frame-Options and nosniff on wp-admin and
 * wp-login.php. HSTS is left to the VIP edge.
 */
final class Herd_Experience_Security_Headers {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		add_action( 'send_headers', array( __CLASS__, 'send_headers' ) );
	}

	/**
	 * Headers to send, keyed by name.
	 *
	 * @return array<string, string>
	 */
	public static function get_headers() {
		$headers = array(
			'X-Frame-Options'        => 'SAMEORIGIN',
			'X-Content-Type-Options' => 'nosniff',
			'Referrer-Policy'        => 'strict-origin-when-cross-origin',
			// Geolocation and payment are left open for embedded maps and donation forms.
			'Permissions-Policy'     => 'camera=(), microphone=(), usb=()',
		);

		/**
		 * Filters the security headers. Remove a key to skip that header.
		 *
		 * @param array<string, string> $headers Header values keyed by name.
		 */
		return (array) apply_filters( 'herd_experience_security_headers', $headers );
	}

	/**
	 * Send each header unless something earlier already set it.
	 *
	 * @return void
	 */
	public static function send_headers() {
		if ( headers_sent() ) {
			return;
		}

		$sent = array_map(
			static function ( $header ) {
				return strtolower( trim( strtok( $header, ':' ) ) );
			},
			headers_list()
		);

		foreach ( self::get_headers() as $name => $value ) {
			if ( '' === (string) $value || in_array( strtolower( $name ), $sent, true ) ) {
				continue;
			}

			header( $name . ': ' . $value );
		}
	}
}
