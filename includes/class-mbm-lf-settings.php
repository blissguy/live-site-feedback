<?php
/**
 * Admin settings screen.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and saves the plugin settings.
 */
class MBM_LF_Settings {

	/**
	 * Admin page slug.
	 */
	const PAGE = 'mbm-live-feedback';

	/**
	 * Nonce action for saving.
	 */
	const NONCE = 'mbm_lf_save_settings';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_mbm_lf_save_settings', [ $this, 'handle_save' ] );
		add_action( 'wp_ajax_mbm_lf_test_connection', [ $this, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_mbm_lf_provision', [ $this, 'ajax_provision' ] );
		add_filter( 'plugin_action_links_' . MBM_LF_BASENAME, [ $this, 'action_links' ] );
	}

	/**
	 * Add the admin menu entry.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Live Site Feedback', 'mbm-live-feedback' ),
			__( 'Live Feedback', 'mbm-live-feedback' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render_page' ],
			'dashicons-format-chat',
			80
		);
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'mbm-live-feedback' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Output the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'mbm-live-feedback' ) );
		}

		/*
		 * Reading which message to show after a redirect. Not form processing —
		 * it decides between a handful of fixed strings and changes nothing — and
		 * the screen is already behind a capability check. Whatever arrives is
		 * reduced to a key and matched against a known list.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['mbm_lf_notice'] ) ? sanitize_key( wp_unslash( $_GET['mbm_lf_notice'] ) ) : '';

		$tabs = self::tabs();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$active    = isset( $tabs[ $requested ] ) ? $requested : 'setup';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Live Site Feedback', 'mbm-live-feedback' ); ?></h1>

			<?php $this->render_notice( $notice ); ?>

			<?php $this->render_status_strip(); ?>

			<h2 class="nav-tab-wrapper" id="mbm-lf-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE, 'tab' => $slug ], admin_url( 'admin.php' ) ) ); ?>"
						class="nav-tab <?php echo $slug === $active ? 'nav-tab-active' : ''; ?>"
						data-tab="<?php echo esc_attr( $slug ); ?>"
					>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="mbm-lf-form">
				<input type="hidden" name="action" value="mbm_lf_save_settings">
				<input type="hidden" name="mbm_lf_tab" id="mbm-lf-active-tab" value="<?php echo esc_attr( $active ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>

				<?php
				/*
				 * Every panel is in the one form, so a single save covers the
				 * whole screen and moving between tabs cannot lose anything.
				 * They all render visible: the script below hides the inactive
				 * ones, so with no JavaScript this degrades to one long page
				 * that still works rather than to four unreachable ones.
				 */
				?>
				<div class="mbm-lf-panel" data-panel="setup">
					<?php $this->render_setup_section(); ?>

					<h2 class="title"><?php esc_html_e( 'Your MarkUp.io account', 'mbm-live-feedback' ); ?></h2>

					<table class="form-table" role="presentation">
						<tbody>
						<?php
						$this->render_secret_row(
							'api_key',
							__( 'API key', 'mbm-live-feedback' ),
							__( 'From your workspace settings in MarkUp.io. Used only on your server.', 'mbm-live-feedback' )
						);

						$this->render_text_row(
							'public_key',
							__( 'Site identifier', 'mbm-live-feedback' ),
							__( 'Filled in by Set up. Safe to appear in your pages.', 'mbm-live-feedback' )
						);
						?>
						</tbody>
					</table>
				</div>

				<div class="mbm-lf-panel" data-panel="feedback">
					<h2 class="title"><?php esc_html_e( 'Where feedback appears', 'mbm-live-feedback' ); ?></h2>

					<?php $this->render_placement_fields(); ?>

					<h2 class="title"><?php esc_html_e( 'Appearance', 'mbm-live-feedback' ); ?></h2>

					<?php $this->render_appearance_fields(); ?>
				</div>

				<div class="mbm-lf-panel" data-panel="people">
					<h2 class="title"><?php esc_html_e( 'Who can leave feedback', 'mbm-live-feedback' ); ?></h2>

					<?php $this->render_visibility_fields(); ?>

					<h2 class="title"><?php esc_html_e( 'How people are identified', 'mbm-live-feedback' ); ?></h2>

					<?php $this->render_identity_fields(); ?>
				</div>

				<div class="mbm-lf-panel" data-panel="integrations">
					<?php
					/**
					 * Lets an optional module add its own settings.
					 *
					 * Used by the Motion integration, which stays out of this
					 * class entirely and adds nothing at all unless configured.
					 */
					do_action( 'mbm_lf_settings_sections' );
					?>
				</div>

				<div class="mbm-lf-panel" data-panel="advanced">
					<?php $this->render_expert_fields(); ?>

					<?php $this->render_status_table(); ?>

					<p>
						<button type="button" class="button button-secondary" id="mbm-lf-test">
							<?php esc_html_e( 'Test connection', 'mbm-live-feedback' ); ?>
						</button>
						<span id="mbm-lf-test-result" style="margin-left:.5rem;"></span>
					</p>

					<?php $this->render_wp_config_help(); ?>

					<?php $this->render_updates_section(); ?>
				</div>

				<?php submit_button( __( 'Save settings', 'mbm-live-feedback' ) ); ?>
			</form>

			<?php $this->render_tab_script( $active ); ?>
		</div>
		<?php
		$this->render_test_script();
	}

