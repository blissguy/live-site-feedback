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
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Live Site Feedback', 'mbm-live-feedback' ); ?></h1>

			<p class="description" style="max-width:44rem;">
				<?php esc_html_e( 'Let your clients leave comments straight on the website, pinned to the exact spot they are looking at. Comments are collected in your MarkUp.io account.', 'mbm-live-feedback' ); ?>
			</p>

			<?php $this->render_notice( $notice ); ?>

			<?php $this->render_setup_section(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mbm_lf_save_settings">
				<?php wp_nonce_field( self::NONCE ); ?>

				<h2 class="title"><?php esc_html_e( 'Connect your MarkUp.io account', 'mbm-live-feedback' ); ?></h2>

				<table class="form-table" role="presentation">
					<tbody>
					<?php
					$this->render_secret_row(
						'api_key',
						__( 'API key', 'mbm-live-feedback' ),
						__( 'Create a key in MarkUp.io under your workspace settings, then paste it here. It is only ever used from your server, never sent to visitors.', 'mbm-live-feedback' )
					);

					$this->render_text_row(
						'public_key',
						__( 'Site identifier', 'mbm-live-feedback' ),
						__( 'Filled in for you when you press Set up. Unlike the API key this one is safe to appear in your pages, and the feedback bar will not load without it.', 'mbm-live-feedback' )
					);
					?>
					</tbody>
				</table>

				<h2 class="title"><?php esc_html_e( 'Signing key', 'mbm-live-feedback' ); ?></h2>

				<p class="description" style="max-width:44rem;">
					<?php esc_html_e( 'This is what lets logged-in users comment as themselves instead of signing in to MarkUp.io separately. Point it at a key file stored outside your website folder.', 'mbm-live-feedback' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tbody>
					<?php
					$this->render_text_row(
						'private_key_path',
						__( 'Key file location', 'mbm-live-feedback' ),
						__( 'Full path to the private key file on your server, for example /home/you/keys/markup.pem', 'mbm-live-feedback' ),
						true
					);
					?>
					</tbody>
				</table>

				<h2 class="title"><?php esc_html_e( 'Where feedback appears', 'mbm-live-feedback' ); ?></h2>

				<?php $this->render_placement_fields(); ?>

				<h2 class="title"><?php esc_html_e( 'Who can leave feedback', 'mbm-live-feedback' ); ?></h2>

				<?php $this->render_visibility_fields(); ?>

				<h2 class="title"><?php esc_html_e( 'How people are identified', 'mbm-live-feedback' ); ?></h2>

				<?php $this->render_identity_fields(); ?>

				<h2 class="title"><?php esc_html_e( 'Appearance', 'mbm-live-feedback' ); ?></h2>

				<?php $this->render_appearance_fields(); ?>

				<?php
				/**
				 * Lets an optional module add its own settings.
				 *
				 * Used by the Motion integration, which stays out of this class
				 * entirely and adds nothing at all unless it is configured.
				 */
				do_action( 'mbm_lf_settings_sections' );
				?>

				<?php submit_button( __( 'Save settings', 'mbm-live-feedback' ) ); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Current status', 'mbm-live-feedback' ); ?></h2>

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
		<?php
		$this->render_test_script();
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
				<p><?php esc_html_e( 'Save your API key below, then come back here and press the button. Everything else is handled for you.', 'mbm-live-feedback' ); ?></p>
			<?php elseif ( $provisioned ) : ?>
				<p><?php esc_html_e( 'This site is registered with MarkUp.io and ready to collect feedback.', 'mbm-live-feedback' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Press the button and the plugin will register this site with MarkUp.io, create its own signing key, and read back everything it needs.', 'mbm-live-feedback' ); ?></p>
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
					<?php esc_html_e( 'MarkUp.io loads each page itself when setting it up, so pages on this address cannot be set up automatically — it will refuse with “URL did not resolve”. Connecting your account works fine, and everything will start working once the site is live at a public address.', 'mbm-live-feedback' ); ?>
				</p>
				<p class="description" style="color:#996800;">
					<?php esc_html_e( 'To try things out before then, create an entry in MarkUp.io against any public address and paste its ID into the site-wide field below.', 'mbm-live-feedback' ); ?>
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
				<?php esc_html_e( 'This cannot be changed afterwards — MarkUp.io does not allow editing a registration. If this site later moves to a different web address, run the setup again to register the new one.', 'mbm-live-feedback' ); ?>
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
					<p class="description">
						<?php esc_html_e( 'Untick to switch it off everywhere without losing your settings.', 'mbm-live-feedback' ); ?>
					</p>
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
						<?php esc_html_e( 'These get a Live Site Feedback panel in the editor where you paste the page’s MarkUp.io ID.', 'mbm-live-feedback' ); ?>
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
						<?php esc_html_e( 'Recommended. Every comment from every page lands in a single list, so you can work through a client’s feedback in one go instead of hunting page by page. Pins still sit on the page they were left on.', 'mbm-live-feedback' ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( 'Untick it and every page keeps its own separate list instead. Useful if different people own different pages.', 'mbm-live-feedback' ); ?>
					</p>

					<?php $this->render_shared_list_state(); ?>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Setting up', 'mbm-live-feedback' ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="auto_create_markups"
							value="1"
							<?php checked( (bool) MBM_LF_Options::get( 'auto_create_markups' ) ); ?>
						>
						<?php esc_html_e( 'Let the plugin set things up in MarkUp.io for me', 'mbm-live-feedback' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Leave this on and you never have to copy an ID by hand. Turn it off only if you want to paste in IDs yourself.', 'mbm-live-feedback' ); ?>
					</p>
				</td>
			</tr>

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
						<?php esc_html_e( 'Filled in for you. Only change it if you already have a MarkUp.io entry you would rather collect this site’s feedback in — clear it and a new one will be made.', 'mbm-live-feedback' ); ?>
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
				<?php esc_html_e( 'No list yet. Either paste an ID below, or switch “Setting up” back on and the plugin will make one.', 'mbm-live-feedback' ); ?>
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
		$capability = (string) MBM_LF_Options::get( 'capability' );
		$roles      = (array) MBM_LF_Options::get( 'roles' );
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
							<?php esc_html_e( 'Everyone who visits your site can currently see the feedback bar and leave comments. Only leave this on while you are actively collecting public feedback.', 'mbm-live-feedback' ); ?>
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
						<?php esc_html_e( 'Only applies when “Only the roles I choose” is selected above.', 'mbm-live-feedback' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="mbm-lf-capability"><?php esc_html_e( 'Required permission', 'mbm-live-feedback' ); ?></label>
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
						<?php esc_html_e( 'Only applies when “People who can edit content” is selected. Leave as edit_posts unless you know you need something different.', 'mbm-live-feedback' ); ?>
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
							<?php esc_html_e( 'They can leave comments, reply, and mark things as done. Deleting a comment is done in MarkUp.io itself.', 'mbm-live-feedback' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( 'Their name and profile picture are shared with MarkUp.io so their comments are attributable. Your WordPress user IDs are not — each person is identified by a code that means nothing outside this site.', 'mbm-live-feedback' ); ?>
						</p>
					<?php else : ?>
						<p style="margin-top:0;color:#996800;">
							<?php esc_html_e( 'Everyone signs in through MarkUp.io at the moment. Run the setup above and your team will be recognised automatically instead.', 'mbm-live-feedback' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Everyone else', 'mbm-live-feedback' ); ?></th>
				<td>
					<p style="margin-top:0;">
						<?php esc_html_e( 'Visitors without an account here sign in through MarkUp.io, and get whatever your MarkUp.io workspace allows them.', 'mbm-live-feedback' ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( 'Who sees the feedback bar at all is set under “Who can leave feedback” above.', 'mbm-live-feedback' ); ?>
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
						<?php esc_html_e( 'Visitors can drag the bar somewhere else if it gets in the way.', 'mbm-live-feedback' ); ?>
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
			<?php esc_html_e( 'Anything saved on this screen is kept in your database, encrypted. If you would rather keep it out of the database altogether — which is the safer choice for a live site — add it to wp-config.php instead. Values there always win, and the matching field above becomes read-only.', 'mbm-live-feedback' ); ?>
		</p>

		<p class="description" style="max-width:44rem;">
			<?php esc_html_e( 'Note that the encryption is tied to your site security keys. If those are ever regenerated, you will need to paste the API key in again.', 'mbm-live-feedback' ); ?>
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
			<?php esc_html_e( 'Updates come straight from the plugin’s repository, so this site updates the same way as any other plugin — no downloading or uploading files.', 'mbm-live-feedback' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mbm_lf_check_updates">
			<?php wp_nonce_field( 'mbm_lf_check_updates' ); ?>
			<?php submit_button( __( 'Check for updates now', 'mbm-live-feedback' ), 'secondary', 'submit', false ); ?>
		</form>
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

		wp_safe_redirect(
			add_query_arg(
				[
					'page'          => self::PAGE,
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
