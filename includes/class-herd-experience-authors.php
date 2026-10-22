<?php
/**
 * Author archive and enumeration removal.
 *
 * @package HerdExperience
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stop author archives from exposing usernames.
 *
 * Author archive slugs are login names, and `?author=N` redirects to them,
 * so anyone can walk user IDs to collect SSO usernames.
 */
final class Herd_Experience_Authors {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		// Before redirect_canonical (priority 10) turns ?author=N into /author/{slug}/.
		add_action( 'template_redirect', array( __CLASS__, 'block_author_archive' ), 1 );
		add_filter( 'author_link', array( __CLASS__, 'filter_author_link' ) );
		add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'remove_users_sitemap' ), 10, 2 );
	}

	/**
	 * Whether author archives are disabled on this site.
	 *
	 * @return bool
	 */
	private static function is_disabled() {
		/**
		 * Filters whether author archives are disabled.
		 *
		 * @param bool $disabled Default true.
		 */
		return (bool) apply_filters( 'herd_experience_disable_author_archives', true );
	}

	/**
	 * Serve a 404 for author archives, including `?author=N` and author feeds.
	 *
	 * @return void
	 */
	public static function block_author_archive() {
		global $wp_query;

		if ( ! is_author() || ! self::is_disabled() ) {
			return;
		}

		if ( is_feed() ) {
			// WP::send_headers() already sent the feed content type.
			header( 'Content-Type: ' . get_option( 'html_type' ) . '; charset=' . get_option( 'blog_charset' ) );
		}

		$wp_query->set_404();
		// set_404() keeps the feed flag, which would still render the author feed.
		$wp_query->is_feed = false;
		status_header( 404 );
	}

	/**
	 * Point author links at the homepage instead of a 404.
	 *
	 * @param string $link Author archive URL.
	 * @return string
	 */
	public static function filter_author_link( $link ) {
		return self::is_disabled() ? home_url( '/' ) : $link;
	}

	/**
	 * Remove the users provider from the core sitemap.
	 *
	 * @param WP_Sitemaps_Provider|false $provider Provider instance.
	 * @param string                     $name     Provider name.
	 * @return WP_Sitemaps_Provider|false
	 */
	public static function remove_users_sitemap( $provider, $name ) {
		return 'users' === $name && self::is_disabled() ? false : $provider;
	}
}
