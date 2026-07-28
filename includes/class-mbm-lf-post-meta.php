<?php
/**
 * Per-post feedback settings.
 *
 * The SDK fixes its markup at start-up and offers no way to change it later, so
 * one page maps to exactly one markup. This stores that mapping.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the per-post feedback panel and stores its values.
 */
class MBM_LF_Post_Meta {

	/**
	 * Meta key holding the markup id.
	 */
	const META_MARKUP_ID = '_mbm_lf_markup_id';

	/**
	 * Meta key marking a post as excluded.
	 */
	const META_DISABLED = '_mbm_lf_disabled';

	/**
	 * Nonce action.
	 */
	const NONCE = 'mbm_lf_save_post_meta';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'init', [ $this, 'register_meta' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post', [ $this, 'save' ], 10, 2 );
		add_action( 'wp_ajax_mbm_lf_create_markup', [ $this, 'ajax_create_markup' ] );
	}

	/**
	 * Create a MarkUp.io entry for a post on request.
	 */
	public function ajax_create_markup() {
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'You cannot edit that page.', 'mbm-live-feedback' ) ], 403 );
		}

		check_ajax_referer( self::NONCE . '_' . $post_id );

		// An explicit click should not be blocked by an earlier failure.
		mbm_lf_markups()->clear_failure( $post_id );

		$result = mbm_lf_markups()->ensure_for_post( $post_id, ! empty( $_POST['force'] ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success(
			[
				'message'  => __( 'Ready. This page will now collect feedback.', 'mbm-live-feedback' ),
				'markupId' => $result,
			]
		);
	}

	/**
	 * Register the meta keys.
	 *
	 * Deliberately not exposed in the REST API — nothing needs to read these
	 * except this plugin, and a markup id is not public information.
	 */
	public function register_meta() {
		foreach ( MBM_LF_Options::post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_MARKUP_ID,
				[
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => [ __CLASS__, 'sanitize_markup_id' ],
					'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				]
			);

			register_post_meta(
				$post_type,
				self::META_DISABLED,
				[
					'type'          => 'boolean',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				]
			);
		}
	}

	/**
	 * Add the editor panel.
	 */
	public function add_meta_box() {
		$post_types = MBM_LF_Options::post_types();

		if ( empty( $post_types ) ) {
			return;
		}

		add_meta_box(
			'mbm-lf-feedback',
			__( 'Live Site Feedback', 'mbm-live-feedback' ),
			[ $this, 'render' ],
			$post_types,
			'side',
			'default'
		);
	}

	/**
	 * Render the panel.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render( $post ) {
		wp_nonce_field( self::NONCE, 'mbm_lf_nonce' );

		$markup_id = self::markup_id( $post->ID );
		$disabled  = self::is_disabled( $post->ID );
		$fallback  = (string) MBM_LF_Options::get( 'default_markup_id' );
		?>
		<p>
			<label>
				<input type="checkbox" name="mbm_lf_disabled" value="1" <?php checked( $disabled ); ?>>
				<?php esc_html_e( 'Turn off feedback for this page', 'mbm-live-feedback' ); ?>
			</label>
		</p>

		<p>
			<label for="mbm-lf-markup-id" style="display:block;font-weight:600;margin-bottom:.25rem;">
				<?php esc_html_e( 'MarkUp.io ID for this page', 'mbm-live-feedback' ); ?>
			</label>
			<input
				type="text"
				id="mbm-lf-markup-id"
				name="mbm_lf_markup_id"
				class="widefat code"
				value="<?php echo esc_attr( $markup_id ); ?>"
				placeholder="<?php esc_attr_e( 'Paste the ID from MarkUp.io', 'mbm-live-feedback' ); ?>"
			>
		</p>

		<p class="description">
			<?php esc_html_e( 'Comments left on this page are collected under this ID. Each page needs its own.', 'mbm-live-feedback' ); ?>
		</p>

		<?php
		// While the site is unreachable, any recorded failure is just a symptom
		// of that — showing both would be noise, and the reachability note below
		// is the accurate explanation.
		$last_error = mbm_lf_provisioner()->site_is_publicly_reachable()
			? mbm_lf_markups()->last_error( $post->ID )
			: '';

		if ( '' !== $last_error ) :
			?>
			<p class="description" style="color:#b32d2e;">
				<?php echo esc_html( $last_error ); ?>
			</p>
		<?php elseif ( '' === $markup_id && '' !== $fallback ) : ?>
			<p class="description">
				<?php esc_html_e( 'Left blank, so this page falls back to the site-wide ID from the settings screen.', 'mbm-live-feedback' ); ?>
			</p>
		<?php elseif ( '' === $markup_id ) : ?>
			<p class="description">
				<?php esc_html_e( 'This page has not been set up yet. It will happen by itself next time you view the page, or you can do it now.', 'mbm-live-feedback' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( ! mbm_lf_provisioner()->site_is_publicly_reachable() ) : ?>
			<p class="description" style="color:#996800;">
				<?php esc_html_e( 'This site is not reachable from the internet yet, so pages cannot be set up automatically. Paste in an ID created against a public address, or wait until the site is live.', 'mbm-live-feedback' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( MBM_LF_Credentials::has( 'api_key' ) && mbm_lf_provisioner()->site_is_publicly_reachable() ) : ?>
			<p>
				<button
					type="button"
					class="button"
					id="mbm-lf-create-markup"
					data-post="<?php echo esc_attr( $post->ID ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE . '_' . $post->ID ) ); ?>"
					data-force="<?php echo esc_attr( '' === $markup_id ? '0' : '1' ); ?>"
				>
					<?php
					echo '' === $markup_id
						? esc_html__( 'Create ID now', 'mbm-live-feedback' )
						: esc_html__( 'Replace with a new ID', 'mbm-live-feedback' );
					?>
				</button>
				<span id="mbm-lf-create-result" style="display:block;margin-top:.5rem;"></span>
			</p>

			<script>
			( function () {
				var btn = document.getElementById( 'mbm-lf-create-markup' );
				var out = document.getElementById( 'mbm-lf-create-result' );

				if ( ! btn ) {
					return;
				}

				btn.addEventListener( 'click', function () {
					if ( '1' === btn.dataset.force &&
						! window.confirm( <?php echo wp_json_encode( __( 'Replace this page’s ID? Comments already left under the old ID will no longer show on the page.', 'mbm-live-feedback' ) ); ?> ) ) {
						return;
					}

					btn.disabled = true;
					out.textContent = <?php echo wp_json_encode( __( 'Setting up…', 'mbm-live-feedback' ) ); ?>;
					out.style.color = '';

					var body = new FormData();
					body.append( 'action', 'mbm_lf_create_markup' );
					body.append( 'post_id', btn.dataset.post );
					body.append( '_wpnonce', btn.dataset.nonce );
					body.append( 'force', btn.dataset.force );

					fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
						method: 'POST',
						credentials: 'same-origin',
						body: body
					} )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							var d = res.data || {};

							out.textContent = d.message || '';
							out.style.color = res.success ? '#1d7a3c' : '#b32d2e';

							if ( res.success && d.markupId ) {
								document.getElementById( 'mbm-lf-markup-id' ).value = d.markupId;
							}
						} )
						.catch( function () {
							out.textContent = <?php echo wp_json_encode( __( 'Could not reach WordPress to do that.', 'mbm-live-feedback' ) ); ?>;
							out.style.color = '#b32d2e';
						} )
						.finally( function () { btn.disabled = false; } );
				} );
			}() );
			</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * Save the panel.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['mbm_lf_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['mbm_lf_nonce'] ) ), self::NONCE ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$markup_id = isset( $_POST['mbm_lf_markup_id'] )
			? self::sanitize_markup_id( wp_unslash( $_POST['mbm_lf_markup_id'] ) )
			: '';

		if ( '' === $markup_id ) {
			delete_post_meta( $post_id, self::META_MARKUP_ID );
		} else {
			update_post_meta( $post_id, self::META_MARKUP_ID, $markup_id );
		}

		if ( empty( $_POST['mbm_lf_disabled'] ) ) {
			delete_post_meta( $post_id, self::META_DISABLED );
		} else {
			update_post_meta( $post_id, self::META_DISABLED, 1 );
		}
	}

	/* ---------------------------------------------------------------------
	 * Readers
	 * ------------------------------------------------------------------ */

	/**
	 * The markup id stored against a post.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public static function markup_id( $post_id ) {
		$value = get_post_meta( $post_id, self::META_MARKUP_ID, true );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Whether feedback is switched off for a post.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function is_disabled( $post_id ) {
		return (bool) get_post_meta( $post_id, self::META_DISABLED, true );
	}

	/**
	 * Keep a markup id to the characters an id can actually contain.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_markup_id( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';

		return (string) preg_replace( '/[^A-Za-z0-9\-_]/', '', $value );
	}
}
