<?php
/**
 * The work queue for everything sent to Motion.
 *
 * Nothing calls Motion inside a web request. A webhook arriving, a page being
 * saved, somebody loading the admin — none of those wait on somebody else's
 * API. They add a job and return; a scheduled worker does the talking.
 *
 * That holds even though the account allows 120 requests a minute. The limit is
 * not the only reason for a queue: a burst of comments must not slow the
 * request that delivered them, a Motion outage must not fail work that has
 * nothing to do with Motion, and retries need somewhere to live.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores, claims and retries Motion jobs.
 */
class MBM_LF_Motion_Queue {

	/**
	 * Bumped whenever the table changes.
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Option holding the installed schema version.
	 */
	const SCHEMA_OPTION = 'mbm_lf_motion_schema';

	/**
	 * Cron hook that drains the queue.
	 */
	const CRON_HOOK = 'mbm_lf_motion_drain';

	/**
	 * Stops two workers running at once.
	 */
	const LOCK = 'mbm_lf_motion_lock';

	/**
	 * How many times to retry before parking a job.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Waits between attempts, in seconds.
	 *
	 * @var int[]
	 */
	const BACKOFF = [ 60, 300, 1200, 3600, 10800 ];

	/**
	 * The table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'mbm_lf_motion_queue';
	}

	/**
	 * Create or update the table when the schema changes.
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				thread_id varchar(64) NOT NULL DEFAULT '',
				action varchar(32) NOT NULL DEFAULT '',
				payload longtext NOT NULL,
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				status varchar(16) NOT NULL DEFAULT 'pending',
				last_error text NULL,
				available_at datetime NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY thread_id (thread_id),
				KEY ready (status,available_at)
			) {$collate};"
		);

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Remove the table and everything scheduled around it.
	 */
	public static function drop() {
		global $wpdb;

		$table = self::table();

		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

		delete_option( self::SCHEMA_OPTION );
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/* ---------------------------------------------------------------------
	 * Adding work
	 * ------------------------------------------------------------------ */

	/**
	 * Add a job, unless the same one is already waiting.
	 *
	 * Webhook deliveries repeat, and a queue that turned each repeat into
	 * another task would be worse than no queue at all.
	 *
	 * @param string $action    What to do.
	 * @param string $thread_id MarkUp thread the job concerns.
	 * @param array  $payload   Everything needed to do it later.
	 * @return bool Whether a job was added.
	 */
	public static function add( $action, $thread_id, array $payload ) {
		global $wpdb;

		self::maybe_upgrade();

		$table = self::table();

		$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE thread_id = %s AND action = %s AND status = 'pending' LIMIT 1",
				$thread_id,
				$action
			)
		);

		if ( $existing ) {
			return false;
		}

		$now = current_time( 'mysql', true );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			[
				'thread_id'    => $thread_id,
				'action'       => $action,
				'payload'      => wp_json_encode( $payload ),
				'available_at' => $now,
				'created_at'   => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
		);

		self::ensure_scheduled();

