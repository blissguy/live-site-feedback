<?php
/**
 * The screen where feedback is worked through.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists comment threads inside WordPress.
 */
class MBM_LF_Feedback_Screen {

	/**
	 * Admin page slug.
	 */
	const PAGE = 'mbm-live-feedback-threads';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		// After the settings screen has added the parent menu, otherwise there
		// is nothing to attach this to and the page never registers.
		add_action( 'admin_menu', [ $this, 'register_menu' ], 11 );
		add_action( 'admin_post_mbm_lf_refresh_threads', [ $this, 'handle_refresh' ] );
		add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
	}

	/**
	 * Add the submenu, with a count of what still needs attention.
	 */
	public function register_menu() {
		$label = __( 'Feedback', 'mbm-live-feedback' );
		$open  = $this->open_count_for_menu();

		if ( $open > 0 ) {
			$label .= sprintf(
				' <span class="update-plugins count-%1$d"><span class="plugin-count">%2$s</span></span>',
				$open,
				esc_html( number_format_i18n( $open ) )
			);
		}

		add_submenu_page(
			MBM_LF_Settings::PAGE,
			__( 'Feedback', 'mbm-live-feedback' ),
			$label,
			'edit_posts',
			self::PAGE,
			[ $this, 'render' ]
		);
	}

	/**
	 * Open count for the menu bubble.
	 *
	 * Only ever uses what is already cached: a menu is built on every admin
	 * page load, and that is no place to wait on somebody else's API.
	 *
	 * @return int
	 */
	private function open_count_for_menu() {
		$cached = get_transient( MBM_LF_Threads::CACHE_KEY );

		if ( ! is_array( $cached ) || empty( $cached['threads'] ) ) {
			return 0;
		}

		$open = 0;

		foreach ( $cached['threads'] as $thread ) {
			if ( empty( $thread['resolved'] ) ) {
				$open++;
			}
		}

		return $open;
	}

	/**
	 * Render the screen.
	 */
	public function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to view feedback.', 'mbm-live-feedback' ) );
		}

		$data  = mbm_lf_threads()->by_page();
		$pages = $data['pages'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Feedback', 'mbm-live-feedback' ); ?></h1>

			<p class="description" style="max-width:44rem;">
				<?php esc_html_e( 'Pages your clients have commented on. Open one and the feedback bar on the site will show you the comments in place, where you can reply and mark them done.', 'mbm-live-feedback' ); ?>
			</p>

			<?php if ( '' !== $data['error'] ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $data['error'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $data['truncated'] ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %d: number of pages. */
							esc_html__( 'This site has more pages collecting feedback than can be checked at once, so only the first %d are counted. Keeping all feedback in one list avoids this.', 'mbm-live-feedback' ),
							(int) MBM_LF_Threads::MAX_MARKUPS
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="float:right;margin:.5rem 0;">
				<input type="hidden" name="action" value="mbm_lf_refresh_threads">
				<?php wp_nonce_field( 'mbm_lf_refresh_threads' ); ?>
				<?php submit_button( __( 'Refresh', 'mbm-live-feedback' ), 'secondary', 'submit', false ); ?>
			</form>

			<table class="wp-list-table widefat fixed striped" style="clear:both;">
				<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Page', 'mbm-live-feedback' ); ?></th>
					<th scope="col" style="width:11rem;"><?php esc_html_e( 'Needs attention', 'mbm-live-feedback' ); ?></th>
					<th scope="col" style="width:9rem;"><?php esc_html_e( 'Comments', 'mbm-live-feedback' ); ?></th>
					<th scope="col" style="width:11rem;"><?php esc_html_e( 'Last activity', 'mbm-live-feedback' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php if ( empty( $pages ) ) : ?>
					<tr>
						<td colspan="4">
							<?php esc_html_e( 'No feedback yet. It will appear here as soon as somebody comments on the site.', 'mbm-live-feedback' ); ?>
						</td>
					</tr>
				<?php endif; ?>

				<?php
				foreach ( $pages as $page ) :
					$link = $page['post_id'] ? get_permalink( $page['post_id'] ) : $page['url'];
					?>
					<tr>
						<td>
							<strong>
								<?php if ( $link ) : ?>
									<a href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener noreferrer">
										<?php echo esc_html( $page['title'] ); ?>
									</a>
								<?php else : ?>
									<?php echo esc_html( $page['title'] ); ?>
								<?php endif; ?>
							</strong>

							<div class="row-actions">
								<?php if ( $link ) : ?>
									<span>
										<a href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener noreferrer">
											<?php esc_html_e( 'Open the page', 'mbm-live-feedback' ); ?>
										</a>
									</span>
								<?php endif; ?>

								<?php if ( $page['post_id'] && current_user_can( 'edit_post', $page['post_id'] ) ) : ?>
									<span> | <a href="<?php echo esc_url( get_edit_post_link( $page['post_id'] ) ); ?>"><?php esc_html_e( 'Edit', 'mbm-live-feedback' ); ?></a></span>
								<?php endif; ?>
							</div>
						</td>
						<td>
							<?php if ( $page['open'] > 0 ) : ?>
								<strong style="color:#996800;"><?php echo esc_html( number_format_i18n( $page['open'] ) ); ?></strong>
							<?php else : ?>
								<span style="color:#1d7a3c;">&#10003; <?php esc_html_e( 'All done', 'mbm-live-feedback' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( $page['total'] ) ); ?></td>
						<td><?php echo esc_html( $this->humanise( $page['activity'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p class="description" style="margin-top:1rem;">
				<?php $this->render_delivery_note(); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Turn a timestamp into something readable.
	 *
	 * @param int $time Seconds since the epoch.
	 * @return string
	 */
	private function humanise( $time ) {
		$time = (int) $time;

		if ( ! $time ) {
			return '—';
		}

		return sprintf(
			/* translators: %s: human readable time difference, e.g. "5 mins". */
			__( '%s ago', 'mbm-live-feedback' ),
			human_time_diff( $time )
		);
	}

	/**
	 * Fetch the list again on demand.
	 */
	public function handle_refresh() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'mbm-live-feedback' ) );
		}

		check_admin_referer( 'mbm_lf_refresh_threads' );

		mbm_lf_threads()->all( true );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Dashboard
	 * ------------------------------------------------------------------ */

	/**
	 * Add the dashboard summary.
	 */
	public function register_dashboard_widget() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'mbm_lf_dashboard',
			__( 'Site feedback', 'mbm-live-feedback' ),
			[ $this, 'render_dashboard_widget' ]
		);
	}

	/**
	 * Render the dashboard summary.
	 */
	public function render_dashboard_widget() {
		// Uses the cache only, so opening the dashboard never waits on the API.
		$cached = get_transient( MBM_LF_Threads::CACHE_KEY );
		$url    = admin_url( 'admin.php?page=' . self::PAGE );

		if ( ! is_array( $cached ) ) {
			printf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $url ),
				esc_html__( 'Open the feedback list', 'mbm-live-feedback' )
			);

			return;
		}

		$open = 0;

		foreach ( $cached['threads'] as $thread ) {
			if ( empty( $thread['resolved'] ) ) {
				$open++;
			}
		}

		if ( 0 === $open ) {
			printf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'Nothing needs attention.', 'mbm-live-feedback' ),
				esc_url( $url ),
				esc_html__( 'See everything', 'mbm-live-feedback' )
			);

			return;
		}

		printf(
			'<p><strong>%s</strong> <a href="%s">%s</a></p>',
			esc_html(
				sprintf(
					/* translators: %s: number of comments. */
					_n( '%s comment needs attention.', '%s comments need attention.', $open, 'mbm-live-feedback' ),
					number_format_i18n( $open )
				)
			),
			esc_url( add_query_arg( 'show', 'open', $url ) ),
			esc_html__( 'Work through them', 'mbm-live-feedback' )
		);
	}
}
