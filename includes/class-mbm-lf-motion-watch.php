<?php
/**
 * Notices what changed in MarkUp.io by asking, instead of waiting to be told.
 *
 * Motion used to hear about feedback only through MarkUp.io's webhook, and that
 * proved to be a single point of failure nobody could see. On learnwithvelo.com
 * no delivery arrived for eight weeks: first the registration still pointed at
 * the site's old temporary address, which redirects, and then Cloudflare's Bot
 * Fight Mode turned MarkUp.io's requests away before WordPress saw them. In
 * both cases MarkUp.io was sending and the site looked healthy. Nothing failed;
 * tasks simply stopped appearing.
 *
 * So the site now checks for itself every few minutes. It keeps a small note
 * of each thread — resolved or not, how many messages, which priority — and
 * compares the next answer against it. Whatever differs becomes a job on the
 * existing queue. A webhook delivery still helps: it just makes the next check
 * happen now instead of in a few minutes.
 *
 * This is not a copy of the comments. MarkUp.io stays the only source of truth;
 * the note holds three small values per thread and nothing anybody wrote.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds new comments, replies, resolves and priority changes, and queues them.
 */
class MBM_LF_Motion_Watch {

	/**
	 * Scheduled event that runs the check.
	 */
	const CRON_HOOK = 'mbm_lf_motion_check';

	/**
	 * Name of the schedule it runs on.
	 */
	const SCHEDULE = 'mbm_lf_five_minutes';

	/**
	 * How often to check.
	 *
	 * Two MarkUp.io requests per markup each time, against a limit of a
	 * thousand a minute. Motion is not called at all unless something changed.
	 */
	const INTERVAL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Option holding what each thread looked like last time.
	 *
	 * Not autoloaded: only the check reads it.
	 */
	const STATE = 'mbm_lf_motion_watch';

