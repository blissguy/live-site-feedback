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
				__( 'Your account does not have access to leave feedback here.', 'mbm-live-feedback' ),
				[ 'status' => 403 ]
			);
		}

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
