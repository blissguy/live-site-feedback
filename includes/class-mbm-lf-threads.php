<?php
/**
 * Reads comment threads back from MarkUp.io.
 *
 * There is deliberately no local copy of the comments. MarkUp.io is where they
 * live, so this asks for them and caches the answer briefly; a webhook clears
 * that cache the moment anything changes. A mirror would only add a second
 * source of truth to keep in step, and would go stale exactly when it matters —
 * when somebody is working through feedback.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches and caches threads for display in the admin.
 */
class MBM_LF_Threads {

	/**
	 * Cache key for the assembled list.
	 */
	const CACHE_KEY = 'mbm_lf_threads';

	/**
	 * How long to hold the list when no webhook tells us otherwise.
	 *
	 * Short, because a delivery may never arrive: the site could be
	 * unreachable, or the registration could have been removed upstream.
	 */
	const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * How many per-page markups to gather when the site is not sharing one list.
	 *
	 * Each one costs a request, so this is capped and reported rather than
	 * quietly fetching hundreds.
	 */
	const MAX_MARKUPS = 25;

	/**
	 * How many pages to keep in the summary the admin bar reads.
	 *
	 * A hover menu stops being useful long before this, and the summary is
	 * stored as an autoloaded option, so it stays small.
	 */
	const SUMMARY_PAGES = 12;