	/**
	 * The tabs, in the order they appear.
	 *
	 * @return array<string,string>
	 */
	private static function tabs() {
		return [
			'setup'        => __( 'Setup', 'mbm-live-feedback' ),
			'feedback'     => __( 'Feedback', 'mbm-live-feedback' ),
			'people'       => __( 'People', 'mbm-live-feedback' ),
			'integrations' => __( 'Integrations', 'mbm-live-feedback' ),
			'advanced'     => __( 'Advanced', 'mbm-live-feedback' ),
		];
	}

	/**
	 * The state of things, on every tab.
	 *
	 * Whether this is connected and working is the first thing anybody wants
	 * to know when something looks wrong, and it used to be three screens down.
	 */
	private function render_status_strip() {
		$connected = MBM_LF_Credentials::has( 'api_key' );
		$ready     = $connected && mbm_lf_provisioner()->is_provisioned();
		$on        = (bool) MBM_LF_Options::get( 'enabled' );

		$items = [
			[
				$ready ? __( 'Connected', 'mbm-live-feedback' ) : ( $connected ? __( 'Not set up', 'mbm-live-feedback' ) : __( 'Not connected', 'mbm-live-feedback' ) ),
				$ready,
			],
			[
				$on ? __( 'Feedback bar on', 'mbm-live-feedback' ) : __( 'Feedback bar off', 'mbm-live-feedback' ),
				$on,
			],
		];

		if ( ! mbm_lf_provisioner()->site_is_publicly_reachable() ) {
			$items[] = [ __( 'Not reachable from the internet', 'mbm-live-feedback' ), false ];
		}
		?>
		<p style="margin:1rem 0 .5rem;">
			<?php foreach ( $items as $item ) : ?>
				<span style="margin-right:1.25rem;">
					<span style="color:<?php echo $item[1] ? '#1d7a3c' : '#996800'; ?>;font-size:1.1em;">●</span>
					<?php echo esc_html( $item[0] ); ?>
				</span>
			<?php endforeach; ?>
		</p>
		<?php
	}

