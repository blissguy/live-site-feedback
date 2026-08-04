<?php
/**
 * Plugin Name: Live Site Feedback
 * Description: Let clients leave comments directly on your website, pinned to exactly what they're looking at. Works with MarkUp.io.
 * Version: 0.11.0
 * Author: Mixbus Marketing
 * Author URI: https://mixbusmarketing.com/
 * Text Domain: mbm-live-feedback
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI: https://github.com/blissguy/live-site-feedback
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MBM_LF_VERSION', '0.11.0' );
define( 'MBM_LF_PATH', plugin_dir_path( __FILE__ ) );
define( 'MBM_LF_URL', plugin_dir_url( __FILE__ ) );
define( 'MBM_LF_BASENAME', plugin_basename( __FILE__ ) );

/**
 * The MarkUp API version this plugin is written against.
 *
 * Sending this on every request is what protects us from upstream breaking
 * changes, so it is pinned deliberately rather than tracking "latest".
 */
define( 'MBM_LF_API_VERSION', '2023-02-22' );

/**
 * The browser library, pinned to an exact build.
 *
 * MarkUp's own documentation and dashboard both point at a v1.0.0 file that does
 * not exist — 1.0.0-rc.1 is the only published build. The CDN has no "latest"
 * alias, so upgrading is always a deliberate edit here.
 *
 * The hash is the sha384 of this exact file, confirmed to match the published
 * npm package. If either the URL or the file contents change, recompute it:
 *
 *   curl -s <url> | openssl dgst -sha384 -binary | openssl base64 -A
 */
define( 'MBM_LF_SDK_VERSION', '1.0.0-rc.1' );
define( 'MBM_LF_SDK_URL', 'https://sdk.markup.io/v1.0.0-rc.1/markup-sdk-ui.min.js' );
define( 'MBM_LF_SDK_SRI', 'sha384-rzI3mFbyDtcAWCZsIybacOMTTsXR8kJw9sy0hHn4kvTV1DBu5HU8pHP/QrIeRV4E' );

/**
 * Where updates come from.
 *
 * Releases are published to GitHub rather than wordpress.org, so the plugin
 * checks there itself. Override with the mbm_lf_github_repo filter if the
 * repository is ever moved or renamed.
 */
if ( ! defined( 'MBM_LF_GITHUB_REPO' ) ) {
	define( 'MBM_LF_GITHUB_REPO', 'blissguy/live-site-feedback' );
}

require_once MBM_LF_PATH . 'includes/class-mbm-lf-credentials.php';
require_once MBM_LF_PATH . 'includes/class-mbm-lf-options.php';
require_once MBM_LF_PATH . 'includes/class-mbm-lf-api-client.php';
require_once MBM_LF_PATH . 'includes/class-mbm-lf-provisioner.php';
require_once MBM_LF_PATH . 'includes/class-mbm-lf-markups.php';
require_once MBM_LF_PATH . 'includes/class-mbm-lf-post-meta.php';
require_once MBM_LF_PATH . 'includes/class-mbm-lf-tokens.php';
require_once MBM_LF_PATH . 'includes/class-mbm-lf-rest.php';
require_once MBM_LF_PATH . 'includes/class-mbm-lf-threads.php';
require_once MBM_LF_PATH . 'includes/class-mbm-lf-webhooks.php';
require_once MBM_LF_PATH . 'includes/class-mbm-lf-feedback-screen.php';
require_once MBM_LF_PATH . 'includes/class-mbm-lf-admin-bar.php';
require_once MBM_LF_PATH . 'includes/class-mbm-lf-frontend.php';
require_once MBM_LF_PATH . 'includes/class-mbm-lf-settings.php';
require_once MBM_LF_PATH . 'includes/class-mbm-lf-updater.php';
require_once MBM_LF_PATH . 'includes/class-mbm-lf-motion-client.php';
require_once MBM_LF_PATH . 'includes/class-mbm-lf-motion-settings.php';
require_once MBM_LF_PATH . 'includes/class-mbm-lf-motion-queue.php';
require_once MBM_LF_PATH . 'includes/class-mbm-lf-motion-tasks.php';

add_action( 'plugins_loaded', 'mbm_lf_bootstrap' );

/**
 * Wire up the plugin.
 */
function mbm_lf_bootstrap() {
	( new MBM_LF_Post_Meta() )->hooks();

	// Update checks also need to work for cron-driven and auto-updates, which
	// do not run in an admin context.
	( new MBM_LF_Updater() )->hooks();

	// These routes have to exist for REST requests, which are neither admin
	// nor front-end.
	( new MBM_LF_Rest() )->hooks();
	( new MBM_LF_Webhooks() )->hooks();

	// The toolbar shows on the front end as well, so this cannot live behind the
	// is_admin() check below.
	( new MBM_LF_Admin_Bar() )->hooks();

	/*
	 * Motion only wires itself in once a key exists. Without one there is no
	 * queue, no scheduled work and no table — someone not using Motion carries
	 * none of it.
	 */
	if ( mbm_lf_motion()->has_key() ) {
		( new MBM_LF_Motion_Tasks() )->hooks();

		if ( is_admin() ) {
			MBM_LF_Motion_Queue::maybe_upgrade();
		}
	}

	// Refreshing the summary happens on a scheduled event, away from anyone's
	// page load, so it has to be registered everywhere too.
	add_action( 'mbm_lf_refresh_feedback', 'mbm_lf_refresh_feedback' );

	if ( is_admin() ) {
		( new MBM_LF_Settings() )->hooks();
		( new MBM_LF_Feedback_Screen() )->hooks();

		// The Motion section is the one place the integration shows itself
		// before it has been connected. Everything else it does stays behind
		// mbm_lf_motion()->has_key().
		( new MBM_LF_Motion_Settings() )->hooks();

		return;
	}

	( new MBM_LF_Frontend() )->hooks();
}

/**
 * Fetch feedback again in the background.
 *
 * Runs on a scheduled event after MarkUp.io reports a change, so the toolbar
 * count is right without waiting for somebody to open the feedback screen.
 */
function mbm_lf_refresh_feedback() {
	mbm_lf_threads()->all( true );
}

/**
 * Shared provisioner instance.
 *
 * @return MBM_LF_Provisioner
 */
function mbm_lf_provisioner() {
	static $provisioner = null;

	if ( null === $provisioner ) {
		$provisioner = new MBM_LF_Provisioner();
	}

	return $provisioner;
}

/**
 * Shared markup manager instance.
 *
 * @return MBM_LF_Markups
 */
function mbm_lf_markups() {
	static $markups = null;

	if ( null === $markups ) {
		$markups = new MBM_LF_Markups();
	}

	return $markups;
}

/**
 * Shared thread reader instance.
 *
 * @return MBM_LF_Threads
 */
function mbm_lf_threads() {
	static $threads = null;

	if ( null === $threads ) {
		$threads = new MBM_LF_Threads();
	}

	return $threads;
}

/**
 * Shared Motion client instance.
 *
 * @return MBM_LF_Motion_Client
 */
function mbm_lf_motion() {
	static $motion = null;

	if ( null === $motion ) {
		$motion = new MBM_LF_Motion_Client();
	}

	return $motion;
}

/**
 * Shared API client instance.
 *
 * @return MBM_LF_Api_Client
 */
function mbm_lf_api() {
	static $client = null;

	if ( null === $client ) {
		$client = new MBM_LF_Api_Client();
	}

	return $client;
}
