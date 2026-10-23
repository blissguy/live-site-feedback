<?php
/**
 * The review link: a secret address that opens the feedback bar.
 *
 * Opening a page with ?feedback=<key> gives that browser a pass for the rest
 * of its session, then reloads the page without the key. The key itself never
 * stays in the address bar, so it stays out of browser history, analytics and
 * any link the reviewer copies.
 *
 * The pass only adds people to the feedback bar's audience. It never signs
 * anybody in to WordPress.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks review links and the passes they hand out.
 */
class MBM_LF_Review_Link {

	/**
	 * The query parameter carrying the key.
	 */
	const PARAM = 'feedback';

	/**
	 * The cookie holding the pass.
	 *
	 * The "wp" prefix matters: the page cache on Pressable (and stock Batcache)
	 * skips anybody carrying a cookie whose name starts with it, so a reviewer
	 * is never handed a cached copy of a page without the bar.
	 */
	const COOKIE = 'wp_mbm_lf_review';

	/**
	 * The pass check, worked out once per request.
	 *
	 * @var bool|null
	 */
	private static $has_pass = null;

	/**
	 * Register hooks.
	 */
	public function hooks() {
		// Ahead of canonical redirects, which run at the default priority and
		// would otherwise carry the key along to wherever they send the visitor.
		add_action( 'template_redirect', [ $this, 'handle_request' ], 0 );
	}

	/**
	 * Swap a key in the address for a pass, and keep reviewers' pages uncached.
	 */
	public function handle_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::PARAM ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$given = sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) );

			if ( self::is_active() && is_string( $given ) && hash_equals( self::key(), $given ) ) {
				$this->set_pass();
			}

			// The key leaves the address whether or not it was right, so a
			// wrong or expired one is not left sitting in history either.
			nocache_headers();
			self::cancel_page_cache();
			wp_safe_redirect( remove_query_arg( self::PARAM, self::current_url() ), 302, 'Live Site Feedback' );
			exit;
		}

		if ( self::has_pass() ) {
			// The page is about to include the feedback bar for this visitor
			// only. It must never be stored and handed to anybody else.
			nocache_headers();
			self::cancel_page_cache();
		}
	}

	/**
	 * Whether this browser holds a pass that still works.
	 *
	 * Making a new link, turning the link off, or reaching its last day all
	 * end every pass at once, with nothing to clean up.
	 *
	 * @return bool
	 */
	public static function has_pass() {
		if ( null !== self::$has_pass ) {
			return self::$has_pass;
		}

		self::$has_pass = false;

		if ( self::is_active() && isset( $_COOKIE[ self::COOKIE ] ) ) {
			$cookie = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );

			self::$has_pass = is_string( $cookie ) && hash_equals( self::pass_value(), $cookie );
		}

		return self::$has_pass;
	}

	/**
	 * Whether the review link is on, has a key, and is within its dates.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return MBM_LF_Options::get( 'review_link' ) && '' !== self::key() && ! self::is_expired();
	}

	/**
	 * Whether the link has passed its last day.
	 *
	 * @return bool
	 */
	public static function is_expired() {
		$last_day = (string) MBM_LF_Options::get( 'review_expires' );

		// Compared as Y-m-d strings in the site's own timezone, so the link
		// works to the end of the chosen day wherever the server is.
		return '' !== $last_day && current_time( 'Y-m-d' ) > $last_day;
	}

	/**
	 * The current key.
	 *
	 * @return string
	 */
	public static function key() {
		return (string) MBM_LF_Options::get( 'review_key' );
	}

	/**
	 * A fresh key.
	 *
	 * @return string
	 */
	public static function new_key() {
		return wp_generate_password( 32, false );
	}

	/**
	 * The review link for a page.
	 *
	 * @param string $url Page address. Defaults to the home page.
	 * @return string Empty when there is no key.
	 */
	public static function url( $url = '' ) {
		if ( '' === self::key() ) {
			return '';
		}

		return add_query_arg( self::PARAM, rawurlencode( self::key() ), $url ? $url : home_url( '/' ) );
	}

	/**
	 * What the pass cookie holds.
	 *
	 * Derived from the key rather than the key itself, so the cookie can't be
	 * turned back into a link, and a new key ends every old pass.
	 *
	 * @return string
	 */
	private static function pass_value() {
		return hash_hmac( 'sha256', 'review:' . self::key(), wp_salt( 'auth' ) );
	}

	/**
	 * Hand this browser a pass for the rest of its session.
	 */
	private function set_pass() {
		setcookie(
			self::COOKIE,
			self::pass_value(),
			[
				'expires'  => 0,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);

		self::$has_pass = true;
	}

	/**
	 * The address of this request, on the site's own host.
	 *
	 * Built from home_url() rather than the Host header, which the visitor
	 * controls.
	 *
	 * @return string
	 */
	private static function current_url() {
		$home   = wp_parse_url( home_url() );
		$origin = ( isset( $home['scheme'] ) ? $home['scheme'] : 'https' ) . '://' . ( isset( $home['host'] ) ? $home['host'] : '' );

		if ( isset( $home['port'] ) ) {
			$origin .= ':' . $home['port'];
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- split into parts below, and wp_safe_redirect() sanitizes the result.
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path  = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );

		// Only the path and query are taken, so "//elsewhere.com/" can't
		// smuggle in another host.
		return $origin . '/' . ltrim( $path, '/' ) . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * Tell Batcache not to store this response.
	 */
	private static function cancel_page_cache() {
		if ( function_exists( 'batcache_cancel' ) ) {
			batcache_cancel();
		}
	}
}
