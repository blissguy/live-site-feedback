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
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_mbm_lf_refresh_threads', [ $this, 'handle_refresh' ] );
		add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
	}

	/**
	 * Add the Live Feedback menu, which opens on this screen.
	 *
	 * This is the screen people come back to every day, so clicking the menu
	 * lands here rather than on the settings. Settings attaches itself below.
	 * The count of open comments shows on both, so it is visible with the menu
	 * folded away too.
	 */
	public function register_menu() {
		$open   = $this->open_count_for_menu();
		$bubble = $open > 0
			? sprintf(
				' <span class="update-plugins count-%1$d"><span class="plugin-count">%2$s</span></span>',
				$open,
				esc_html( number_format_i18n( $open ) )
			)
			: '';

		add_menu_page(
			__( 'Comments', 'mbm-live-feedback' ),
			__( 'Live Feedback', 'mbm-live-feedback' ) . $bubble,
			'edit_posts',
			self::PAGE,
			[ $this, 'render' ],
			'dashicons-format-chat',
			80
		);

		// Same slug as the parent, so the first item is this screen under its own name.
		add_submenu_page(
			self::PAGE,
			__( 'Comments', 'mbm-live-feedback' ),
			__( 'Comments', 'mbm-live-feedback' ) . $bubble,
			'edit_posts',
			self::PAGE,
			[ $this, 'render' ]
		);
	}

	/**
	 * Open count for the menu bubble.
	 *
	 * Reads the stored summary rather than fetching: a menu is built on every
	 * admin page load, and that is no place to wait on somebody else's API.
	 *
	 * @return int
	 */
	private function open_count_for_menu() {
		return (int) mbm_lf_threads()->summary()['open'];
	}

	/**
	 * Render the screen.
	 */
	public function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You don\'t have permission to view these comments.', 'mbm-live-feedback' ) );
		}

		$data = mbm_lf_threads()->by_page();

		/*
		 * Which comments to show. Reading a query argument to choose between two
		 * fixed views; nothing is changed, and the screen is behind a capability
		 * check already.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$show = isset( $_GET['show'] ) && 'all' === sanitize_key( wp_unslash( $_GET['show'] ) ) ? 'all' : 'open';

		$open_total = 0;
		$all_total  = 0;

		foreach ( $data['pages'] as $page ) {
			$open_total += $page['open'];
			$all_total  += $page['total'];
		}

		// Open only: pages with nothing left to do drop out of the list.
		$pages = 'all' === $show
			? $data['pages']
			: array_values(
				array_filter(
					$data['pages'],
					static function ( $page ) {
						return $page['open'] > 0;
					}
				)
			);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Comments', 'mbm-live-feedback' ); ?></h1>

			<p class="description" style="max-width:44rem;">
				<?php esc_html_e( 'Pages your clients have commented on.', 'mbm-live-feedback' ); ?>
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
							esc_html__( 'Only the first %d pages are counted. Keep all feedback in one list to see everything.', 'mbm-live-feedback' ),
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

			<?php $this->render_views( $show, $open_total, $all_total ); ?>

			<table class="wp-list-table widefat fixed striped" style="clear:both;">
				<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Page', 'mbm-live-feedback' ); ?></th>
					<th scope="col" style="width:11rem;"><?php esc_html_e( 'Open', 'mbm-live-feedback' ); ?></th>
					<th scope="col" style="width:9rem;"><?php esc_html_e( 'Total', 'mbm-live-feedback' ); ?></th>
					<th scope="col" style="width:11rem;"><?php esc_html_e( 'Last activity', 'mbm-live-feedback' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php if ( empty( $pages ) ) : ?>
					<tr>
						<td colspan="4">
							<?php
							if ( 0 === $all_total ) {
								esc_html_e( 'No comments yet.', 'mbm-live-feedback' );
							} else {
								esc_html_e( 'Nothing needs attention. Every comment has been resolved.', 'mbm-live-feedback' );
							}
							?>
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

							<?php $this->render_comments( $page['threads'], $show ); ?>
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
	 * The two views: open only, or everything including resolved.
	 *
	 * @param string $show       Current view.
	 * @param int    $open_total Open comments.
	 * @param int    $all_total  Every comment.
	 */
	private function render_views( $show, $open_total, $all_total ) {
		$base  = admin_url( 'admin.php?page=' . self::PAGE );
		$views = [
			'open' => [
				/* translators: %s: number of comments. */
				__( 'Open %s', 'mbm-live-feedback' ),
				$open_total,
				$base,
			],
			'all'  => [
				/* translators: %s: number of comments. */
				__( 'All, including resolved %s', 'mbm-live-feedback' ),
				$all_total,
				add_query_arg( 'show', 'all', $base ),
			],
		];
		?>
		<ul class="subsubsub" style="margin:.75rem 0;">
			<?php
			$last = array_key_last( $views );

			foreach ( $views as $key => $view ) :
				?>
				<li>
					<a href="<?php echo esc_url( $view[2] ); ?>" <?php echo $key === $show ? 'class="current" aria-current="page"' : ''; ?>>
						<?php
						printf(
							esc_html( $view[0] ),
							'<span class="count">(' . esc_html( number_format_i18n( $view[1] ) ) . ')</span>'
						);
						?>
					</a><?php echo $key === $last ? '' : ' |'; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * The comments on one page, folded away until somebody wants them.
	 *
	 * Enough to recognise each one and decide where to start; the full thread,
	 * replies and all, is a click away in MarkUp.io or on the page itself.
	 *
	 * @param array  $threads Normalised threads for the page.
	 * @param string $show    Current view.
	 */
	private function render_comments( array $threads, $show ) {
		if ( 'all' !== $show ) {
			$threads = array_filter(
				$threads,
				static function ( $thread ) {
					return ! $thread['resolved'];
				}
			);
		}

		if ( ! $threads ) {
			return;
		}

		// Open ones first, then the most recently active.
		usort(
			$threads,
			static function ( $a, $b ) {
				if ( $a['resolved'] !== $b['resolved'] ) {
					return $a['resolved'] ? 1 : -1;
				}

				return $b['activity'] <=> $a['activity'];
			}
		);
		?>
		<details style="margin-top:.5rem;">
			<summary>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of comments. */
						_n( 'Show %s comment', 'Show %s comments', count( $threads ), 'mbm-live-feedback' ),
						number_format_i18n( count( $threads ) )
					)
				);
				?>
			</summary>

			<ul style="margin:.5rem 0 0;">
				<?php foreach ( $threads as $thread ) : ?>
					<li style="margin-bottom:.5rem;<?php echo $thread['resolved'] ? 'opacity:.7;' : ''; ?>">
						<?php if ( $thread['number'] ) : ?>
							<strong>#<?php echo esc_html( (string) $thread['number'] ); ?></strong>
						<?php endif; ?>

						<?php echo esc_html( '' !== $thread['excerpt'] ? $thread['excerpt'] : __( '(no text)', 'mbm-live-feedback' ) ); ?>

						<br>
						<span class="description">
							<?php
							$meta = [];

							if ( $thread['resolved'] ) {
								$meta[] = __( 'Resolved', 'mbm-live-feedback' );
							}

							if ( '' !== $thread['author'] ) {
								$meta[] = $thread['author'];
							}

							if ( $thread['replies'] > 0 ) {
								/* translators: %s: number of replies. */
								$meta[] = sprintf( _n( '%s reply', '%s replies', $thread['replies'], 'mbm-live-feedback' ), number_format_i18n( $thread['replies'] ) );
							}

							if ( $thread['activity'] ) {
								$meta[] = $this->humanise( $thread['activity'] );
							}

							echo esc_html( implode( ' · ', $meta ) );
							?>
							·
							<a href="<?php echo esc_url( $thread['app_url'] ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Open in MarkUp.io', 'mbm-live-feedback' ); ?>
							</a>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	/**
	 * Say whether notifications are arriving.
	 *
	 * Worth stating plainly, because it is the difference between counts that
	 * update the moment a client comments and counts that lag by a few minutes.
	 */
	private function render_delivery_note() {
		$webhooks = new MBM_LF_Webhooks();

		if ( ! $webhooks->is_registered() ) {
			esc_html_e( 'These counts refresh every few minutes.', 'mbm-live-feedback' );

			return;
		}

		$last = $webhooks->last_delivery();

		if ( ! $last ) {
			esc_html_e( 'No updates from MarkUp.io yet.', 'mbm-live-feedback' );

			return;
		}

		printf(
			/* translators: %s: human readable time difference, e.g. "5 mins". */
			esc_html__( 'MarkUp.io last reported a change %s ago.', 'mbm-live-feedback' ),
			esc_html( human_time_diff( (int) $last['at'] ) )
		);
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
			wp_die( esc_html__( 'You don\'t have permission to do that.', 'mbm-live-feedback' ) );
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
			__( 'Live Feedback', 'mbm-live-feedback' ),
			[ $this, 'render_dashboard_widget' ]
		);
	}

	/**
	 * Render the dashboard summary.
	 */
	public function render_dashboard_widget() {
		// Reads the stored summary, so opening the dashboard never waits on the API.
		$summary = mbm_lf_threads()->summary();
		$open    = (int) $summary['open'];
		$url     = admin_url( 'admin.php?page=' . self::PAGE );

		if ( 0 === $open ) {
			printf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'Nothing needs attention.', 'mbm-live-feedback' ),
				esc_url( $url ),
				esc_html__( 'View all', 'mbm-live-feedback' )
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
			esc_html__( 'View open comments', 'mbm-live-feedback' )
		);
	}
}