	/**
	 * The settings almost nobody should change.
	 *
	 * Kept together and out of the way. Explaining when to ignore a field on
	 * the main screen invites somebody to change it anyway.
	 */
	private function render_expert_fields() {
		$capability = (string) MBM_LF_Options::get( 'capability' );
		?>
		<h2 class="title"><?php esc_html_e( 'Advanced', 'mbm-live-feedback' ); ?></h2>

		<p class="description" style="max-width:44rem;">
			<?php esc_html_e( 'These are set up for you. Change them only if you know you need to.', 'mbm-live-feedback' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tbody>
			<?php
			$this->render_text_row(
				'private_key_path',
				__( 'Signing key file', 'mbm-live-feedback' ),
				__( 'Full path on your server, stored outside your website folder.', 'mbm-live-feedback' ),
				true
			);
			?>
			<tr>
				<th scope="row">
					<label for="mbm-lf-default-markup">
						<?php esc_html_e( 'Shared list ID', 'mbm-live-feedback' ); ?>
					</label>
				</th>
				<td>
					<input
						type="text"
						class="regular-text code"
						id="mbm-lf-default-markup"
						name="default_markup_id"
						value="<?php echo esc_attr( (string) MBM_LF_Options::get( 'default_markup_id' ) ); ?>"
					>
					<p class="description">
						<?php esc_html_e( 'Clear it and a new one is made.', 'mbm-live-feedback' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="mbm-lf-capability"><?php esc_html_e( 'Permission', 'mbm-live-feedback' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						class="regular-text code"
						id="mbm-lf-capability"
						name="capability"
						value="<?php echo esc_attr( $capability ); ?>"
					>
					<p class="description">
						<?php esc_html_e( 'Used when “People who can edit content” is selected.', 'mbm-live-feedback' ); ?>
					</p>
				</td>
			</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Switch tabs without leaving the page, and say something before losing edits.
	 *
	 * @param string $active Active tab slug.
	 */
	private function render_tab_script( $active ) {
		?>
		<script>
		( function () {
			var form = document.getElementById( 'mbm-lf-form' );
			var tabs = document.getElementById( 'mbm-lf-tabs' );

			if ( ! form || ! tabs ) {
				return;
			}

			var panels = form.querySelectorAll( '.mbm-lf-panel' );
			var field  = document.getElementById( 'mbm-lf-active-tab' );
			var dirty  = false;

			function show( name ) {
				panels.forEach( function ( panel ) {
					panel.style.display = panel.dataset.panel === name ? '' : 'none';
				} );

				tabs.querySelectorAll( '.nav-tab' ).forEach( function ( tab ) {
					tab.classList.toggle( 'nav-tab-active', tab.dataset.tab === name );
				} );

				field.value = name;
			}

			tabs.addEventListener( 'click', function ( event ) {
				var tab = event.target.closest( '.nav-tab' );

				if ( ! tab ) {
					return;
				}

				// Nothing is lost by moving between panels, so no warning here.
				event.preventDefault();
				show( tab.dataset.tab );
			} );

			form.addEventListener( 'change', function () { dirty = true; } );
			form.addEventListener( 'input', function () { dirty = true; } );

			// Saving is not leaving. Without this, pressing Save asks whether
			// you want to discard the changes you are in the middle of saving.
			form.addEventListener( 'submit', function () { dirty = false; } );

			window.addEventListener( 'beforeunload', function ( event ) {
				if ( ! dirty ) {
					return;
				}

				// The wording is the browser's; ours is ignored either way.
				event.preventDefault();
				event.returnValue = '';
			} );

			show( <?php echo wp_json_encode( $active ); ?> );
		}() );
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Sections
	 * ------------------------------------------------------------------ */

	/**
	 * The one-click setup box.
	 */
	private function render_setup_section() {
		$provisioner  = mbm_lf_provisioner();
		$provisioned  = $provisioner->is_provisioned();
		$has_key      = MBM_LF_Credentials::has( 'api_key' );
		$public_local = ! $provisioner->site_is_publicly_reachable();
		?>
		<div class="card" style="max-width:44rem;padding:1rem 1.25rem;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Set up', 'mbm-live-feedback' ); ?></h2>

			<?php if ( ! $has_key ) : ?>
				<p><?php esc_html_e( 'Add your API key below, then press the button.', 'mbm-live-feedback' ); ?></p>
			<?php elseif ( $provisioned ) : ?>
				<p><?php esc_html_e( 'This site is registered with MarkUp.io and ready to collect feedback.', 'mbm-live-feedback' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Press the button to register this site with MarkUp.io.', 'mbm-live-feedback' ); ?></p>
			<?php endif; ?>

			<?php $this->render_checklist(); ?>

			<p>
				<button
					type="button"
					class="button button-primary"
					id="mbm-lf-provision"
					<?php disabled( ! $has_key ); ?>
				>
					<?php
					echo esc_html(
						$provisioned
							? __( 'Check and repair setup', 'mbm-live-feedback' )
							: __( 'Set up automatically', 'mbm-live-feedback' )
					);
					?>
				</button>
				<span id="mbm-lf-provision-result" style="margin-left:.5rem;"></span>
			</p>

			<?php if ( $public_local ) : ?>
				<p class="description" style="color:#996800;">
					<strong><?php esc_html_e( 'This site is not reachable from the internet yet.', 'mbm-live-feedback' ); ?></strong>
					<?php esc_html_e( 'MarkUp.io loads each page itself, so pages cannot be set up until this site is live at a public address. Connecting your account works now.', 'mbm-live-feedback' ); ?>
				</p>
				<p class="description" style="color:#996800;">
					<?php esc_html_e( 'To try it now, paste an existing MarkUp.io ID into the shared list field under Advanced.', 'mbm-live-feedback' ); ?>
				</p>
			<?php endif; ?>

			<p class="description">
				<?php
				printf(
					/* translators: %s: list of web addresses. */
					esc_html__( 'Feedback will be allowed to run on: %s', 'mbm-live-feedback' ),
					'<code>' . esc_html( implode( '</code>, <code>', $provisioner->origins() ) ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				?>
			</p>

			<p class="description">
				<?php esc_html_e( 'This cannot be changed afterwards. If the site moves to a new address, run setup again.', 'mbm-live-feedback' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Setup checklist.
	 */
	private function render_checklist() {
		$limits = MBM_LF_Options::get( 'rate_limits' );

		$items = [
			[
				'label' => __( 'API key saved', 'mbm-live-feedback' ),
				'ok'    => MBM_LF_Credentials::has( 'api_key' ),
			],
			[
				'label' => __( 'Workspace found', 'mbm-live-feedback' ),
				'ok'    => MBM_LF_Credentials::has( 'workspace_id' ),
			],
			[
				'label' => __( 'Site registered with MarkUp.io', 'mbm-live-feedback' ),
				'ok'    => MBM_LF_Credentials::has( 'installation_id' ),
			],
			[
				'label' => __( 'Site identifier in place', 'mbm-live-feedback' ),
				'ok'    => MBM_LF_Credentials::has( 'public_key' ),
			],
			[
				'label' => __( 'Signing key ready', 'mbm-live-feedback' ),
				'ok'    => '' !== MBM_LF_Credentials::private_key(),
			],
			[
				'label' => __( 'Sign-in settings read', 'mbm-live-feedback' ),
				'ok'    => MBM_LF_Credentials::has( 'iss' ) && MBM_LF_Credentials::has( 'aud' ),
			],
			[
				'label' => __( 'Your team can comment as themselves', 'mbm-live-feedback' ),
				'ok'    => ( new MBM_LF_Tokens() )->can_sign(),
			],
		];
		?>
		<ul style="margin:1rem 0;">
			<?php foreach ( $items as $item ) : ?>
				<li style="margin-bottom:.25rem;">
					<?php if ( $item['ok'] ) : ?>
						<span style="color:#1d7a3c;font-weight:600;">&#10003;</span>
					<?php else : ?>
						<span style="color:#8a8a8a;font-weight:600;">&mdash;</span>
					<?php endif; ?>
					<?php echo esc_html( $item['label'] ); ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( ! empty( $limits['requestsPerMinute'] ) ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: requests per minute, 2: sign-ins per minute. */
					esc_html__( 'Your MarkUp.io plan allows %1$s requests and %2$s sign-ins per minute.', 'mbm-live-feedback' ),
					esc_html( number_format_i18n( $limits['requestsPerMinute'] ) ),
					esc_html( number_format_i18n( isset( $limits['exchangePerMinute'] ) ? $limits['exchangePerMinute'] : 0 ) )
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Master switch, post types, and the site-wide fallback markup.
	 */
	private function render_placement_fields() {
		$enabled    = (bool) MBM_LF_Options::get( 'enabled' );
		$selected   = MBM_LF_Options::post_types();
		$fallback   = (string) MBM_LF_Options::get( 'default_markup_id' );
		$post_types = MBM_LF_Options::selectable_post_types();
		?>
		<table class="form-table" role="presentation">
			<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Feedback bar', 'mbm-live-feedback' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?>>
						<?php esc_html_e( 'Show the feedback bar on this site', 'mbm-live-feedback' ); ?>
					</label>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Content types', 'mbm-live-feedback' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text">
							<?php esc_html_e( 'Content types that can collect feedback', 'mbm-live-feedback' ); ?>
						</legend>
						<?php foreach ( $post_types as $post_type ) : ?>
							<label style="display:block;margin-bottom:.25rem;">
								<input
									type="checkbox"
									name="post_types[]"
									value="<?php echo esc_attr( $post_type->name ); ?>"
									<?php checked( in_array( $post_type->name, $selected, true ) ); ?>
								>
								<?php echo esc_html( $post_type->labels->name ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="description">
						<?php esc_html_e( 'Only these can collect feedback.', 'mbm-live-feedback' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Comment lists', 'mbm-live-feedback' ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="shared_thread"
							value="1"
							<?php checked( (bool) MBM_LF_Options::get( 'shared_thread' ) ); ?>
						>
						<strong><?php esc_html_e( 'Keep all feedback for this site in one list', 'mbm-live-feedback' ); ?></strong>
					</label>

					<p class="description">
						<?php esc_html_e( 'One list for the whole site, so you can work through a client’s feedback in one go.', 'mbm-live-feedback' ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( 'Untick for a separate list per page.', 'mbm-live-feedback' ); ?>
					</p>

					<?php $this->render_shared_list_state(); ?>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'New lists', 'mbm-live-feedback' ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="auto_create_markups"
							value="1"
							<?php checked( (bool) MBM_LF_Options::get( 'auto_create_markups' ) ); ?>
						>
						<?php esc_html_e( 'Create them in MarkUp.io automatically', 'mbm-live-feedback' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Off means pasting IDs in yourself.', 'mbm-live-feedback' ); ?>
					</p>
				</td>
			</tr>

			</tbody>
		</table>
		<?php
	}

	/**
	 * Show whether the shared list exists yet, and why not if it does not.
	 */
	private function render_shared_list_state() {
		if ( ! MBM_LF_Options::get( 'shared_thread' ) ) {
			return;
		}

		$id    = (string) MBM_LF_Options::get( 'default_markup_id' );
		$error = mbm_lf_markups()->site_wide_error();

		if ( '' !== $id ) {
			?>
			<p class="description" style="color:#1d7a3c;">
				<?php esc_html_e( 'The shared list is ready.', 'mbm-live-feedback' ); ?>
			</p>
			<?php
			return;
		}

		if ( '' !== $error ) {
			?>
			<p class="description" style="color:#b32d2e;"><?php echo esc_html( $error ); ?></p>
			<?php
			return;
		}

		if ( ! MBM_LF_Options::get( 'auto_create_markups' ) ) {
			?>
			<p class="description" style="color:#996800;">
				<?php esc_html_e( 'No list yet. Turn on “New lists”, or paste an ID under Advanced.', 'mbm-live-feedback' ); ?>
			</p>
			<?php
			return;
		}

		?>
		<p class="description">
			<?php esc_html_e( 'The shared list will be created the next time you view the site.', 'mbm-live-feedback' ); ?>
		</p>
		<?php
	}

	/**
	 * Visibility controls.
	 */
	private function render_visibility_fields() {
		$mode       = (string) MBM_LF_Options::get( 'visibility' );
		$roles = (array) MBM_LF_Options::get( 'roles' );
		$all_roles  = wp_roles()->get_names();
		?>
		<table class="form-table" role="presentation">
			<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Show the bar to', 'mbm-live-feedback' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text">
							<?php esc_html_e( 'Who can see the feedback bar', 'mbm-live-feedback' ); ?>
						</legend>
						<?php foreach ( MBM_LF_Options::visibility_choices() as $value => $label ) : ?>
							<label style="display:block;margin-bottom:.25rem;">
								<input
									type="radio"
									name="visibility"
									value="<?php echo esc_attr( $value ); ?>"
									<?php checked( $mode, $value ); ?>
								>
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>

					<?php if ( 'everyone' === $mode ) : ?>
						<p class="description" style="color:#996800;">
							<?php esc_html_e( 'Anyone visiting your site can comment. Use this only while collecting public feedback.', 'mbm-live-feedback' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Chosen roles', 'mbm-live-feedback' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text">
							<?php esc_html_e( 'Roles that can see the feedback bar', 'mbm-live-feedback' ); ?>
						</legend>
						<?php foreach ( $all_roles as $slug => $name ) : ?>
							<label style="display:block;margin-bottom:.25rem;">
								<input
									type="checkbox"
									name="roles[]"
									value="<?php echo esc_attr( $slug ); ?>"
									<?php checked( in_array( $slug, $roles, true ) ); ?>
								>
								<?php echo esc_html( translate_user_role( $name ) ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="description">
						<?php esc_html_e( 'Used when “Only the roles I choose” is selected.', 'mbm-live-feedback' ); ?>
					</p>
				</td>
			</tr>

			</tbody>
		</table>
		<?php
	}

	/**
	 * Explain how people are identified, and what they can do.
	 *
	 * There is deliberately nothing to configure here. MarkUp.io decides what a
	 * signed-in visitor may do and currently ignores any role we ask for, so a
	 * setting would be a control that does nothing.
	 */
	private function render_identity_fields() {
		$can_sign = ( new MBM_LF_Tokens() )->can_sign();
		?>
		<table class="form-table" role="presentation">
			<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Your team', 'mbm-live-feedback' ); ?></th>
				<td>
					<?php if ( $can_sign ) : ?>
						<p style="margin-top:0;">
							<span style="color:#1d7a3c;font-weight:600;">&#10003;</span>
							<?php esc_html_e( 'People signed in to this site comment under their own name, with nothing extra to sign in to.', 'mbm-live-feedback' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( 'They can comment, reply and mark things done. Deleting happens in MarkUp.io.', 'mbm-live-feedback' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( 'Their name and picture are shared with MarkUp.io. Nothing else about their account is.', 'mbm-live-feedback' ); ?>
						</p>
					<?php else : ?>
						<p style="margin-top:0;color:#996800;">
							<?php esc_html_e( 'Everyone signs in through MarkUp.io until setup is finished.', 'mbm-live-feedback' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Everyone else', 'mbm-live-feedback' ); ?></th>
				<td>
					<p style="margin-top:0;">
						<?php esc_html_e( 'Visitors without an account here sign in through MarkUp.io.', 'mbm-live-feedback' ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( 'Who sees the bar at all is set above.', 'mbm-live-feedback' ); ?>
					</p>
				</td>
			</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Toolbar appearance.
	 */
	private function render_appearance_fields() {
		$position = (string) MBM_LF_Options::get( 'position' );
		$theme    = (string) MBM_LF_Options::get( 'theme' );
		?>
		<table class="form-table" role="presentation">
			<tbody>
			<tr>
				<th scope="row">
					<label for="mbm-lf-position"><?php esc_html_e( 'Position on screen', 'mbm-live-feedback' ); ?></label>
				</th>
				<td>
					<select id="mbm-lf-position" name="position">
						<?php foreach ( MBM_LF_Options::positions() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $position, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Visitors can drag it out of the way.', 'mbm-live-feedback' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="mbm-lf-theme"><?php esc_html_e( 'Colour', 'mbm-live-feedback' ); ?></label>
				</th>
				<td>
					<select id="mbm-lf-theme" name="theme">
						<?php foreach ( MBM_LF_Options::themes() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $theme, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Show where each value is coming from.
	 *
	 * This is the whole point of supporting wp-config.php constants — an admin
	 * needs to see at a glance why a field is greyed out.
	 */
	private function render_status_table() {
		$rows = [
			'api_key'          => __( 'API key', 'mbm-live-feedback' ),
			'private_key_path' => __( 'Key file location', 'mbm-live-feedback' ),
			'public_key'       => __( 'Site identifier', 'mbm-live-feedback' ),
			'workspace_id'     => __( 'Workspace', 'mbm-live-feedback' ),
			'installation_id'  => __( 'Installation', 'mbm-live-feedback' ),
			'webhook_secret'   => __( 'Notification secret', 'mbm-live-feedback' ),
		];
		?>
		<table class="widefat striped" style="max-width:48rem;">
			<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Setting', 'mbm-live-feedback' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'mbm-live-feedback' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Coming from', 'mbm-live-feedback' ); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $key => $label ) : ?>
				<tr>
					<td><?php echo esc_html( $label ); ?></td>
					<td>
						<?php if ( MBM_LF_Credentials::has( $key ) ) : ?>
							<span style="color:#1d7a3c;font-weight:600;">&#10003; <?php esc_html_e( 'Set', 'mbm-live-feedback' ); ?></span>
						<?php else : ?>
							<span style="color:#8a8a8a;"><?php esc_html_e( 'Not set yet', 'mbm-live-feedback' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( MBM_LF_Credentials::is_locked( $key ) ) : ?>
							<code>wp-config.php</code>
						<?php elseif ( MBM_LF_Credentials::has( $key ) ) : ?>
							<?php esc_html_e( 'Saved on this screen', 'mbm-live-feedback' ); ?>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Explain the wp-config.php option.
	 */
	private function render_wp_config_help() {
		?>
		<hr>
		<h2><?php esc_html_e( 'Storing settings in wp-config.php instead', 'mbm-live-feedback' ); ?></h2>

		<p class="description" style="max-width:44rem;">
			<?php esc_html_e( 'Keys are stored encrypted in your database. Adding them to wp-config.php instead is safer on a live site. Values there always win, and the matching field becomes read-only.', 'mbm-live-feedback' ); ?>
		</p>

		<p class="description" style="max-width:44rem;">
			<?php esc_html_e( 'Encryption is tied to your site security keys. Regenerate those and you will need to paste the API key again.', 'mbm-live-feedback' ); ?>
		</p>

		<pre style="background:#fff;border:1px solid #dcdcde;padding:1rem;max-width:44rem;overflow:auto;"><code>define( 'MBM_LF_API_KEY', '...' );
define( 'MBM_LF_PRIVATE_KEY_PATH', '/path/to/markup.pem' );</code></pre>
		<?php
	}

	/**
	 * Version and update information.
	 */
	private function render_updates_section() {
		$updater = new MBM_LF_Updater();
		$release = $updater->latest_release();
		?>
		<hr>
		<h2><?php esc_html_e( 'Updates', 'mbm-live-feedback' ); ?></h2>

		<p class="description" style="max-width:44rem;">
			<?php
			printf(
				/* translators: %s: version number. */
				esc_html__( 'You are running version %s.', 'mbm-live-feedback' ),
				'<strong>' . esc_html( MBM_LF_VERSION ) . '</strong>'
			);
			?>

			<?php if ( $release && version_compare( $release['version'], MBM_LF_VERSION, '>' ) ) : ?>
				<strong style="color:#996800;">
					<?php
					printf(
						/* translators: %s: version number. */
						esc_html__( 'Version %s is available.', 'mbm-live-feedback' ),
						esc_html( $release['version'] )
					);
					?>
				</strong>
				<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">
					<?php esc_html_e( 'Update on the Plugins screen', 'mbm-live-feedback' ); ?>
				</a>
			<?php elseif ( $release ) : ?>
				<?php esc_html_e( 'This is the latest version.', 'mbm-live-feedback' ); ?>
			<?php endif; ?>
		</p>

		<p class="description" style="max-width:44rem;">
			<?php esc_html_e( 'Updates arrive on the Plugins screen like any other plugin.', 'mbm-live-feedback' ); ?>
		</p>

		<?php /* A link, not a form: this sits inside the settings form and HTML has no nested forms. */ ?>
		<p>
			<a
				href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mbm_lf_check_updates' ), 'mbm_lf_check_updates' ) ); ?>"
				class="button button-secondary"
			>
				<?php esc_html_e( 'Check for updates now', 'mbm-live-feedback' ); ?>
			</a>
		</p>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Field rendering
	 * ------------------------------------------------------------------ */

	/**
	 * A password-style row that never echoes the stored value back.
	 *
	 * @param string $key         Credential key.
	 * @param string $label       Field label.
	 * @param string $description Help text.
	 */
	private function render_secret_row( $key, $label, $description ) {
		$locked = MBM_LF_Credentials::is_locked( $key );
		$has    = MBM_LF_Credentials::has( $key );
		$id     = 'mbm-lf-' . $key;
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			</th>
			<td>
				<input
					type="password"
					class="regular-text"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $key ); ?>"
					value=""
					autocomplete="off"
					<?php disabled( $locked ); ?>
					placeholder="<?php echo esc_attr( $this->secret_placeholder( $locked, $has ) ); ?>"
				>
				<?php $this->render_lock_note( $key, $locked ); ?>
				<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php if ( ! $locked && $has ) : ?>
					<p class="description">
						<?php esc_html_e( 'Leave blank to keep the key you already saved.', 'mbm-live-feedback' ); ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * A plain text row.
	 *
	 * @param string $key         Credential key.
	 * @param string $label       Field label.
	 * @param string $description Help text.
	 * @param bool   $is_path     Whether the value is a file path, so we can
	 *                            warn when the file cannot be read.
	 */
	private function render_text_row( $key, $label, $description, $is_path = false ) {
		$locked = MBM_LF_Credentials::is_locked( $key );
		$value  = MBM_LF_Credentials::get( $key );
		$id     = 'mbm-lf-' . $key;
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			</th>
			<td>
				<input
					type="text"
					class="regular-text code"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $key ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					<?php disabled( $locked ); ?>
				>
				<?php $this->render_lock_note( $key, $locked ); ?>
				<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php
				if ( $is_path && ! $locked && '' !== $value && ! is_readable( $value ) ) :
					?>
					<p class="description" style="color:#b32d2e;">
						<?php esc_html_e( 'That file cannot be read. Check the path and the file permissions.', 'mbm-live-feedback' ); ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * The "set in wp-config.php" badge.
	 *
	 * @param string $key    Credential key.
	 * @param bool   $locked Whether a constant controls it.
	 */
	private function render_lock_note( $key, $locked ) {
		if ( ! $locked ) {
			return;
		}
		?>
		<p class="description" style="margin-top:.4rem;">
			<span style="display:inline-block;background:#eef;border:1px solid #ccd;border-radius:3px;padding:.1rem .4rem;font-size:11px;">
				<?php esc_html_e( 'Set in wp-config.php', 'mbm-live-feedback' ); ?>
			</span>
			<?php
			printf(
				/* translators: %s: PHP constant name. */
				esc_html__( 'Defined as %s, so it cannot be changed here.', 'mbm-live-feedback' ),
				'<code>' . esc_html( MBM_LF_Credentials::constant_name( $key ) ) . '</code>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Placeholder text for a secret field.
	 *
	 * @param bool $locked Controlled by a constant.
	 * @param bool $has    Already has a value.
	 * @return string
	 */
	private function secret_placeholder( $locked, $has ) {
		if ( $locked ) {
			return __( 'Set in wp-config.php', 'mbm-live-feedback' );
		}

		return $has
			? __( 'Saved — leave blank to keep it', 'mbm-live-feedback' )
			: __( 'Paste your key', 'mbm-live-feedback' );
	}

	/* ---------------------------------------------------------------------
	 * Save + test
	 * ------------------------------------------------------------------ */

	/**
	 * Persist submitted settings.
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'mbm-live-feedback' ) );
		}

		check_admin_referer( self::NONCE );

		// A blank secret means "keep what is already saved", so only write when
		// something was actually typed.
		$api_key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';

		if ( '' !== $api_key ) {
			MBM_LF_Credentials::set( 'api_key', $api_key );
		}

		if ( isset( $_POST['public_key'] ) ) {
			MBM_LF_Credentials::set(
				'public_key',
				trim( sanitize_text_field( wp_unslash( $_POST['public_key'] ) ) )
			);
		}

		if ( isset( $_POST['private_key_path'] ) ) {
			MBM_LF_Credentials::set(
				'private_key_path',
				trim( sanitize_text_field( wp_unslash( $_POST['private_key_path'] ) ) )
			);
		}

		MBM_LF_Options::update( $this->sanitize_options( $_POST ) );

		/**
		 * Lets an optional module save its own settings.
		 *
		 * The nonce and capability have already been checked. Raw input is
		 * passed deliberately — each module knows the shape of its own fields
		 * and is responsible for validating them.
		 *
		 * @param array $raw Raw $_POST.
		 */
		do_action( 'mbm_lf_save_settings', $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// Come back to the tab they were on, rather than throwing them to the
		// front of the screen every time they save.
		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$tab = isset( $_POST['mbm_lf_tab'] ) ? sanitize_key( wp_unslash( $_POST['mbm_lf_tab'] ) ) : '';

		wp_safe_redirect(
			add_query_arg(
				[
					'page'          => self::PAGE,
					'tab'           => isset( $tabs[ $tab ] ) ? $tab : 'setup',
					'mbm_lf_notice' => 'saved',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Validate submitted preferences against the values we actually allow.
	 *
	 * Everything is checked against a known list rather than trusted, so a
	 * tampered form cannot set an arbitrary capability or post type.
	 *
	 * @param array $raw Raw $_POST.
	 * @return array
	 */
	private function sanitize_options( array $raw ) {
		$allowed_post_types = array_keys( MBM_LF_Options::selectable_post_types() );
		$allowed_roles      = array_keys( wp_roles()->get_names() );

		$post_types = isset( $raw['post_types'] ) && is_array( $raw['post_types'] )
			? array_map( 'sanitize_key', wp_unslash( $raw['post_types'] ) )
			: [];

		$roles = isset( $raw['roles'] ) && is_array( $raw['roles'] )
			? array_map( 'sanitize_key', wp_unslash( $raw['roles'] ) )
			: [];

		$visibility = isset( $raw['visibility'] ) ? sanitize_key( wp_unslash( $raw['visibility'] ) ) : 'capability';

		if ( ! array_key_exists( $visibility, MBM_LF_Options::visibility_choices() ) ) {
			$visibility = 'capability';
		}

		$position = isset( $raw['position'] ) ? sanitize_key( wp_unslash( $raw['position'] ) ) : '';

		if ( ! array_key_exists( $position, MBM_LF_Options::positions() ) ) {
			$position = 'bottom-right';
		}

		$theme = isset( $raw['theme'] ) ? sanitize_key( wp_unslash( $raw['theme'] ) ) : '';

		if ( ! array_key_exists( $theme, MBM_LF_Options::themes() ) ) {
			$theme = 'auto';
		}

		$capability = isset( $raw['capability'] )
			? sanitize_key( wp_unslash( $raw['capability'] ) )
			: 'edit_posts';

		if ( '' === $capability ) {
			$capability = 'edit_posts';
		}

		$markup_id = isset( $raw['default_markup_id'] )
			? MBM_LF_Post_Meta::sanitize_markup_id( wp_unslash( $raw['default_markup_id'] ) )
			: '';

		return [
			'enabled'             => ! empty( $raw['enabled'] ),
			'auto_create_markups' => ! empty( $raw['auto_create_markups'] ),
			'shared_thread'       => ! empty( $raw['shared_thread'] ),
			'post_types'        => array_values( array_intersect( $post_types, $allowed_post_types ) ),
			'roles'             => array_values( array_intersect( $roles, $allowed_roles ) ),
			'visibility'        => $visibility,
			'capability'        => $capability,
			'position'          => $position,
			'theme'             => $theme,
			'default_markup_id' => $markup_id,
		];
	}

	/**
	 * Check the saved key against the API.
	 */
	public function ajax_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', 'mbm-live-feedback' ) ], 403 );
		}

		check_ajax_referer( 'mbm_lf_test_connection' );

		$workspace = mbm_lf_api()->get_workspace();

		if ( is_wp_error( $workspace ) ) {
			$data       = $workspace->get_error_data();
			$request_id = is_array( $data ) && ! empty( $data['request_id'] ) ? $data['request_id'] : '';

			wp_send_json_error(
				[
					'message'    => $workspace->get_error_message(),
					'request_id' => $request_id,
				]
			);
		}

		$name = isset( $workspace['name'] ) ? $workspace['name'] : '';

		// Remember the workspace so later steps do not have to look it up again.
		if ( ! empty( $workspace['id'] ) ) {
			MBM_LF_Credentials::set( 'workspace_id', $workspace['id'] );
		}

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %s: MarkUp.io workspace name. */
					__( 'Connected to %s.', 'mbm-live-feedback' ),
					$name
				),
			]
		);
	}

	/**
	 * Run the automatic setup.
	 */
	public function ajax_provision() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', 'mbm-live-feedback' ) ], 403 );
		}

		check_ajax_referer( 'mbm_lf_provision' );

		$result = mbm_lf_provisioner()->provision();

		if ( is_wp_error( $result ) ) {
			$data       = $result->get_error_data();
			$request_id = is_array( $data ) && ! empty( $data['request_id'] ) ? $data['request_id'] : '';

			wp_send_json_error(
				[
					'message'    => $result->get_error_message(),
					'request_id' => $request_id,
				]
			);
		}

		if ( ! empty( $result['warning'] ) ) {
			// Setup worked, but something needs a human to look at it — so do
			// not reload the page out from under the message.
			wp_send_json_success(
				[
					'message' => $result['warning'],
					'reload'  => false,
				]
			);
		}

		wp_send_json_success(
			[
				'message' => __( 'Setup complete. Reloading…', 'mbm-live-feedback' ),
				'reload'  => true,
			]
		);
	}

	/**
	 * Inline script driving the buttons on this screen.
	 */
	private function render_test_script() {
		$buttons = [
			[
				'button' => 'mbm-lf-test',
				'result' => 'mbm-lf-test-result',
				'action' => 'mbm_lf_test_connection',
				'nonce'  => wp_create_nonce( 'mbm_lf_test_connection' ),
				'busy'   => __( 'Checking…', 'mbm-live-feedback' ),
			],
			[
				'button' => 'mbm-lf-provision',
				'result' => 'mbm-lf-provision-result',
				'action' => 'mbm_lf_provision',
				'nonce'  => wp_create_nonce( 'mbm_lf_provision' ),
				'busy'   => __( 'Setting things up…', 'mbm-live-feedback' ),
			],
		];
		?>
		<script>
		( function () {
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var unreachable = <?php echo wp_json_encode( __( 'Could not reach WordPress to do that.', 'mbm-live-feedback' ) ); ?>;

			<?php echo 'var buttons = ' . wp_json_encode( $buttons ) . ';'; ?>

			buttons.forEach( function ( cfg ) {
				var btn = document.getElementById( cfg.button );
				var out = document.getElementById( cfg.result );

				if ( ! btn || ! out ) {
					return;
				}

				btn.addEventListener( 'click', function () {
					btn.disabled = true;
					out.textContent = cfg.busy;
					out.style.color = '';

					var body = new FormData();
					body.append( 'action', cfg.action );
					body.append( '_wpnonce', cfg.nonce );

					fetch( ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						body: body
					} )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							var d = res.data || {};

							out.textContent = d.message || '';
							out.style.color = res.success ? '#1d7a3c' : '#b32d2e';

							if ( ! res.success && d.request_id ) {
								out.textContent += ' (' + d.request_id + ')';
							}

							if ( res.success && d.reload ) {
								window.location.reload();
							}
						} )
						.catch( function () {
							out.textContent = unreachable;
							out.style.color = '#b32d2e';
						} )
						.finally( function () { btn.disabled = false; } );
				} );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Render an admin notice.
	 *
	 * @param string $notice Notice key.
	 */
	private function render_notice( $notice ) {
		$notices = [
			'saved'            => [ 'success', __( 'Settings saved.', 'mbm-live-feedback' ) ],
			'update-available' => [ 'warning', __( 'A newer version is available. You can install it from the Plugins screen.', 'mbm-live-feedback' ) ],
			'update-none'      => [ 'success', __( 'You are running the latest version.', 'mbm-live-feedback' ) ],
			'update-failed'    => [ 'error', __( 'Could not reach the update server. Check back shortly.', 'mbm-live-feedback' ) ],
			'motion-refreshed' => [ 'success', __( 'Fetched your workspaces, projects and people from Motion again.', 'mbm-live-feedback' ) ],
			'motion-retrying'  => [ 'success', __( 'Those comments are queued to go to Motion again.', 'mbm-live-feedback' ) ],
			'motion-retried-one' => [ 'success', __( 'That comment is queued to go to Motion again.', 'mbm-live-feedback' ) ],
			'motion-forgotten' => [ 'success', __( 'That one has been dropped. It will not be sent to Motion.', 'mbm-live-feedback' ) ],
		];

		if ( ! isset( $notices[ $notice ] ) ) {
			return;
		}

		list( $type, $message ) = $notices[ $notice ];
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}
}
