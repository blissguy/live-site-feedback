<?php
/**
 * Receives notifications from MarkUp.io.
 *
 * MarkUp.io does not sign its webhook deliveries — verified against real ones,
 * see the project notes. It hands back a signing key when you register a
 * webhook and then never uses it. So this endpoint is public by necessity, and
 * everything here is built on that assumption:
 *
 *   - the URL itself carries a long random token, which is the actual gate
 *   - a delivery for someone else's workspace is refused
 *   - a delivery can only clear a cache; it never writes content, deletes
 *     anything, or causes an outbound request
 *
 * The worst a forged delivery achieves is making the next admin page load
 * fetch fresh data from MarkUp.io, which it is allowed to do anyway.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhook receiver and registration.
 */
class MBM_LF_Webhooks {

	/**
	 * Events worth being told about.
	 *
	 * @var string[]
	 */
	const EVENTS = [
		'comment_created',
		'comment_reply_created',
		'comment_updated',
		'comment_resolved',
		'comment_unresolved',
	];

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the receiving route.
	 *
	 * The token lives in the path rather than a header because MarkUp.io only
	 * lets us choose a URL.
	 */
	public function register_routes() {
		register_rest_route(
			MBM_LF_Rest::NAMESPACE,
			'/webhook/(?P<token>[A-Za-z0-9]{20,128})',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => [ $this, 'check_token' ],
				'args'                => [
					'token' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * Confirm the caller knows the secret in our URL.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public function check_token( WP_REST_Request $request ) {
		$expected = MBM_LF_Credentials::get( 'webhook_secret' );
		$given    = (string) $request->get_param( 'token' );

		if ( '' === $expected || ! hash_equals( $expected, $given ) ) {
			return new WP_Error(
				'mbm_lf_bad_webhook_token',
				__( 'Not found.', 'mbm-live-feedback' ),
				[ 'status' => 404 ]
			);
		}

		return true;
	}

	/**
	 * Handle a delivery.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( [ 'ignored' => 'unreadable' ], 202 );
		}

		// Someone else's workspace is not our business.
		$workspace = isset( $body['workspaceId'] ) ? (string) $body['workspaceId'] : '';
		$ours      = MBM_LF_Credentials::get( 'workspace_id' );

		if ( '' !== $ours && '' !== $workspace && ! hash_equals( $ours, $workspace ) ) {
			return new WP_REST_Response( [ 'ignored' => 'other workspace' ], 202 );
		}

		$type = isset( $body['type'] ) ? sanitize_key( $body['type'] ) : '';

		/*
		 * The only effect of a delivery: forget what we cached, so the next
		 * time somebody looks at the admin screen it asks MarkUp.io for the
		 * current state. Nothing here trusts the payload's contents.
		 */
		mbm_lf_threads()->flush_cache();

		$this->record_delivery( $type );

		/**
		 * Fires when MarkUp.io reports activity.
		 *
		 * The payload is unauthenticated — MarkUp.io does not sign deliveries —
		 * so treat it as a hint that something changed, not as fact. Read the
		 * real state back through the API before acting on it.
		 *
		 * @param string $type The event type.
		 * @param array  $body The raw delivery.
		 */
		do_action( 'mbm_lf_webhook_received', $type, $body );

		return new WP_REST_Response( [ 'received' => true ], 200 );
	}

	/* ---------------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------------ */

	/**
	 * The URL MarkUp.io should send to, creating the secret if needed.
	 *
	 * @return string
	 */
	public function receiver_url() {
		return rest_url( MBM_LF_Rest::NAMESPACE . '/webhook/' . $this->token() );
	}

	/**
	 * Our webhook secret, generated on first use.
	 *
	 * @return string
	 */
	public function token() {
		$token = MBM_LF_Credentials::get( 'webhook_secret' );

		if ( '' === $token ) {
			$token = wp_generate_password( 48, false, false );

			MBM_LF_Credentials::set( 'webhook_secret', $token );
		}

		return $token;
	}

	/**
	 * Whether MarkUp.io is currently set up to notify us.
	 *
	 * @return bool
	 */
	public function is_registered() {
		return '' !== (string) MBM_LF_Options::get( 'webhook_registration_id' );
	}

	/**
	 * Ask MarkUp.io to notify this site.
	 *
	 * Any registration we made before is removed first, so repeated setup runs
	 * cannot leave a trail of dead endpoints behind.
	 *
	 * @return array|WP_Error
	 */
	public function register() {
		$workspace_id = MBM_LF_Credentials::get( 'workspace_id' );

		if ( '' === $workspace_id ) {
			return new WP_Error(
				'mbm_lf_no_workspace',
				__( 'Run the automatic setup first so we know which workspace to use.', 'mbm-live-feedback' )
			);
		}

		if ( ! mbm_lf_provisioner()->site_is_publicly_reachable() ) {
			return new WP_Error(
				'mbm_lf_not_reachable',
				__( 'MarkUp.io cannot reach this site to notify it yet. This will start working once the site is live.', 'mbm-live-feedback' )
			);
		}

		$this->unregister();

		$result = mbm_lf_api()->register_webhook( $workspace_id, $this->receiver_url(), self::EVENTS );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['id'] ) ) {
			MBM_LF_Options::update( [ 'webhook_registration_id' => $result['id'] ] );
		}

		return $result;
	}

	/**
	 * Remove our registration from MarkUp.io, if we have one.
	 *
	 * @return bool Whether anything was removed.
	 */
	public function unregister() {
		$id = (string) MBM_LF_Options::get( 'webhook_registration_id' );

		if ( '' === $id ) {
			return false;
		}

		mbm_lf_api()->request( 'DELETE', '/api/v2/webhook-registrations/' . rawurlencode( $id ) );

		MBM_LF_Options::update( [ 'webhook_registration_id' => '' ] );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Diagnostics
	 * ------------------------------------------------------------------ */

	/**
	 * Remember that a delivery arrived, for the settings screen.
	 *
	 * @param string $type Event type.
	 */
	private function record_delivery( $type ) {
		MBM_LF_Options::update(
			[
				'last_delivery' => [
					'at'   => time(),
					'type' => $type,
				],
			]
		);
	}

	/**
	 * When we last heard from MarkUp.io.
	 *
	 * @return array{at:int,type:string}|null
	 */
	public function last_delivery() {
		$value = MBM_LF_Options::get( 'last_delivery' );

		return is_array( $value ) && ! empty( $value['at'] ) ? $value : null;
	}
}