	/**
	 * Every thread we can see, newest activity first.
	 *
	 * @param bool $force Skip the cache.
	 * @return array{threads:array,truncated:bool,error:string}
	 */
	public function all( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				/*
				 * Rebuild the summary if it has gone missing while the cache is
				 * still warm — after an update that introduced it, or if the
				 * option was cleared. Without this the toolbar would show
				 * nothing until the cache happened to expire.
				 */
				if ( 0 === $this->summary()['updated'] ) {
					$this->store_summary( $cached );
				}

				return $cached;
			}
		}

		$result = [
			'threads'   => [],
			'truncated' => false,
			'error'     => '',
		];

		if ( ! MBM_LF_Credentials::has( 'api_key' ) ) {
			$result['error'] = __( 'Add your MarkUp.io API key to see feedback here.', 'mbm-live-feedback' );

			return $result;
		}

		$markup_ids = $this->markup_ids();

		if ( count( $markup_ids ) > self::MAX_MARKUPS ) {
			$markup_ids          = array_slice( $markup_ids, 0, self::MAX_MARKUPS );
			$result['truncated'] = true;
		}

		foreach ( $markup_ids as $markup_id ) {
			$response = mbm_lf_api()->list_threads( $markup_id, [ 'limit' => 100 ] );

			if ( is_wp_error( $response ) ) {
				// One bad markup should not blank the whole screen.
				$result['error'] = $response->get_error_message();

				continue;
			}

			$threads = isset( $response['threads'] ) && is_array( $response['threads'] )
				? $response['threads']
				: [];

			foreach ( $threads as $thread ) {
				$normalised = $this->normalise( $thread, $markup_id );

				if ( $normalised ) {
					$result['threads'][] = $normalised;
				}
			}
		}

		usort(
			$result['threads'],
			static function ( $a, $b ) {
				return $b['activity'] <=> $a['activity'];
			}
		);

		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );

		$this->store_summary( $result );

		return $result;
	}

	/**
	 * Keep a small summary that is always available.
	 *
	 * The admin bar appears on every page load, front end included, so it can
	 * neither call the API nor depend on the cache — a webhook clears that
	 * cache, which would blank the count at exactly the moment new feedback
	 * arrives. This is a stored option instead: cheap to read, never empty, and
	 * refreshed whenever the full list is fetched.
	 *
	 * @param array $result Result from all().
	 */
	private function store_summary( array $result ) {
		if ( '' !== $result['error'] ) {
			// A failed fetch should not wipe a summary that was right until now.
			return;
		}

		$pages = [];
		$open  = 0;

		foreach ( $this->group( $result['threads'] ) as $page ) {
			$open += $page['open'];

			if ( $page['open'] > 0 && count( $pages ) < self::SUMMARY_PAGES ) {
				$pages[] = [
					'title' => $page['title'],
					'url'   => $page['post_id'] ? get_permalink( $page['post_id'] ) : $page['url'],
					'open'  => $page['open'],
				];
			}
		}

		MBM_LF_Options::update(
			[
				'feedback_summary' => [
					'open'    => $open,
					'pages'   => $pages,
					'updated' => time(),
				],
			]
		);
	}

	/**
	 * The stored summary, for anything that must not wait on the API.
	 *
	 * @return array{open:int,pages:array,updated:int}
	 */
	public function summary() {
		$summary = MBM_LF_Options::get( 'feedback_summary' );

		if ( ! is_array( $summary ) ) {
			$summary = [];
		}

		return [
			'open'    => isset( $summary['open'] ) ? (int) $summary['open'] : 0,
			'pages'   => isset( $summary['pages'] ) && is_array( $summary['pages'] ) ? $summary['pages'] : [],
			'updated' => isset( $summary['updated'] ) ? (int) $summary['updated'] : 0,
		];
	}

	/**
	 * Feedback grouped by the page it was left on.
	 *
	 * The per-comment detail belongs on the site itself, where the feedback bar
	 * can show it in place. What WordPress is useful for is the overview: which
	 * pages have something waiting, and how much.
	 *
	 * @return array{pages:array,truncated:bool,error:string}
	 */
	public function by_page() {
		$data = $this->all();

		return [
			'pages'     => $this->group( $data['threads'] ),
			'truncated' => $data['truncated'],
			'error'     => $data['error'],
		];
	}

	/**
	 * Collapse threads into one row per page.
	 *
	 * @param array $threads Normalised threads.
	 * @return array
	 */
	private function group( array $threads ) {
		$pages = [];

		foreach ( $threads as $thread ) {
			$key = $this->page_key( $thread );

			if ( ! isset( $pages[ $key ] ) ) {
				$pages[ $key ] = [
					'url'      => $thread['url'],
					'post_id'  => $thread['post_id'],
					'title'    => $this->page_title( $thread ),
					'total'    => 0,
					'open'     => 0,
					'activity' => 0,
				];
			}

			$pages[ $key ]['total']++;

			if ( ! $thread['resolved'] ) {
				$pages[ $key ]['open']++;
			}

			if ( $thread['activity'] > $pages[ $key ]['activity'] ) {
				$pages[ $key ]['activity'] = $thread['activity'];
			}
		}

		// Pages still waiting on someone come first, then by recency.
		uasort(
			$pages,
			static function ( $a, $b ) {
				if ( $a['open'] !== $b['open'] ) {
					return $b['open'] <=> $a['open'];
				}

				return $b['activity'] <=> $a['activity'];
			}
		);

		return array_values( $pages );
	}

	/**
	 * What identifies a page for grouping.
	 *
	 * @param array $thread Normalised thread.
	 * @return string
	 */
	private function page_key( $thread ) {
		if ( $thread['post_id'] ) {
			return 'post:' . $thread['post_id'];
		}

		if ( '' !== $thread['url'] ) {
			// Ignore anything after the path: the same page with a tracking
			// parameter on the end is still the same page.
			$parts = wp_parse_url( $thread['url'] );
			$path  = isset( $parts['path'] ) ? $parts['path'] : '/';

			return 'url:' . untrailingslashit( $path );
		}

		return 'unknown';
	}

	/**
	 * A readable name for the page a comment was left on.
	 *
	 * @param array $thread Normalised thread.
	 * @return string
	 */
	private function page_title( $thread ) {
		if ( $thread['post_id'] ) {
			$title = get_the_title( $thread['post_id'] );

			if ( '' !== trim( (string) $title ) ) {
				return $title;
			}
		}

		if ( '' !== $thread['url'] ) {
			$path = wp_parse_url( $thread['url'], PHP_URL_PATH );

			return $path && '/' !== $path ? trim( $path, '/' ) : __( 'Home page', 'mbm-live-feedback' );
		}

		return __( 'Somewhere on the site', 'mbm-live-feedback' );
	}

	/**
	 * How many threads are still open.
	 *
	 * @return int
	 */
	public function open_count() {
		$count = 0;

		foreach ( $this->all()['threads'] as $thread ) {
			if ( ! $thread['resolved'] ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Forget the cached list.
	 */
	public function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Which MarkUp.io entries this site collects feedback in.
	 *
	 * @return string[]
	 */
	public function markup_ids() {
		if ( MBM_LF_Options::get( 'shared_thread' ) ) {
			$shared = (string) MBM_LF_Options::get( 'default_markup_id' );

			return '' === $shared ? [] : [ $shared ];
		}

		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
				MBM_LF_Post_Meta::META_MARKUP_ID
			)
		);

		return array_values( array_filter( (array) $ids ) );
	}

	/**
	 * Reduce a thread to what the admin screen needs.
	 *
	 * @param array  $thread    Thread from the API.
	 * @param string $markup_id The markup it belongs to.
	 * @return array|null
	 */
	private function normalise( $thread, $markup_id ) {
		if ( ! is_array( $thread ) || empty( $thread['id'] ) ) {
			return null;
		}

		$messages = isset( $thread['messages'] ) && is_array( $thread['messages'] ) ? $thread['messages'] : [];
		$first    = isset( $messages[0] ) && is_array( $messages[0] ) ? $messages[0] : [];

		$text = '';

		foreach ( [ 'message', 'content', 'text' ] as $key ) {
			if ( ! empty( $first[ $key ] ) && is_string( $first[ $key ] ) ) {
				$text = $first[ $key ];
				break;
			}
		}

		/*
		 * The page address sits at the top level here. Note the webhook payload
		 * nests the same thing under `comment.location`, so the two cannot share
		 * a reader.
		 */
		$url = '';

		foreach ( [ 'canonicalUrl', 'originalUrl' ] as $key ) {
			if ( ! empty( $thread[ $key ] ) && is_string( $thread[ $key ] ) ) {
				$url = $thread[ $key ];
				break;
			}
		}

		$activity = 0;

		foreach ( [ 'lastActivityAt', 'modifiedAt', 'createdAt' ] as $key ) {
			if ( ! empty( $thread[ $key ] ) ) {
				$activity = $this->to_timestamp( $thread[ $key ] );

				if ( $activity ) {
					break;
				}
			}
		}

		return [
			'id'        => (string) $thread['id'],
			'markup_id' => (string) $markup_id,
			'number'    => isset( $thread['number'] ) ? (int) $thread['number'] : 0,
			'resolved'  => ! empty( $thread['resolved'] ),
			'author'    => isset( $thread['user']['name'] ) ? (string) $thread['user']['name'] : '',
			'excerpt'   => wp_trim_words( wp_strip_all_tags( $text ), 20 ),
			'replies'   => max( 0, count( $messages ) - 1 ),
			'activity'  => $activity,
			'url'       => $url,
			'post_id'   => $url ? url_to_postid( $url ) : 0,
			'app_url'   => 'https://app.markup.io/markup/' . rawurlencode( (string) $markup_id ),
		];
	}

	/**
	 * Turn whatever the API gives us into a Unix timestamp.
	 *
	 * Threads report times as milliseconds since the epoch, while other parts
	 * of the API use ISO 8601 strings. Handle both rather than assuming.
	 *
	 * @param mixed $value Raw value.
	 * @return int Seconds since the epoch, or 0.
	 */
	private function to_timestamp( $value ) {
		if ( is_numeric( $value ) ) {
			$number = (float) $value;

			// Anything this large is milliseconds, not seconds.
			return (int) ( $number > 9999999999 ? round( $number / 1000 ) : $number );
		}

		if ( is_string( $value ) ) {
			return (int) strtotime( $value );
		}

		return 0;
	}
}
