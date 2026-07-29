<?php
/**
 * Creates a MarkUp.io entry per page, on demand.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Looks after the WordPress page to MarkUp.io mapping.
 */
class MBM_LF_Markups {

	/**
	 * Meta key holding the last failure reason.
	 */
	const META_ERROR = '_mbm_lf_last_error';

	/**
	 * How long to wait before retrying a page that failed, in seconds.
	 */
	const RETRY_DELAY = 900;

	/**
	 * Ensure a post has a markup, creating one if needed.
	 *
	 * @param int  $post_id Post id.
	 * @param bool $force   Create a new one even if the post already has one.
	 * @return string|WP_Error Markup id.
	 */
	public function ensure_for_post( $post_id, $force = false ) {
		$post_id = (int) $post_id;

		if ( ! $force ) {
			$existing = MBM_LF_Post_Meta::markup_id( $post_id );

			if ( '' !== $existing ) {
				return $existing;
			}
		}

		if ( ! MBM_LF_Credentials::has( 'api_key' ) ) {
			return new WP_Error(
				'mbm_lf_no_api_key',
				__( 'Add your MarkUp.io API key before creating IDs automatically.', 'mbm-live-feedback' )
			);
		}

		$workspace_id = MBM_LF_Credentials::get( 'workspace_id' );

		if ( '' === $workspace_id ) {
			return new WP_Error(
				'mbm_lf_no_workspace',
				__( 'Run the automatic setup first so we know which workspace to use.', 'mbm-live-feedback' )
			);
		}

		/*
		 * MarkUp.io loads the page itself when creating an entry for it, and
		 * refuses with "URL did not resolve" if it cannot. There is no point
		 * spending a request to be told that, and no point recording it as a
		 * failure to retry later — it will keep failing until the site is live.
		 */
		if ( ! mbm_lf_provisioner()->site_is_publicly_reachable() ) {
			// Drop any earlier failure note: it was caused by this same thing,
			// and leaving it behind would just be stale clutter.
			$this->clear_failure( $post_id );

			return new WP_Error(
				'mbm_lf_not_reachable',
				__( 'MarkUp.io has to be able to load this page to set it up, and this site is not reachable from the internet yet. Once the site is live this will work by itself. In the meantime you can paste in an ID you created against a public address.', 'mbm-live-feedback' )
			);
		}

		// One request at a time per post. Without this, several editors opening
		// the same page at once would each create their own duplicate.
		$lock = 'mbm_lf_lock_' . $post_id;

		if ( get_transient( $lock ) ) {
			return new WP_Error(
				'mbm_lf_locked',
				__( 'Already setting this page up. Refresh in a moment.', 'mbm-live-feedback' )
			);
		}

		set_transient( $lock, 1, 60 );

		$permalink = get_permalink( $post_id );
		$title     = get_the_title( $post_id );

		if ( '' === trim( (string) $title ) ) {
			$title = sprintf(
				/* translators: %d: post id. */
				__( 'Page %d', 'mbm-live-feedback' ),
				$post_id
			);
		}

		$markup = mbm_lf_api()->create_markup_from_url(
			$permalink,
			$title,
			$workspace_id
		);

		delete_transient( $lock );

		if ( is_wp_error( $markup ) ) {
			// A public-looking address can still be unreachable — behind a
			// firewall, or not in DNS yet. Say what actually went wrong rather
			// than the generic "we could not find that".
			$data = $markup->get_error_data();

			if ( is_array( $data ) && isset( $data['message'] ) && false !== stripos( $data['message'], 'did not resolve' ) ) {
				$markup = new WP_Error(
					'mbm_lf_url_unreachable',
					sprintf(
						/* translators: %s: the page address. */
						__( 'MarkUp.io could not load %s, so it could not set this page up. Check the page is reachable from outside your network.', 'mbm-live-feedback' ),
						$permalink
					)
				);
			}

			$this->record_failure( $post_id, $markup->get_error_message() );

			return $markup;
		}

		if ( empty( $markup['id'] ) ) {
			$message = __( 'MarkUp.io created the entry but did not return an ID for it.', 'mbm-live-feedback' );

			$this->record_failure( $post_id, $message );

			return new WP_Error( 'mbm_lf_no_markup_id', $message );
		}

		update_post_meta( $post_id, MBM_LF_Post_Meta::META_MARKUP_ID, $markup['id'] );
		delete_post_meta( $post_id, self::META_ERROR );

		return $markup['id'];
	}

	/**
	 * Create a markup during a front-end view, if that is wanted and sensible.
	 *
	 * This runs while somebody is waiting for a page, so it is deliberately
	 * cautious: only for people who can edit the page, only once, and never
	 * again for a while after a failure.
	 *
	 * @param int $post_id Post id.
	 * @return string Markup id, or an empty string.
	 */
	public function maybe_create_on_view( $post_id ) {
		if ( ! MBM_LF_Options::get( 'auto_create_markups' ) ) {
			return '';
		}

		// Only somebody who could fix a problem should be able to trigger the
		// slow path, and only they see the bar on an unprovisioned page anyway.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return '';
		}

		if ( $this->is_backing_off( $post_id ) ) {
			return '';
		}

