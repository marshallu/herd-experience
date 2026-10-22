<?php
/**
 * Disable comments settings.
 *
 * @package HerdExperience
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings UI for disabling comments.
 *
 * - Multisite: Network Admin > Settings has a network-wide switch. Each
 *   site's Settings > General can follow it, disable comments, or (Super
 *   Admins only) allow comments. Site admins can only turn comments off,
 *   and only while the network allows them.
 * - Single site: Settings > General has a yes/no switch.
 */
final class Herd_Experience_Comments_Settings {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		add_action( 'admin_init', array( __CLASS__, 'register_site_setting' ) );

		if ( is_multisite() ) {
			add_action( 'wpmu_options', array( __CLASS__, 'render_network_setting' ) );
			add_action( 'update_wpmu_options', array( __CLASS__, 'save_network_setting' ) );
		}
	}

	/**
	 * Whether the current user can manage the network.
	 *
	 * @return bool
	 */
	private static function is_network_manager() {
		return is_multisite() && current_user_can( 'manage_network_options' );
	}

	/**
	 * Site setting choices the current user may pick, with labels.
	 *
	 * @return array<string, string>
	 */
	private static function get_choices() {
		if ( ! is_multisite() ) {
			return current_user_can( 'manage_options' ) ? array(
				'yes' => __( 'Disable comments', 'herd-experience' ),
				'no'  => __( 'Allow comments', 'herd-experience' ),
			) : array();
		}

		$choices = array(
			'default' => Herd_Experience_Comments::is_network_disabled()
				? __( 'Follow the network setting (comments disabled)', 'herd-experience' )
				: __( 'Follow the network setting (comments allowed)', 'herd-experience' ),
			'yes'     => __( 'Disable comments on this site', 'herd-experience' ),
		);

		if ( self::is_network_manager() ) {
			$choices['no'] = __( 'Allow comments on this site', 'herd-experience' );
			return $choices;
		}

		// Site admins may only opt out while the network allows comments, and
		// may not undo a Super Admin's decision to allow them.
		if ( Herd_Experience_Comments::is_network_disabled() || 'no' === Herd_Experience_Comments::get_site_setting() ) {
			return array();
		}

		return current_user_can( 'manage_options' ) ? $choices : array();
	}

	/**
	 * Register the site setting and its field on Settings > General.
	 *
	 * @return void
	 */
	public static function register_site_setting() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only users who can change the value get a registered (saveable) setting.
		if ( self::get_choices() && ! Herd_Experience_Comments::is_locked() ) {
			register_setting(
				'general',
				Herd_Experience_Comments::SITE_OPTION,
				array(
					'type'              => 'string',
					'sanitize_callback' => array( __CLASS__, 'sanitize_site_setting' ),
				)
			);
		}

		add_settings_field(
			Herd_Experience_Comments::SITE_OPTION,
			__( 'Comments', 'herd-experience' ),
			array( __CLASS__, 'render_site_setting' ),
			'general'
		);
	}

	/**
	 * Keep only values the current user may choose.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_site_setting( $value ) {
		if ( is_string( $value ) && array_key_exists( $value, self::get_choices() ) ) {
			return $value;
		}

		return Herd_Experience_Comments::get_site_setting();
	}

	/**
	 * Explain why the current user can't change the site setting.
	 *
	 * @return string Empty when the setting is editable.
	 */
	private static function get_locked_message() {
		if ( Herd_Experience_Comments::is_locked() ) {
			return __( 'Set in code by the HERD_EXPERIENCE_DISABLE_COMMENTS constant.', 'herd-experience' );
		}

		if ( self::get_choices() ) {
			return '';
		}

		if ( 'no' === Herd_Experience_Comments::get_site_setting() ) {
			return __( 'A Super Admin has allowed comments on this site.', 'herd-experience' );
		}

		return __( 'Comments are disabled across the network. Only a Super Admin can change this.', 'herd-experience' );
	}

	/**
	 * Render the site setting.
	 *
	 * @return void
	 */
	public static function render_site_setting() {
		$message = self::get_locked_message();
		$current = Herd_Experience_Comments::get_site_setting();
		$choices = $message ? array() : self::get_choices();

		if ( $message ) {
			printf(
				'<p><strong>%s</strong></p><p class="description">%s</p>',
				esc_html( Herd_Experience_Comments::is_disabled() ? __( 'Comments are disabled.', 'herd-experience' ) : __( 'Comments are allowed.', 'herd-experience' ) ),
				esc_html( $message )
			);
			return;
		}
		?>
		<fieldset>
			<legend class="screen-reader-text"><span><?php esc_html_e( 'Comments', 'herd-experience' ); ?></span></legend>
			<?php foreach ( $choices as $value => $label ) : ?>
				<label>
					<input type="radio" name="<?php echo esc_attr( Herd_Experience_Comments::SITE_OPTION ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</label><br>
			<?php endforeach; ?>
			<p class="description"><?php esc_html_e( 'Disabling comments closes comments and pings and removes the comments screens. Block Notes in the editor keep working.', 'herd-experience' ); ?></p>
		</fieldset>
		<?php
	}

	/**
	 * Render the network setting on Network Admin > Settings.
	 *
	 * @return void
	 */
	public static function render_network_setting() {
		$disabled = Herd_Experience_Comments::is_network_disabled();
		?>
		<h2><?php esc_html_e( 'Comments', 'herd-experience' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Disable comments on all sites', 'herd-experience' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><span><?php esc_html_e( 'Disable comments on all sites', 'herd-experience' ); ?></span></legend>
						<label>
							<input type="radio" name="<?php echo esc_attr( Herd_Experience_Comments::NETWORK_OPTION ); ?>" value="yes" <?php checked( $disabled ); ?>>
							<?php esc_html_e( 'Yes', 'herd-experience' ); ?>
						</label><br>
						<label>
							<input type="radio" name="<?php echo esc_attr( Herd_Experience_Comments::NETWORK_OPTION ); ?>" value="no" <?php checked( ! $disabled ); ?>>
							<?php esc_html_e( 'No, let each site decide', 'herd-experience' ); ?>
						</label>
						<p class="description"><?php esc_html_e( "Super Admins can override this for a single site on that site's Settings > General screen.", 'herd-experience' ); ?></p>
					</fieldset>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the network setting.
	 *
	 * Runs on update_wpmu_options, after network/settings.php has checked
	 * the capability; the nonce check is repeated here for clarity.
	 *
	 * @return void
	 */
	public static function save_network_setting() {
		check_admin_referer( 'siteoptions' );

		if ( ! current_user_can( 'manage_network_options' ) || ! isset( $_POST[ Herd_Experience_Comments::NETWORK_OPTION ] ) ) {
			return;
		}

		$value = sanitize_key( wp_unslash( $_POST[ Herd_Experience_Comments::NETWORK_OPTION ] ) );

		update_site_option( Herd_Experience_Comments::NETWORK_OPTION, 'no' === $value ? 'no' : 'yes' );
	}
}
