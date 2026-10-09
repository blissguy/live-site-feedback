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
				__( 'MarkUp.io didn\'t say which workspace this key belongs to.', 'mbm-live-feedback' )
			);
		}

		MBM_LF_Credentials::set( 'workspace_id', $workspace['id'] );

		$done['workspace'] = isset( $workspace['name'] ) ? $workspace['name'] : $workspace['id'];

		// Everything from here on is reported back in plain words, so somebody
		// who pressed the button can see what changed and what did not.
		$lines = [];

		// 2. The registration, which decides where the feedback bar may load.
		$installation = $this->ensure_installation( $workspace['id'], $lines );

		if ( is_wp_error( $installation ) ) {
			return $installation;
		}

		if ( ! empty( $installation['key_conflict'] ) ) {
			$done['warning'] = $installation['key_conflict'];
		}

		$done = $this->read_token_settings( $done );

		/*
		 * 3. Is this a copy of a site that is still running somewhere else?
		 * Asked once, before anything below changes, because the answer decides
		 * the shared list, the notification token and whether Motion should
		 * stay on.
		 */
		$copy_of = '';

		foreach ( $installation['previous'] as $origin ) {
			if ( MBM_LF_Address::OTHER_SITE === MBM_LF_Address::probe( $origin ) ) {
				$copy_of = $origin;
				break;
			}
		}

		$this->ensure_own_list( $lines );
		$this->settle_motion( '' !== $copy_of, $lines );

		// 4. Notifications. Not fatal: Motion checks for changes by itself
		// every few minutes, and the admin screen refreshes on a timer.
		$synced = ( new MBM_LF_Webhooks() )->sync( '' !== $copy_of );

		if ( is_wp_error( $synced ) ) {
			$lines[] = $synced->get_error_message();
		} else {
			$lines = array_merge( $lines, $synced );
		}

		// Only promise the five-minute check where it actually runs.
		$lines[] = ( new MBM_LF_Motion_Tasks() )->is_active()
			? __( 'If this site is behind Cloudflare or another firewall, it may turn MarkUp.io\'s notifications away. Motion still gets new comments within five minutes.', 'mbm-live-feedback' )
			: __( 'If this site is behind Cloudflare or another firewall, it may turn MarkUp.io\'s notifications away. The Feedback screen still refreshes every few minutes.', 'mbm-live-feedback' );

		MBM_LF_Address::remember();
		MBM_LF_Address::forget_health();

		update_option(
			'mbm_lf_repair_report',
			[
				'at'    => time(),
				'lines' => $lines,
			],
			false
		);

		$done['lines'] = $lines;

		return $done;
	}

	/**
	 * Make sure the registration allows this address, registering again if not.
	 *
	 * A registration's allowed addresses cannot be edited, so a site that has
	 * moved needs a new one. The old one is removed only when every address it
	 * allowed is gone, redirects here, or is this same site — otherwise it
	 * still belongs to a working copy, and removing it would switch off the
	 * feedback bar over there.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param array  $lines        Report lines, added to.
	 * @return array|WP_Error `previous` holds the other addresses it allowed.
	 */
	private function ensure_installation( $workspace_id, array &$lines ) {
		$want     = array_map( [ 'MBM_LF_Address', 'origin_of' ], $this->origins() );
		$previous = [];
		$old_id   = '';

		$recorded = MBM_LF_Address::recorded();

		if ( '' !== $recorded && ! in_array( $recorded, $want, true ) ) {
			$previous[] = $recorded;
		}

		// Reuse the existing registration, but only after confirming it is
		// still there. Registrations cannot be edited, so making a spare on
		// every button press would quietly clutter the account — while trusting
		// stored identifiers blindly would leave the site pointing at something
		// that was deleted, which looks like the plugin is broken.
		if ( MBM_LF_Credentials::has( 'public_key' ) && MBM_LF_Credentials::has( 'installation_id' ) ) {
			$existing = mbm_lf_api()->get_installation( MBM_LF_Credentials::get( 'installation_id' ) );

			if ( ! is_wp_error( $existing ) ) {
				$allowed  = array_map( [ 'MBM_LF_Address', 'origin_of' ], (array) ( $existing['allowedOrigins'] ?? [] ) );
				$previous = array_values( array_unique( array_merge( $previous, array_diff( $allowed, $want ) ) ) );

				if ( ! array_diff( $want, $allowed ) ) {
					$lines[] = __( 'The feedback bar is already allowed on this address. Left as it is.', 'mbm-live-feedback' );

					return [ 'previous' => $previous ];
				}

				$old_id = (string) MBM_LF_Credentials::get( 'installation_id' );
			} elseif ( ! $this->is_missing( $existing ) ) {
				// A network problem or a bad key — do not register again on the
				// strength of an error we do not understand.
				return $existing;
			} else {
				// It is genuinely gone, so start fresh.
				$this->forget_installation();
			}
		}

		$created = $this->create_installation( $workspace_id );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$created['previous'] = $previous;

		$lines[] = sprintf(
			/* translators: %s: list of web addresses. */
			__( 'Registered this site with MarkUp.io for %s.', 'mbm-live-feedback' ),
			implode( ', ', array_map( [ 'MBM_LF_Address', 'host_of' ], $want ) )
		);

		if ( '' === $old_id ) {
			return $created;
		}

		$others  = array_diff( $allowed, $want );
		$ours    = true;
		$working = false;

		foreach ( $others as $origin ) {
			$verdict = MBM_LF_Address::probe( $origin );
			$ours    = $ours && MBM_LF_Address::is_ours( $verdict );
			$working = $working || MBM_LF_Address::OTHER_SITE === $verdict;
		}

		$hosts = implode( ', ', array_map( [ 'MBM_LF_Address', 'host_of' ], $others ) );

		if ( $ours ) {
			$deleted = mbm_lf_api()->delete_installation( $old_id );

			if ( is_wp_error( $deleted ) ) {
				$lines[] = sprintf(
					/* translators: 1: web address, 2: error message. */
					__( 'Couldn\'t remove the old registration for %1$s: %2$s', 'mbm-live-feedback' ),
					'' !== $hosts ? $hosts : __( 'the old address', 'mbm-live-feedback' ),
					$deleted->get_error_message()
				);
			} elseif ( '' !== $hosts ) {
				$lines[] = sprintf(
					/* translators: %s: web address. */
					__( 'Removed the old registration for %s.', 'mbm-live-feedback' ),
					$hosts
				);
			}
		} else {
			$lines[] = sprintf(
				/* translators: %s: web address. */
				$working
					? __( 'Kept the registration for %s, because a working site still uses it.', 'mbm-live-feedback' )
					: __( 'Kept the registration for %s, because it couldn\'t be confirmed that nothing there uses it.', 'mbm-live-feedback' ),
				$hosts
			);
		}

		return $created;
	}

	/**
	 * Make sure the shared list is this site's own.
	 *
	 * Copying a site copies the list id, so a staging copy would otherwise
	 * collect its comments in the live site's list — and, with Motion on, turn
	 * them into tasks on the live board. Which site a list belongs to is the
	 * address it was made for. A list made for an address that has since moved
	 * here, or gone, stays: that is a domain change, and its feedback carries
	 * on. A list made for a site that is still running elsewhere is that
	 * site's, and this one gets its own.
	 *
	 * Every address's list is remembered, and the memory travels with the
	 * database. That is what makes pushing staging back over live safe: live
	 * arrives holding staging's list, finds its own address in the memory, and
	 * switches back to the list it had, rather than adopting staging's.
	 *
	 * @param array $lines Report lines, added to.
	 */
	private function ensure_own_list( array &$lines ) {
		if ( ! MBM_LF_Options::get( 'shared_thread' ) ) {
			return;
		}

		$here = MBM_LF_Address::current();
		$list = (string) MBM_LF_Options::get( 'default_markup_id' );
		$map  = (array) MBM_LF_Options::get( 'lists_by_origin' );

		if ( ! empty( $map[ $here ] ) && $map[ $here ] !== $list ) {
			MBM_LF_Options::update( [ 'default_markup_id' => $map[ $here ] ] );
			$this->forget_thread_note();

			$lines[] = __( 'Switched back to this address\'s own feedback list.', 'mbm-live-feedback' );

			return;
		}

		if ( '' === $list ) {
			return;
		}

		// Who the list was made for: remembered, or else asked of MarkUp.io.
		$owner = (string) array_search( $list, $map, true );

		if ( '' === $owner ) {
			$markup = mbm_lf_api()->request( 'GET', '/api/v2/markups/' . rawurlencode( $list ) );
			$owner  = is_wp_error( $markup ) ? '' : MBM_LF_Address::origin_of( $markup['url'] ?? '' );
		}

		if ( '' !== $owner && $owner !== $here && MBM_LF_Address::OTHER_SITE === MBM_LF_Address::probe( $owner ) ) {
			MBM_LF_Options::update( [ 'default_markup_id' => '' ] );
			mbm_lf_markups()->clear_site_wide_failure();

			$created = mbm_lf_markups()->create_site_wide();

			if ( is_wp_error( $created ) ) {
				// Put the old one back rather than leave the site with no list.
				MBM_LF_Options::update( [ 'default_markup_id' => $list ] );

				$lines[] = sprintf(
					/* translators: 1: web address, 2: error message. */
					__( 'This site still shares its feedback list with %1$s, because a separate one couldn\'t be made: %2$s', 'mbm-live-feedback' ),
					MBM_LF_Address::host_of( $owner ),
					$created->get_error_message()
				);

				return;
			}

			$this->forget_thread_note();

			$map[ $owner ] = $list;
			$list          = $created;

			$lines[] = sprintf(
				/* translators: %s: web address. */
				__( 'This site is a copy of %s, so it now has its own feedback list. Comments left here no longer appear in that site\'s list.', 'mbm-live-feedback' ),
				MBM_LF_Address::host_of( $owner )
			);
		}

		$map[ $here ] = $list;

		MBM_LF_Options::update( [ 'lists_by_origin' => $map ] );
	}

	/**
	 * Keep Motion off on a staging copy, and back on when live is repaired.
	 *
	 * A staging copy arrives with Motion switched on and pointed at the live
	 * site's project, so every comment left while testing would become a real
	 * task. Pausing is recorded, so that if staging is later pushed over live,
	 * repairing live turns it back on — something a person paused by hand is
	 * left alone.
	 *
	 * @param bool  $is_copy Whether another working copy of this site exists.
	 * @param array $lines   Report lines, added to.
	 */
	private function settle_motion( $is_copy, array &$lines ) {
		if ( ! mbm_lf_motion()->has_key() ) {
			return;
		}

		$production = 'production' === wp_get_environment_type();
		$enabled    = (bool) MBM_LF_Options::get( 'motion_enabled' );

		if ( ! $production && $enabled && ( $is_copy || MBM_LF_Address::changed() ) ) {
			MBM_LF_Options::update(
				[
					'motion_enabled'          => false,
					'motion_paused_by_repair' => true,
				]
			);

			$lines[] = __( 'Paused Motion, because this is a staging site. Switch it back on in the Motion integration tab if you want tasks from here, ideally in a separate project.', 'mbm-live-feedback' );

			return;
		}

		if ( $production && ! $enabled && MBM_LF_Options::get( 'motion_paused_by_repair' ) ) {
			MBM_LF_Options::update(
				[
					'motion_enabled'          => true,
					'motion_paused_by_repair' => false,
				]
			);

			$lines[] = __( 'Switched Motion back on. It had been paused on a staging copy of this site.', 'mbm-live-feedback' );
		}
	}

	/**
	 * Start the Motion check afresh after the list changes.
	 *
	 * Its note describes the old list. Compared against the new one, every
	 * thread would look new and the old ones deleted.
	 */
	private function forget_thread_note() {
		delete_option( MBM_LF_Motion_Watch::STATE );
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
				__( 'MarkUp.io registered the site but didn\'t return a site identifier.', 'mbm-live-feedback' )
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
					__( 'A signing key file is set in wp-config.php as %s. It doesn\'t match the new key, so remove that line or point it at a matching key.', 'mbm-live-feedback' ),
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
				__( 'Couldn\'t create a signing key on this server.', 'mbm-live-feedback' )
			);
		}

		$private = '';

		if ( ! openssl_pkey_export( $resource, $private ) ) {
			return new WP_Error(
				'mbm_lf_keygen_failed',
				__( 'Couldn\'t read back the new signing key.', 'mbm-live-feedback' )
			);
		}

		$details = openssl_pkey_get_details( $resource );

		if ( ! is_array( $details ) || empty( $details['key'] ) ) {
			return new WP_Error(
				'mbm_lf_keygen_failed',
				__( 'Couldn\'t read the public half of the signing key.', 'mbm-live-feedback' )
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
