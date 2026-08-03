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
	}

	/**
	 * Render the section.
	 */
	public function render() {
		$client    = mbm_lf_motion();
		$connected = $client->has_key();
		?>
		<h2 class="title"><?php esc_html_e( 'Send feedback to Motion', 'mbm-live-feedback' ); ?></h2>

		<p class="description" style="max-width:44rem;">
			<?php esc_html_e( 'Optional. Connect Motion and each new comment can become a task for whoever looks after that page. Leave the key empty and nothing about Motion runs at all.', 'mbm-live-feedback' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tbody>
			<?php
			$this->secret_row(
				'motion_api_key',
				__( 'Motion key', 'mbm-live-feedback' ),
				__( 'From your Motion account settings, under API. It is only ever used from your server.', 'mbm-live-feedback' )
			);
			?>

			<?php if ( $connected ) : ?>
				<?php $this->workspace_row(); ?>
				<?php $this->project_row(); ?>
				<?php $this->assignee_row(); ?>
			<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $connected ) : ?>
			<p>
				<button type="button" class="button button-secondary" id="mbm-lf-motion-test">
					<?php esc_html_e( 'Test Motion connection', 'mbm-live-feedback' ); ?>
				</button>
				<span id="mbm-lf-motion-test-result" style="margin-left:.5rem;"></span>
			</p>

			<p class="description" style="max-width:44rem;">
				<?php esc_html_e( 'The lists above are remembered for an hour so this screen stays quick. Just made a new project or added someone to the team? Fetch them again now:', 'mbm-live-feedback' ); ?>
			</p>

			<p>
				<a
					href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mbm_lf_motion_refresh' ), 'mbm_lf_motion_refresh' ) ); ?>"
					class="button button-secondary"
				>
					<?php esc_html_e( 'Fetch workspaces and projects again', 'mbm-live-feedback' ); ?>
				</a>
			</p>

			<?php $this->render_queue(); ?>

			<?php $this->render_script(); ?>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'Save the key and the workspace, project and assignee choices will appear here.', 'mbm-live-feedback' ); ?>
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
							esc_html__( 'Defined as %s, so it cannot be changed here.', 'mbm-live-feedback' ),
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
						<?php esc_html_e( 'Save after changing this and the project list below will follow.', 'mbm-live-feedback' ); ?>
					</p>
				<?php endif; ?>
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
					<p class="description">
						<?php esc_html_e( 'Where this site’s feedback should land. Usually the project for this client.', 'mbm-live-feedback' ); ?>
					</p>
				<?php endif; ?>
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
				<label for="mbm-lf-motion-assignee"><?php esc_html_e( 'Give work to', 'mbm-live-feedback' ); ?></label>
			</th>
			<td>
				<?php if ( is_wp_error( $users ) ) : ?>
					<p style="color:#b32d2e;margin-top:0;"><?php echo esc_html( $users->get_error_message() ); ?></p>
				<?php else : ?>
					<select id="mbm-lf-motion-assignee" name="motion_default_assignee">
						<option value=""><?php esc_html_e( '— Nobody in particular —', 'mbm-live-feedback' ); ?></option>
						<?php foreach ( $users as $user ) : ?>
							<option value="<?php echo esc_attr( $user['id'] ?? '' ); ?>" <?php selected( $current, $user['id'] ?? '' ); ?>>
								<?php echo esc_html( trim( ( $user['name'] ?? '' ) . ' · ' . ( $user['email'] ?? '' ), ' ·' ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Feedback normally goes to whoever wrote the page, matched by email address. This is who picks it up when that person has no Motion account.', 'mbm-live-feedback' ); ?>
					</p>
					<?php $this->render_author_coverage( $workspace ); ?>
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
				<?php esc_html_e( 'Everyone here who can edit content has a matching Motion account.', 'mbm-live-feedback' ); ?>
			</p>
			<?php
			return;
		}
		?>
		<p class="description" style="color:#996800;">
			<?php
			printf(
				/* translators: %s: comma separated list of names. */
				esc_html__( 'No Motion account found for %s, so their pages will fall back to the person above.', 'mbm-live-feedback' ),
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
			? __( 'Saved — leave blank to keep it', 'mbm-live-feedback' )
			: __( 'Paste your Motion key', 'mbm-live-feedback' );
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

		// A project or person from the old workspace means nothing in the new
		// one, so drop them rather than storing something that cannot work.
		if ( '' !== $previous && $workspace !== $previous ) {
			$project  = '';
			$assignee = '';
		}

		MBM_LF_Options::update(
			[
				'motion_workspace_id'     => $workspace,
				'motion_project_id'       => $project,
				'motion_default_assignee' => $assignee,
			]
		);
	}

	/**
	 * Say what the queue is doing.
	 *
	 * Work that happens on a schedule is invisible unless something says so,
	 * and silently parked jobs are just data loss with extra steps.
	 */
	private function render_queue() {
		$ready = ( new MBM_LF_Motion_Tasks() )->is_ready();

		if ( ! $ready ) {
			?>
			<p class="description" style="max-width:44rem;color:#996800;">
				<?php esc_html_e( 'Choose a workspace and a project above and new comments will start becoming tasks.', 'mbm-live-feedback' ); ?>
			</p>
			<?php
			return;
		}

		$stats = MBM_LF_Motion_Queue::stats();
		?>
		<p class="description" style="max-width:44rem;">
			<strong><?php esc_html_e( 'New comments become tasks in the project above.', 'mbm-live-feedback' ); ?></strong>
			<?php esc_html_e( 'Each one is given to whoever wrote the page, matched by email address, or to the person you chose when they have no Motion account.', 'mbm-live-feedback' ); ?>
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

		<?php if ( $stats['parked'] > 0 ) : ?>
			<div class="notice notice-warning inline" style="max-width:44rem;margin:.5rem 0;">
				<p>
					<?php
					printf(
						/* translators: %s: number of items. */
						esc_html( _n( '%s comment could not be sent to Motion.', '%s comments could not be sent to Motion.', $stats['parked'], 'mbm-live-feedback' ) ),
						esc_html( number_format_i18n( $stats['parked'] ) )
					);
					?>
				</p>

				<?php foreach ( $stats['errors'] as $error ) : ?>
					<p><code><?php echo esc_html( $error ); ?></code></p>
				<?php endforeach; ?>

				<p>
					<a
						href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mbm_lf_motion_retry' ), 'mbm_lf_motion_retry' ) ); ?>"
						class="button button-secondary"
					>
						<?php esc_html_e( 'Try sending them again', 'mbm-live-feedback' ); ?>
					</a>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) : ?>
			<p class="description" style="max-width:44rem;">
				<?php esc_html_e( 'Sending happens in the background, shortly after a comment arrives. On a quiet site that waits for the next visitor, so a real server cron makes it prompt.', 'mbm-live-feedback' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Put failed jobs back in the queue.
	 */
	public function handle_retry() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'mbm-live-feedback' ) );
		}

		check_admin_referer( 'mbm_lf_motion_retry' );

		MBM_LF_Motion_Queue::retry_parked();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'          => MBM_LF_Settings::PAGE,
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
			wp_die( esc_html__( 'You do not have permission to do that.', 'mbm-live-feedback' ) );
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
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', 'mbm-live-feedback' ) ], 403 );
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
						out.textContent = <?php echo wp_json_encode( __( 'Could not reach WordPress to do that.', 'mbm-live-feedback' ) ); ?>;
						out.style.color = '#b32d2e';
					} )
					.finally( function () { btn.disabled = false; } );
			} );
		}() );
		</script>
		<?php
	}
}
