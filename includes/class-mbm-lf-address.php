<?php
/**
 * Notices when the site's address has changed, and works out what that means.
 *
 * Two things MarkUp.io holds are tied to an address and go quietly wrong when
 * it changes:
 *
 *   - the registration's allowed origins, which cannot be edited, so the
 *     feedback bar stops loading on the new address;
 *   - the webhook registration, which keeps notifying the old address.
 *
 * Both happened on learnwithvelo.com, which started life as
 * velo.mystagingwebsite.com. The old address redirects, MarkUp.io does not
 * follow redirects, and nothing anywhere said so.
 *
 * Copies are the other half of the problem. Pushing live to staging, or the
 * other way round, copies the database and with it every identifier this
 * plugin stores. Running setup on the copy used to delete the original's
 * webhook registration, because "remove the one we made before" could not tell
 * that the one it made before belonged to a different site.
 *
 * So before anything is removed, the old address is asked whether it is still
 * this site. See probe().
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Address bookkeeping, probing and the health check.
 */
class MBM_LF_Address {

	/**
	 * The old address no longer answers at all.
	 */
	const GONE = 'gone';

	/**
	 * The old address redirects to this one. The usual domain change.
	 */
	const MOVED_HERE = 'moved_here';

	/**
	 * The old address is this very site under another name.
	 */
	const SAME_SITE = 'same_site';

	/**
	 * The old address is a different, working site — the other half of a copy.
	 */
	const OTHER_SITE = 'other_site';

	/**
	 * Could not tell: a firewall challenge, a server error, a redirect elsewhere.
	 */
	const UNKNOWN = 'unknown';

	/**
	 * Cached health check.
	 */
	const HEALTH = 'mbm_lf_health';

