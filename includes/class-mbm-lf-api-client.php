<?php
/**
 * MarkUp API client.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper over the MarkUp REST API.
 */
class MBM_LF_Api_Client {

	/**
	 * Default API host.
	 */
	const BASE_URL = 'https://api.markup.io';

	/**
	 * Perform a request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path beginning with a slash, query string included.
	 * @param array|null $body   Optional JSON body.
	 * @param array      $args   Options: 'no_auth' (bool), 'timeout' (int).
	 * @return array|WP_Error Decoded `data` payload, or WP_Error on failure.
	 */
	public function request( $method, $path, $body = null, $args = [] ) {
		$headers = [
			'Markup-API-Version' => MBM_LF_API_VERSION,
			'Accept'             => 'application/json',
		];

		/*
		 * Most endpoints authenticate with the API key. /auth/exchange is the
		 * exception: it authenticates by publicKey in the request body, and
		 * sending an Authorization header there makes it fail with a 401.
		 * Verified against the live API — the published spec is wrong on this.
		 */
		$no_auth = ! empty( $args['no_auth'] );

		if ( ! $no_auth ) {
			$api_key = MBM_LF_Credentials::get( 'api_key' );

			if ( '' === $api_key ) {
				return new WP_Error(
					'mbm_lf_no_api_key',
					__( 'No API key has been saved yet.', 'mbm-live-feedback' )
				);
			}

			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$request = [
			'method'  => strtoupper( $method ),
			'headers' => $headers,
			'timeout' => isset( $args['timeout'] ) ? (int) $args['timeout'] : 20,
		];

		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
			$request['headers']      = $headers;
			$request['body']         = wp_json_encode( $body );
		}

		$url = $this->base_url() . $path;

		$response = wp_remote_request( $url, $request );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mbm_lf_http_failed',
				sprintf(
					/* translators: %s: underlying error message. */
					__( 'Could not reach MarkUp.io: %s', 'mbm-live-feedback' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$json   = json_decode( $raw, true );

		if ( $status < 200 || $status >= 300 ) {
			return $this->error_from_response( $status, $json );
		}

		if ( ! is_array( $json ) ) {
			return new WP_Error(
				'mbm_lf_bad_response',
				__( 'MarkUp.io returned a response we could not read.', 'mbm-live-feedback' )
			);
		}

		// Successful responses wrap their payload in a `data` envelope. A 204
		// has no body at all.
		return isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : [];
	}

	/* ---------------------------------------------------------------------
	 * Endpoint helpers
	 * ------------------------------------------------------------------ */

	/**
	 * The workspace the saved API key belongs to.
	 *
	 * Doubles as the "is this key valid?" check.
	 *
	 * @return array|WP_Error
	 */
	public function get_workspace() {
		return $this->request( 'GET', '/api/v2/workspace' );
	}

	/**
	 * Token settings for an installation.
	 *
	 * Also reports the request limits, which are worth storing rather than
	 * assuming.
	 *
	 * @param string $public_key Installation public key.
	 * @return array|WP_Error
	 */
	public function get_auth_config( $public_key ) {
		return $this->request(
			'GET',
			'/api/v2/auth/config?publicKey=' . rawurlencode( $public_key )
		);
	}

	/**
	 * Register a new SDK installation.
	 *
	 * Installations cannot be edited afterwards — changing the allowed origins
	 * means creating a new one — so each site gets its own.
	 *
	 * @param string   $workspace_id    Workspace to create it in.
	 * @param string   $public_key_pem  RSA public key, PEM format.
	 * @param string[] $allowed_origins Origins as scheme + host, no trailing slash.
	 * @param array    $extra           Optional 'url' and 'name'.
	 * @return array|WP_Error
	 */
	public function create_installation( $workspace_id, $public_key_pem, $allowed_origins, $extra = [] ) {
		$body = [
			'workspaceId'    => $workspace_id,
			'signingKey'     => $public_key_pem,
			'allowedOrigins' => array_values( $allowed_origins ),
		];

		if ( ! empty( $extra['url'] ) ) {
			$body['url'] = $extra['url'];
		}

		if ( ! empty( $extra['name'] ) ) {
			$body['name'] = $extra['name'];
		}

		return $this->request( 'POST', '/api/v2/installations', $body );
	}

	/**
	 * Fetch a single installation.
	 *
	 * Used to confirm a registration still exists before trusting the
	 * identifiers we have stored for it.
	 *
	 * @param string $installation_id Installation id.
	 * @return array|WP_Error
	 */
	public function get_installation( $installation_id ) {
		return $this->request( 'GET', '/api/v2/installations/' . rawurlencode( $installation_id ) );
	}

	/**
	 * Delete an installation.
	 *
	 * @param string $installation_id Installation id.
	 * @return array|WP_Error
	 */
	public function delete_installation( $installation_id ) {
		return $this->request( 'DELETE', '/api/v2/installations/' . rawurlencode( $installation_id ) );
	}

	/**
	 * All installations in a workspace.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return array|WP_Error
	 */
	public function list_installations( $workspace_id ) {
		return $this->request(
			'GET',
			'/api/v2/installations?workspaceId=' . rawurlencode( $workspace_id )
		);
	}

	/**
	 * Create a markup for a page URL.
	 *
	 * The URL must be publicly reachable — MarkUp fetches it to build the
	 * screenshot.
	 *
	 * @param string $url          Page permalink.
	 * @param string $name         Human-readable name.
	 * @param string $workspace_id Workspace id.
	 * @param string $parent       Optional parent folder id.
	 * @return array|WP_Error
	 */
	public function create_markup_from_url( $url, $name, $workspace_id, $parent = '' ) {
		$body = [
			'url'         => $url,
			'name'        => $name,
			'workspaceId' => $workspace_id,
		];

		if ( '' !== $parent ) {
			$body['parentFolderId'] = $parent;
		}

		return $this->request( 'POST', '/api/v2/markups/url', $body );
	}

	/**
	 * Comment threads on a markup.
	 *
	 * @param string $markup_id Markup id.
	 * @param array  $params    Optional query parameters.
	 * @return array|WP_Error
	 */
	public function list_threads( $markup_id, $params = [] ) {
		$params['markupId'] = $markup_id;

		return $this->request( 'GET', '/api/v2/threads?' . http_build_query( $params ) );
	}

	/**
	 * Registered webhooks for a workspace.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return array|WP_Error
	 */
	public function list_webhooks( $workspace_id ) {
		return $this->request(
			'GET',
			'/api/v2/webhook-registrations?workspaceId=' . rawurlencode( $workspace_id )
		);
	}

	/**
	 * Register a webhook endpoint.
	 *
	 * @param string   $workspace_id Workspace id.
	 * @param string   $url          Receiver URL. Must be publicly reachable.
	 * @param string[] $event_types  Events to subscribe to.
	 * @return array|WP_Error
	 */
	public function register_webhook( $workspace_id, $url, $event_types ) {
		return $this->request(
			'POST',
			'/api/v2/webhook-registrations',
			[
				'workspaceId' => $workspace_id,
				'url'         => $url,
				'enabled'     => true,
				'eventTypes'  => array_values( $event_types ),
			]
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * API host, filterable for testing against another environment.
	 *
	 * @return string
	 */
	private function base_url() {
		/**
		 * Filters the MarkUp API base URL.
		 *
		 * @param string $base_url Base URL with no trailing slash.
		 */
		return untrailingslashit( apply_filters( 'mbm_lf_api_base_url', self::BASE_URL ) );
	}

	/**
	 * Turn an error response into a WP_Error.
	 *
	 * The API returns { error: { code, message, requestId } }. The requestId is
	 * retained because it is the first thing MarkUp support will ask for.
	 *
	 * @param int        $status HTTP status code.
	 * @param array|null $json   Decoded body.
	 * @return WP_Error
	 */
	private function error_from_response( $status, $json ) {
		$error      = isset( $json['error'] ) && is_array( $json['error'] ) ? $json['error'] : [];
		$code       = isset( $error['code'] ) ? (string) $error['code'] : '';
		$message    = isset( $error['message'] ) ? (string) $error['message'] : '';
		$request_id = isset( $error['requestId'] ) ? (string) $error['requestId'] : '';

		if ( 401 === $status || 403 === $status ) {
			$friendly = __( 'MarkUp.io rejected the API key. Check that it was copied in full and has not been revoked.', 'mbm-live-feedback' );
		} elseif ( 404 === $status ) {
			$friendly = __( 'MarkUp.io could not find what we asked for. It may have been deleted.', 'mbm-live-feedback' );
		} elseif ( 429 === $status ) {
			$friendly = __( 'MarkUp.io is rate limiting us. Please wait a moment and try again.', 'mbm-live-feedback' );
		} elseif ( $status >= 500 ) {
			$friendly = __( 'MarkUp.io had a server error. This is on their end — try again shortly.', 'mbm-live-feedback' );
		} else {
			$friendly = '' !== $message
				? $message
				: __( 'MarkUp.io returned an error.', 'mbm-live-feedback' );
		}

		return new WP_Error(
			'mbm_lf_api_error',
			$friendly,
			[
				'status'     => $status,
				'code'       => $code,
				'message'    => $message,
				'request_id' => $request_id,
			]
		);
	}
}
