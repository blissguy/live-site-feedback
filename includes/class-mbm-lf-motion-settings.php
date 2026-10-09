<?php
/**
 * The Motion section of the settings screen.
 *
 * Deliberately the only part of the plugin that knows Motion exists at this
 * stage. Nothing is created in Motion yet — this is the connection, the
 * workspace and project to use, and who work should go to.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings for the Motion integration.
 */
class MBM_LF_Motion_Settings {

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'mbm_lf_settings_sections', [ $this, 'render' ] );
		add_action( 'mbm_lf_save_settings', [ $this, 'save' ] );
		add_action( 'wp_ajax_mbm_lf_motion_test', [ $this, 'ajax_test' ] );
		add_action( 'admin_post_mbm_lf_motion_refresh', [ $this, 'handle_refresh' ] );
		add_action( 'admin_post_mbm_lf_motion_retry', [ $this, 'handle_retry' ] );
		add_action( 'admin_post_mbm_lf_motion_retry_one', [ $this, 'handle_retry_one' ] );
		add_action( 'admin_post_mbm_lf_motion_forget', [ $this, 'handle_forget' ] );
	}

	/**
	 * Render the section.
	 */
	public function render() {
		$client    = mbm_lf_motion();
		$connected = $client->has_key();
		?>
		<?php // No heading: the tab already says Motion. ?>
		<p class="description" style="max-width:44rem;margin-top:1rem;">
			<?php esc_html_e( 'Turns new comments into Motion tasks.', 'mbm-live-feedback' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tbody>
			<?php
			$this->secret_row(
				'motion_api_key',
				__( 'Motion API key', 'mbm-live-feedback' ),
				__( 'From your Motion account settings, under API.', 'mbm-live-feedback' )
			);
			?>

			<?php if ( $connected ) : ?>
				<?php $this->enabled_row(); ?>
				<?php $this->workspace_row(); ?>
				<?php $this->project_row(); ?>
				<?php $this->status_row(); ?>
				<?php $this->labels_row(); ?>
				<?php $this->assignee_row(); ?>
			<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $connected ) : ?>
			<p>
				<button type="button" class="button button-secondary" id="mbm-lf-motion-test">
					<?php esc_html_e( 'Test connection', 'mbm-live-feedback' ); ?>
				</button>
				<span id="mbm-lf-motion-test-result" style="margin-left:.5rem;"></span>
			</p>

			<p>
				<a
					href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mbm_lf_motion_refresh' ), 'mbm_lf_motion_refresh' ) ); ?>"
					class="button button-secondary"
				>
					<?php esc_html_e( 'Refresh from Motion', 'mbm-live-feedback' ); ?>
				</a>
				<span class="description" style="margin-left:.5rem;">
					<?php esc_html_e( 'Use after adding a project or person in Motion.', 'mbm-live-feedback' ); ?>
				</span>
			</p>

			<?php $this->render_queue(); ?>

			<?php $this->render_script(); ?>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'Save the key to choose a workspace and project.', 'mbm-live-feedback' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Fields
	 * ------------------------------------------------------------------ */

	/**
	 * The key field. Never echoes a stored value back.
	 *
	 * @param string $key         Credential key.
	 * @param string $label       Field label.
	 * @param string $description Help text.
	 */
	private function secret_row( $key, $label, $description ) {
		$locked = MBM_LF_Credentials::is_locked( $key );
		$has    = MBM_LF_Credentials::has( $key );
		?>
		<tr>
			<th scope="row">
				<label for="mbm-lf-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			</th>
			<td>
				<input
					type="password"
					class="regular-text"
					id="mbm-lf-<?php echo esc_attr( $key ); ?>"
					name="<?php echo esc_attr( $key ); ?>"
					value=""
					autocomplete="off"
					<?php disabled( $locked ); ?>
					placeholder="<?php echo esc_attr( $this->placeholder( $locked, $has ) ); ?>"
				>

				<?php if ( $locked ) : ?>
					<p class="description">
						<span style="display:inline-block;background:#eef;border:1px solid #ccd;border-radius:3px;padding:.1rem .4rem;font-size:11px;">
							<?php esc_html_e( 'Set in wp-config.php', 'mbm-live-feedback' ); ?>
						</span>
						<?php
						printf(
							/* translators: %s: PHP constant name. */
							esc_html__( 'Defined as %s, so it can\'t be changed here.', 'mbm-live-feedback' ),
							'<code>' . esc_html( MBM_LF_Credentials::constant_name( $key ) ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						);
						?>
					</p>
				<?php endif; ?>

				<p class="description"><?php echo esc_html( $description ); ?></p>

				<?php if ( ! $locked && $has ) : ?>
					<p class="description"><?php esc_html_e( 'Leave blank to keep the key you already saved.', 'mbm-live-feedback' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Workspace picker.
	 */
	private function workspace_row() {
		$current    = (string) MBM_LF_Options::get( 'motion_workspace_id' );
		$workspaces = mbm_lf_motion()->workspaces();
		?>
		<tr>
			<th scope="row">
				<label for="mbm-lf-motion-workspace"><?php esc_html_e( 'Workspace', 'mbm-live-feedback' ); ?></label>
			</th>
			<td>
				<?php if ( is_wp_error( $workspaces ) ) : ?>
					<p style="color:#b32d2e;margin-top:0;"><?php echo esc_html( $workspaces->get_error_message() ); ?></p>
				<?php else : ?>
					<select id="mbm-lf-motion-workspace" name="motion_workspace_id">
						<option value=""><?php esc_html_e( '— Choose —', 'mbm-live-feedback' ); ?></option>
						<?php foreach ( $workspaces as $workspace ) : ?>
							<option value="<?php echo esc_attr( $workspace['id'] ?? '' ); ?>" <?php selected( $current, $workspace['id'] ?? '' ); ?>>
								<?php echo esc_html( $workspace['name'] ?? $workspace['id'] ?? '' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Save to load this workspace’s projects.', 'mbm-live-feedback' ); ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Each thing that could not be sent, and what to do about it.
	 */
	private function render_parked() {
		$jobs = MBM_LF_Motion_Queue::parked();

		if ( empty( $jobs ) ) {
			return;
		}

		$labels = [
			'create_task'   => __( 'Create the task', 'mbm-live-feedback' ),
			'add_comment'   => __( 'Add a reply to the task', 'mbm-live-feedback' ),
			'resolve_task'  => __( 'Mark the task finished', 'mbm-live-feedback' ),
			'reopen_task'   => __( 'Reopen the task', 'mbm-live-feedback' ),
			'sync_priority' => __( 'Update the task’s priority', 'mbm-live-feedback' ),
		];
		?>
		<table class="widefat striped" style="max-width:60rem;margin:.5rem 0 1rem;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Comment', 'mbm-live-feedback' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Step', 'mbm-live-feedback' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reason', 'mbm-live-feedback' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'mbm-live-feedback' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $jobs as $job ) :
					$payload = json_decode( (string) $job['payload'], true );
					$payload = is_array( $payload ) ? $payload : [];
					$page    = (string) ( $payload['page_url'] ?? '' );
					$text    = (string) ( $payload['text'] ?? '' );
					$action  = (string) $job['action'];
					$nonce   = 'mbm_lf_motion_job_' . (int) $job['id'];
					$again   = wp_nonce_url( admin_url( 'admin-post.php?action=mbm_lf_motion_retry_one&job=' . (int) $job['id'] ), $nonce );
					$drop    = wp_nonce_url( admin_url( 'admin-post.php?action=mbm_lf_motion_forget&job=' . (int) $job['id'] ), $nonce );
					?>
					<tr>
						<td>
							<?php if ( '' !== $text ) : ?>
								<strong><?php echo esc_html( wp_trim_words( $text, 12 ) ); ?></strong><br />
							<?php endif; ?>
							<?php if ( '' !== $page ) : ?>
								<a href="<?php echo esc_url( $page ); ?>"><?php echo esc_html( wp_parse_url( $page, PHP_URL_PATH ) ?: $page ); ?></a>
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'Page unknown', 'mbm-live-feedback' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $labels[ $action ] ?? $action ); ?></td>
						<td>
							<?php echo esc_html( (string) $job['last_error'] ); ?>
							<br />
							<span class="description">
								<?php
								printf(
									/* translators: %s: number of attempts. */
									esc_html( _n( 'after %s try', 'after %s tries', (int) $job['attempts'], 'mbm-live-feedback' ) ),
									esc_html( number_format_i18n( (int) $job['attempts'] ) )
								);
								?>
							</span>
						</td>
						<td>
							<a href="<?php echo esc_url( $again ); ?>" class="button button-small">
								<?php esc_html_e( 'Retry', 'mbm-live-feedback' ); ?>
							</a>
							<a href="<?php echo esc_url( $drop ); ?>" class="button button-small button-link-delete">
								<?php esc_html_e( 'Remove', 'mbm-live-feedback' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The master switch.
	 */
	private function enabled_row() {
		if ( ! mbm_lf_motion()->has_key() ) {
			return;
		}

		$on = (bool) MBM_LF_Options::get( 'motion_enabled' );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Sending', 'mbm-live-feedback' ); ?></th>
			<td>
				<?php /* Marks the control as present. Without it an unchecked box and an unrendered one look identical on save. */ ?>
				<input type="hidden" name="motion_enabled_shown" value="1" />
				<label>
					<input type="checkbox" name="motion_enabled" value="1" <?php checked( $on ); ?> />
					<?php esc_html_e( 'Send new comments to Motion', 'mbm-live-feedback' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Uncheck to pause without losing your settings. Comments left while paused aren\'t sent later.', 'mbm-live-feedback' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Project picker.
	 */
	private function project_row() {
		$workspace = (string) MBM_LF_Options::get( 'motion_workspace_id' );

		if ( '' === $workspace ) {
			return;
		}

		$current  = (string) MBM_LF_Options::get( 'motion_project_id' );
		$projects = mbm_lf_motion()->projects( $workspace );
		?>
		<tr>
			<th scope="row">
				<label for="mbm-lf-motion-project"><?php esc_html_e( 'Project', 'mbm-live-feedback' ); ?></label>
			</th>
			<td>
				<?php if ( is_wp_error( $projects ) ) : ?>
					<p style="color:#b32d2e;margin-top:0;"><?php echo esc_html( $projects->get_error_message() ); ?></p>
				<?php else : ?>
					<select id="mbm-lf-motion-project" name="motion_project_id">
						<option value=""><?php esc_html_e( '— Choose —', 'mbm-live-feedback' ); ?></option>
						<?php foreach ( $projects as $project ) : ?>
							<option value="<?php echo esc_attr( $project['id'] ?? '' ); ?>" <?php selected( $current, $project['id'] ?? '' ); ?>>
								<?php echo esc_html( $project['name'] ?? $project['id'] ?? '' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Starting-status picker.
	 */
	private function status_row() {
		$workspace = (string) MBM_LF_Options::get( 'motion_workspace_id' );

		if ( '' === $workspace ) {
			return;
		}

		$current  = (string) MBM_LF_Options::get( 'motion_new_status' );
		$statuses = mbm_lf_motion()->statuses( $workspace );
		$resolved = mbm_lf_motion()->resolved_status( $workspace );
		$default  = mbm_lf_motion()->default_status( $workspace );

		if ( empty( $statuses ) ) {
			return;
		}
		?>
		<tr>
			<th scope="row">
				<label for="mbm-lf-motion-status"><?php esc_html_e( 'Starting status', 'mbm-live-feedback' ); ?></label>
			</th>
			<td>
				<select id="mbm-lf-motion-status" name="motion_new_status">
					<option value="">
						<?php
						if ( '' !== $default ) {
							/* translators: %s: name of the workspace's default status, e.g. Todo. */
							printf( esc_html__( 'Motion\'s default (%s)', 'mbm-live-feedback' ), esc_html( $default ) );
						} else {
							esc_html_e( 'Motion\'s default', 'mbm-live-feedback' );
						}
						?>
					</option>
					<?php
					foreach ( $statuses as $status ) {
						$name = (string) ( $status['name'] ?? '' );

						// Resolving a comment owns this one; starting there would mean arriving finished.
						if ( '' === $name || $name === $resolved ) {
							continue;
						}
						?>
						<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $current, $name ); ?>>
							<?php echo esc_html( $name ); ?>
						</option>
						<?php
					}
					?>
				</select>
				<p class="description">
					<?php esc_html_e( 'Reopening a comment sends its task back here too.', 'mbm-live-feedback' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Labels applied to every new task.
	 */
	private function labels_row() {
		$workspace = (string) MBM_LF_Options::get( 'motion_workspace_id' );

		if ( '' === $workspace ) {
			return;
		}

		$available = mbm_lf_motion()->labels( $workspace );

		if ( empty( $available ) ) {
			return;
		}

		$current = (array) MBM_LF_Options::get( 'motion_labels' );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Labels', 'mbm-live-feedback' ); ?></th>
			<td>
				<input type="hidden" name="motion_labels_shown" value="1" />
				<?php foreach ( $available as $label ) : ?>
					<label style="display:block;margin-bottom:.25rem;">
						<input
							type="checkbox"
							name="motion_labels[]"
							value="<?php echo esc_attr( $label ); ?>"
							<?php checked( in_array( $label, $current, true ) ); ?> />
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
				<p class="description">
					<?php esc_html_e( 'Added to every new task.', 'mbm-live-feedback' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Fallback assignee picker.
	 */
	private function assignee_row() {
		$workspace = (string) MBM_LF_Options::get( 'motion_workspace_id' );

		if ( '' === $workspace ) {
			return;
		}

		$current = (string) MBM_LF_Options::get( 'motion_default_assignee' );
		$users   = mbm_lf_motion()->users( $workspace );
		?>
		<tr>
			<th scope="row">
				<label for="mbm-lf-motion-assignee"><?php esc_html_e( 'Default assignee', 'mbm-live-feedback' ); ?></label>
			</th>
			<td>
				<?php if ( is_wp_error( $users ) ) : ?>
					<p style="color:#b32d2e;margin-top:0;"><?php echo esc_html( $users->get_error_message() ); ?></p>
				<?php else : ?>
					<select id="mbm-lf-motion-assignee" name="motion_default_assignee">
						<option value=""><?php esc_html_e( '— Nobody —', 'mbm-live-feedback' ); ?></option>
						<?php foreach ( $users as $user ) : ?>
							<option value="<?php echo esc_attr( $user['id'] ?? '' ); ?>" <?php selected( $current, $user['id'] ?? '' ); ?>>
								<?php echo esc_html( trim( ( $user['name'] ?? '' ) . ' · ' . ( $user['email'] ?? '' ), ' ·' ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Used when the page’s author has no Motion account.', 'mbm-live-feedback' ); ?>
					</p>

					<p style="margin-top:.75rem;">
						<label>
							<input
								type="checkbox"
								name="motion_always_fallback"
								value="1"
								<?php checked( (bool) MBM_LF_Options::get( 'motion_always_fallback' ) ); ?>
							>
							<?php esc_html_e( 'Assign every task to the default assignee', 'mbm-live-feedback' ); ?>
						</label>
					</p>

					<p class="description">
						<?php esc_html_e( 'Leave off to assign each task to the page\'s author when they have a Motion account.', 'mbm-live-feedback' ); ?>
					</p>

					<?php if ( ! MBM_LF_Options::get( 'motion_always_fallback' ) ) : ?>
						<?php $this->render_author_coverage( $workspace ); ?>
					<?php endif; ?>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Say which of this site's authors Motion actually knows about.
	 *
	 * Better to find out here than to discover months later that everything has
	 * been quietly landing on the fallback.
	 *
	 * @param string $workspace Workspace id.
	 */
	private function render_author_coverage( $workspace ) {
		$map = mbm_lf_motion()->email_map( $workspace );

		if ( ! $map ) {
			return;
		}

		$authors = get_users(
			[
				'capability' => [ 'edit_posts' ],
				'number'     => 50,
				'fields'     => [ 'display_name', 'user_email' ],
			]
		);

		$missing = [];

		foreach ( $authors as $author ) {
			if ( ! isset( $map[ strtolower( trim( $author->user_email ) ) ] ) ) {
				$missing[] = $author->display_name;
			}
		}

		if ( ! $missing ) {
			?>
			<p class="description" style="color:#1d7a3c;">
				<?php esc_html_e( 'Everyone who can edit content has a Motion account.', 'mbm-live-feedback' ); ?>
			</p>
			<?php
			return;
		}
		?>
		<p class="description" style="color:#996800;">
			<?php
			printf(
				/* translators: %s: comma separated list of names. */
				esc_html__( 'No Motion account found for %s, so their pages go to the default assignee.', 'mbm-live-feedback' ),
				esc_html( implode( ', ', array_slice( $missing, 0, 6 ) ) . ( count( $missing ) > 6 ? '…' : '' ) )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Placeholder for the key field.
	 *
	 * @param bool $locked Controlled by a constant.
	 * @param bool $has    Already has a value.
	 * @return string
	 */
	private function placeholder( $locked, $has ) {
		if ( $locked ) {
			return __( 'Set in wp-config.php', 'mbm-live-feedback' );
		}

		return $has
			? __( 'Saved. Leave blank to keep it.', 'mbm-live-feedback' )
			: __( 'Paste your Motion API key', 'mbm-live-feedback' );
	}

	/* ---------------------------------------------------------------------
	 * Saving
	 * ------------------------------------------------------------------ */

	/**
	 * Store the section's settings.
	 *
	 * The nonce and capability were checked before this ran.
	 *
	 * @param array $raw Raw $_POST.
	 */
	public function save( $raw ) {
		$key = isset( $raw['motion_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $raw['motion_api_key'] ) ) ) : '';

		// Blank means "keep what is saved", as with the other secrets.
		if ( '' !== $key ) {
			MBM_LF_Credentials::set( 'motion_api_key', $key );

			// A different key can see entirely different workspaces.
			mbm_lf_motion()->flush_caches();
		}

		$workspace = isset( $raw['motion_workspace_id'] ) ? sanitize_text_field( wp_unslash( $raw['motion_workspace_id'] ) ) : '';
		$previous  = (string) MBM_LF_Options::get( 'motion_workspace_id' );
		$project   = isset( $raw['motion_project_id'] ) ? sanitize_text_field( wp_unslash( $raw['motion_project_id'] ) ) : '';
		$assignee  = isset( $raw['motion_default_assignee'] ) ? sanitize_text_field( wp_unslash( $raw['motion_default_assignee'] ) ) : '';
		$status    = isset( $raw['motion_new_status'] ) ? sanitize_text_field( wp_unslash( $raw['motion_new_status'] ) ) : '';

		// A project, person or status from the old workspace means nothing in the
		// new one, so drop them rather than storing something that cannot work.
		if ( '' !== $previous && $workspace !== $previous ) {
			$project  = '';
			$assignee = '';
			$status   = '';
		}

		// A status Motion does not recognise makes every task creation fail, so
		// only store one the workspace actually offers.
		if ( '' !== $status && ! $this->status_exists( $workspace, $status ) ) {
			$status = '';
		}

		MBM_LF_Options::update(
			[
				'motion_workspace_id'     => $workspace,
				'motion_project_id'       => $project,
				'motion_default_assignee' => $assignee,
				'motion_always_fallback'  => ! empty( $raw['motion_always_fallback'] ),
				'motion_new_status'       => $status,
			]
		);

		/*
		 * Only when the switch was actually on screen. An unchecked box sends
		 * nothing, and so does a form that never showed one — on the first save
		 * after adding a key, that would read as "switch it off" and the
		 * integration would arrive dead.
		 */
		if ( ! empty( $raw['motion_labels_shown'] ) ) {
			$wanted = isset( $raw['motion_labels'] ) ? (array) wp_unslash( $raw['motion_labels'] ) : [];
			$known  = mbm_lf_motion()->labels( $workspace );

			// An unknown label is a 404 on every task creation, discovered at
			// drain time rather than here.
			$labels = array_values( array_intersect( array_map( 'sanitize_text_field', $wanted ), $known ) );

			MBM_LF_Options::update( [ 'motion_labels' => $labels ] );
		}

		if ( ! empty( $raw['motion_enabled_shown'] ) ) {
			MBM_LF_Options::update( [ 'motion_enabled' => ! empty( $raw['motion_enabled'] ) ] );
		}
	}

	/**
	 * Whether a workspace offers a status by that name.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $status       Status name.
	 * @return bool
	 */
	private function status_exists( $workspace_id, $status ) {
		foreach ( mbm_lf_motion()->statuses( $workspace_id ) as $candidate ) {
			if ( isset( $candidate['name'] ) && $candidate['name'] === $status ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Say when the site last checked MarkUp.io for changes.
	 *
	 * The check is what creates tasks, so if it stops, tasks stop. Saying when
	 * it last ran is the difference between noticing that in minutes and
	 * noticing it weeks later, which is how the webhook-only version failed.
	 */
	private function render_last_check() {
		$last = MBM_LF_Motion_Watch::last_check();

		if ( ! $last ) {
			?>
			<p class="description" style="max-width:44rem;">
				<?php esc_html_e( 'Checks for new comments every five minutes. The first check hasn\'t run yet.', 'mbm-live-feedback' ); ?>
			</p>
			<?php
			return;
		}

		$ago = human_time_diff( (int) $last['at'] );

		// Three missed intervals means scheduled work is not running, not that
		// the site is merely quiet.
		$late = time() - (int) $last['at'] > 3 * MBM_LF_Motion_Watch::INTERVAL;

		if ( '' !== $last['error'] ) {
			?>
			<p class="description" style="max-width:44rem;color:#b32d2e;">
				<?php
				printf(
					/* translators: 1: time difference such as "5 mins", 2: error message. */
					esc_html__( 'Couldn\'t check for new comments %1$s ago: %2$s', 'mbm-live-feedback' ),
					esc_html( $ago ),
					esc_html( $last['error'] )
				);
				?>
			</p>
			<?php
			return;
		}
		?>
		<p class="description" style="max-width:44rem;<?php echo $late ? 'color:#996800;' : ''; ?>">
			<?php
			printf(
				/* translators: %s: time difference such as "5 mins". */
				esc_html__( 'Last checked for new comments %s ago.', 'mbm-live-feedback' ),
				esc_html( $ago )
			);

			if ( $late ) {
				echo ' ';
				esc_html_e( 'It should check every five minutes, so scheduled tasks on this site may not be running.', 'mbm-live-feedback' );
			}
			?>
		</p>
		<?php
	}

	/**
	 * Say what the queue is doing.
	 *
	 * Work that happens on a schedule is invisible unless something says so,
	 * and silently parked jobs are just data loss with extra steps.
	 */
	private function render_queue() {
		$tasks = new MBM_LF_Motion_Tasks();

		if ( ! $tasks->is_ready() ) {
			?>
			<p class="description" style="max-width:44rem;color:#996800;">
				<?php esc_html_e( 'Choose a workspace and project to start.', 'mbm-live-feedback' ); ?>
			</p>
			<?php
			return;
		}

		// Set up but switched off. Saying nothing here would look like a fault.
		if ( ! $tasks->is_active() ) {
			?>
			<p class="description" style="max-width:44rem;color:#996800;">
				<?php esc_html_e( 'Paused. Nothing is being sent to Motion.', 'mbm-live-feedback' ); ?>
			</p>
			<?php
			return;
		}

		$stats = MBM_LF_Motion_Queue::stats();
		?>
		<p class="description" style="max-width:44rem;">
			<?php esc_html_e( 'Replies, priority changes and resolving also reach the task.', 'mbm-live-feedback' ); ?>
		</p>

		<p class="description" style="max-width:44rem;">
			<?php
			if ( $stats['pending'] > 0 ) {
				printf(
					/* translators: %s: number of items. */
					esc_html( _n( '%s comment is waiting to be sent.', '%s comments are waiting to be sent.', $stats['pending'], 'mbm-live-feedback' ) ),
					esc_html( number_format_i18n( $stats['pending'] ) )
				);
			} else {
				esc_html_e( 'Nothing is waiting to be sent.', 'mbm-live-feedback' );
			}
			?>
		</p>

		<?php $this->render_last_check(); ?>

		<?php if ( $stats['parked'] > 0 ) : ?>
			<div class="notice notice-warning inline" style="max-width:44rem;margin:.5rem 0;">
				<p>
					<?php
					printf(
						/* translators: %s: number of items. */
						esc_html( _n( '%s comment couldn\'t be sent to Motion.', '%s comments couldn\'t be sent to Motion.', $stats['parked'], 'mbm-live-feedback' ) ),
						esc_html( number_format_i18n( $stats['parked'] ) )
					);
					?>
				</p>

				<p>
					<a
						href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mbm_lf_motion_retry' ), 'mbm_lf_motion_retry' ) ); ?>"
						class="button button-secondary"
					>
						<?php esc_html_e( 'Retry all', 'mbm-live-feedback' ); ?>
					</a>
				</p>
			</div>

			<?php $this->render_parked(); ?>
		<?php endif; ?>

		<?php if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) : ?>
			<p class="description" style="max-width:44rem;">
				<?php esc_html_e( 'Feedback reaches Motion within a few minutes.', 'mbm-live-feedback' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Put failed jobs back in the queue.
	 */
	/**
	 * Put one parked job back in the queue.
	 */
	public function handle_retry_one() {
		$job = $this->requested_job();

		MBM_LF_Motion_Queue::retry_one( $job );

		$this->back_to_settings( 'motion-retried-one' );
	}

	/**
	 * Throw one parked job away.
	 */
	public function handle_forget() {
		$job = $this->requested_job();

		MBM_LF_Motion_Queue::forget( $job );

		$this->back_to_settings( 'motion-forgotten' );
	}

	/**
	 * The job id in the request, once it is allowed to be acted on.
	 *
	 * The nonce is tied to the specific job, so a link for one cannot be bent
	 * into acting on another.
	 *
	 * @return int
	 */
	private function requested_job() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You don\'t have permission to do that.', 'mbm-live-feedback' ) );
		}

		$job = isset( $_GET['job'] ) ? absint( wp_unslash( $_GET['job'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( 'mbm_lf_motion_job_' . $job );

		return $job;
	}

	/**
	 * Return to the settings screen with something to say.
	 *
	 * @param string $notice Notice key.
	 */
	private function back_to_settings( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'          => MBM_LF_Settings::PAGE,
					'tab'           => 'motion',
					'mbm_lf_notice' => $notice,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_retry() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You don\'t have permission to do that.', 'mbm-live-feedback' ) );
		}

		check_admin_referer( 'mbm_lf_motion_retry' );

		MBM_LF_Motion_Queue::retry_parked();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'          => MBM_LF_Settings::PAGE,
					'tab'           => 'motion',
					'mbm_lf_notice' => 'motion-retrying',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Forget the cached lists and read them again.
	 *
	 * A link rather than a form, because this section lives inside the main
	 * settings form and a nested one is not allowed.
	 */
	public function handle_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You don\'t have permission to do that.', 'mbm-live-feedback' ) );
		}

		check_admin_referer( 'mbm_lf_motion_refresh' );

		mbm_lf_motion()->flush_caches();

		// Warm them straight away so the screen we return to is already right.
		$workspace = (string) MBM_LF_Options::get( 'motion_workspace_id' );

		mbm_lf_motion()->workspaces( true );

		if ( '' !== $workspace ) {
			mbm_lf_motion()->projects( $workspace, true );
			mbm_lf_motion()->users( $workspace, true );
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'          => MBM_LF_Settings::PAGE,
					'tab'           => 'motion',
					'mbm_lf_notice' => 'motion-refreshed',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Connection test
	 * ------------------------------------------------------------------ */

	/**
	 * Check the saved key against Motion.
	 */
	public function ajax_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You don\'t have permission to do that.', 'mbm-live-feedback' ) ], 403 );
		}

		check_ajax_referer( 'mbm_lf_motion_test' );

		$workspaces = mbm_lf_motion()->workspaces( true );

		if ( is_wp_error( $workspaces ) ) {
			wp_send_json_error( [ 'message' => $workspaces->get_error_message() ] );
		}

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %s: number of workspaces. */
					esc_html( _n( 'Connected. %s workspace found.', 'Connected. %s workspaces found.', count( $workspaces ), 'mbm-live-feedback' ) ),
					esc_html( number_format_i18n( count( $workspaces ) ) )
				),
			]
		);
	}

	/**
	 * The test button's script.
	 */
	private function render_script() {
		?>
		<script>
		( function () {
			var btn = document.getElementById( 'mbm-lf-motion-test' );
			var out = document.getElementById( 'mbm-lf-motion-test-result' );

			if ( ! btn ) {
				return;
			}

			btn.addEventListener( 'click', function () {
				btn.disabled = true;
				out.textContent = <?php echo wp_json_encode( __( 'Checking…', 'mbm-live-feedback' ) ); ?>;
				out.style.color = '';

				var body = new FormData();
				body.append( 'action', 'mbm_lf_motion_test' );
				body.append( '_wpnonce', <?php echo wp_json_encode( wp_create_nonce( 'mbm_lf_motion_test' ) ); ?> );

				fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						out.textContent = ( res.data && res.data.message ) || '';
						out.style.color = res.success ? '#1d7a3c' : '#b32d2e';
					} )
					.catch( function () {
						out.textContent = <?php echo wp_json_encode( __( 'Couldn\'t reach WordPress. Try again.', 'mbm-live-feedback' ) ); ?>;
						out.style.color = '#b32d2e';
					} )
					.finally( function () { btn.disabled = false; } );
			} );
		}() );
		</script>
		<?php
	}
}
