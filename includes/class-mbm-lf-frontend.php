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
	 * Script handle for the screenshot plugin.
	 */
	const HANDLE_SCREENSHOT = 'mbm-lf-sdk-screenshot';

	/**
	 * Script handle for our bootstrap.
	 */
	const HANDLE_BOOT = 'mbm-lf-feedback';

	/**
	 * Style handle for the guest sign-in form.
	 */
	const HANDLE_GUEST_CSS = 'mbm-lf-guest';

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

		$deps       = [ self::HANDLE_SDK ];
		$screenshot = (bool) MBM_LF_Options::get( 'screenshots' );

		if ( $screenshot ) {
			// After the library: the plugin attaches itself to it, and logs an
			// error and does nothing if it arrives first.
			wp_enqueue_script( self::HANDLE_SCREENSHOT, MBM_LF_SDK_SCREENSHOT_URL, [ self::HANDLE_SDK ], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion

			$deps[] = self::HANDLE_SCREENSHOT;
		}

		wp_enqueue_script(
			self::HANDLE_BOOT,
			MBM_LF_URL . 'assets/js/feedback.js',
			$deps,
			MBM_LF_VERSION,
			true
		);

		$config = [
			'publicKey'  => MBM_LF_Credentials::get( 'public_key' ),
			'markupId'   => $markup_id,
			'render'     => $this->render_options(),
			'debug'      => $this->is_debug(),
			'screenshot' => $screenshot,
		];

		/*
		 * Only hand over a token endpoint when we can actually produce a token.
		 * The library treats a failing callback as a hard error rather than
		 * falling back to its own sign-in, so offering one we cannot honour
		 * would leave the visitor stuck instead of merely signing in manually.
		 */
		$can_sign = ( new MBM_LF_Tokens() )->can_sign();

		if ( is_user_logged_in() && $can_sign ) {
			$config['tokenEndpoint'] = rest_url( MBM_LF_Rest::NAMESPACE . '/token' );
			$config['nonce']         = wp_create_nonce( 'wp_rest' );
		} elseif ( $can_sign ) {
			// Somebody without an account here: they give a name and email
			// once, and WordPress vouches for them as a guest. Nothing is
			// created here and nobody is logged in.
			$config['guestEndpoint'] = rest_url( MBM_LF_Rest::NAMESPACE . '/guest-token' );
			$config['guestText']     = $this->guest_text();

			wp_enqueue_style( self::HANDLE_GUEST_CSS, MBM_LF_URL . 'assets/css/guest.css', [], MBM_LF_VERSION );
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
		$hashes = [
			self::HANDLE_SDK        => MBM_LF_SDK_SRI,
			self::HANDLE_SCREENSHOT => MBM_LF_SDK_SCREENSHOT_SRI,
		];

		if ( empty( $hashes[ $handle ] ) ) {
			return $tag;
		}

		return str_replace(
			' src=',
			' integrity="' . esc_attr( $hashes[ $handle ] ) . '" crossorigin="anonymous" src=',
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
		$post_id = self::current_page_id();

		if ( ! $post_id ) {
			// Categories, tags, archives and search results have no post of
			// their own to attach comments to, so they only make sense when
			// everything shares one list.
			return $this->shared_mode() && (bool) MBM_LF_Options::get( 'archives' );
		}

		if ( ! in_array( get_post_type( $post_id ), MBM_LF_Options::post_types(), true ) ) {
			return false;
		}

		if ( MBM_LF_Post_Meta::is_disabled( $post_id ) ) {
			return false;
		}

		if ( 'chosen' === MBM_LF_Options::get( 'placement' ) ) {
			return MBM_LF_Post_Meta::is_enabled( $post_id );
		}

		return true;
	}

	/**
	 * The page being viewed, when it is one somebody could pick.
	 *
	 * The blog page and the WooCommerce shop page are pages in the editor, with
	 * their own settings box, but WordPress shows them as lists of posts and
	 * products. They count as themselves here, so turning one on or off in its
	 * editor does what it says.
	 *
	 * @return int Post id, or 0 for an archive or search page.
	 */
	public static function current_page_id() {
		if ( is_singular() ) {
			return (int) get_queried_object_id();
		}

		if ( is_home() && ! is_front_page() ) {
			return (int) get_option( 'page_for_posts' );
		}

		if ( function_exists( 'is_shop' ) && is_shop() && function_exists( 'wc_get_page_id' ) ) {
			return max( 0, (int) wc_get_page_id( 'shop' ) );
		}

		return 0;
	}

	/**
	 * Whether the current visitor is allowed to see the feedback bar.
	 *
	 * @return bool
	 */
	public function user_can_see() {
		$mode = (string) MBM_LF_Options::get( 'visibility' );
		$user = wp_get_current_user();
		$can  = self::settings_allow( $user, $mode );

		// A review link pass lets anybody in, logged in or not.
		if ( ! $can && MBM_LF_Review_Link::has_pass() ) {
			$can = true;
		}

		// An individual switched off on their profile is out, whatever the
		// settings say. This is the narrower rule, so it goes last.
		if ( $can && MBM_LF_User_Meta::is_disabled( $user->ID ) ) {
			$can = false;
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
	 * Whether the site-wide visibility setting covers somebody.
	 *
	 * Takes a user rather than reading the current one so the profile screen
	 * can ask the same question about the account being edited. It deliberately
	 * ignores that account's own switch: this answers "is this person in the
	 * audience", and the switch is what takes them back out of it.
	 *
	 * @param WP_User     $user The user to check. ID 0 for a logged-out visitor.
	 * @param string|null $mode Visibility mode, or null to read the setting.
	 * @return bool
	 */
	public static function settings_allow( $user, $mode = null ) {
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		if ( null === $mode ) {
			$mode = (string) MBM_LF_Options::get( 'visibility' );
		}

		switch ( $mode ) {
			case 'everyone':
				return true;

			case 'logged_in':
				return $user->exists();

			case 'roles':
				$allowed = (array) MBM_LF_Options::get( 'roles' );

				return $user->exists() && (bool) array_intersect( $allowed, (array) $user->roles );

			case 'capability':
			default:
				$capability = (string) MBM_LF_Options::get( 'capability' );

				return user_can( $user, $capability ? $capability : 'edit_posts' );
		}
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
		 * Comments can be left anywhere on the page by design — the header and
		 * footer get feedback too. A site that genuinely needs to fence them
		 * into one area can set `commentableContainer` here, but note it is an
		 * allow-list: anything outside it stops accepting comments entirely.
		 *
		 * @param array $options Render options.
		 */
		return (array) apply_filters( 'mbm_lf_render_options', $options );
	}

	/**
	 * Wording for the guest sign-in form.
	 *
	 * @return array<string, string>
	 */
	private function guest_text() {
		return [
			'open'    => __( 'Leave feedback', 'mbm-live-feedback' ),
			'title'   => __( 'Leave feedback', 'mbm-live-feedback' ),
			'intro'   => __( 'Your name and email go with each comment you leave.', 'mbm-live-feedback' ),
			'name'    => __( 'Name', 'mbm-live-feedback' ),
			'email'   => __( 'Email', 'mbm-live-feedback' ),
			'submit'  => __( 'Start', 'mbm-live-feedback' ),
			'cancel'  => __( 'Cancel', 'mbm-live-feedback' ),
			'working' => __( 'Starting…', 'mbm-live-feedback' ),
			'failed'  => __( 'Couldn\'t start feedback. Try again.', 'mbm-live-feedback' ),
		];
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
