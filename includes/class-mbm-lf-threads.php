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
	const CACHE_KEY = 'mbm_lf_threads_v2';

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
			// Open and resolved both. Asking once only ever returned the open
			// ones, so resolved feedback never reached this screen at all.
			$threads = $this->fetch_all_raw( $markup_id );

			if ( is_wp_error( $threads ) ) {
				// One bad markup should not blank the whole screen.
				$result['error'] = $threads->get_error_message();

				continue;
			}

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
					'threads'  => [],
				];
			}

			$pages[ $key ]['threads'][] = $thread;
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
	/**
	 * Every markup this site has ever created.
	 *
	 * Deliberately not the same question as markup_ids(). That one answers
	 * "where should I read threads from right now", which follows the shared
	 * list setting. This one answers "is this ours", and a markup does not stop
	 * being ours because the setting changed after it was made — a site that
	 * switched to a shared list still owns the per-page markups it created
	 * before. Filtering on the narrower list would silently discard real
	 * feedback, which is a worse failure than the leak it is guarding against,
	 * because nobody notices it.
	 *
	 * @return string[]
	 */
	public function owned_markup_ids() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
				MBM_LF_Post_Meta::META_MARKUP_ID
			)
		);

		$ids[] = (string) MBM_LF_Options::get( 'default_markup_id' );

		/**
		 * Filters the markups this site claims as its own.
		 *
		 * The counterpart to `mbm_lf_markup_id`. Anything substituting a markup
		 * there has to declare it here too, or its feedback is refused as
		 * another site's — silently, which is the worst way to find out.
		 *
		 * @param string[] $ids Markup ids.
		 */
		$ids = (array) apply_filters( 'mbm_lf_owned_markup_ids', $ids );

		$cache = array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );

		return $cache;
	}

	/**
	 * Whether a webhook delivery is about this site at all.
	 *
	 * MarkUp.io webhook registrations cannot be scoped: the only fields are
	 * url, workspaceId, eventTypes and enabled, so one registration receives
	 * every comment on every markup in the workspace. On a plan with a single
	 * workspace that means one site hears about every other site's feedback,
	 * and without this check it would act on it. Confirmed against the docs and
	 * against a real registration record.
	 *
	 * The markup id is the exact answer and is a documented field. The page URL
	 * is only consulted when there is no markup id at all — a delivery naming a
	 * markup that is not ours is refused outright, even if it happens to point
	 * at our domain, because we have no way to map it to a page.
	 *
	 * @param array $body The delivery.
	 * @return bool
	 */
	public function owns_delivery( $body ) {
		if ( ! is_array( $body ) ) {
			return false;
		}

		$markup_id = isset( $body['data']['markup']['id'] ) ? (string) $body['data']['markup']['id'] : '';

		if ( '' !== $markup_id ) {
			return in_array( $markup_id, $this->owned_markup_ids(), true );
		}

		$comment = isset( $body['data']['comment'] ) && is_array( $body['data']['comment'] ) ? $body['data']['comment'] : [];
		$url     = (string) (
			$body['data']['markup']['webpageUrl']
				?? $comment['location']['canonicalUrl']
				?? $comment['location']['originalUrl']
				?? ''
		);

		if ( '' === $url ) {
			// Nothing identifies it. Refusing is the safe half of the guess.
			return false;
		}

		$theirs = wp_parse_url( $url, PHP_URL_HOST );
		$ours   = wp_parse_url( home_url(), PHP_URL_HOST );

		return '' !== (string) $theirs && strtolower( (string) $theirs ) === strtolower( (string) $ours );
	}

	public function markup_ids() {
		if ( MBM_LF_Options::get( 'shared_thread' ) ) {
			$shared = (string) MBM_LF_Options::get( 'default_markup_id' );

			return '' === $shared ? [] : [ $shared ];
		}

		global $wpdb;

		/*
		 * Collecting the distinct values of one meta key is not something
		 * WP_Query can do without loading every matching post, which is far more
		 * work than the question deserves. This only runs when the thread list
		 * is being rebuilt — the result of that rebuild is what gets cached — so
		 * it is not repeated on ordinary page loads.
		 */
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
				MBM_LF_Post_Meta::META_MARKUP_ID
			)
		);

		return array_values( array_filter( (array) $ids ) );
	}

	/**
	 * Every thread on one markup, open and resolved, exactly as MarkUp.io sends it.
	 *
	 * The list endpoint quietly defaults to `resolved=false`, so asking once
	 * only ever returns open threads — resolved ones need their own request.
	 * It also wants `1`/`0` rather than `true`/`false` ("resolved must be a
	 * boolean value"), and caps a page at 200. Verified against the live API.
	 *
	 * All or nothing: a partial list would make missing threads look deleted
	 * and unseen ones look new, which is worse than no answer.
	 *
	 * @param string $markup_id Markup id.
	 * @param int[]  $states    Which to ask for: 0 for open, 1 for resolved.
	 * @return array|WP_Error Raw threads.
	 */
	public function fetch_all_raw( $markup_id, array $states = [ 0, 1 ] ) {
		$threads = [];

		foreach ( $states as $resolved ) {
			$offset = 0;

			do {
				$response = mbm_lf_api()->list_threads(
					$markup_id,
					[
						'limit'    => 200,
						'offset'   => $offset,
						'resolved' => $resolved,
					]
				);

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$page = isset( $response['threads'] ) && is_array( $response['threads'] ) ? $response['threads'] : [];

				foreach ( $page as $thread ) {
					if ( is_array( $thread ) && ! empty( $thread['id'] ) ) {
						$threads[] = $thread;
					}
				}

				$offset += count( $page );
				$total   = isset( $response['total'] ) ? (int) $response['total'] : 0;
			} while ( $page && $offset < $total );
		}

		return $threads;
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

		$post_id = $url ? url_to_postid( $url ) : 0;

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
			'post_id'   => $post_id,
			'page_link' => self::page_link( $post_id ? (string) get_permalink( $post_id ) : $url, (string) $thread['id'] ),
			// Straight to the comment, in the same form MarkUp.io's own links use.
			'app_url'   => 'https://app.markup.io/markup/' . rawurlencode( (string) $markup_id ) . '/#thread/' . rawurlencode( (string) $thread['id'] ),
		];
	}

	/**
	 * Feedback on one page, in full.
	 *
	 * The cached list only says which markups hold this page's threads. The
	 * threads themselves are read again, because the cached copy keeps an
	 * excerpt and a count rather than what was actually said.
	 *
	 * @param int    $post_id Post id, or 0 to go by address.
	 * @param string $url     Page address, used when there is no post id.
	 * @param string $status  open, resolved or all.
	 * @param bool   $force   Skip the cached list.
	 * @return array{threads:array,error:string}
	 */
	public function page_threads( $post_id, $url, $status = 'open', $force = false ) {
		$post_id = (int) $post_id;
		$url     = (string) $url;

		if ( ! $post_id && '' !== $url ) {
			$post_id = url_to_postid( $url );
		}

		$target = $this->page_key(
			[
				'post_id' => $post_id,
				'url'     => $url,
			]
		);

		$data   = $this->all( $force );
		$result = [
			'threads' => [],
			'error'   => $data['error'],
		];

		$markup_ids = [];

		foreach ( $data['threads'] as $thread ) {
			if ( $this->page_key( $thread ) === $target ) {
				$markup_ids[ $thread['markup_id'] ] = true;
			}
		}

		if ( 'open' === $status ) {
			$states = [ 0 ];
		} elseif ( 'resolved' === $status ) {
			$states = [ 1 ];
		} else {
			$states = [ 0, 1 ];
		}

		foreach ( array_keys( $markup_ids ) as $markup_id ) {
			$raw = $this->fetch_all_raw( (string) $markup_id, $states );

			if ( is_wp_error( $raw ) ) {
				$result['error'] = $raw->get_error_message();

				continue;
			}

			// A shared list holds every page's threads, so filter again here.
			foreach ( $raw as $thread ) {
				$detail = $this->detail( $thread, (string) $markup_id );

				if ( $detail && $this->page_key( $detail ) === $target ) {
					$result['threads'][] = $detail;
				}
			}
		}

		// In pin order, the way they read on the page.
		usort(
			$result['threads'],
			static function ( $a, $b ) {
				return $a['number'] <=> $b['number'];
			}
		);

		return $result;
	}

	/**
	 * One thread, in full, as long as it belongs to this site.
	 *
	 * @param string $thread_id Thread id.
	 * @return array|WP_Error
	 */
	public function thread( $thread_id ) {
		$thread = mbm_lf_api()->request( 'GET', '/api/v2/threads/' . rawurlencode( (string) $thread_id ) );

		if ( is_wp_error( $thread ) ) {
			return $thread;
		}

		/*
		 * `projectId` is the markup a thread belongs to: it matched the markup
		 * id on every thread checked against a live site in October 2026. The
		 * API key reads the whole workspace, so without this check any site
		 * could be asked for another site's feedback.
		 */
		$markup_id = isset( $thread['projectId'] ) ? (string) $thread['projectId'] : '';

		if ( '' === $markup_id || ! in_array( $markup_id, $this->owned_markup_ids(), true ) ) {
			return new WP_Error(
				'mbm_lf_not_ours',
				__( 'That comment isn\'t on this site.', 'mbm-live-feedback' )
			);
		}

		$detail = $this->detail( $thread, $markup_id );

		if ( ! $detail ) {
			return new WP_Error(
				'mbm_lf_bad_response',
				__( 'MarkUp.io sent a reply this site couldn\'t read.', 'mbm-live-feedback' )
			);
		}

		return $detail;
	}

	/**
	 * Everything said in a thread, and where and how it was left.
	 *
	 * The admin screen's normalise() keeps an excerpt and a reply count, which
	 * is all a list needs. This keeps the full text of every message, the
	 * attachments, the pin and the browser, for anyone acting on the feedback.
	 * Field names follow what GET /threads actually returns.
	 *
	 * @param array  $thread    Thread from the API.
	 * @param string $markup_id The markup it belongs to.
	 * @return array|null
	 */
	public function detail( $thread, $markup_id ) {
		$base = $this->normalise( $thread, $markup_id );

		if ( ! $base ) {
			return null;
		}

		$messages = [];

		foreach ( (array) ( $thread['messages'] ?? [] ) as $message ) {
			if ( ! is_array( $message ) || ! empty( $message['deletedAt'] ) ) {
				continue;
			}

			$messages[] = [
				'author'      => (string) ( $message['user']['name'] ?? '' ),
				'date'        => $this->iso_date( $message['createdAt'] ?? 0 ),
				'text'        => $this->message_text( $message ),
				'attachments' => $this->attachments( $message ),
			];
		}

		$first = $messages ? array_shift( $messages ) : [
			'text'        => '',
			'attachments' => [],
		];

		$elements = [];

		foreach ( (array) ( $thread['elements'] ?? [] ) as $element ) {
			if ( ! is_array( $element ) || empty( $element['path'] ) ) {
				continue;
			}

			$elements[] = [
				'path'     => (string) $element['path'],
				'offset_x' => isset( $element['offsetXPercentage'] ) ? (float) $element['offsetXPercentage'] : null,
				'offset_y' => isset( $element['offsetYPercentage'] ) ? (float) $element['offsetYPercentage'] : null,
			];
		}

		$context = isset( $thread['browserContext'] ) && is_array( $thread['browserContext'] ) ? $thread['browserContext'] : [];

		return [
			'id'            => $base['id'],
			'markup_id'     => $base['markup_id'],
			'number'        => $base['number'],
			'status'        => $base['resolved'] ? 'resolved' : 'open',
			'priority'      => (string) ( $thread['priority']['name'] ?? '' ),
			'author'        => $base['author'],
			'created'       => $this->iso_date( $thread['createdAt'] ?? 0 ),
			'last_activity' => $base['activity'] ? wp_date( 'c', $base['activity'] ) : '',
			'message'       => $first['text'],
			'attachments'   => $first['attachments'],
			'replies'       => $messages,
			'screenshot'    => (string) ( $thread['screenshot']['url'] ?? '' ),
			'pin'           => [
				'viewport_x' => isset( $thread['viewportXPercentage'] ) ? (float) $thread['viewportXPercentage'] : null,
				'viewport_y' => isset( $thread['viewportYPercentage'] ) ? (float) $thread['viewportYPercentage'] : null,
				'elements'   => $elements,
			],
			'browser'       => [
				'viewport_width'  => (int) ( $context['viewportWidth'] ?? 0 ),
				'viewport_height' => (int) ( $context['viewportHeight'] ?? 0 ),
				'screen_width'    => (int) ( $context['screenWidth'] ?? 0 ),
				'screen_height'   => (int) ( $context['screenHeight'] ?? 0 ),
				'browser'         => (string) ( $context['browserName'] ?? '' ),
				'browser_version' => (string) ( $context['browserVersion'] ?? '' ),
				'os'              => (string) ( $context['os'] ?? '' ),
				'os_version'      => (string) ( $context['osVersion'] ?? '' ),
				'platform'        => (string) ( $context['platform'] ?? '' ),
			],
			'url'           => $base['url'],
			'post_id'       => $base['post_id'],
			'page_title'    => $this->page_title( $base ),
			'page_link'     => $base['page_link'],
			'app_url'       => $base['app_url'],
		];
	}

	/**
	 * What a message says, with its links and mentions intact.
	 *
	 * The plain `message` field keeps the text a link was written over but
	 * drops where it points, and the two can differ: one real comment reads as
	 * one Dropbox file and links to another. `messageJson.ops` keeps both, so
	 * it is read first.
	 *
	 * @param array $message Message from the API.
	 * @return string
	 */
	private function message_text( array $message ) {
		$ops = $message['messageJson']['ops'] ?? null;

		if ( is_array( $ops ) && $ops ) {
			$text = '';

			foreach ( $ops as $op ) {
				$insert = is_array( $op ) && isset( $op['insert'] ) ? $op['insert'] : null;

				if ( is_string( $insert ) ) {
					$link  = isset( $op['attributes']['link'] ) && is_string( $op['attributes']['link'] ) ? $op['attributes']['link'] : '';
					$text .= ( '' !== $link && $link !== $insert ) ? '[' . $insert . '](' . $link . ')' : $insert;
				} elseif ( isset( $insert['messageTag']['name'] ) ) {
					$text .= '@' . $insert['messageTag']['name'];
				}
			}

			if ( '' !== trim( $text ) ) {
				return trim( $text );
			}
		}

		foreach ( [ 'message', 'content', 'text' ] as $key ) {
			if ( ! empty( $message[ $key ] ) && is_string( $message[ $key ] ) ) {
				return trim( $message[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Files attached to a message, including screenshots taken by the bar.
	 *
	 * @param array $message Message from the API.
	 * @return array
	 */
	private function attachments( array $message ) {
		$files = [];

		foreach ( (array) ( $message['attachments'] ?? [] ) as $file ) {
			if ( ! is_array( $file ) || empty( $file['url'] ) || ! empty( $file['deletedAt'] ) ) {
				continue;
			}

			$files[] = [
				'url'       => (string) $file['url'],
				'filename'  => (string) ( $file['originalFilename'] ?? '' ),
				'mime_type' => (string) ( $file['mimeType'] ?? '' ),
				'width'     => (int) ( $file['width'] ?? 0 ),
				'height'    => (int) ( $file['height'] ?? 0 ),
			];
		}

		return $files;
	}

	/**
	 * A time from the API as ISO 8601, in the site's timezone.
	 *
	 * @param mixed $value Raw value.
	 * @return string Empty when there is none.
	 */
	private function iso_date( $value ) {
		$timestamp = $this->to_timestamp( $value );

		return $timestamp ? (string) wp_date( 'c', $timestamp ) : '';
	}

	/**
	 * The page a comment was left on, opened at that comment.
	 *
	 * The feedback bar reads `commentThread` when it loads: it scrolls to the
	 * pin, selects it and opens the thread, the same as clicking it. MarkUp.io's
	 * own notification emails link the same way. Anyone who can't see the bar
	 * just gets the page.
	 *
	 * @param string $url       Page address.
	 * @param string $thread_id Thread id.
	 * @return string Empty when there is no page to link to.
	 */
	public static function page_link( $url, $thread_id ) {
		if ( '' === (string) $url || '' === (string) $thread_id ) {
			return '';
		}

		return add_query_arg( 'commentThread', rawurlencode( (string) $thread_id ), (string) $url );
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
