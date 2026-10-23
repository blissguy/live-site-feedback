<?php
/**
 * The endpoint the feedback bar asks for a token.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's REST routes.
 */
class MBM_LF_Rest {

	/**
	 * Route namespace.
	 */
	const NAMESPACE = 'mbm-lf/v1';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/token',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'issue_token' ],
				'permission_callback' => [ $this, 'can_request_token' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/guest-token',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'issue_guest_token' ],
				'permission_callback' => [ $this, 'can_request_guest_token' ],
				'args'                => [
					'name'  => [
						'type'     => 'string',
						'required' => true,
					],
					'email' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * Who may ask for a token.
	 *
	 * Being logged in is not enough on its own — the visitor also has to be
	 * someone the site has chosen to show the feedback bar to.
	 *
	 * @return bool|WP_Error
	 */
	public function can_request_token() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'mbm_lf_not_logged_in',
				__( 'You need to be signed in to leave feedback as yourself.', 'mbm-live-feedback' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! ( new MBM_LF_Frontend() )->user_can_see() ) {
			return new WP_Error(
				'mbm_lf_not_permitted',
				__( 'Your account doesn\'t have access to leave feedback here.', 'mbm-live-feedback' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Who may ask for a guest token.
	 *
	 * Only visitors who aren't logged in, and only when the feedback bar is
	 * open to them: every visitor, or a review link pass.
	 *
	 * @return bool|WP_Error
	 */
	public function can_request_guest_token() {
		if ( is_user_logged_in() ) {
			return new WP_Error(
				'mbm_lf_logged_in',
				__( 'You\'re logged in, so you leave feedback under your own name.', 'mbm-live-feedback' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! MBM_LF_Options::get( 'enabled' ) || ! ( new MBM_LF_Frontend() )->user_can_see() ) {
			return new WP_Error(
				'mbm_lf_not_permitted',
				__( 'Feedback isn\'t open here. Ask for a new review link.', 'mbm-live-feedback' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! $this->guest_within_limit() ) {
			return new WP_Error(
				'mbm_lf_too_many',
				__( 'Too many attempts. Try again in an hour.', 'mbm-live-feedback' ),
				[ 'status' => 429 ]
			);
		}

		return true;
	}

	/**
	 * Issue a token for a guest.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function issue_guest_token( WP_REST_Request $request ) {
		$name  = trim( sanitize_text_field( (string) $request->get_param( 'name' ) ) );
		$email = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( '' === $name ) {
			return new WP_Error( 'mbm_lf_no_name', __( 'Enter your name.', 'mbm-live-feedback' ), [ 'status' => 400 ] );
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'mbm_lf_bad_email', __( 'Enter a valid email address.', 'mbm-live-feedback' ), [ 'status' => 400 ] );
		}

		/*
		 * Somebody with an account here logs in instead. Otherwise anyone could
		 * type a team member's email and have MarkUp.io match their comments to
		 * that person.
		 */
		if ( email_exists( $email ) ) {
			return new WP_Error(
				'mbm_lf_has_account',
				__( 'That email belongs to an account on this site. Log in to leave feedback.', 'mbm-live-feedback' ),
				[ 'status' => 409 ]
			);
		}

		$token = ( new MBM_LF_Tokens() )->mint_guest( mb_substr( $name, 0, 80 ), $email );

		if ( is_wp_error( $token ) ) {
			$token->add_data( [ 'status' => 500 ] );

			return $token;
		}

		nocache_headers();

		$response = new WP_REST_Response(
			[
				'token'     => $token,
				'expiresIn' => MBM_LF_Tokens::LIFETIME,
			]
		);

		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );

		return $response;
	}

	/**
	 * Count a guest token request, and say whether it is within the limit.
	 *
	 * Per visitor address, per hour. The library asks again every few minutes
	 * while somebody is commenting, so the limit is set well above that.
	 *
	 * @return bool
	 */
	private function guest_within_limit() {
		/**
		 * Filters how many guest tokens one address may get in an hour.
		 *
		 * @param int $limit Requests per hour.
		 */
		$limit = (int) apply_filters( 'mbm_lf_guest_token_limit', 60 );

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'mbm_lf_guest_' . md5( $ip );

		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Issue a token for the current user.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function issue_token( WP_REST_Request $request ) {
		$tokens = new MBM_LF_Tokens();
		$user   = wp_get_current_user();

		$token = $tokens->mint( $user );

		if ( is_wp_error( $token ) ) {
			$token->add_data( [ 'status' => 500 ] );

			return $token;
		}

		// A token is per-user and short-lived, so it must never be cached by a
		// page cache, a CDN, or the browser.
		nocache_headers();

		$response = new WP_REST_Response(
			[
				'token'     => $token,
				'expiresIn' => MBM_LF_Tokens::LIFETIME,
			]
		);

		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );

		return $response;
	}
}