		$result = $this->ensure_for_post( $post_id );

		return is_wp_error( $result ) ? '' : $result;
	}

	/**
	 * The MarkUp.io entry used when the whole site shares one list.
	 *
	 * Creates it on first need so nobody has to fetch an ID by hand.
	 *
	 * @return string Markup id, or an empty string.
	 */
	public function site_wide_id() {
		$existing = (string) MBM_LF_Options::get( 'default_markup_id' );

		if ( '' !== $existing ) {
			return $existing;
		}

		if ( ! MBM_LF_Options::get( 'auto_create_markups' ) ) {
			return '';
		}

		// Same reasoning as per-page: only somebody who could fix a problem
		// should be able to trigger the slow path.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		if ( get_transient( 'mbm_lf_backoff_site' ) ) {
			return '';
		}

		$created = $this->create_site_wide();

		return is_wp_error( $created ) ? '' : $created;
	}

	/**
	 * Create the shared MarkUp.io entry for this site.
	 *
	 * @return string|WP_Error Markup id.
	 */
	public function create_site_wide() {
		if ( ! MBM_LF_Credentials::has( 'api_key' ) ) {
			return new WP_Error(
				'mbm_lf_no_api_key',
				__( 'Add your MarkUp.io API key first.', 'mbm-live-feedback' )
			);
		}

		$workspace_id = MBM_LF_Credentials::get( 'workspace_id' );

		if ( '' === $workspace_id ) {
			return new WP_Error(
				'mbm_lf_no_workspace',
				__( 'Run the automatic setup first so we know which workspace to use.', 'mbm-live-feedback' )
			);
		}

		if ( ! mbm_lf_provisioner()->site_is_publicly_reachable() ) {
			delete_transient( 'mbm_lf_site_error' );

			return new WP_Error(
				'mbm_lf_not_reachable',
				__( 'MarkUp.io has to be able to load this site to set it up, and it is not reachable from the internet yet. Once the site is live this will work by itself.', 'mbm-live-feedback' )
			);
		}

		if ( get_transient( 'mbm_lf_lock_site' ) ) {
			return new WP_Error(
				'mbm_lf_locked',
				__( 'Already setting this up. Refresh in a moment.', 'mbm-live-feedback' )
			);
		}

		set_transient( 'mbm_lf_lock_site', 1, 60 );

		$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		if ( '' === trim( (string) $name ) ) {
			$name = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}

		$markup = mbm_lf_api()->create_markup_from_url( home_url(), $name, $workspace_id );

		delete_transient( 'mbm_lf_lock_site' );

		if ( is_wp_error( $markup ) ) {
			set_transient( 'mbm_lf_site_error', $markup->get_error_message(), DAY_IN_SECONDS );
			set_transient( 'mbm_lf_backoff_site', 1, self::RETRY_DELAY );

			return $markup;
		}

		if ( empty( $markup['id'] ) ) {
			$message = __( 'MarkUp.io created the entry but did not return an ID for it.', 'mbm-live-feedback' );

			set_transient( 'mbm_lf_site_error', $message, DAY_IN_SECONDS );
			set_transient( 'mbm_lf_backoff_site', 1, self::RETRY_DELAY );

			return new WP_Error( 'mbm_lf_no_markup_id', $message );
		}

		MBM_LF_Options::update( [ 'default_markup_id' => $markup['id'] ] );

		delete_transient( 'mbm_lf_site_error' );
		delete_transient( 'mbm_lf_backoff_site' );

		return $markup['id'];
	}

	/**
	 * The last failure recorded for the shared entry.
	 *
	 * @return string
	 */
	public function site_wide_error() {
		$value = get_transient( 'mbm_lf_site_error' );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Forget any failure recorded for the shared entry.
	 */
	public function clear_site_wide_failure() {
		delete_transient( 'mbm_lf_site_error' );
		delete_transient( 'mbm_lf_backoff_site' );
	}

	/**
	 * Note a failure and hold off retrying for a while.
	 *
	 * A broken key would otherwise mean a failed API call on every single page
	 * view, which is slow for the editor and rude to MarkUp.io.
	 *
	 * @param int    $post_id Post id.
	 * @param string $message Why it failed.
	 */
	private function record_failure( $post_id, $message ) {
		update_post_meta( $post_id, self::META_ERROR, $message );

		set_transient( 'mbm_lf_backoff_' . $post_id, 1, self::RETRY_DELAY );
	}

	/**
	 * Whether we are waiting before retrying this post.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public function is_backing_off( $post_id ) {
		return (bool) get_transient( 'mbm_lf_backoff_' . $post_id );
	}

	/**
	 * Clear a stored failure so the next attempt goes through immediately.
	 *
	 * @param int $post_id Post id.
	 */
	public function clear_failure( $post_id ) {
		delete_post_meta( $post_id, self::META_ERROR );
		delete_transient( 'mbm_lf_backoff_' . $post_id );
	}

	/**
	 * The last failure recorded for a post.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function last_error( $post_id ) {
		$value = get_post_meta( $post_id, self::META_ERROR, true );

		return is_string( $value ) ? $value : '';
	}
}
