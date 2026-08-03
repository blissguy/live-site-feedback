<?php
/**
 * Motion API client.
 *
 * Read-only so far: workspaces, projects and users, so the settings screen can
 * offer real choices instead of asking anyone to paste identifiers. Nothing
 * here creates anything in Motion.
 *
 * Motion allows 120 requests a minute on a Teams plan and 12 on an individual
 * one, so every list here is cached and none of it runs on a front-end request.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to Motion.
 */
class MBM_LF_Motion_Client {

	/**
	 * API root.
	 */
	const BASE_URL = 'https://api.usemotion.com/v1';

	/**
	 * How long to keep the lists Motion is unlikely to change hourly.
	 */
	const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Cache key for workspaces.
	 */
	const CACHE_WORKSPACES = 'mbm_lf_motion_workspaces';

	/**
	 * Cache key for projects.
	 */
	const CACHE_PROJECTS = 'mbm_lf_motion_projects';

	/**
	 * Cache key for the address book.
	 */
	const CACHE_USERS = 'mbm_lf_motion_users';

	/**
	 * Whether a key has been saved.
	 *
	 * @return bool
	 */
	public function has_key() {
		return MBM_LF_Credentials::has( 'motion_api_key' );
	}

	/**
	 * Perform a request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path beginning with a slash, query string included.
	 * @param array|null $body   Optional JSON body.
	 * @return array|WP_Error Decoded response.
	 */
	public function request( $method, $path, $body = null ) {
		$key = MBM_LF_Credentials::get( 'motion_api_key' );

		if ( '' === $key ) {
			return new WP_Error(
				'mbm_lf_motion_no_key',
				__( 'No Motion key has been saved yet.', 'mbm-live-feedback' )
			);
		}

		// Motion authenticates with its own header, not a bearer token.
		$args = [
			'method'  => strtoupper( $method ),
			'timeout' => 20,
			'headers' => [
				'X-API-Key' => $key,
				'Accept'    => 'application/json',
			],
		];

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base_url() . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mbm_lf_motion_unreachable',
				sprintf(
					/* translators: %s: underlying error message. */
					__( 'Could not reach Motion: %s', 'mbm-live-feedback' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$json   = json_decode( $raw, true );

		if ( $status < 200 || $status >= 300 ) {
			return $this->error_from_response( $status, $json, $response );
		}

		if ( 204 === $status || '' === trim( (string) $raw ) ) {
			return [];
		}

		return is_array( $json ) ? $json : [];
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Every workspace this key can see.
	 *
	 * Each carries its own statuses, including which one counts as resolved —
	 * that is how a finished task gets the right status in a workspace whose
	 * owner renamed "Completed" to something else.
	 *
	 * @param bool $force Skip the cache.
	 * @return array|WP_Error
	 */
	public function workspaces( $force = false ) {
		return $this->cached_list( self::CACHE_WORKSPACES, '/workspaces', 'workspaces', $force );
	}

	/**
	 * Projects, optionally limited to one workspace.
	 *
	 * @param string $workspace_id Workspace to filter by.
	 * @param bool   $force        Skip the cache.
	 * @return array|WP_Error
	 */
	public function projects( $workspace_id = '', $force = false ) {
		$path = '/projects';

		if ( '' !== $workspace_id ) {
			$path .= '?workspaceId=' . rawurlencode( $workspace_id );
		}

		$projects = $this->cached_list( self::CACHE_PROJECTS . '_' . md5( $workspace_id ), $path, 'projects', $force );

		if ( is_wp_error( $projects ) || '' === $workspace_id ) {
			return $projects;
		}

		/*
		 * Only the single-project endpoint is clearly documented, so it is not
		 * certain the list one honours a workspace filter. Filtering again here
		 * costs nothing and means the picker is right either way.
		 */
		return array_values(
			array_filter(
				$projects,
				static function ( $project ) use ( $workspace_id ) {
					return ! isset( $project['workspaceId'] ) || $project['workspaceId'] === $workspace_id;
				}
			)
		);
	}

	/**
	 * Everyone in a workspace.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param bool   $force        Skip the cache.
	 * @return array|WP_Error
	 */
	public function users( $workspace_id = '', $force = false ) {
		$path = '/users';

		if ( '' !== $workspace_id ) {
			$path .= '?workspaceId=' . rawurlencode( $workspace_id );
		}

		return $this->cached_list( self::CACHE_USERS . '_' . md5( $workspace_id ), $path, 'users', $force );
	}

	/**
	 * Email address to Motion user id.
	 *
	 * This is what lets a page's WordPress author be assigned automatically:
	 * the team uses the same addresses in both systems, so nobody has to map
	 * anything by hand. Cached, so assignment normally costs no requests.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return array<string, string> Lowercased email to user id.
	 */
	public function email_map( $workspace_id = '' ) {
		$users = $this->users( $workspace_id );

		if ( is_wp_error( $users ) ) {
			return [];
		}

		$map = [];

		foreach ( $users as $user ) {
			if ( empty( $user['email'] ) || empty( $user['id'] ) ) {
				continue;
			}

			$map[ strtolower( trim( $user['email'] ) ) ] = (string) $user['id'];
		}

		return $map;
	}

	/**
	 * The Motion user matching an email address, if there is one.
	 *
	 * @param string $email        Email address.
	 * @param string $workspace_id Workspace id.
	 * @return string User id, or an empty string.
	 */
	public function user_id_for_email( $email, $workspace_id = '' ) {
		$email = strtolower( trim( (string) $email ) );

		if ( '' === $email ) {
			return '';
		}

		$map = $this->email_map( $workspace_id );

		return isset( $map[ $email ] ) ? $map[ $email ] : '';
	}

	/**
	 * The status a workspace uses for finished work.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return string Status name, or an empty string.
	 */
	public function resolved_status( $workspace_id ) {
		$workspaces = $this->workspaces();

		if ( is_wp_error( $workspaces ) ) {
			return '';
		}

		foreach ( $workspaces as $workspace ) {
			if ( ! isset( $workspace['id'] ) || $workspace['id'] !== $workspace_id ) {
				continue;
			}

			foreach ( (array) ( $workspace['statuses'] ?? [] ) as $status ) {
				if ( ! empty( $status['isResolvedStatus'] ) && ! empty( $status['name'] ) ) {
					return (string) $status['name'];
				}
			}
		}

		return '';
	}

	/**
	 * Forget every cached list.
	 */
	public function flush_caches() {
		global $wpdb;

		delete_transient( self::CACHE_WORKSPACES );

		// Project and user lists are keyed per workspace, so clear the family.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::CACHE_PROJECTS ) . '%',
				$wpdb->esc_like( '_transient_' . self::CACHE_USERS ) . '%'
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Fetch a paginated list, following the cursor, and cache the result.
	 *
	 * @param string $cache_key Transient name.
	 * @param string $path      Path to read.
	 * @param string $key       Key in the response holding the list.
	 * @param bool   $force     Skip the cache.
	 * @return array|WP_Error
	 */
	private function cached_list( $cache_key, $path, $key, $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( $cache_key );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$items  = [];
		$cursor = '';
		$pages  = 0;

		do {
			$url = $path;

			if ( '' !== $cursor ) {
				$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'cursor=' . rawurlencode( $cursor );
			}

			$response = $this->request( 'GET', $url );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$page = isset( $response[ $key ] ) && is_array( $response[ $key ] ) ? $response[ $key ] : [];

			// Some responses use the plural key, others answer with a bare list.
			if ( ! $page && isset( $response[0] ) ) {
				$page = $response;
			}

			$items = array_merge( $items, $page );
			$cursor = isset( $response['meta']['nextCursor'] ) ? (string) $response['meta']['nextCursor'] : '';

			$pages++;

			// A runaway cursor should not be able to spend the whole minute's
			// budget in one go.
		} while ( '' !== $cursor && $pages < 10 );

		set_transient( $cache_key, $items, self::CACHE_TTL );

		return $items;
	}

	/**
	 * API root, filterable for testing.
	 *
	 * @return string
	 */
	private function base_url() {
		/**
		 * Filters the Motion API base URL.
		 *
		 * @param string $base_url Base URL with no trailing slash.
		 */
		return untrailingslashit( apply_filters( 'mbm_lf_motion_base_url', self::BASE_URL ) );
	}

	/**
	 * Turn a failed response into something worth reading.
	 *
	 * @param int   $status   HTTP status.
	 * @param mixed $json     Decoded body.
	 * @param array $response Full response, for headers.
	 * @return WP_Error
	 */
	private function error_from_response( $status, $json, $response ) {
		$detail = '';

		if ( is_array( $json ) ) {
			foreach ( [ 'message', 'error', 'detail' ] as $field ) {
				if ( ! empty( $json[ $field ] ) && is_string( $json[ $field ] ) ) {
					$detail = $json[ $field ];
					break;
				}
			}
		}

		if ( 401 === $status || 403 === $status ) {
			$message = __( 'Motion rejected the key. Check it was copied in full and has not been revoked.', 'mbm-live-feedback' );
		} elseif ( 429 === $status ) {
			$message = __( 'Motion is rate limiting us. Tasks will catch up on their own shortly.', 'mbm-live-feedback' );
		} elseif ( 404 === $status ) {
			$message = __( 'Motion could not find that. It may have been deleted.', 'mbm-live-feedback' );
		} elseif ( $status >= 500 ) {
			$message = __( 'Motion had a server error. This is on their end — it should sort itself out.', 'mbm-live-feedback' );
		} else {
			$message = '' !== $detail ? $detail : __( 'Motion returned an error.', 'mbm-live-feedback' );
		}

		return new WP_Error(
			'mbm_lf_motion_error',
			$message,
			[
				'status'      => $status,
				'detail'      => $detail,
				// Undocumented whether Motion sends these; captured so the queue
				// can use them later if it does.
				'retry_after' => wp_remote_retrieve_header( $response, 'retry-after' ),
			]
		);
	}
}