	/**
	 * Lock, so a scheduled check and a webhook-triggered one cannot overlap and
	 * queue the same change twice.
	 */
	const LOCK = 'mbm_lf_motion_watch_lock';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_filter( 'cron_schedules', [ $this, 'add_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- five minutes is the point.
		add_action( self::CRON_HOOK, [ $this, 'check' ] );
		add_action( 'mbm_lf_webhook_received', [ $this, 'check_soon' ] );
		add_action( 'init', [ $this, 'ensure_scheduled' ] );
	}

	/**
	 * Add the five-minute schedule.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_schedule( $schedules ) {
		$schedules[ self::SCHEDULE ] = [
			'interval' => self::INTERVAL,
			'display'  => __( 'Every five minutes (Live Site Feedback)', 'mbm-live-feedback' ),
		];

		return $schedules;
	}

	/**
	 * Keep the recurring check scheduled while Motion is set up.
	 *
	 * Runs on every request, so it is one cached lookup in the common case.
	 * Scheduling only once Motion is ready means a site that saved a key but
	 * never chose a project carries no scheduled work.
	 */
	public function ensure_scheduled() {
		if ( ! $this->tasks()->is_ready() ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * MarkUp.io reported something. Check now rather than at the next interval.
	 *
	 * The delivery itself is not read. It is unsigned, it can repeat and it can
	 * arrive out of order; all it is trusted to say is "look again". The
	 * webhook has already refused deliveries about other sites by this point.
	 *
	 * The argument matters. WordPress refuses a single event within ten minutes
	 * of another with the same hook and arguments, and the recurring check is
	 * always within ten minutes, so without it this would never be scheduled.
	 */
	public function check_soon() {
		if ( ! $this->tasks()->is_ready() ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK, [ 'soon' ] ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK, [ 'soon' ] );
		}
	}

	/**
	 * Compare MarkUp.io with what we saw last time, and queue the differences.
	 */
	public function check() {
		$tasks = $this->tasks();

		if ( ! $tasks->is_ready() || get_transient( self::LOCK ) ) {
			return;
		}

		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );

		$current = $this->read_threads();

		if ( is_wp_error( $current ) ) {
			// Leave the note as it was. Writing a partial answer would make the
			// threads we could not read look deleted, and then new next time.
			$this->record_check( $current->get_error_message() );
			delete_transient( self::LOCK );

			return;
		}

		$state = $this->state();

		/*
		 * The first check only takes note. Everything already there was left
		 * before this ran, and turning all of it into tasks at once would bury
		 * the board in feedback people have already dealt with.
		 *
		 * The same goes for while Motion is paused: the note keeps up, so
		 * resuming starts from now instead of replaying everything missed.
		 */
		$quiet = ! $state['baseline'] || ! $tasks->is_active();

		$next = [];

		foreach ( $current as $thread_id => $thread ) {
			$before = isset( $state['threads'][ $thread_id ] ) ? $state['threads'][ $thread_id ] : null;
			$now    = $this->summarise( $thread );

			if ( null === $before ) {
				// "Ours" means this thread has, or is about to have, a task. Only
				// those get replies, resolves and priority passed on later.
				$now['ours'] = ! $quiet || '' !== $tasks->task_for_thread( $thread_id );

				if ( ! $quiet ) {
					$this->queue_new( $thread_id, $thread, $now );
				}
			} else {
				$now['ours'] = ! empty( $before['ours'] ) || '' !== $tasks->task_for_thread( $thread_id );

				if ( ! $quiet && $now['ours'] ) {
					$this->queue_changes( $thread_id, $before, $now );
				}
			}

			$next[ $thread_id ] = $now;
		}

		// Threads no longer listed were deleted; forgetting them keeps the note small.
		update_option(
			self::STATE,
			[
				'baseline' => true,
				'threads'  => $next,
			],
			false
		);

		$this->record_check( '' );

		delete_transient( self::LOCK );

		// Already in a scheduled event, so there is no visitor to make wait.
		// Sending now saves the queue's own thirty-second delay.
		$tasks->drain();
	}

	/**
	 * Every thread on every markup this site reads from, keyed by id.
	 *
	 * @return array|WP_Error
	 */
	private function read_threads() {
		$threads = [];

		foreach ( array_slice( mbm_lf_threads()->markup_ids(), 0, MBM_LF_Threads::MAX_MARKUPS ) as $markup_id ) {
			$raw = mbm_lf_threads()->fetch_all_raw( $markup_id );

			if ( is_wp_error( $raw ) ) {
				return $raw;
			}

			foreach ( $raw as $thread ) {
				$thread['_markup_id']                = (string) $markup_id;
				$threads[ (string) $thread['id'] ] = $thread;
			}
		}

		return $threads;
	}

	/**
	 * The three things compared between checks.
	 *
	 * @param array $thread Raw thread.
	 * @return array{resolved:bool,messages:int,priority:string}
	 */
	private function summarise( array $thread ) {
		$messages = isset( $thread['messages'] ) && is_array( $thread['messages'] ) ? $thread['messages'] : [];

		// Priority lives on the root message in practice; see priority_for_thread().
		$priority = $thread['priority'] ?? $thread['priorityId'] ?? $messages[0]['priority'] ?? $messages[0]['priorityId'] ?? '';

		return [
			'resolved' => ! empty( $thread['resolved'] ),
			'messages' => count( $messages ),
			'priority' => is_scalar( $priority ) ? (string) $priority : (string) wp_json_encode( $priority ),
		];
	}

	/**
	 * Queue a task for a thread seen for the first time.
	 *
	 * @param string $thread_id Thread id.
	 * @param array  $thread    Raw thread.
	 * @param array  $now       Its summary.
	 */
	private function queue_new( $thread_id, array $thread, array $now ) {
		$messages = isset( $thread['messages'] ) && is_array( $thread['messages'] ) ? $thread['messages'] : [];
		$first    = isset( $messages[0] ) && is_array( $messages[0] ) ? $messages[0] : [];
		$text     = '';

		foreach ( [ 'message', 'content', 'text' ] as $key ) {
			if ( ! empty( $first[ $key ] ) && is_string( $first[ $key ] ) ) {
				$text = $first[ $key ];
				break;
			}
		}

		$markup_id = (string) $thread['_markup_id'];

		MBM_LF_Motion_Queue::add(
			'create_task',
			$thread_id,
			[
				'text'      => $text,
				'number'    => (int) ( $thread['number'] ?? 0 ),
				// The same deep link MarkUp.io puts in its own deliveries.
				'app_url'   => 'https://app.markup.io/markup/' . rawurlencode( $markup_id ) . '/#thread/' . rawurlencode( $thread_id ),
				'author'    => (string) ( $thread['user']['name'] ?? $first['user']['name'] ?? '' ),
				'page_url'  => (string) ( $thread['canonicalUrl'] ?? $thread['originalUrl'] ?? '' ),
				'markup_id' => $markup_id,
				'browser'   => isset( $thread['browserContext'] ) && is_array( $thread['browserContext'] ) ? $thread['browserContext'] : [],
			]
		);

		// Replied to or resolved within the same few minutes it was left.
		// Both jobs wait for the task to exist before they do anything.
		if ( $now['messages'] > 1 ) {
			MBM_LF_Motion_Queue::add( 'add_comment', $thread_id, [ 'markup_id' => $markup_id ] );
		}

		if ( $now['resolved'] ) {
			MBM_LF_Motion_Queue::add( 'resolve_task', $thread_id, [] );
		}
	}

	/**
	 * Queue whatever changed on a thread that already has a task.
	 *
	 * @param string $thread_id Thread id.
	 * @param array  $before    Last summary.
	 * @param array  $now       Current summary.
	 */
	private function queue_changes( $thread_id, array $before, array $now ) {
		if ( $now['messages'] > (int) $before['messages'] ) {
			MBM_LF_Motion_Queue::add( 'add_comment', $thread_id, [] );
		}

		if ( $now['resolved'] !== (bool) $before['resolved'] ) {
			MBM_LF_Motion_Queue::add( $now['resolved'] ? 'resolve_task' : 'reopen_task', $thread_id, [] );
		}

		if ( $now['priority'] !== (string) $before['priority'] ) {
			MBM_LF_Motion_Queue::add( 'sync_priority', $thread_id, [] );
		}
	}

	/**
	 * The note from last time.
	 *
	 * @return array{baseline:bool,threads:array}
	 */
	private function state() {
		$state = get_option( self::STATE, [] );

		return [
			'baseline' => ! empty( $state['baseline'] ),
			'threads'  => isset( $state['threads'] ) && is_array( $state['threads'] ) ? $state['threads'] : [],
		];
	}

	/**
	 * Remember when the last check ran, for the settings screen.
	 *
	 * @param string $error Why it failed, or empty when it worked.
	 */
	private function record_check( $error ) {
		MBM_LF_Options::update(
			[
				'motion_last_check' => [
					'at'    => time(),
					'error' => (string) $error,
				],
			]
		);
	}

	/**
	 * When the last check ran, and whether it worked.
	 *
	 * @return array{at:int,error:string}|null
	 */
	public static function last_check() {
		$value = MBM_LF_Options::get( 'motion_last_check' );

		return is_array( $value ) && ! empty( $value['at'] ) ? $value : null;
	}

	/**
	 * Forget everything, for uninstall and for removing the Motion key.
	 */
	public static function forget() {
		delete_option( self::STATE );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::CRON_HOOK, [ 'soon' ] );
	}

	/**
	 * The task worker.
	 *
	 * @return MBM_LF_Motion_Tasks
	 */
	private function tasks() {
		static $tasks = null;

		if ( null === $tasks ) {
			$tasks = new MBM_LF_Motion_Tasks();
		}

		return $tasks;
	}
}
