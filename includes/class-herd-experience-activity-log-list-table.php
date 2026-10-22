<?php
/**
 * Activity log list table.
 *
 * Loaded only when the Activity Log screen renders, after WP_List_Table.
 *
 * @package HerdExperience
 */

defined( 'ABSPATH' ) || exit;

/**
 * List activity log entries with site, user, and action filters.
 */
final class Herd_Experience_Activity_Log_List_Table extends WP_List_Table {
	/**
	 * Entries per page.
	 */
	const PER_PAGE = 50;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'entry',
				'plural'   => 'entries',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Read a filter from the query string.
	 *
	 * @param string $key Query var.
	 * @return string
	 */
	private function get_filter( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters on a GET screen.
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/**
	 * URL of the current screen with one filter set, back on page 1.
	 *
	 * @param string     $key   Query var.
	 * @param string|int $value Value.
	 * @return string
	 */
	private function filter_url( $key, $value ) {
		return remove_query_arg( 'paged', add_query_arg( $key, $value ) );
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		$columns = array(
			'created_at' => __( 'Date', 'herd-experience' ),
			'user_login' => __( 'User', 'herd-experience' ),
			'blog_id'    => __( 'Site', 'herd-experience' ),
			'action'     => __( 'Action', 'herd-experience' ),
			'summary'    => __( 'Details', 'herd-experience' ),
		);

		if ( ! is_multisite() ) {
			unset( $columns['blog_id'] );
		}

		return $columns;
	}

	/**
	 * Load the current page of entries.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$site   = $this->get_filter( 'site' );
		$result = Herd_Experience_Activity_Log::query(
			array(
				'blog_id'    => is_multisite() ? ( '' === $site ? null : (int) $site ) : get_current_blog_id(),
				'user_login' => $this->get_filter( 'user' ),
				'action'     => $this->get_filter( 'log_action' ),
				'per_page'   => self::PER_PAGE,
				'page'       => $this->get_pagenum(),
			)
		);

		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Filters above the table.
	 *
	 * @param string $which `top` or `bottom`.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$action = $this->get_filter( 'log_action' );
		?>
		<div class="alignleft actions">
			<?php if ( is_multisite() ) : ?>
				<label class="screen-reader-text" for="herd-log-site"><?php esc_html_e( 'Site ID', 'herd-experience' ); ?></label>
				<input type="number" min="0" id="herd-log-site" name="site" value="<?php echo esc_attr( $this->get_filter( 'site' ) ); ?>" placeholder="<?php esc_attr_e( 'Site ID (0 = network)', 'herd-experience' ); ?>" style="width: 12em;">
			<?php endif; ?>
			<label class="screen-reader-text" for="herd-log-user"><?php esc_html_e( 'Username', 'herd-experience' ); ?></label>
			<input type="text" id="herd-log-user" name="user" value="<?php echo esc_attr( $this->get_filter( 'user' ) ); ?>" placeholder="<?php esc_attr_e( 'Username', 'herd-experience' ); ?>">
			<label class="screen-reader-text" for="herd-log-action"><?php esc_html_e( 'Action', 'herd-experience' ); ?></label>
			<select id="herd-log-action" name="log_action">
				<option value=""><?php esc_html_e( 'All actions', 'herd-experience' ); ?></option>
				<?php foreach ( Herd_Experience_Activity_Log::get_actions() as $name ) : ?>
					<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $action, $name ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'herd-experience' ), '', 'filter', false ); ?>
		</div>
		<?php
	}

	/**
	 * Message when there are no entries.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No activity found.', 'herd-experience' );
	}

	/**
	 * Default column output.
	 *
	 * @param object $item        Entry.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return esc_html( $item->$column_name ?? '' );
	}

	/**
	 * Date column, shown in the site's timezone.
	 *
	 * @param object $item Entry.
	 * @return string
	 */
	protected function column_created_at( $item ) {
		$time = strtotime( $item->created_at . ' UTC' );

		return sprintf(
			'<time datetime="%s">%s</time>',
			esc_attr( gmdate( 'c', $time ) ),
			esc_html( wp_date( 'Y-m-d g:i:s a', $time ) )
		);
	}

	/**
	 * User column, linked to that user's entries.
	 *
	 * @param object $item Entry.
	 * @return string
	 */
	protected function column_user_login( $item ) {
		if ( '' === $item->user_login ) {
			return '&mdash;';
		}

		return sprintf( '<a href="%s">%s</a>', esc_url( $this->filter_url( 'user', $item->user_login ) ), esc_html( $item->user_login ) );
	}

	/**
	 * Site column, linked to that site's entries.
	 *
	 * @param object $item Entry.
	 * @return string
	 */
	protected function column_blog_id( $item ) {
		$blog_id = (int) $item->blog_id;

		if ( 0 === $blog_id ) {
			$label = __( 'Network', 'herd-experience' );
		} else {
			$site  = get_site( $blog_id );
			$label = $site ? $site->path : '#' . $blog_id;
		}

		return sprintf( '<a href="%s">%s</a>', esc_url( $this->filter_url( 'site', $blog_id ) ), esc_html( $label ) );
	}

	/**
	 * Action column, linked to that action's entries.
	 *
	 * @param object $item Entry.
	 * @return string
	 */
	protected function column_action( $item ) {
		return sprintf( '<a href="%s"><code>%s</code></a>', esc_url( $this->filter_url( 'log_action', $item->action ) ), esc_html( $item->action ) );
	}
}
