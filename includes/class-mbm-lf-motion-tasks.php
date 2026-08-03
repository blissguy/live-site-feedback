<?php
/**
 * Turns feedback into Motion tasks.
 *
 * A comment arriving adds a job; this drains the queue and does the talking.
 * The webhook that triggered it is unsigned and therefore untrusted, so nothing
 * here believes the payload about anything that matters — the page is resolved
 * locally, and the workspace and project come from settings.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and updates Motion tasks.
 */
class MBM_LF_Motion_Tasks {

	/**
	 * Option holding thread id to task id.
	 *
	 * Not autoloaded: only the worker needs it.
	 */
	const LINKS = 'mbm_lf_motion_links';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'mbm_lf_webhook_received', [ $this, 'on_feedback' ], 10, 2 );
		add_action( MBM_LF_Motion_Queue::CRON_HOOK, [ $this, 'drain' ] );
		add_action( 'admin_init', [ $this, 'maybe_drain_on_admin' ] );
	}

	/**
	 * Whether there is enough configuration to create anything.
	 *
	 * @return bool
	 */
	public function is_ready() {
		return mbm_lf_motion()->has_key()
			&& '' !== (string) MBM_LF_Options::get( 'motion_workspace_id' )
			&& '' !== (string) MBM_LF_Options::get( 'motion_project_id' );
	}

	/* ---------------------------------------------------------------------
	 * Taking work in
	 * ------------------------------------------------------------------ */

	/**
	 * MarkUp.io reported something. Queue whatever it means for Motion.
	 *
	 * @param string $type Event type.
	 * @param array  $body The delivery.
	 */
	public function on_feedback( $type, $body ) {
		if ( ! $this->is_ready() ) {
			return;
		}

		$comment = isset( $body['data']['comment'] ) && is_array( $body['data']['comment'] )
			? $body['data']['comment']
			: [];

		$thread_id = isset( $comment['id'] ) ? (string) $comment['id'] : '';

		if ( '' === $thread_id ) {
			return;
		}

		if ( 'comment_reply_created' === $type ) {
			/*
			 * The reply itself is not read from the delivery. Deliveries are
			 * unsigned, they repeat, and they can arrive out of order, so the
			 * text is fetched from MarkUp.io when the job runs. A MarkUp read
			 * costs nothing worth counting — a thousand a minute against
			 * Motion's hundred and twenty — and it is the authoritative copy.
			 */
			MBM_LF_Motion_Queue::add(
				'add_comment',
				$thread_id,
				[
					'markup_id' => (string) ( $body['data']['markup']['id'] ?? '' ),
				]
			);

			return;
		}

		if ( 'comment_created' !== $type ) {
			// Resolving arrives in M3. Ignoring it now is better than queueing
			// work nothing knows how to do.
			return;
		}

		MBM_LF_Motion_Queue::add(
			'create_task',
			$thread_id,
			[
				// Only what we need, so a queued job stays readable and small.
				'text'      => (string) ( $comment['firstMessage']['text'] ?? '' ),
				'number'    => (int) ( $comment['threadNumber'] ?? 0 ),
				'app_url'   => (string) ( $comment['appUrl'] ?? '' ),
				'author'    => (string) ( $comment['author']['name'] ?? '' ),
				'page_url'  => (string) ( $comment['location']['canonicalUrl'] ?? $comment['location']['originalUrl'] ?? '' ),
				'markup_id' => (string) ( $body['data']['markup']['id'] ?? '' ),
			]
		);
	}

	/* ---------------------------------------------------------------------
	 * Doing the work
	 * ------------------------------------------------------------------ */

	/**
	 * Work through the queue.
	 */
	public function drain() {
		if ( ! $this->is_ready() ) {
			return;
		}

		// One worker at a time, or two cron runs would double-post.
		if ( get_transient( MBM_LF_Motion_Queue::LOCK ) ) {
			return;
		}

		set_transient( MBM_LF_Motion_Queue::LOCK, 1, 2 * MINUTE_IN_SECONDS );

		$budget = MBM_LF_Motion_Queue::budget_remaining();
		$jobs   = $budget > 0 ? MBM_LF_Motion_Queue::claim( $budget ) : [];

		foreach ( $jobs as $job ) {
			if ( MBM_LF_Motion_Queue::budget_remaining() < 1 ) {
				break;
			}

			$this->run( $job );
		}

		delete_transient( MBM_LF_Motion_Queue::LOCK );

		// More waiting? Come back for it.
		$stats = MBM_LF_Motion_Queue::stats();

		if ( $stats['pending'] > 0 ) {
			MBM_LF_Motion_Queue::ensure_scheduled();
		}
	}

	/**
	 * Nudge the queue along on admin page loads.
	 *
	 * WP-Cron only fires when somebody visits, so on a quiet site jobs can sit
	 * for a while. Anyone in the admin who can edit is a reasonable excuse to
	 * clear a couple, and it costs them nothing noticeable.
	 */
	public function maybe_drain_on_admin() {
		if ( wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		if ( ! $this->is_ready() || get_transient( 'mbm_lf_motion_nudged' ) ) {
			return;
		}

		$stats = MBM_LF_Motion_Queue::stats();

		if ( $stats['pending'] < 1 ) {
			return;
		}

		// At most once a minute, so a busy admin session does not turn into a
		// stream of outbound requests.
		set_transient( 'mbm_lf_motion_nudged', 1, MINUTE_IN_SECONDS );

		$this->drain();
	}

	/**
	 * Run one job.
	 *
	 * @param array $job Queue row.
	 */
	private function run( array $job ) {
		$payload = json_decode( (string) $job['payload'], true );

		if ( ! is_array( $payload ) ) {
			MBM_LF_Motion_Queue::fail( $job, __( 'The stored job could not be read.', 'mbm-live-feedback' ), true );

			return;
		}

		if ( 'create_task' === $job['action'] ) {
			$this->create_task( $job, $payload );

			return;
		}

		if ( 'add_comment' === $job['action'] ) {
			$this->add_comment( $job, $payload );

			return;
		}

		MBM_LF_Motion_Queue::fail(
			$job,
			sprintf(
				/* translators: %s: the job type. */
				__( 'Nothing here knows how to do "%s" yet.', 'mbm-live-feedback' ),
				$job['action']
			),
			true
		);
	}

	/**
	 * Create the task for a thread.
	 *
	 * @param array $job     Queue row.
	 * @param array $payload Job payload.
	 */
	private function create_task( array $job, array $payload ) {
		$thread_id = (string) $job['thread_id'];

		// Already done. A repeated delivery must not make a second task.
		if ( '' !== $this->task_for_thread( $thread_id ) ) {
			MBM_LF_Motion_Queue::complete( $job['id'] );

			return;
		}

		$post_id = $payload['page_url'] ? url_to_postid( $payload['page_url'] ) : 0;

		$body = [
			'name'        => $this->title( $payload, $post_id ),
			'workspaceId' => (string) MBM_LF_Options::get( 'motion_workspace_id' ),
			'projectId'   => (string) MBM_LF_Options::get( 'motion_project_id' ),
			'description' => $this->description( $payload, $post_id ),
		];

		$assignee = $this->assignee_for( $post_id );

		if ( '' !== $assignee ) {
			$body['assigneeId'] = $assignee;
		}

		/**
		 * Filters the task about to be created in Motion.
		 *
		 * @param array $body    The request body.
		 * @param array $payload What the feedback told us.
		 * @param int   $post_id The page it was left on, or 0.
		 */
		$body = (array) apply_filters( 'mbm_lf_motion_task', $body, $payload, $post_id );

		MBM_LF_Motion_Queue::spend();

		$result = mbm_lf_motion()->request( 'POST', '/tasks', $body );

		if ( is_wp_error( $result ) ) {
			$this->handle_error( $job, $result );

			return;
		}

		if ( empty( $result['id'] ) ) {
			MBM_LF_Motion_Queue::fail( $job, __( 'Motion accepted the task but did not say which one it made.', 'mbm-live-feedback' ), true );

			return;
		}

		$this->link( $thread_id, (string) $result['id'] );

		MBM_LF_Motion_Queue::complete( $job['id'] );
	}

	/**
	 * Put a reply on the task as a comment.
	 *
	 * @param array $job     Queue row.
	 * @param array $payload Job payload.
	 */
	private function add_comment( array $job, array $payload ) {
		$thread_id = (string) $job['thread_id'];
		$task_id   = $this->task_for_thread( $thread_id );

		if ( '' === $task_id ) {
			/*
			 * The task is not there yet. Usually that means the job creating it
			 * is still in the queue ahead of this one, so retrying shortly is
			 * right. If that job has parked, this will eventually park too, with
			 * a message that explains why.
			 */
			MBM_LF_Motion_Queue::fail(
				$job,
				__( 'Waiting for this page\'s task to be created first.', 'mbm-live-feedback' )
			);

			return;
		}

		$reply = $this->latest_reply( $thread_id );

		if ( is_wp_error( $reply ) ) {
			MBM_LF_Motion_Queue::fail( $job, $reply->get_error_message() );

			return;
		}

		if ( ! $reply ) {
			// Nothing new to say. Not a failure — the reply may have been
			// deleted between the delivery and now.
			MBM_LF_Motion_Queue::complete( $job['id'] );

			return;
		}

		// A repeated delivery must not repeat the comment.
		if ( $this->message_seen( $thread_id, $reply['id'] ) ) {
			MBM_LF_Motion_Queue::complete( $job['id'] );

			return;
		}

		MBM_LF_Motion_Queue::spend();

		$result = mbm_lf_motion()->request(
			'POST',
			'/comments',
			[
				'taskId'  => $task_id,
				'content' => $this->comment_markdown( $reply ),
			]
		);

		if ( is_wp_error( $result ) ) {
			$this->handle_error( $job, $result );

			return;
		}

		$this->mark_message_seen( $thread_id, $reply['id'] );

		MBM_LF_Motion_Queue::complete( $job['id'] );
	}

	/**
	 * The newest reply on a thread, read from MarkUp.io.
	 *
	 * The first message is the comment itself, which is already the task, so
	 * only what came after it counts as a reply.
	 *
	 * @param string $thread_id MarkUp thread id.
	 * @return array|false|WP_Error Reply with id, text and author.
	 */
	private function latest_reply( $thread_id ) {
		$thread = mbm_lf_api()->request( 'GET', '/api/v2/threads/' . rawurlencode( $thread_id ) );

		if ( is_wp_error( $thread ) ) {
			return $thread;
		}

		$messages = isset( $thread['messages'] ) && is_array( $thread['messages'] ) ? $thread['messages'] : [];

		if ( count( $messages ) < 2 ) {
			return false;
		}

		// Work backwards, so a burst of replies still finds one we have not
		// posted rather than stopping at the newest every time.
		for ( $i = count( $messages ) - 1; $i >= 1; $i-- ) {
			$message = $messages[ $i ];

			if ( empty( $message['id'] ) || $this->message_seen( $thread_id, $message['id'] ) ) {
				continue;
			}

			$text = '';

			foreach ( [ 'message', 'content', 'text' ] as $field ) {
				if ( ! empty( $message[ $field ] ) && is_string( $message[ $field ] ) ) {
					$text = $message[ $field ];
					break;
				}
			}

			return [
				'id'     => (string) $message['id'],
				'text'   => $text,
				'author' => (string) ( $message['user']['name'] ?? '' ),
			];
		}

		return false;
	}

	/**
	 * A reply, as Markdown for Motion.
	 *
	 * @param array $reply Reply details.
	 * @return string
	 */
	private function comment_markdown( array $reply ) {
		$lines = [];
		$text  = trim( wp_strip_all_tags( (string) $reply['text'] ) );

		if ( '' === $text ) {
			$text = __( '(no text)', 'mbm-live-feedback' );
		}

		if ( '' !== $reply['author'] ) {
			$lines[] = sprintf(
				/* translators: %s: person's name. */
				__( '**%s** replied on the site:', 'mbm-live-feedback' ),
				$reply['author']
			);
			$lines[] = '';
		}

		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$lines[] = '> ' . $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Decide what a failure means for the job.
	 *
	 * @param array    $job   Queue row.
	 * @param WP_Error $error What went wrong.
	 */
	private function handle_error( array $job, WP_Error $error ) {
		$data   = (array) $error->get_error_data();
		$status = isset( $data['status'] ) ? (int) $data['status'] : 0;

		if ( 429 === $status ) {
			// Not the job's fault. Wait out whatever Motion asks for, or a
			// minute, and try again without counting an attempt.
			$retry = isset( $data['retry_after'] ) && is_numeric( $data['retry_after'] )
				? (int) $data['retry_after']
				: MINUTE_IN_SECONDS;

			MBM_LF_Motion_Queue::defer( $job, $retry );

			// Treat the minute as spent so nothing else goes out meanwhile.
			set_transient( 'mbm_lf_motion_spent', PHP_INT_MAX, $retry );

			return;
		}

		if ( 401 === $status || 403 === $status ) {
			// A rejected key will not fix itself, and retrying every minute
			// would spend the whole budget discovering that.
			MBM_LF_Motion_Queue::park_all( $error->get_error_message() );

			return;
		}

		// 4xx means Motion looked at the request and said no. Retrying an
		// unchanged request will get the same answer.
		$fatal = $status >= 400 && $status < 500;

		MBM_LF_Motion_Queue::fail( $job, $error->get_error_message(), $fatal );
	}

	/* ---------------------------------------------------------------------
	 * Building the task
	 * ------------------------------------------------------------------ */

	/**
	 * The task title.
	 *
	 * Leads with the page, because a Motion list is read by scanning it.
	 *
	 * @param array $payload Job payload.
	 * @param int   $post_id Page id, or 0.
	 * @return string
	 */
	private function title( array $payload, $post_id ) {
		$page = $post_id ? get_the_title( $post_id ) : $this->page_label( $payload['page_url'] );
		$text = trim( wp_strip_all_tags( (string) $payload['text'] ) );

		if ( '' === $text ) {
			$text = __( 'New comment', 'mbm-live-feedback' );
		}

		if ( mb_strlen( $text ) > 60 ) {
			$text = mb_substr( $text, 0, 59 ) . '…';
		}

		return '' !== $page
			? sprintf( '%s — “%s”', $page, $text )
			: sprintf( '“%s”', $text );
	}

	/**
	 * The task description, as Markdown.
	 *
	 * @param array $payload Job payload.
	 * @param int   $post_id Page id, or 0.
	 * @return string
	 */
	private function description( array $payload, $post_id ) {
		$lines = [];

		$text = trim( wp_strip_all_tags( (string) $payload['text'] ) );

		if ( '' !== $text ) {
			// Quote it, so the client's words are visibly theirs.
			foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
				$lines[] = '> ' . $line;
			}

			$lines[] = '';
		}

		$where = $post_id ? get_permalink( $post_id ) : (string) $payload['page_url'];
		$page  = $post_id ? get_the_title( $post_id ) : $this->page_label( $where );

		$meta = [];

		if ( '' !== (string) $payload['author'] ) {
			$meta[] = sprintf(
				/* translators: %s: person's name. */
				__( 'Left by **%s**', 'mbm-live-feedback' ),
				$payload['author']
			);
		}

		if ( '' !== $where ) {
			$meta[] = sprintf( 'on [%s](%s)', $page ? $page : $where, $where );
		}

		if ( $payload['number'] > 0 ) {
			$meta[] = sprintf(
				/* translators: %d: the pin number. */
				__( 'pin #%d', 'mbm-live-feedback' ),
				$payload['number']
			);
		}

		if ( $meta ) {
			$lines[] = implode( ' · ', $meta );
			$lines[] = '';
		}

		if ( '' !== (string) $payload['app_url'] ) {
			$lines[] = sprintf(
				'[%s](%s)',
				__( 'Open the comment in MarkUp.io', 'mbm-live-feedback' ),
				$payload['app_url']
			);
		}

		return trim( implode( "\n", $lines ) );
	}

	/**
	 * A readable name for a page we have no post for.
	 *
	 * @param string $url Page address.
	 * @return string
	 */
	private function page_label( $url ) {
		if ( '' === (string) $url ) {
			return '';
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );

		return $path && '/' !== $path ? trim( $path, '/' ) : __( 'Home page', 'mbm-live-feedback' );
	}

	/**
	 * Who should pick this up.
	 *
	 * Whoever wrote the page, matched to Motion by email, because they are the
	 * person who knows it. Falls back to the person named in settings when they
	 * have no Motion account.
	 *
	 * @param int $post_id Page id, or 0.
	 * @return string Motion user id, or an empty string.
	 */
	private function assignee_for( $post_id ) {
		$workspace = (string) MBM_LF_Options::get( 'motion_workspace_id' );
		$fallback  = (string) MBM_LF_Options::get( 'motion_default_assignee' );

		if ( $post_id ) {
			$author = (int) get_post_field( 'post_author', $post_id );
			$email  = $author ? (string) get_the_author_meta( 'user_email', $author ) : '';

			if ( '' !== $email ) {
				$matched = mbm_lf_motion()->user_id_for_email( $email, $workspace );

				if ( '' !== $matched ) {
					return $matched;
				}
			}
		}

		/**
		 * Filters who a piece of feedback is given to in Motion.
		 *
		 * @param string $assignee Motion user id.
		 * @param int    $post_id  The page, or 0.
		 */
		return (string) apply_filters( 'mbm_lf_motion_assignee', $fallback, $post_id );
	}

	/* ---------------------------------------------------------------------
	 * Thread to task
	 * ------------------------------------------------------------------ */

	/**
	 * Everything we remember about the threads we have sent.
	 *
	 * Each entry is `[ 'task' => id, 'seen' => [ message ids ] ]`. Earlier
	 * versions stored the task id as a bare string, so that shape is still
	 * understood — an upgrade must not orphan tasks and start making duplicates.
	 *
	 * @return array
	 */
	private function links() {
		$links = get_option( self::LINKS, [] );

		return is_array( $links ) ? $links : [];
	}

	/**
	 * Normalise one entry, whichever shape it was stored in.
	 *
	 * @param mixed $entry Stored value.
	 * @return array{task:string,seen:string[]}
	 */
	private function entry( $entry ) {
		if ( is_string( $entry ) ) {
			return [
				'task' => $entry,
				'seen' => [],
			];
		}

		if ( ! is_array( $entry ) ) {
			return [
				'task' => '',
				'seen' => [],
			];
		}

		return [
			'task' => isset( $entry['task'] ) ? (string) $entry['task'] : '',
			'seen' => isset( $entry['seen'] ) && is_array( $entry['seen'] ) ? $entry['seen'] : [],
		];
	}

	/**
	 * The Motion task made for a thread, if there is one.
	 *
	 * @param string $thread_id MarkUp thread id.
	 * @return string
	 */
	public function task_for_thread( $thread_id ) {
		$links = $this->links();

		return isset( $links[ $thread_id ] ) ? $this->entry( $links[ $thread_id ] )['task'] : '';
	}

	/**
	 * Whether a reply has already been posted to Motion.
	 *
	 * @param string $thread_id  MarkUp thread id.
	 * @param string $message_id MarkUp message id.
	 * @return bool
	 */
	private function message_seen( $thread_id, $message_id ) {
		$links = $this->links();

		if ( ! isset( $links[ $thread_id ] ) ) {
			return false;
		}

		return in_array( (string) $message_id, $this->entry( $links[ $thread_id ] )['seen'], true );
	}

	/**
	 * Note that a reply has been posted.
	 *
	 * @param string $thread_id  MarkUp thread id.
	 * @param string $message_id MarkUp message id.
	 */
	private function mark_message_seen( $thread_id, $message_id ) {
		$links = $this->links();
		$entry = $this->entry( $links[ $thread_id ] ?? [] );

		$entry['seen'][] = (string) $message_id;

		// Only ever compared against, so keeping every id forever would be
		// storing history nobody reads.
		$entry['seen'] = array_slice( array_unique( $entry['seen'] ), -50 );

		$links[ $thread_id ] = $entry;

		update_option( self::LINKS, $links, false );
	}

	/**
	 * Remember which task belongs to which thread.
	 *
	 * @param string $thread_id MarkUp thread id.
	 * @param string $task_id   Motion task id.
	 */
	private function link( $thread_id, $task_id ) {
		$links = $this->links();
		$entry = $this->entry( $links[ $thread_id ] ?? [] );

		$entry['task']       = $task_id;
		$links[ $thread_id ] = $entry;

		update_option( self::LINKS, $links, false );
	}
}
