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

		/*
		 * The workspace check above is not a filter. Registrations cannot be
		 * scoped to a markup — url, workspaceId, eventTypes and enabled are the
		 * only fields there are — so every comment on every markup in the
		 * workspace arrives here, including other sites'. Refuse those before
		 * anything at all happens to them: no cache clearing, no scheduled
		 * fetch, and above all no action fired, so nothing downstream can treat
		 * another site's feedback as ours.
		 */
		if ( ! mbm_lf_threads()->owns_delivery( $body ) ) {
			return new WP_REST_Response( [ 'ignored' => 'other markup' ], 202 );
		}

		$type = isset( $body['type'] ) ? sanitize_key( $body['type'] ) : '';

		/*
		 * The only effect of a delivery: forget what we cached, so the next
		 * time somebody looks at the admin screen it asks MarkUp.io for the
		 * current state. Nothing here trusts the payload's contents.
		 */
		mbm_lf_threads()->flush_cache();

		/*
		 * Fetch again shortly, off this request. Clearing the cache alone would
		 * leave the toolbar count blank until somebody opened the feedback
		 * screen — worst at exactly the moment feedback has just arrived.
		 */
		if ( ! wp_next_scheduled( 'mbm_lf_refresh_feedback' ) ) {
			wp_schedule_single_event( time() + 15, 'mbm_lf_refresh_feedback' );
		}

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
	 * Make MarkUp.io notify this address, and only this one.
	 *
	 * Keeps a registration that already points at this exact receiver, so
	 * running repair on a healthy site changes nothing. Otherwise registers
	 * one. Then tidies registrations this site made for addresses it used to
	 * have — but only once probe() has shown the old address is gone, redirects
	 * here, or is this same site. One belonging to a working copy elsewhere is
	 * left exactly as it is; removing it is what used to break the other site.
	 *
	 * This replaces "delete the registration on record, then register again".
	 * After a database copy the one on record belongs to the other site.
	 *
	 * @param bool $new_token Start a fresh token, so this site stops sharing
	 *                        one with a copy of itself.
	 * @return array|WP_Error Plain-language lines saying what was done.
	 */
	public function sync( $new_token = false ) {
		$workspace_id = MBM_LF_Credentials::get( 'workspace_id' );

		if ( '' === $workspace_id ) {
			return new WP_Error(
				'mbm_lf_no_workspace',
				__( 'Press Set up automatically on the Setup tab first.', 'mbm-live-feedback' )
			);
		}

		if ( ! mbm_lf_provisioner()->site_is_publicly_reachable() ) {
			return new WP_Error(
				'mbm_lf_not_reachable',
				__( 'MarkUp.io can\'t reach this site to notify it yet. This starts working once the site is live.', 'mbm-live-feedback' )
			);
		}

		$response = mbm_lf_api()->list_webhooks( $workspace_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$registrations = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : [];

		// Read before any change: a copy is recognised by the token it shares.
		$old_token = MBM_LF_Credentials::get( 'webhook_secret' );
		$elsewhere = MBM_LF_Address::elsewhere( $registrations, $old_token );
		$lines     = [];

		if ( $new_token ) {
			MBM_LF_Credentials::set( 'webhook_secret', wp_generate_password( 48, false, false ) );
		}

		$receiver = $this->receiver_url();
		$keep     = '';

		foreach ( $registrations as $registration ) {
			if ( isset( $registration['url'], $registration['id'] ) && $receiver === $registration['url'] && ! empty( $registration['enabled'] ) ) {
				$keep = (string) $registration['id'];
				break;
			}
		}

		if ( '' === $keep ) {
			$created = mbm_lf_api()->register_webhook( $workspace_id, $receiver, self::EVENTS );

			if ( is_wp_error( $created ) ) {
				return $created;
			}

			$keep    = isset( $created['id'] ) ? (string) $created['id'] : '';
			// Asked, not confirmed: a firewall can still turn deliveries away.
			$lines[] = sprintf(
				/* translators: %s: web address. */
				__( 'Asked MarkUp.io to notify %s.', 'mbm-live-feedback' ),
				MBM_LF_Address::host_of( home_url() )
			);
		} else {
			$lines[] = __( 'MarkUp.io was already set to notify this address. Left as it is.', 'mbm-live-feedback' );
		}

		MBM_LF_Options::update( [ 'webhook_registration_id' => $keep ] );

		$here = MBM_LF_Address::host_of( home_url() );

		foreach ( $registrations as $registration ) {
			$id  = isset( $registration['id'] ) ? (string) $registration['id'] : '';
			$url = isset( $registration['url'] ) ? (string) $registration['url'] : '';

			// Earlier registrations for this same address, under an old token,
			// can only ever be ours, and MarkUp.io would be notifying a dead URL.
			if ( '' !== $id && $id !== $keep && '' !== MBM_LF_Address::webhook_token( $url ) && MBM_LF_Address::host_of( $url ) === $here ) {
				$this->delete_registration( $id );
			}
		}


		foreach ( $elsewhere as $registration ) {
			$host    = MBM_LF_Address::host_of( $registration['origin'] );
			$verdict = MBM_LF_Address::probe( $registration['origin'] );

			if ( MBM_LF_Address::is_ours( $verdict ) ) {
				$deleted = $this->delete_registration( (string) $registration['id'] );

				$lines[] = true === $deleted
					? sprintf(
						/* translators: %s: web address. */
						__( 'Stopped MarkUp.io notifying the old address %s.', 'mbm-live-feedback' ),
						$host
					)
					: sprintf(
						/* translators: 1: web address, 2: error message. */
						__( 'Couldn\'t stop MarkUp.io notifying the old address %1$s: %2$s', 'mbm-live-feedback' ),
						$host,
						$deleted->get_error_message()
					);

				continue;
			}

			$lines[] = sprintf(
				/* translators: %s: web address. */
				MBM_LF_Address::OTHER_SITE === $verdict
					? __( '%s is another working copy of this site, so MarkUp.io keeps notifying it too. Run Check and repair setup there as well.', 'mbm-live-feedback' )
					: __( 'Couldn\'t tell whether %s is still this site, so MarkUp.io keeps notifying it.', 'mbm-live-feedback' ),
				$host
			);
		}

		return array_values( array_filter( $lines ) );
	}

	/**
	 * Remove one registration from MarkUp.io.
	 *
	 * @param string $id Registration id.
	 * @return true|WP_Error
	 */
	private function delete_registration( $id ) {
		$result = mbm_lf_api()->request( 'DELETE', '/api/v2/webhook-registrations/' . rawurlencode( $id ) );

		return is_wp_error( $result ) ? $result : true;
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
