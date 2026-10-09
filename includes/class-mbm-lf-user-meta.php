<?php
/**
 * Per-person feedback settings.
 *
 * Site-wide visibility answers "which kind of person sees the feedback bar".
 * This answers "except this one". It is an override rather than another rule:
 * the settings decide the audience, and this takes an individual back out of
 * it.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the feedback switch to a profile, and stores it.
 */
class MBM_LF_User_Meta {

	/**
	 * Meta key marking a person as excluded.
	 *
	 * Only ever present when they are switched off. Everybody else has no row
	 * at all, so the default stays "on" without writing anything for the
	 * accounts nobody has touched.
	 */
	const META_DISABLED = '_mbm_lf_disabled';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'show_user_profile', [ $this, 'render' ] );
		add_action( 'edit_user_profile', [ $this, 'render' ] );
		add_action( 'personal_options_update', [ $this, 'save' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save' ] );
	}

	/**
	 * Whether the feedback bar is switched off for somebody.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_disabled( $user_id ) {
		$user_id = (int) $user_id;

		// Nobody is signed in, so there is no individual to have switched off.
		// Whether a visitor sees the bar is the site-wide setting's business.
		if ( $user_id <= 0 ) {
			return false;
		}

		return (bool) get_user_meta( $user_id, self::META_DISABLED, true );
	}

	/**
	 * Whether the feedback bar is switched on for somebody.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_enabled( $user_id ) {
		return ! self::is_disabled( $user_id );
	}

	/* ---------------------------------------------------------------------
	 * Screen
	 * ------------------------------------------------------------------ */

	/**
	 * Show the switch on a profile.
	 *
	 * @param WP_User $user The profile being viewed.
	 */
	public function render( $user ) {
		if ( ! $this->can_edit( $user->ID ) ) {
			return;
		}

		$enabled = self::is_enabled( $user->ID );
		$is_self = get_current_user_id() === (int) $user->ID;
		?>
		<h2><?php esc_html_e( 'Live Site Feedback', 'mbm-live-feedback' ); ?></h2>

		<table class="form-table" role="presentation">
			<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Feedback bar', 'mbm-live-feedback' ); ?></th>
				<td>
					<?php wp_nonce_field( self::nonce_action( $user->ID ), 'mbm_lf_user_nonce' ); ?>
					<input type="hidden" name="mbm_lf_user_shown" value="1">

					<label for="mbm-lf-user-enabled">
						<input
							type="checkbox"
							id="mbm-lf-user-enabled"
							name="mbm_lf_user_enabled"
							value="1"
							<?php checked( $enabled ); ?>
						>
						<?php
						echo esc_html(
							$is_self
								? __( 'Show me the feedback bar on the site', 'mbm-live-feedback' )
								: __( 'Show this person the feedback bar on the site', 'mbm-live-feedback' )
						);
						?>
					</label>

					<p class="description">
						<?php
						echo esc_html(
							$is_self
								? __( 'Uncheck and the bar never loads for you, so you can\'t leave or read comments on the site.', 'mbm-live-feedback' )
								: __( 'Uncheck and the bar never loads for them, so they can\'t leave or read comments on the site.', 'mbm-live-feedback' )
						);
						?>
					</p>

					<?php $this->render_settings_note( $user, $enabled ); ?>
				</td>
			</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Say so when the site-wide settings already rule this person out.
	 *
	 * Without this the checkbox looks like the whole answer, and ticking it on
	 * an account whose role was never in the audience does nothing visible —
	 * which reads as a broken setting rather than a second rule.
	 *
	 * @param WP_User $user    The profile being viewed.
	 * @param bool    $enabled Whether the switch is on.
	 */
	private function render_settings_note( $user, $enabled ) {
		if ( ! $enabled || MBM_LF_Frontend::settings_allow( $user ) ) {
			return;
		}

		$settings = add_query_arg(
			[
				'page' => MBM_LF_Settings::PAGE,
				'tab'  => 'people',
			],
			admin_url( 'admin.php' )
		);
		?>
		<p class="description" style="color:#996800;">
			<?php esc_html_e( 'They still won\'t see it: this account isn\'t covered by “Shown to” on the People tab.', 'mbm-live-feedback' ); ?>
			<a href="<?php echo esc_url( $settings ); ?>"><?php esc_html_e( 'Change who sees it', 'mbm-live-feedback' ); ?></a>
		</p>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Saving
	 * ------------------------------------------------------------------ */

	/**
	 * Store the switch.
	 *
	 * @param int $user_id The profile being saved.
	 */
	public function save( $user_id ) {
		$user_id = (int) $user_id;

		/*
		 * Only act when our own field was on the screen. Other plugins and
		 * WordPress itself save profiles through these same hooks, and an
		 * absent checkbox is indistinguishable from an unticked one — so
		 * without this, any such save would read as "switch them off".
		 */
		if ( empty( $_POST['mbm_lf_user_shown'] ) ) {
			return;
		}

		if ( ! $this->can_edit( $user_id ) ) {
			return;
		}

		check_admin_referer( self::nonce_action( $user_id ), 'mbm_lf_user_nonce' );

		if ( empty( $_POST['mbm_lf_user_enabled'] ) ) {
			update_user_meta( $user_id, self::META_DISABLED, 1 );
		} else {
			delete_user_meta( $user_id, self::META_DISABLED );
		}
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the person doing the editing may change this.
	 *
	 * Deliberately stricter than "can edit this profile": someone who has been
	 * switched off must not be able to switch themselves back on from their own
	 * profile page, which edit_user alone would let them do.
	 *
	 * @param int $user_id The profile being edited.
	 * @return bool
	 */
	private function can_edit( $user_id ) {
		return current_user_can( 'edit_users' ) && current_user_can( 'edit_user', (int) $user_id );
	}

	/**
	 * Nonce action for a profile.
	 *
	 * @param int $user_id The profile being edited.
	 * @return string
	 */
	private static function nonce_action( $user_id ) {
		return 'mbm_lf_save_user_meta_' . (int) $user_id;
	}
}