		return true;
	}

	/**
	 * Make sure the worker is due to run.
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
	}

	/* ---------------------------------------------------------------------
	 * Doing the work
	 * ------------------------------------------------------------------ */

	/**
	 * Jobs that are ready to run.
	 *
	 * @param int $limit How many at most.
	 * @return array
	 */
	public static function claim( $limit ) {
		global $wpdb;

		$table = self::table();

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'pending' AND available_at <= %s ORDER BY id ASC LIMIT %d",
				current_time( 'mysql', true ),
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
	}

	/**
	 * A job is done. Remove it.
	 *
	 * @param int $id Job id.
	 */
	public static function complete( $id ) {
		global $wpdb;

		$wpdb->delete( self::table(), [ 'id' => (int) $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * A job failed. Retry it later, or park it if it has had enough goes.
	 *
	 * @param array  $job     The job row.
	 * @param string $message Why it failed.
	 * @param bool   $fatal   Skip retries — the request was rejected on its merits.
	 */
	public static function fail( array $job, $message, $fatal = false ) {
		global $wpdb;

		$attempts = (int) $job['attempts'] + 1;
		$parked   = $fatal || $attempts >= self::MAX_ATTEMPTS;

		$wait = isset( self::BACKOFF[ $attempts - 1 ] ) ? self::BACKOFF[ $attempts - 1 ] : end( self::BACKOFF );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::table(),
			[
				'attempts'     => $attempts,
				'status'       => $parked ? 'parked' : 'pending',
				'last_error'   => $message,
				'available_at' => gmdate( 'Y-m-d H:i:s', time() + $wait ),
			],
			[ 'id' => (int) $job['id'] ],
			[ '%d', '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Push a job back without counting it as a failure.
	 *
	 * Used when Motion rate limits us: the job was fine, the timing was not.
	 *
	 * @param array $job     The job row.
	 * @param int   $seconds How long to wait.
	 */
	public static function defer( array $job, $seconds ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::table(),
			[ 'available_at' => gmdate( 'Y-m-d H:i:s', time() + max( 5, (int) $seconds ) ) ],
			[ 'id' => (int) $job['id'] ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Park every waiting job.
	 *
	 * For when the key is rejected: retrying cannot help, and a bad key would
	 * otherwise burn the whole minute's budget every minute.
	 *
	 * @param string $message Why.
	 */
	public static function park_all( $message ) {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'parked', last_error = %s WHERE status = 'pending'",
				$message
			)
		);
	}

	/**
	 * Put parked jobs back in the queue.
	 *
	 * @return int How many were released.
	 */
	public static function retry_parked() {
		global $wpdb;

		$count = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'pending', attempts = 0, available_at = %s WHERE status = 'parked'",
				current_time( 'mysql', true )
			)
		);

		if ( $count > 0 ) {
			self::ensure_scheduled();
		}

		return $count;
	}

	/* ---------------------------------------------------------------------
	 * Rate limiting
	 * ------------------------------------------------------------------ */

	/**
	 * How many requests are still allowed this minute.
	 *
	 * Deliberately below the real ceiling. The plugin should never be the
	 * reason somebody else's Motion script starts failing.
	 *
	 * @return int
	 */
	public static function budget_remaining() {
		$limit  = (int) MBM_LF_Options::get( 'motion_rate_limit' );
		$limit  = $limit > 0 ? $limit : 120;
		$usable = max( 1, (int) floor( $limit * 0.8 ) );

		$window = get_transient( 'mbm_lf_motion_spent' );
		$spent  = is_numeric( $window ) ? (int) $window : 0;

		return max( 0, $usable - $spent );
	}

	/**
	 * Record that a request was made.
	 */
	public static function spend() {
		$spent = get_transient( 'mbm_lf_motion_spent' );
		$spent = is_numeric( $spent ) ? (int) $spent + 1 : 1;

		// A rolling minute: the transient expiring is what resets the count.
		set_transient( 'mbm_lf_motion_spent', $spent, MINUTE_IN_SECONDS );
	}

	/* ---------------------------------------------------------------------
	 * Reporting
	 * ------------------------------------------------------------------ */

	/**
	 * Counts for the settings screen.
	 *
	 * @return array{pending:int,parked:int,next:string,errors:array}
	 */
	public static function stats() {
		global $wpdb;

		if ( (int) get_option( self::SCHEMA_OPTION ) !== self::SCHEMA_VERSION ) {
			return [
				'pending' => 0,
				'parked'  => 0,
				'next'    => '',
				'errors'  => [],
			];
		}

		$table = self::table();

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status",
			ARRAY_A
		);

		$counts = [
			'pending' => 0,
			'parked'  => 0,
		];

		foreach ( $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}

		$errors = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT DISTINCT last_error FROM {$table} WHERE status = 'parked' AND last_error <> '' LIMIT 3"
		);

		return [
			'pending' => $counts['pending'],
			'parked'  => $counts['parked'],
			'next'    => (string) $wpdb->get_var( "SELECT MIN(available_at) FROM {$table} WHERE status = 'pending'" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'errors'  => $errors,
		];
	}
}
