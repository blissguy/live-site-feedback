<?php
/**
 * Lets AI agents read feedback through the WordPress Abilities API.
 *
 * An MCP adapter such as Novamira hands every registered ability to the agents
 * connected to the site, so registering these is all it takes for an agent to
 * ask "what feedback is there on this page?" without writing PHP against this
 * plugin's internals. All three only read. Nothing here replies to a comment,
 * resolves one or changes a setting.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's abilities.
 */
class MBM_LF_Abilities {

	/**
	 * Ability category, and the prefix of every ability name.
	 */
	const CATEGORY = 'live-site-feedback';

	/**
	 * Wire up, on WordPress versions that have the Abilities API.
	 */
	public function hooks() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * The category the abilities are listed under.
	 */
	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'Live Site Feedback', 'mbm-live-feedback' ),
				'description' => __( 'Comments clients have left on this site\'s pages through MarkUp.io.', 'mbm-live-feedback' ),
			]
		);
	}

	/**
	 * The abilities themselves.
	 */
	public function register_abilities() {
		wp_register_ability(
			self::CATEGORY . '/list-pages',
			[
				'label'               => __( 'List pages with feedback', 'mbm-live-feedback' ),
				'description'         => __( 'Lists every page on this site that has MarkUp.io feedback, with its post ID, title, address, how many comments are open, how many there are in all, and when the last one changed. Pages with open comments come first. Start here to find which pages need attention, then call live-site-feedback/get-page-feedback for the comments on one of them.', 'mbm-live-feedback' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'default'              => [],
					'properties'           => [
						'refresh' => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Ask MarkUp.io again instead of using the list cached for up to five minutes.', 'mbm-live-feedback' ),
						],
					],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'pages'     => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'post_id'       => [
										'type'        => 'integer',
										'description' => __( '0 when the address doesn\'t match a post on this site.', 'mbm-live-feedback' ),
									],
									'title'         => [ 'type' => 'string' ],
									'url'           => [ 'type' => 'string' ],
									'open'          => [ 'type' => 'integer' ],
									'total'         => [ 'type' => 'integer' ],
									'last_activity' => [
										'type'        => 'string',
										'description' => __( 'ISO 8601, in the site\'s timezone.', 'mbm-live-feedback' ),
									],
								],
							],
						],
						'truncated' => [
							'type'        => 'boolean',
							'description' => __( 'True when the site has more per-page lists than are read at once, so some pages may be missing.', 'mbm-live-feedback' ),
						],
						'error'     => [
							'type'        => 'string',
							'description' => __( 'Why some or all feedback couldn\'t be read. Empty when everything was.', 'mbm-live-feedback' ),
						],
					],
				],
				'execute_callback'    => [ $this, 'list_pages' ],
				'permission_callback' => [ $this, 'can_read' ],
				'meta'                => $this->meta(),
			]
		);

		wp_register_ability(
			self::CATEGORY . '/get-page-feedback',
			[
				'label'               => __( 'Get feedback on a page', 'mbm-live-feedback' ),
				'description'         => __( 'Returns the MarkUp.io comments left on one page, by post ID or address. Each comment comes with its pin number, status, author, dates, the full text of the comment and of every reply, attached files and screenshots, where the pin sits (CSS selector paths, innermost first, and its position in the window), the window size and browser it was left in, and links to open it on the page or in MarkUp.io. Open comments only unless status says otherwise. The markdown field has the same thing ready to read.', 'mbm-live-feedback' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'default'              => [],
					'properties'           => [
						'post_id' => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The page\'s post ID. Give this or url.', 'mbm-live-feedback' ),
						],
						'url'     => [
							'type'        => 'string',
							'description' => __( 'The page\'s address on this site. Used when post_id isn\'t given.', 'mbm-live-feedback' ),
						],
						'status'  => [
							'type'        => 'string',
							'enum'        => [ 'open', 'resolved', 'all' ],
							'default'     => 'open',
							'description' => __( 'Which comments to return.', 'mbm-live-feedback' ),
						],
						'refresh' => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Skip the list cached for up to five minutes when working out which comments are on this page.', 'mbm-live-feedback' ),
						],
					],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'page'     => [
							'type'       => 'object',
							'properties' => [
								'post_id' => [ 'type' => 'integer' ],
								'title'   => [ 'type' => 'string' ],
								'url'     => [ 'type' => 'string' ],
							],
						],
						'status'   => [ 'type' => 'string' ],
						'count'    => [ 'type' => 'integer' ],
						'threads'  => [
							'type'  => 'array',
							'items' => self::thread_schema(),
						],
						'markdown' => [ 'type' => 'string' ],
						'error'    => [
							'type'        => 'string',
							'description' => __( 'Why some feedback couldn\'t be read. Empty when it all was.', 'mbm-live-feedback' ),
						],
					],
				],
				'execute_callback'    => [ $this, 'get_page_feedback' ],
				'permission_callback' => [ $this, 'can_read' ],
				'meta'                => $this->meta(),
			]
		);

		wp_register_ability(
			self::CATEGORY . '/get-thread',
			[
				'label'               => __( 'Get one comment', 'mbm-live-feedback' ),
				'description'         => __( 'Returns one MarkUp.io comment thread by its ID, in the same shape as each thread from live-site-feedback/get-page-feedback, read fresh from MarkUp.io. Thread IDs come from that ability, or from the commentThread parameter in a link to a comment on the page. Refuses threads that belong to another site.', 'mbm-live-feedback' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'thread_id' => [
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'The thread\'s ID.', 'mbm-live-feedback' ),
						],
					],
					'required'             => [ 'thread_id' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'thread'   => self::thread_schema(),
						'markdown' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ $this, 'get_thread' ],
				'permission_callback' => [ $this, 'can_read' ],
				'meta'                => $this->meta(),
			]
		);
	}

	/**
	 * Who may read feedback: the same people as the Comments screen.
	 *
	 * @return bool
	 */
	public function can_read() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Pages with feedback.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function list_pages( $input = [] ) {
		if ( ! empty( $input['refresh'] ) ) {
			mbm_lf_threads()->flush_cache();
		}

		$data  = mbm_lf_threads()->by_page();
		$pages = [];

		foreach ( $data['pages'] as $page ) {
			$pages[] = [
				'post_id'       => (int) $page['post_id'],
				'title'         => (string) $page['title'],
				'url'           => $page['post_id'] ? (string) get_permalink( $page['post_id'] ) : (string) $page['url'],
				'open'          => (int) $page['open'],
				'total'         => (int) $page['total'],
				'last_activity' => $page['activity'] ? (string) wp_date( 'c', $page['activity'] ) : '',
			];
		}

		return [
			'pages'     => $pages,
			'truncated' => (bool) $data['truncated'],
			'error'     => (string) $data['error'],
		];
	}

	/**
	 * Feedback on one page, in full.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function get_page_feedback( $input = [] ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$url     = isset( $input['url'] ) ? esc_url_raw( trim( (string) $input['url'] ) ) : '';
		$status  = isset( $input['status'] ) && in_array( $input['status'], [ 'open', 'resolved', 'all' ], true ) ? $input['status'] : 'open';

		if ( ! $post_id && '' === $url ) {
			return new WP_Error(
				'mbm_lf_no_page',
				__( 'Give a post_id or a url.', 'mbm-live-feedback' )
			);
		}

		if ( $post_id && ! get_post( $post_id ) ) {
			return new WP_Error(
				'mbm_lf_no_page',
				__( 'There\'s no post with that ID on this site.', 'mbm-live-feedback' )
			);
		}

		$data = mbm_lf_threads()->page_threads( $post_id, $url, $status, ! empty( $input['refresh'] ) );

		if ( ! $post_id ) {
			$post_id = url_to_postid( $url );
		}

		$page = [
			'post_id' => $post_id,
			'title'   => $post_id ? (string) get_the_title( $post_id ) : '',
			'url'     => $post_id ? (string) get_permalink( $post_id ) : $url,
		];

		if ( '' === $page['title'] && $data['threads'] ) {
			$page['title'] = (string) $data['threads'][0]['page_title'];
		}

		return [
			'page'     => $page,
			'status'   => $status,
			'count'    => count( $data['threads'] ),
			'threads'  => $data['threads'],
			'markdown' => $this->page_markdown( $page, $status, $data['threads'], $data['error'] ),
			'error'    => (string) $data['error'],
		];
	}

	/**
	 * One thread, in full.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function get_thread( $input = [] ) {
		$thread_id = isset( $input['thread_id'] ) ? sanitize_text_field( (string) $input['thread_id'] ) : '';

		if ( '' === $thread_id ) {
			return new WP_Error(
				'mbm_lf_no_thread',
				__( 'Give a thread_id.', 'mbm-live-feedback' )
			);
		}

		$thread = mbm_lf_threads()->thread( $thread_id );

		if ( is_wp_error( $thread ) ) {
			return $thread;
		}

		return [
			'thread'   => $thread,
			'markdown' => $this->thread_markdown( $thread ),
		];
	}

	/**
	 * Meta shared by every ability here.
	 *
	 * @return array
	 */
	private function meta() {
		return [
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
			'mcp'          => [
				'public' => true,
				'type'   => 'tool',
			],
		];
	}

	/**
	 * The shape of one thread, as MBM_LF_Threads::detail() returns it.
	 *
	 * @return array
	 */
	private static function thread_schema() {
		$message = [
			'type'       => 'object',
			'properties' => [
				'author'      => [ 'type' => 'string' ],
				'date'        => [ 'type' => 'string' ],
				'text'        => [ 'type' => 'string' ],
				'attachments' => [
					'type'  => 'array',
					'items' => self::attachment_schema(),
				],
			],
		];

		return [
			'type'       => 'object',
			'properties' => [
				'id'            => [ 'type' => 'string' ],
				'markup_id'     => [ 'type' => 'string' ],
				'number'        => [
					'type'        => 'integer',
					'description' => __( 'The number on the pin.', 'mbm-live-feedback' ),
				],
				'status'        => [
					'type' => 'string',
					'enum' => [ 'open', 'resolved' ],
				],
				'priority'      => [
					'type'        => 'string',
					'description' => __( 'Priority set in MarkUp.io, such as High. Empty when none is set.', 'mbm-live-feedback' ),
				],
				'author'        => [ 'type' => 'string' ],
				'created'       => [
					'type'        => 'string',
					'description' => __( 'ISO 8601, in the site\'s timezone.', 'mbm-live-feedback' ),
				],
				'last_activity' => [ 'type' => 'string' ],
				'message'       => [
					'type'        => 'string',
					'description' => __( 'The full comment. Links are written as Markdown, mentions as @name.', 'mbm-live-feedback' ),
				],
				'attachments'   => [
					'type'        => 'array',
					'description' => __( 'Files on the comment itself, including screenshots the feedback bar takes of the spot.', 'mbm-live-feedback' ),
					'items'       => self::attachment_schema(),
				],
				'replies'       => [
					'type'        => 'array',
					'description' => __( 'Every reply, oldest first.', 'mbm-live-feedback' ),
					'items'       => $message,
				],
				'screenshot'    => [
					'type'        => 'string',
					'description' => __( 'The thread\'s own screenshot from MarkUp.io. Empty when there isn\'t one.', 'mbm-live-feedback' ),
				],
				'pin'           => [
					'type'       => 'object',
					'properties' => [
						'viewport_x' => [
							'type'        => [ 'number', 'null' ],
							'description' => __( 'How far across the window the pin was placed, from 0 to 1.', 'mbm-live-feedback' ),
						],
						'viewport_y' => [
							'type'        => [ 'number', 'null' ],
							'description' => __( 'How far down the window the pin was placed, from 0 to 1.', 'mbm-live-feedback' ),
						],
						'elements'   => [
							'type'        => 'array',
							'description' => __( 'The element the pin is on and its ancestors, innermost first, each with the pin\'s position inside it from 0 to 1.', 'mbm-live-feedback' ),
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'path'     => [ 'type' => 'string' ],
									'offset_x' => [ 'type' => [ 'number', 'null' ] ],
									'offset_y' => [ 'type' => [ 'number', 'null' ] ],
								],
							],
						],
					],
				],
				'browser'       => [
					'type'        => 'object',
					'description' => __( 'Where the comment was left. Sizes are in pixels and 0 when unknown.', 'mbm-live-feedback' ),
					'properties'  => [
						'viewport_width'  => [ 'type' => 'integer' ],
						'viewport_height' => [ 'type' => 'integer' ],
						'screen_width'    => [ 'type' => 'integer' ],
						'screen_height'   => [ 'type' => 'integer' ],
						'browser'         => [ 'type' => 'string' ],
						'browser_version' => [ 'type' => 'string' ],
						'os'              => [ 'type' => 'string' ],
						'os_version'      => [ 'type' => 'string' ],
						'platform'        => [
							'type'        => 'string',
							'description' => __( 'The device type MarkUp.io filed it under, such as desktop.', 'mbm-live-feedback' ),
						],
					],
				],
				'url'           => [ 'type' => 'string' ],
				'post_id'       => [ 'type' => 'integer' ],
				'page_title'    => [ 'type' => 'string' ],
				'page_link'     => [
					'type'        => 'string',
					'description' => __( 'The page, opened with this comment showing in the feedback bar.', 'mbm-live-feedback' ),
				],
				'app_url'       => [
					'type'        => 'string',
					'description' => __( 'The comment in MarkUp.io.', 'mbm-live-feedback' ),
				],
			],
		];
	}

	/**
	 * The shape of one attached file.
	 *
	 * @return array
	 */
	private static function attachment_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'url'       => [ 'type' => 'string' ],
				'filename'  => [ 'type' => 'string' ],
				'mime_type' => [ 'type' => 'string' ],
				'width'     => [ 'type' => 'integer' ],
				'height'    => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * A page's feedback as Markdown.
	 *
	 * @param array  $page    Page details.
	 * @param string $status  Which comments were asked for.
	 * @param array  $threads Threads from MBM_LF_Threads::detail().
	 * @param string $error   Anything that went wrong.
	 * @return string
	 */
	private function page_markdown( array $page, $status, array $threads, $error ) {
		$title = '' !== $page['title'] ? $page['title'] : $page['url'];
		$lines = [ '## ' . $title ];

		if ( '' !== $page['url'] && $page['url'] !== $title ) {
			$lines[] = $page['url'];
		}

		$lines[] = '';

		if ( '' !== $error ) {
			$lines[] = sprintf(
				/* translators: %s: error message. */
				__( '**Some feedback couldn\'t be read:** %s', 'mbm-live-feedback' ),
				$error
			);
			$lines[] = '';
		}

		if ( ! $threads ) {
			if ( 'open' === $status ) {
				$lines[] = __( 'No open feedback on this page.', 'mbm-live-feedback' );
			} elseif ( 'resolved' === $status ) {
				$lines[] = __( 'No resolved feedback on this page.', 'mbm-live-feedback' );
			} else {
				$lines[] = __( 'No feedback on this page.', 'mbm-live-feedback' );
			}

			return implode( "\n", $lines );
		}

		foreach ( $threads as $thread ) {
			$lines[] = $this->thread_markdown( $thread );
			$lines[] = '';
		}

		return trim( implode( "\n", $lines ) );
	}

	/**
	 * One thread as Markdown.
	 *
	 * @param array $thread Thread from MBM_LF_Threads::detail().
	 * @return string
	 */
	private function thread_markdown( array $thread ) {
		$heading = [
			'#' . $thread['number'],
			'resolved' === $thread['status'] ? __( 'Resolved', 'mbm-live-feedback' ) : __( 'Open', 'mbm-live-feedback' ),
		];

		if ( '' !== $thread['priority'] ) {
			/* translators: %s: priority name, such as High. */
			$heading[] = sprintf( __( '%s priority', 'mbm-live-feedback' ), $thread['priority'] );
		}

		$lines   = [ '### ' . implode( ' · ', $heading ), '' ];
		$lines[] = $this->byline( $thread['author'], $thread['created'] );
		$lines[] = '';
		$lines   = array_merge( $lines, $this->quote( $thread['message'] ) );
		$lines   = array_merge( $lines, $this->files( $thread['attachments'] ) );

		if ( '' !== $thread['screenshot'] ) {
			$lines[] = '';
			$lines[] = sprintf( '%s: %s', __( 'Screenshot', 'mbm-live-feedback' ), $thread['screenshot'] );
		}

		$lines[] = '';

		$where = $this->pin_line( $thread['pin'] );

		if ( '' !== $where ) {
			$lines[] = $where;
			$lines[] = '';
		}

		// The same line Motion tasks carry, so both read alike.
		$seen = MBM_LF_Motion_Tasks::viewport_line(
			[
				'viewportWidth'  => $thread['browser']['viewport_width'],
				'viewportHeight' => $thread['browser']['viewport_height'],
				'browserName'    => $thread['browser']['browser'],
				'browserVersion' => $thread['browser']['browser_version'],
				'os'             => $thread['browser']['os'],
			]
		);

		if ( '' !== $seen ) {
			$lines[] = $seen;
			$lines[] = '';
		}

		if ( $thread['replies'] ) {
			$lines[] = sprintf(
				/* translators: %d: number of replies. */
				_n( '**%d reply**', '**%d replies**', count( $thread['replies'] ), 'mbm-live-feedback' ),
				count( $thread['replies'] )
			);
			$lines[] = '';

			foreach ( $thread['replies'] as $reply ) {
				$lines[] = $this->byline( $reply['author'], $reply['date'] );
				$lines[] = '';
				$lines   = array_merge( $lines, $this->quote( $reply['text'] ) );
				$lines   = array_merge( $lines, $this->files( $reply['attachments'] ) );
				$lines[] = '';
			}
		}

		$links = [];

		if ( '' !== $thread['page_link'] ) {
			$links[] = sprintf( '[%s](%s)', __( 'View it on the page', 'mbm-live-feedback' ), $thread['page_link'] );
		}

		$links[] = sprintf( '[%s](%s)', __( 'Open the comment in MarkUp.io', 'mbm-live-feedback' ), $thread['app_url'] );
		$lines[] = implode( ' · ', $links );

		return implode( "\n", $lines );
	}

	/**
	 * Who said something, and when.
	 *
	 * @param string $author Name.
	 * @param string $date   ISO 8601.
	 * @return string
	 */
	private function byline( $author, $date ) {
		$parts = [ '**' . ( '' !== $author ? $author : __( 'Someone', 'mbm-live-feedback' ) ) . '**' ];

		if ( '' !== $date ) {
			$parts[] = (string) wp_date( 'Y-m-d H:i T', strtotime( $date ) );
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Text as a Markdown quote, so the client's words are visibly theirs.
	 *
	 * @param string $text Message text.
	 * @return string[]
	 */
	private function quote( $text ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			$text = __( '(no text)', 'mbm-live-feedback' );
		}

		$lines = [];

		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$lines[] = '> ' . $line;
		}

		return $lines;
	}

	/**
	 * Attached files as Markdown links.
	 *
	 * @param array $files Attachments.
	 * @return string[]
	 */
	private function files( array $files ) {
		if ( ! $files ) {
			return [];
		}

		$lines = [ '' ];

		foreach ( $files as $file ) {
			$name    = '' !== $file['filename'] ? $file['filename'] : basename( (string) wp_parse_url( $file['url'], PHP_URL_PATH ) );
			$lines[] = sprintf( '%s: [%s](%s)', __( 'Attached', 'mbm-live-feedback' ), $name, $file['url'] );
		}

		return $lines;
	}

	/**
	 * Where the pin is, in words.
	 *
	 * @param array $pin Pin details.
	 * @return string
	 */
	private function pin_line( array $pin ) {
		$parts = [];

		if ( $pin['elements'] ) {
			$parts[] = sprintf(
				/* translators: %s: CSS selector. */
				__( 'Pinned to `%s`', 'mbm-live-feedback' ),
				$pin['elements'][0]['path']
			);
		}

		if ( null !== $pin['viewport_x'] && null !== $pin['viewport_y'] ) {
			$parts[] = sprintf(
				/* translators: 1: percentage across the window, 2: percentage down the window. */
				__( '%1$d%% across and %2$d%% down the window', 'mbm-live-feedback' ),
				(int) round( $pin['viewport_x'] * 100 ),
				(int) round( $pin['viewport_y'] * 100 )
			);
		}

		return implode( ', ', $parts );
	}
}
