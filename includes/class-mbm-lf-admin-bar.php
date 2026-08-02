<?php
/**
 * The feedback item in the toolbar.
 *
 * Shows how much feedback is waiting from anywhere on the site, admin or front
 * end, and lists the pages it is waiting on.
 *
 * This runs on every single page load, so it only ever reads a stored summary.
 * No API call, and no dependence on the short-lived cache — that cache is
 * cleared the moment new feedback arrives, which is precisely when the count
 * needs to be right.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the toolbar item.
 */
class MBM_LF_Admin_Bar {

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_bar_menu', [ $this, 'add_items' ], 80 );
		add_action( 'wp_head', [ $this, 'styles' ] );
		add_action( 'admin_head', [ $this, 'styles' ] );
	}

	/**
	 * Build the toolbar item and its list of pages.
	 *
	 * @param WP_Admin_Bar $bar The toolbar.
	 */
	public function add_items( $bar ) {
		if ( ! $this->should_show() ) {
			return;
		}

		$summary = mbm_lf_threads()->summary();
		$open    = (int) $summary['open'];
		$screen  = admin_url( 'admin.php?page=' . MBM_LF_Feedback_Screen::PAGE );

		$bar->add_node(
			[
				'id'    => 'mbm-lf',
				'title' => $this->title( $open ),
				'href'  => $screen,
				'meta'  => [
					'title' => $open > 0
						? __( 'Feedback waiting on you', 'mbm-live-feedback' )
						: __( 'Site feedback', 'mbm-live-feedback' ),
				],
			]
		);

		if ( empty( $summary['pages'] ) ) {
			$bar->add_node(
				[
					'id'     => 'mbm-lf-none',
					'parent' => 'mbm-lf',
					'title'  => 0 === $open
						? __( 'Nothing waiting', 'mbm-live-feedback' )
						: __( 'Open the feedback list', 'mbm-live-feedback' ),
					'href'   => $screen,
				]
			);

			return;
		}

		// Each page goes to the page itself, so the feedback bar is right there
		// to work in. The parent goes to the overview.
		foreach ( $summary['pages'] as $index => $page ) {
			if ( empty( $page['url'] ) ) {
				continue;
			}

			$bar->add_node(
				[
					'id'     => 'mbm-lf-page-' . $index,
					'parent' => 'mbm-lf',
					'title'  => sprintf(
						'<span class="mbm-lf-page">%1$s</span><span class="mbm-lf-page-count">%2$s</span>',
						esc_html( $this->shorten( $page['title'] ) ),
						esc_html( number_format_i18n( (int) $page['open'] ) )
					),
					'href'   => $page['url'],
				]
			);
		}

		$bar->add_node(
			[
				'id'     => 'mbm-lf-all',
				'parent' => 'mbm-lf',
				'title'  => __( 'See all feedback', 'mbm-live-feedback' ),
				'href'   => $screen,
			]
		);
	}

	/**
	 * The toolbar label, with a count when there is something waiting.
	 *
	 * @param int $open How many comments need attention.
	 * @return string
	 */
	private function title( $open ) {
		$label = '<span class="ab-icon dashicons dashicons-format-chat" aria-hidden="true"></span>';

		$label .= '<span class="ab-label">' . esc_html__( 'Feedback', 'mbm-live-feedback' ) . '</span>';

		if ( $open > 0 ) {
			$label .= sprintf(
				'<span class="mbm-lf-bubble">%s</span><span class="screen-reader-text"> %s</span>',
				esc_html( number_format_i18n( $open ) ),
				esc_html(
					sprintf(
						/* translators: %s: number of comments. */
						_n( '%s comment needs attention', '%s comments need attention', $open, 'mbm-live-feedback' ),
						number_format_i18n( $open )
					)
				)
			);
		}

		return $label;
	}

	/**
	 * Keep page names short enough for a menu.
	 *
	 * @param string $title Page title.
	 * @return string
	 */
	private function shorten( $title ) {
		$title = (string) $title;

		if ( mb_strlen( $title ) <= 32 ) {
			return $title;
		}

		return mb_substr( $title, 0, 31 ) . '…';
	}

	/**
	 * Whether to show the item at all.
	 *
	 * @return bool
	 */
	private function should_show() {
		if ( ! is_admin_bar_showing() ) {
			return false;
		}

		// Same audience as the feedback screen.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		// Nothing useful to say before the site has been connected.
		return MBM_LF_Credentials::has( 'api_key' ) || MBM_LF_Credentials::has( 'public_key' );
	}

	/**
	 * Just enough styling for the count bubble and the page rows.
	 */
	public function styles() {
		if ( ! $this->should_show() ) {
			return;
		}
		?>
		<style id="mbm-lf-admin-bar">
			#wp-admin-bar-mbm-lf .mbm-lf-bubble {
				display: inline-block;
				min-width: 18px;
				margin-left: 6px;
				padding: 0 6px;
				border-radius: 9px;
				background: #d63638;
				color: #fff;
				font-size: 11px;
				line-height: 18px;
				text-align: center;
			}
			#wp-admin-bar-mbm-lf .ab-icon:before {
				top: 2px;
			}
			#wp-admin-bar-mbm-lf-default .mbm-lf-page-count {
				float: right;
				margin-left: 12px;
				opacity: .7;
			}
			#wp-admin-bar-mbm-lf-default .mbm-lf-page {
				display: inline-block;
				max-width: 15em;
				overflow: hidden;
				text-overflow: ellipsis;
				vertical-align: bottom;
				white-space: nowrap;
			}
		</style>
		<?php
	}
}