	/**
	 * How long the health check is trusted. It costs two MarkUp.io requests.
	 */
	const HEALTH_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
	}

	/* ---------------------------------------------------------------------
	 * Addresses
	 * ------------------------------------------------------------------ */

	/**
	 * This site's origin, as scheme and host.
	 *
	 * @return string
	 */
	public static function current() {
		return self::origin_of( home_url() );
	}

	/**
	 * Reduce any URL to scheme://host[:port].
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function origin_of( $url ) {
		$parts = wp_parse_url( (string) $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		return strtolower( $parts['scheme'] . '://' . $parts['host'] . ( empty( $parts['port'] ) ? '' : ':' . $parts['port'] ) );
	}

	/**
	 * Just the host, for comparing and for saying in a sentence.
	 *
	 * @param string $url URL or origin.
	 * @return string
	 */
	public static function host_of( $url ) {
		return strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
	}

	/**
	 * The address the connection was last set up or repaired for.
	 *
	 * Empty on sites set up before this was recorded. The health check fills
	 * it in once it has confirmed the connection matches where the site is now.
	 *
	 * @return string
	 */
	public static function recorded() {
		return (string) MBM_LF_Options::get( 'connected_origin' );
	}

	/**
	 * Note that the connection now matches this address.
	 */
	public static function remember() {
		MBM_LF_Options::update( [ 'connected_origin' => self::current() ] );
	}

	/**
	 * Whether the site has moved since the connection was set up.
	 *
	 * Free to call: no request, just two strings.
	 *
	 * @return bool
	 */
	public static function changed() {
		$recorded = self::recorded();

		return '' !== $recorded && $recorded !== self::current();
	}

	/* ---------------------------------------------------------------------
	 * Asking an old address what it is now
	 * ------------------------------------------------------------------ */

	/**
	 * Register the route an old address is asked through.
	 */
	public function register_routes() {
		register_rest_route(
			MBM_LF_Rest::NAMESPACE,
			'/ping',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'answer_ping' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'challenge' => [
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[A-Za-z0-9]{32}$',
					],
				],
			]
		);
	}

	/**
	 * Say whether this site is the one that set the challenge.
	 *
	 * Public on purpose: it reveals nothing except whether a random string,
	 * made a moment ago, is in this site's own cache. A copy of the database
	 * does not help — the challenge is never stored anywhere a copy would
	 * carry, only in a short-lived transient on the site that asked.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function answer_ping( WP_REST_Request $request ) {
		$challenge = (string) $request->get_param( 'challenge' );

		$response = new WP_REST_Response(
			[ 'match' => (bool) get_transient( self::challenge_key( $challenge ) ) ]
		);

		// Every challenge is unique, but a page cache must never answer for us.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );

		return $response;
	}

	/**
	 * What an old address is now.
	 *
	 * Asks it, through the ping route, whether it knows a challenge this site
	 * has just made up. Only the same site can. The request does not follow
	 * redirects, so a domain change shows up as a redirect to here rather than
	 * as a confusing yes.
	 *
	 * The query-string form of the route is used because it works whether or
	 * not pretty permalinks are on at the other end.
	 *
	 * @param string $origin scheme://host of the old address.
	 * @return string One of the class constants.
	 */
	public static function probe( $origin ) {
		static $seen = [];

		$origin = self::origin_of( $origin );

		if ( '' === $origin ) {
			return self::UNKNOWN;
		}

		if ( $origin === self::current() ) {
			return self::SAME_SITE;
		}

		if ( isset( $seen[ $origin ] ) ) {
			return $seen[ $origin ];
		}

		$challenge = wp_generate_password( 32, false, false );

		set_transient( self::challenge_key( $challenge ), 1, 2 * MINUTE_IN_SECONDS );

		$response = wp_remote_get(
			$origin . '/?rest_route=/' . MBM_LF_Rest::NAMESPACE . '/ping&challenge=' . $challenge,
			[
				'redirection' => 0,
				'timeout'     => 8,
			]
		);

		delete_transient( self::challenge_key( $challenge ) );

		$seen[ $origin ] = self::read_probe( $response );

		return $seen[ $origin ];
	}

	/**
	 * Turn the answer into a verdict.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @return string
	 */
	private static function read_probe( $response ) {
		if ( is_wp_error( $response ) ) {
			// No DNS, refused, timed out. Nothing is there to be another site.
			return self::GONE;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 300 && $code < 400 ) {
			$to = self::host_of( wp_remote_retrieve_header( $response, 'location' ) );

			return $to === self::host_of( home_url() ) ? self::MOVED_HERE : self::UNKNOWN;
		}

		if ( 200 === $code ) {
			$json = json_decode( wp_remote_retrieve_body( $response ), true );

			return ! empty( $json['match'] ) ? self::SAME_SITE : self::OTHER_SITE;
		}

		/*
		 * A 404 is a working site that is not this one: another WordPress
		 * without this plugin, or not WordPress at all. Anything else — a
		 * firewall challenge, a server error — says nothing about whose site it
		 * is, and the cautious reading of "do not know" is "leave it alone".
		 */
		return 404 === $code ? self::OTHER_SITE : self::UNKNOWN;
	}

	/**
	 * Whether a verdict means what is at the old address is ours to tidy up.
	 *
	 * @param string $verdict Verdict from probe().
	 * @return bool
	 */
	public static function is_ours( $verdict ) {
		return in_array( $verdict, [ self::GONE, self::MOVED_HERE, self::SAME_SITE ], true );
	}

	/**
	 * Transient key for a challenge.
	 *
	 * @param string $challenge Challenge.
	 * @return string
	 */
	private static function challenge_key( $challenge ) {
		return 'mbm_lf_ping_' . md5( $challenge );
	}

	/* ---------------------------------------------------------------------
	 * Webhook registrations that look like ours
	 * ------------------------------------------------------------------ */

	/**
	 * The token in a registration URL, if it points at this plugin's receiver.
	 *
	 * Both URL shapes are recognised, because rest_url() gives the query-string
	 * one on sites without pretty permalinks.
	 *
	 * @param string $url Registration URL.
	 * @return string Token, or empty when the URL is not one of ours.
	 */
	public static function webhook_token( $url ) {
		if ( preg_match( '#mbm-lf/v1/webhook/([A-Za-z0-9]{20,128})#', rawurldecode( (string) $url ), $match ) ) {
			return $match[1];
		}

		return '';
	}

	/**
	 * Registrations elsewhere that this site's data says it made.
	 *
	 * A registration counts when it points at this plugin's receiver on
	 * another host, and either carries this site's token or is the one this
	 * site has on record. A database copy carries both, which is exactly how
	 * the copy knows about them. Other sites in the same workspace have their
	 * own tokens, and are never touched.
	 *
	 * @param array  $registrations Registrations from MarkUp.io.
	 * @param string $token         This site's token as it was before any change.
	 * @return array Registrations, each with an added `origin`.
	 */
	public static function elsewhere( array $registrations, $token ) {
		$found  = [];
		$here   = self::host_of( home_url() );
		$stored = (string) MBM_LF_Options::get( 'webhook_registration_id' );

		foreach ( $registrations as $registration ) {
			$url    = isset( $registration['url'] ) ? (string) $registration['url'] : '';
			$theirs = self::webhook_token( $url );

			if ( '' === $theirs || self::host_of( $url ) === $here ) {
				continue;
			}

			$mine = ( '' !== $token && hash_equals( $token, $theirs ) )
				|| ( '' !== $stored && isset( $registration['id'] ) && $stored === (string) $registration['id'] );

			if ( $mine ) {
				$registration['origin'] = self::origin_of( $url );
				$found[]                = $registration;
			}
		}

		return $found;
	}

	/* ---------------------------------------------------------------------
	 * Health
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the MarkUp.io side matches this address.
	 *
	 * @param bool $force Skip the cache.
	 * @return array{checked:int,bar:?bool,notify:?bool,stale:string[],error:string}
	 */
	public static function health( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::HEALTH );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$health = [
			'checked' => time(),
			'bar'     => null,
			'notify'  => null,
			'stale'   => [],
			'error'   => '',
		];

		if ( ! MBM_LF_Credentials::has( 'api_key' ) || ! mbm_lf_provisioner()->is_provisioned() ) {
			return $health;
		}

		// The feedback bar loads only where the registration allows it.
		$installation_id = MBM_LF_Credentials::get( 'installation_id' );

		if ( '' !== $installation_id ) {
			$installation = mbm_lf_api()->get_installation( $installation_id );

			if ( is_wp_error( $installation ) ) {
				$health['error'] = $installation->get_error_message();
			} else {
				$allowed       = array_map( [ __CLASS__, 'origin_of' ], (array) ( $installation['allowedOrigins'] ?? [] ) );
				$health['bar'] = ! array_diff( array_map( [ __CLASS__, 'origin_of' ], mbm_lf_provisioner()->origins() ), $allowed );
			}
		}

		$workspace_id = MBM_LF_Credentials::get( 'workspace_id' );

		if ( '' !== $workspace_id ) {
			$response = mbm_lf_api()->list_webhooks( $workspace_id );

			if ( is_wp_error( $response ) ) {
				$health['error'] = $response->get_error_message();
			} else {
				$registrations    = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : [];
				$receiver         = ( new MBM_LF_Webhooks() )->receiver_url();
				$health['notify'] = false;

				foreach ( $registrations as $registration ) {
					if ( isset( $registration['url'] ) && $receiver === $registration['url'] && ! empty( $registration['enabled'] ) ) {
						$health['notify'] = true;
					}
				}

				foreach ( self::elsewhere( $registrations, MBM_LF_Credentials::get( 'webhook_secret' ) ) as $registration ) {
					$health['stale'][] = self::host_of( $registration['origin'] );
				}

				$health['stale'] = array_values( array_unique( $health['stale'] ) );
			}
		}

		// Sites set up before the address was recorded learn it here, once the
		// connection is confirmed to match where the site actually is.
		if ( '' === self::recorded() && true === $health['bar'] && true === $health['notify'] ) {
			self::remember();
		}

		set_transient( self::HEALTH, $health, self::HEALTH_TTL );

		return $health;
	}

	/**
	 * Forget the cached health check, after anything that changes the answer.
	 */
	public static function forget_health() {
		delete_transient( self::HEALTH );
	}

	/* ---------------------------------------------------------------------
	 * Notice
	 * ------------------------------------------------------------------ */

	/**
	 * Say so on every admin screen when the address has changed.
	 *
	 * Worth the space: until it is repaired, the feedback bar and the
	 * notifications are pointing at an address the site no longer has, and
	 * nothing else would ever mention it. Costs no request to decide.
	 */
	public function render_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! self::changed() ) {
			return;
		}

		$url = add_query_arg(
			[
				'page' => MBM_LF_Settings::PAGE,
				'tab'  => 'setup',
			],
			admin_url( 'admin.php' )
		);
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Live Site Feedback: this site\'s address has changed.', 'mbm-live-feedback' ); ?></strong>
				<?php
				printf(
					/* translators: 1: old address, 2: new address. */
					esc_html__( 'It was set up for %1$s and is now %2$s. Until it\'s repaired, the feedback bar and MarkUp.io notifications may not work here.', 'mbm-live-feedback' ),
					'<code>' . esc_html( self::host_of( self::recorded() ) ) . '</code>',
					'<code>' . esc_html( self::host_of( self::current() ) ) . '</code>'
				);
				?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $url ); ?>">
					<?php esc_html_e( 'Open setup', 'mbm-live-feedback' ); ?>
				</a>
				<span class="description" style="margin-left:.5rem;">
					<?php esc_html_e( 'Then press Check and repair setup.', 'mbm-live-feedback' ); ?>
				</span>
			</p>
		</div>
		<?php
	}
}
