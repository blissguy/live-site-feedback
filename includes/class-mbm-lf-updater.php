<?php
/**
 * Updates the plugin from its GitHub releases.
 *
 * WordPress only knows how to update plugins it got from wordpress.org, so this
 * fills in the gap: it tells WordPress which version is the latest and where to
 * download it, and then the normal Plugins screen and auto-update machinery do
 * the rest.
 *
 * The release workflow already builds a zip whose contents sit inside a folder
 * named after the plugin, which is exactly what WordPress expects — so the
 * release asset can be handed over as-is.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves plugin updates from GitHub releases.
 */
class MBM_LF_Updater {

	/**
	 * Transient holding the last release we saw.
	 */
	const CACHE_KEY = 'mbm_lf_latest_release';

	/**
	 * How long to trust a successful lookup, in seconds.
	 */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * How long to wait after a failed lookup before trying again.
	 *
	 * Shorter than a success so a temporary outage does not delay updates for
	 * half a day, but long enough not to hammer an API that allows only 60
	 * unauthenticated requests an hour.
	 */
	const CACHE_TTL_FAILURE = HOUR_IN_SECONDS;

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_details' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_folder_name' ], 10, 4 );
		add_action( 'upgrader_process_complete', [ $this, 'clear_cache_after_update' ], 10, 2 );
		add_action( 'admin_post_mbm_lf_check_updates', [ $this, 'handle_manual_check' ] );
	}

	/**
	 * Tell WordPress about a newer release.
	 *
	 * @param object $transient The update_plugins transient being built.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		if ( empty( $transient->checked ) && empty( $transient->response ) ) {
			// Very early in the request WordPress passes an empty object around;
			// there is nothing useful to add to it yet.
			return $transient;
		}

		$release = $this->latest_release();

		if ( ! $release ) {
			return $transient;
		}

		if ( ! version_compare( $release['version'], MBM_LF_VERSION, '>' ) ) {
			// Up to date. Recording it as such keeps the Plugins screen from
			// showing a stale "update available" row.
			if ( isset( $transient->response[ MBM_LF_BASENAME ] ) ) {
				unset( $transient->response[ MBM_LF_BASENAME ] );
			}

			$transient->no_update[ MBM_LF_BASENAME ] = $this->update_object( $release );

			return $transient;
		}

		$transient->response[ MBM_LF_BASENAME ] = $this->update_object( $release );

		return $transient;
	}

	/**
	 * Provide the details WordPress shows in the "View details" popup.
	 *
	 * @param mixed  $result The value being filtered.
	 * @param string $action The API action being performed.
	 * @param object $args   Arguments, including the plugin slug.
	 * @return mixed
	 */
	public function plugin_details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( empty( $args->slug ) || $this->slug() !== $args->slug ) {
			return $result;
		}

		$release = $this->latest_release();

		if ( ! $release ) {
			return $result;
		}

		$info = new stdClass();

		$info->name          = 'Live Site Feedback';
		$info->slug          = $this->slug();
		$info->version       = $release['version'];
		$info->author        = '<a href="https://mixbusmarketing.com/">Mixbus Marketing</a>';
		$info->homepage      = $this->repo_url();
		$info->download_link = $release['package'];
		$info->requires      = '6.0';
		$info->requires_php  = '7.4';
		$info->last_updated  = $release['published'];
		$info->sections      = [
			'description' => wpautop( esc_html__( 'Let clients leave comments directly on your website, pinned to exactly what they are looking at. Comments are collected in your MarkUp.io account.', 'mbm-live-feedback' ) ),
			'changelog'   => $this->changelog_html(),
		];

		return $info;
	}

	/**
	 * Make sure the unpacked folder is named after the plugin.
	 *
	 * Our release asset already is, so this only matters if the download ever
	 * falls back to GitHub's generated source archive, which unpacks to
	 * "repo-tagname" and would otherwise install as a separate plugin.
	 *
	 * @param string      $source        Path the download was unpacked to.
	 * @param string      $remote_source Top level path of the unpacked download.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Extra arguments.
	 * @return string|WP_Error
	 */
	public function fix_folder_name( $source, $remote_source, $upgrader = null, $hook_extra = [] ) {
		if ( empty( $hook_extra['plugin'] ) || MBM_LF_BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}

		$slug = $this->slug();

		if ( basename( untrailingslashit( $source ) ) === $slug ) {
			return $source;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			return $source;
		}

		$corrected = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $slug;

		if ( $wp_filesystem->exists( $corrected ) ) {
			$wp_filesystem->delete( $corrected, true );
		}

		if ( ! $wp_filesystem->move( untrailingslashit( $source ), $corrected ) ) {
			return new WP_Error(
				'mbm_lf_rename_failed',
				__( 'Could not prepare the downloaded update for installation.', 'mbm-live-feedback' )
			);
		}

		return trailingslashit( $corrected );
	}

	/**
	 * Forget the cached release once an update has been installed.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $extra    Details of what was updated.
	 */
	public function clear_cache_after_update( $upgrader, $extra ) {
		if ( empty( $extra['type'] ) || 'plugin' !== $extra['type'] ) {
			return;
		}

		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Handle the "Check for updates" button.
	 */
	public function handle_manual_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to check for updates.', 'mbm-live-feedback' ) );
		}

		check_admin_referer( 'mbm_lf_check_updates' );

		delete_transient( self::CACHE_KEY );
		delete_site_transient( 'update_plugins' );

		wp_update_plugins();

		$release = $this->latest_release();
		$notice  = 'update-none';

		if ( ! $release ) {
			$notice = 'update-failed';
		} elseif ( version_compare( $release['version'], MBM_LF_VERSION, '>' ) ) {
			$notice = 'update-available';
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'          => MBM_LF_Settings::PAGE,
					'mbm_lf_notice' => $notice,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * The newest release, or null when we could not find out.
	 *
	 * @return array|null Keys: version, package, url, published, body.
	 */
	public function latest_release() {
		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			return isset( $cached['version'] ) ? $cached : null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->repo() . '/releases/latest',
			[
				'timeout' => 15,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Live Site Feedback/' . MBM_LF_VERSION . '; ' . home_url(),
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the failure too, so a private or missing repo does not mean
			// a wasted request on every update check.
			set_transient( self::CACHE_KEY, [ 'failed' => true ], self::CACHE_TTL_FAILURE );

			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, [ 'failed' => true ], self::CACHE_TTL_FAILURE );

			return null;
		}

		$package = $this->pick_asset( $body );

		if ( '' === $package ) {
			set_transient( self::CACHE_KEY, [ 'failed' => true ], self::CACHE_TTL_FAILURE );

			return null;
		}

		$release = [
			'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
			'package'   => $package,
			'url'       => ! empty( $body['html_url'] ) ? $body['html_url'] : $this->repo_url(),
			'published' => ! empty( $body['published_at'] ) ? $body['published_at'] : '',
			'body'      => ! empty( $body['body'] ) ? $body['body'] : '',
		];

		set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Choose which file to download from a release.
	 *
	 * Prefers the built zip attached to the release, because that one already
	 * has the right folder inside it. GitHub's generated source archive is only
	 * a last resort.
	 *
	 * @param array $release Decoded release payload.
	 * @return string Download URL, or an empty string.
	 */
	private function pick_asset( array $release ) {
		$assets   = ! empty( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : [];
		$slug     = $this->slug();
		$fallback = '';

		foreach ( $assets as $asset ) {
			if ( empty( $asset['browser_download_url'] ) || empty( $asset['name'] ) ) {
				continue;
			}

			if ( '.zip' !== strtolower( substr( $asset['name'], -4 ) ) ) {
				continue;
			}

			$url = $this->safe_url( $asset['browser_download_url'] );

			if ( '' === $url ) {
				continue;
			}

			// The workflow names it "<slug>-<version>.zip".
			if ( 0 === strpos( $asset['name'], $slug ) ) {
				return $url;
			}

			if ( '' === $fallback ) {
				$fallback = $url;
			}
		}

		if ( '' !== $fallback ) {
			return $fallback;
		}

		return ! empty( $release['zipball_url'] ) ? $this->safe_url( $release['zipball_url'] ) : '';
	}

	/**
	 * Only accept download URLs that actually point at GitHub.
	 *
	 * @param string $url Candidate URL.
	 * @return string The URL, or an empty string if we do not trust it.
	 */
	private function safe_url( $url ) {
		$parts = wp_parse_url( (string) $url );

		if ( empty( $parts['scheme'] ) || 'https' !== $parts['scheme'] || empty( $parts['host'] ) ) {
			return '';
		}

		$allowed = [ 'github.com', 'api.github.com', 'objects.githubusercontent.com', 'codeload.github.com' ];

		return in_array( strtolower( $parts['host'] ), $allowed, true ) ? (string) $url : '';
	}

	/**
	 * Build the object WordPress expects for an available update.
	 *
	 * @param array $release Release details.
	 * @return stdClass
	 */
	private function update_object( array $release ) {
		$update = new stdClass();

		$update->slug         = $this->slug();
		$update->plugin       = MBM_LF_BASENAME;
		$update->new_version  = $release['version'];
		$update->url          = $release['url'];
		$update->package      = $release['package'];
		$update->requires     = '6.0';
		$update->requires_php = '7.4';
		$update->tested       = '7.0';

		return $update;
	}

	/**
	 * The changelog from readme.txt, as HTML.
	 *
	 * @return string
	 */
	private function changelog_html() {
		$readme = MBM_LF_PATH . 'readme.txt';

		if ( ! is_readable( $readme ) ) {
			return '';
		}

		$contents = file_get_contents( $readme ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! is_string( $contents ) ) {
			return '';
		}

		if ( ! preg_match( '/==\s*Changelog\s*==(.*)$/is', $contents, $matches ) ) {
			return '';
		}

		$html  = '';
		$open  = false;
		$lines = preg_split( '/\r\n|\r|\n/', trim( $matches[1] ) );

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			if ( preg_match( '/^=\s*(.+?)\s*=$/', $line, $heading ) ) {
				if ( $open ) {
					$html .= '</ul>';
					$open  = false;
				}

				$html .= '<h4>' . esc_html( $heading[1] ) . '</h4>';

				continue;
			}

			if ( '*' === substr( $line, 0, 1 ) ) {
				if ( ! $open ) {
					$html .= '<ul>';
					$open  = true;
				}

				$html .= '<li>' . esc_html( trim( substr( $line, 1 ) ) ) . '</li>';
			}
		}

		if ( $open ) {
			$html .= '</ul>';
		}

		return $html;
	}

	/**
	 * The plugin's folder name.
	 *
	 * @return string
	 */
	private function slug() {
		return dirname( MBM_LF_BASENAME );
	}

	/**
	 * The GitHub repository, as "owner/name".
	 *
	 * @return string
	 */
	public function repo() {
		/**
		 * Filters the GitHub repository updates are fetched from.
		 *
		 * @param string $repo Repository as "owner/name".
		 */
		return (string) apply_filters( 'mbm_lf_github_repo', MBM_LF_GITHUB_REPO );
	}

	/**
	 * Web address of the repository.
	 *
	 * @return string
	 */
	public function repo_url() {
		return 'https://github.com/' . $this->repo();
	}
}
