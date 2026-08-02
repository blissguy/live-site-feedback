<?php
/**
 * Sets the site up with MarkUp.io automatically.
 *
 * Given nothing but an API key this creates the signing keys, registers the
 * site, and reads back the token settings — so nobody has to copy identifiers
 * between two browser tabs.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-click setup.
 */
class MBM_LF_Provisioner {

	/**
	 * Run the whole setup.
	 *
	 * Ordered so that nothing is stored until the step that produced it has
	 * succeeded, and so a re-run after a partial failure picks up where it
	 * stopped rather than creating a second installation.
	 *
	 * @return array|WP_Error Summary of what was done.
	 */
	public function provision() {
		if ( ! MBM_LF_Credentials::has( 'api_key' ) ) {
			return new WP_Error(
				'mbm_lf_no_api_key',
				__( 'Add your MarkUp.io API key first.', 'mbm-live-feedback' )
			);
		}

		$done = [];

		// 1. Confirm the key works and find out which workspace it belongs to.
		$workspace = mbm_lf_api()->get_workspace();

		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}

		if ( empty( $workspace['id'] ) ) {
			return new WP_Error(
				'mbm_lf_no_workspace',
				__( 'MarkUp.io did not tell us which workspace this key belongs to.', 'mbm-live-feedback' )
			);
		}

		MBM_LF_Credentials::set( 'workspace_id', $workspace['id'] );

		$done['workspace'] = isset( $workspace['name'] ) ? $workspace['name'] : $workspace['id'];

		// 2. Reuse the existing registration, but only after confirming it is
		// still there. Registrations cannot be edited, so making a spare on
		// every button press would quietly clutter the account — while trusting
		// stored identifiers blindly would leave the site pointing at something
		// that was deleted, which looks like the plugin is broken.
		if ( MBM_LF_Credentials::has( 'public_key' ) && MBM_LF_Credentials::has( 'installation_id' ) ) {
			$existing = mbm_lf_api()->get_installation( MBM_LF_Credentials::get( 'installation_id' ) );

			if ( ! is_wp_error( $existing ) ) {
				$done['installation'] = __( 'Already registered — left as it is.', 'mbm-live-feedback' );

				return $this->register_notifications( $this->read_token_settings( $done ) );
			}

			if ( ! $this->is_missing( $existing ) ) {
				// A network problem or a bad key — do not register again on the
				// strength of an error we do not understand.
				return $existing;
			}

			// It is genuinely gone, so start fresh.
			$this->forget_installation();
		}

		$created = $this->create_installation( $workspace['id'] );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$done['installation'] = $created['id'];
		$done['origins']      = $created['origins'];

		if ( ! empty( $created['key_conflict'] ) ) {
			$done['warning'] = $created['key_conflict'];
		}

		$done = $this->read_token_settings( $done );

