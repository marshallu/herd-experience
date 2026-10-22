<?php
/**
 * REST API access restrictions.
 *
 * @package HerdExperience
 */

defined( 'ABSPATH' ) || exit;

/**
 * Restrict what logged-out visitors can read from the REST API.
 *
 * The mode is a per-site option on Settings > Reading:
 *
 * - `all`       — no restrictions (WordPress default).
 * - `no-users`  — everything except the users endpoints (default).
 * - `logged-in` — nothing without authentication.
 */
final class Herd_Experience_Rest_Api {
	/**
	 * Option that stores the access mode.
	 */
	const OPTION = 'herd_experience_rest_api_access';

	/**
	 * Mode used when the option is missing or invalid.
	 */
	const DEFAULT_MODE = 'no-users';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		// rest_pre_dispatch also runs for `?_embed` sub-requests, which
		// rest_authentication_errors does not, so embedded authors are covered.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'restrict_request' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
	}

	/**
	 * Access modes and their Settings screen labels.
	 *
	 * @return array<string, string>
	 */
	private static function modes() {
		return array(
			'all'       => __( 'Show the REST API to everyone', 'herd-experience' ),
			'no-users'  => __( 'Show the REST API to everyone except the users endpoints', 'herd-experience' ),
			'logged-in' => __( 'Show the REST API only to logged-in users', 'herd-experience' ),
		);
	}

	/**
	 * Get the current access mode.
	 *
	 * @return string
	 */
	public static function get_mode() {
		/**
		 * Filters the REST API access mode.
		 *
		 * @param string $mode `all`, `no-users`, or `logged-in`.
		 */
		$mode = apply_filters( 'herd_experience_rest_api_access', get_option( self::OPTION, self::DEFAULT_MODE ) );

		return array_key_exists( $mode, self::modes() ) ? $mode : self::DEFAULT_MODE;
	}

	/**
	 * Reject restricted routes for logged-out visitors.
	 *
	 * @param mixed           $result  Response to replace the requested one with, or null.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request being dispatched.
	 * @return mixed
	 */
	public static function restrict_request( $result, $server, $request ) {
		if ( null !== $result || is_user_logged_in() ) {
			return $result;
		}

		$mode  = self::get_mode();
		$route = untrailingslashit( $request->get_route() );

		/**
		 * Filters routes that logged-out visitors can always read.
		 *
		 * Routes must match exactly, for example `/wp/v2/posts`.
		 *
		 * @param string[] $allowlist Routes.
		 */
		$allowlist = (array) apply_filters( 'herd_experience_rest_api_allowlist', array() );

		if ( 'all' === $mode || in_array( $route, $allowlist, true ) ) {
			return $result;
		}

		if ( 'no-users' === $mode && '/wp/v2/users' !== $route && ! str_starts_with( $route, '/wp/v2/users/' ) ) {
			return $result;
		}

		return new WP_Error(
			'rest_cannot_access',
			__( 'Only authenticated users can access this endpoint.', 'herd-experience' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Whether the current user may change the access mode.
	 *
	 * On multisite this is limited to Super Admins so site admins cannot
	 * reopen the users endpoints.
	 *
	 * @return bool
	 */
	private static function current_user_can_manage() {
		return current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );
	}

	/**
	 * Register the setting and its field on Settings > Reading.
	 *
	 * @return void
	 */
	public static function register_setting() {
		if ( ! self::current_user_can_manage() ) {
			return;
		}

		register_setting(
			'reading',
			self::OPTION,
			array(
				'type'              => 'string',
				'default'           => self::DEFAULT_MODE,
				'sanitize_callback' => array( __CLASS__, 'sanitize_mode' ),
			)
		);

		add_settings_field(
			self::OPTION,
			__( 'REST API access', 'herd-experience' ),
			array( __CLASS__, 'render_field' ),
			'reading'
		);
	}

	/**
	 * Sanitize a submitted access mode.
	 *
	 * @param mixed $mode Submitted value.
	 * @return string
	 */
	public static function sanitize_mode( $mode ) {
		return is_string( $mode ) && array_key_exists( $mode, self::modes() ) ? $mode : self::DEFAULT_MODE;
	}

	/**
	 * Render the access mode radio buttons.
	 *
	 * @return void
	 */
	public static function render_field() {
		$current = get_option( self::OPTION, self::DEFAULT_MODE );
		?>
		<fieldset>
			<?php foreach ( self::modes() as $mode => $label ) : ?>
				<label>
					<input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>" value="<?php echo esc_attr( $mode ); ?>" <?php checked( $current, $mode ); ?>>
					<?php echo esc_html( $label ); ?>
				</label><br>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}
}
