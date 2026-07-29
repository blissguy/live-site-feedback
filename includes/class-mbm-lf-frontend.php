<?php
/**
 * Loads the feedback bar on the front end.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether to show the feedback bar, and loads it when so.
 */
class MBM_LF_Frontend {

	/**
	 * Script handle for the vendor SDK.
	 */
	const HANDLE_SDK = 'mbm-lf-sdk';

	/**
	 * Script handle for our bootstrap.
	 */
	const HANDLE_BOOT = 'mbm-lf-feedback';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'script_loader_tag', [ $this, 'add_integrity_attributes' ], 10, 2 );
	}

	/**
	 * Load the SDK and our bootstrap when appropriate.
	 */
	public function enqueue() {
		if ( ! $this->should_render() ) {
			return;
		}

		$markup_id = $this->markup_id();

		wp_enqueue_script( self::HANDLE_SDK, MBM_LF_SDK_URL, [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion

		wp_enqueue_script(
			self::HANDLE_BOOT,
			MBM_LF_URL . 'assets/js/feedback.js',
			[ self::HANDLE_SDK ],
			MBM_LF_VERSION,
			true
		);

		$config = [
			'publicKey' => MBM_LF_Credentials::get( 'public_key' ),
			'markupId'  => $markup_id,
			'render'    => $this->render_options(),
			'debug'     => $this->is_debug(),
		];

		/*
		 * Only hand over a token endpoint when we can actually produce a token.
		 * The library treats a failing callback as a hard error rather than
		 * falling back to its own sign-in, so offering one we cannot honour
		 * would leave the visitor stuck instead of merely signing in manually.
		 */
		if ( is_user_logged_in() && ( new MBM_LF_Tokens() )->can_sign() ) {
			$config['tokenEndpoint'] = rest_url( MBM_LF_Rest::NAMESPACE . '/token' );
			$config['nonce']         = wp_create_nonce( 'wp_rest' );
		}

		wp_add_inline_script(
			self::HANDLE_BOOT,
			'window.mbmLiveFeedback = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * Add the integrity and crossorigin attributes to the vendor script.
	 *
	 * WordPress has no native way to do this, and it matters here: the file is
	 * loaded from someone else's CDN, so if it ever changes underneath us the
	 * browser should refuse to run it rather than execute something unverified.
	 *
	 * @param string $tag    The script tag.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public function add_integrity_attributes( $tag, $handle ) {
		if ( self::HANDLE_SDK !== $handle || '' === MBM_LF_SDK_SRI ) {
			return $tag;
		}

		return str_replace(
			' src=',
			' integrity="' . esc_attr( MBM_LF_SDK_SRI ) . '" crossorigin="anonymous" src=',
			$tag
		);
	}

	/* ---------------------------------------------------------------------
	 * Decisions
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the feedback bar should load for this request.
	 *
	 * Ordered cheapest-first so the common "no" costs almost nothing.
	 *
	 * @return bool
	 */
	public function should_render() {
		$render = $this->check_should_render();

		/**
		 * Filters whether the feedback bar loads for this request.
		 *
		 * @param bool $render Whether to load it.
		 */
		return (bool) apply_filters( 'mbm_lf_should_render', $render );
	}

	/**
	 * The unfiltered decision.
	 *
	 * @return bool
	 */
	private function check_should_render() {
		if ( ! MBM_LF_Options::get( 'enabled' ) ) {
			return false;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_embed() || is_preview() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( $this->is_builder_context() ) {
			return false;
		}

		if ( ! $this->location_enabled() ) {
			return false;
		}

		if ( ! $this->user_can_see() ) {
			return false;
		}

		// Credentials last: these cost a database read, and by this point we
		// already know the request is a candidate.
		if ( ! MBM_LF_Credentials::has( 'public_key' ) ) {
			return false;
		}

		return '' !== $this->markup_id();
	}

	/**
	 * Whether we are inside a page builder's editing canvas.
	 *
	 * The feedback bar would fight the builder for clicks, and pin positions
	 * captured against an editing UI would be meaningless.
	 *
	 * @return bool
	 */
	private function is_builder_context() {
		foreach ( [ 'bricks_is_builder', 'bricks_is_builder_iframe', 'bricks_is_builder_main' ] as $fn ) {
			if ( function_exists( $fn ) && call_user_func( $fn ) ) {
				return true;
			}
		}

		// Other builders that render the site inside their own editor.
		$builder_params = [
			'elementor-preview',
			'fl_builder',
			'ct_builder',
			'et_fb',
			'vc_editable',
			'tve',
		];

		foreach ( $builder_params as $param ) {
			if ( isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether everything on the site shares one comment list.
	 *
	 * @return bool
	 */
	public function shared_mode() {
		return (bool) MBM_LF_Options::get( 'shared_thread' );
	}

	/**
	 * Whether the bar may appear on whatever is being viewed.
	 *
	 * @return bool
	 */
	private function location_enabled() {
		if ( ! is_singular() ) {
			// Archives, the blog index, search results and the shop have no post
			// of their own to attach comments to, so they only make sense when
			// everything shares one list.
			return $this->shared_mode();
		}

		return in_array( get_post_type(), MBM_LF_Options::post_types(), true );
	}

	/**
	 * Whether the current visitor is allowed to see the feedback bar.
	 *
	 * @return bool
	 */
	public function user_can_see() {
		$mode = MBM_LF_Options::get( 'visibility' );
		$can  = false;

		switch ( $mode ) {
			case 'everyone':
				$can = true;
				break;

			case 'logged_in':
				$can = is_user_logged_in();
				break;

			case 'roles':
				$allowed = (array) MBM_LF_Options::get( 'roles' );
				$user    = wp_get_current_user();
				$can     = $user && $user->exists() && array_intersect( $allowed, (array) $user->roles );
				break;

			case 'capability':
			default:
				$capability = (string) MBM_LF_Options::get( 'capability' );
				$can        = current_user_can( $capability ? $capability : 'edit_posts' );
				break;
		}

		/**
		 * Filters whether this visitor may see the feedback bar.
		 *
		 * @param bool   $can  Whether they may.
		 * @param string $mode The configured visibility mode.
		 */
		return (bool) apply_filters( 'mbm_lf_user_can_see', (bool) $can, $mode );
	}

	/**
	 * The markup this request should attach comments to.
	 *
	 * A per-post value wins; otherwise the site-wide fallback is used.
	 *
	 * @return string
	 */
	public function markup_id() {
		$markup_id = '';

		if ( is_singular() ) {
			$post_id = get_queried_object_id();

			// An explicit "no feedback here" beats everything, including the
			// shared list.
			if ( $post_id && MBM_LF_Post_Meta::is_disabled( $post_id ) ) {
				return (string) apply_filters( 'mbm_lf_markup_id', '' );
			}

			/*
			 * When the site shares one list, page-level ids are deliberately
			 * ignored rather than taking precedence. Otherwise turning the
			 * setting on would leave pages that already had their own id
			 * quietly excluded from the shared list, which is the opposite of
			 * what "keep it all in one place" should mean. The ids stay stored,
			 * so switching back restores them.
			 */
			if ( $post_id && ! $this->shared_mode() ) {
				$markup_id = MBM_LF_Post_Meta::markup_id( $post_id );

				// First view of a page that has not been set up yet: create its
				// entry now. Only editors reach this, and only once.
				if ( '' === $markup_id && in_array( get_post_type( $post_id ), MBM_LF_Options::post_types(), true ) ) {
					$markup_id = mbm_lf_markups()->maybe_create_on_view( $post_id );
				}
			}
		}

		if ( '' === $markup_id && $this->shared_mode() ) {
			$markup_id = mbm_lf_markups()->site_wide_id();
		}

		/**
		 * Filters the markup comments attach to for this request.
		 *
		 * @param string $markup_id Markup id, or an empty string for none.
		 */
		return (string) apply_filters( 'mbm_lf_markup_id', $markup_id );
	}

	/**
	 * Options passed to the SDK's render() call.
	 *
	 * @return array
	 */
	private function render_options() {
		$options = [
			'position'  => (string) MBM_LF_Options::get( 'position' ),
			'theme'     => (string) MBM_LF_Options::get( 'theme' ),
			'draggable' => true,
			'zIndex'    => 99999,
		];

		/**
		 * Filters the SDK render options.
		 *
		 * Useful for pointing `commentableContainer` at a theme's content
		 * wrapper so visitors cannot pin comments onto site furniture.
		 *
		 * @param array $options Render options.
		 */
		return (array) apply_filters( 'mbm_lf_render_options', $options );
	}

	/**
	 * Whether to log SDK activity to the browser console.
	 *
	 * @return bool
	 */
	private function is_debug() {
		if ( isset( $_GET['mbm_lf_debug'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return current_user_can( 'manage_options' );
		}

		return false;
	}
}