		return $this->register_notifications( $done );
	}

	/**
	 * Ask MarkUp.io to notify this site when feedback changes.
	 *
	 * Not fatal if it fails — the admin screen falls back to refreshing on a
	 * timer, so feedback still shows up, just a little later.
	 *
	 * @param array $done Summary so far.
	 * @return array
	 */
	private function register_notifications( array $done ) {
		$result = ( new MBM_LF_Webhooks() )->register();

		$done['notifications'] = is_wp_error( $result )
			? $result->get_error_message()
			: __( 'MarkUp.io will tell this site when feedback changes.', 'mbm-live-feedback' );

		return $done;
	}

	/**
	 * Read and store the sign-in settings for the current registration.
	 *
	 * Failing here is not fatal — the site can still collect feedback through
	 * MarkUp.io sign-in without them.
	 *
	 * @param array $done Summary so far.
	 * @return array
	 */
	private function read_token_settings( array $done ) {
		$config = mbm_lf_api()->get_auth_config( MBM_LF_Credentials::get( 'public_key' ) );

		if ( is_wp_error( $config ) ) {
			$done['token_settings'] = $config->get_error_message();

			return $done;
		}

		if ( ! empty( $config['expectedIss'] ) ) {
			MBM_LF_Credentials::set( 'iss', $config['expectedIss'] );
		}

		if ( ! empty( $config['expectedAud'] ) ) {
			MBM_LF_Credentials::set( 'aud', $config['expectedAud'] );
		}

		if ( ! empty( $config['limits'] ) && is_array( $config['limits'] ) ) {
			// Worth storing rather than assuming — the published docs do not
			// mention these limits at all.
			MBM_LF_Options::update( [ 'rate_limits' => $config['limits'] ] );
		}

		$done['token_settings'] = __( 'Read from MarkUp.io.', 'mbm-live-feedback' );

		return $done;
	}

	/**
	 * Whether an API error means "this no longer exists".
	 *
	 * @param WP_Error $error Error from the API client.
	 * @return bool
	 */
	private function is_missing( WP_Error $error ) {
		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['status'] ) && 404 === (int) $data['status'];
	}

	/**
	 * Drop the identifiers for a registration that is gone.
	 *
	 * The signing key goes too — it only had meaning for that registration.
	 */
	private function forget_installation() {
		MBM_LF_Credentials::set( 'installation_id', '' );
		MBM_LF_Credentials::set( 'public_key', '' );
		MBM_LF_Credentials::set( 'private_key', '' );
	}

	/**
	 * Create the signing keys and register this site.
	 *
	 * @param string $workspace_id Workspace to register in.
	 * @return array|WP_Error
	 */
	private function create_installation( $workspace_id ) {
		$keys = $this->generate_keypair();

		if ( is_wp_error( $keys ) ) {
			return $keys;
		}

		$origins = $this->origins();

		$installation = mbm_lf_api()->create_installation(
			$workspace_id,
			$keys['public'],
			$origins,
			[
				'name' => $this->installation_name(),
			]
		);

		if ( is_wp_error( $installation ) ) {
			return $installation;
		}

		if ( empty( $installation['publicKey'] ) ) {
			return new WP_Error(
				'mbm_lf_no_public_key',
				__( 'MarkUp.io registered the site but did not return a site identifier.', 'mbm-live-feedback' )
			);
		}

		// Store the private key only once the registration it belongs to exists,
		// so we never end up holding a key for nothing.
		MBM_LF_Credentials::set( 'private_key', $keys['private'] );
		MBM_LF_Credentials::set( 'public_key', $installation['publicKey'] );

		if ( ! empty( $installation['id'] ) ) {
			MBM_LF_Credentials::set( 'installation_id', $installation['id'] );
		}

		$result = [
			'id'      => isset( $installation['id'] ) ? $installation['id'] : '',
			'origins' => $origins,
		];

		/*
		 * A key file takes precedence when we read the signing key, so an older
		 * path would quietly win over the key we just generated — and signing
		 * with a key MarkUp.io does not recognise fails in a way that is very
		 * hard to diagnose. Stand the old path down, or say so if we cannot.
		 */
		if ( '' !== MBM_LF_Credentials::get( 'private_key_path' ) ) {
			if ( MBM_LF_Credentials::is_locked( 'private_key_path' ) ) {
				$result['key_conflict'] = sprintf(
					/* translators: %s: PHP constant name. */
					__( 'A signing key file is set in wp-config.php as %s. It does not match the new key we just registered, so remove that line or point it at a matching key.', 'mbm-live-feedback' ),
					MBM_LF_Credentials::constant_name( 'private_key_path' )
				);
			} else {
				MBM_LF_Credentials::set( 'private_key_path', '' );

				$result['key_replaced'] = true;
			}
		}

		return $result;
	}

	/**
	 * Generate an RSA keypair.
	 *
	 * @return array|WP_Error Keys under 'private' and 'public'.
	 */
	public function generate_keypair() {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return new WP_Error(
				'mbm_lf_no_openssl',
				__( 'This server is missing the OpenSSL support needed to create a signing key. Your host can enable it.', 'mbm-live-feedback' )
			);
		}

		$resource = openssl_pkey_new(
			[
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);

		if ( false === $resource ) {
			return new WP_Error(
				'mbm_lf_keygen_failed',
				__( 'Could not create a signing key on this server.', 'mbm-live-feedback' )
			);
		}

		$private = '';

		if ( ! openssl_pkey_export( $resource, $private ) ) {
			return new WP_Error(
				'mbm_lf_keygen_failed',
				__( 'Could not read back the signing key we just created.', 'mbm-live-feedback' )
			);
		}

		$details = openssl_pkey_get_details( $resource );

		if ( ! is_array( $details ) || empty( $details['key'] ) ) {
			return new WP_Error(
				'mbm_lf_keygen_failed',
				__( 'Could not read the public half of the signing key.', 'mbm-live-feedback' )
			);
		}

		return [
			'private' => $private,
			'public'  => $details['key'],
		];
	}

	/**
	 * Origins the feedback bar will run on.
	 *
	 * Registrations cannot be edited later, so anything missing here means
	 * registering the site again. Both the site and home URLs are included
	 * because they differ on plenty of installs.
	 *
	 * @return string[]
	 */
	public function origins() {
		$origins = [];

		foreach ( [ home_url(), site_url() ] as $url ) {
			$origin = $this->origin_from_url( $url );

			if ( '' !== $origin && ! in_array( $origin, $origins, true ) ) {
				$origins[] = $origin;
			}
		}

		/**
		 * Filters the origins registered with MarkUp.io.
		 *
		 * Add a staging or CDN hostname here before running setup if the
		 * feedback bar will also run there.
		 *
		 * @param string[] $origins Origins as scheme + host, no trailing slash.
		 */
		return (array) apply_filters( 'mbm_lf_allowed_origins', $origins );
	}

	/**
	 * Reduce a URL to a bare origin.
	 *
	 * @param string $url Any URL.
	 * @return string
	 */
	private function origin_from_url( $url ) {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];

		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}

		return $origin;
	}

	/**
	 * A recognisable name for this registration inside MarkUp.io.
	 *
	 * @return string
	 */
	private function installation_name() {
		$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		if ( '' === trim( (string) $name ) ) {
			$name = $this->origin_from_url( home_url() );
		}

		return sprintf(
			/* translators: %s: website name. */
			__( '%s (WordPress)', 'mbm-live-feedback' ),
			$name
		);
	}

	/**
	 * Whether the site looks reachable from the outside world.
	 *
	 * MarkUp.io has to fetch a page to take its screenshot, which it cannot do
	 * for a local address. Comments still work; only the preview image does not.
	 *
	 * @return bool
	 */
	public function site_is_publicly_reachable() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		if ( 'localhost' === $host ) {
			return false;
		}

		// Development suffixes that never resolve publicly.
		foreach ( [ '.local', '.test', '.localhost', '.invalid', '.example' ] as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return false;
			}
		}

		// A bare IP in a private range.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether setup has been completed.
	 *
	 * @return bool
	 */
	public function is_provisioned() {
		return MBM_LF_Credentials::has( 'public_key' );
	}
}
