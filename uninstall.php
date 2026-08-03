<?php
/**
 * Removes everything this plugin stored.
 *
 * The MarkUp.io side is deliberately left alone — comments and markups belong
 * to the account owner, and silently deleting them because a plugin was removed
 * would be the wrong call.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-mbm-lf-credentials.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mbm-lf-options.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mbm-lf-post-meta.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mbm-lf-markups.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mbm-lf-updater.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mbm-lf-threads.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mbm-lf-motion-client.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mbm-lf-motion-queue.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mbm-lf-motion-tasks.php';

/*
 * Tell MarkUp.io to stop notifying this site before the credentials go.
 *
 * Skipping this would leave a registration pointing at an endpoint that no
 * longer exists, and MarkUp.io would keep trying to deliver to it. Done by hand
 * rather than through the API client, because only this file is loaded during
 * an uninstall. Best effort: a failure here must never stop the uninstall.
 */
$mbm_lf_registration = MBM_LF_Options::get( 'webhook_registration_id' );
$mbm_lf_api_key      = MBM_LF_Credentials::get( 'api_key' );

if ( is_string( $mbm_lf_registration ) && '' !== $mbm_lf_registration && '' !== $mbm_lf_api_key ) {
	wp_remote_request(
		'https://api.markup.io/api/v2/webhook-registrations/' . rawurlencode( $mbm_lf_registration ),
		[
			'method'  => 'DELETE',
			'timeout' => 10,
			'headers' => [
				'Authorization'      => 'Bearer ' . $mbm_lf_api_key,
				'Markup-API-Version' => '2023-02-22',
			],
		]
	);
}

MBM_LF_Credentials::delete_all();
MBM_LF_Options::delete();

delete_transient( MBM_LF_Updater::CACHE_KEY );
delete_transient( MBM_LF_Threads::CACHE_KEY );

wp_clear_scheduled_hook( 'mbm_lf_refresh_feedback' );

( new MBM_LF_Motion_Client() )->flush_caches();

MBM_LF_Motion_Queue::drop();
delete_option( MBM_LF_Motion_Tasks::LINKS );

delete_post_meta_by_key( MBM_LF_Post_Meta::META_MARKUP_ID );
delete_post_meta_by_key( MBM_LF_Post_Meta::META_DISABLED );
delete_post_meta_by_key( MBM_LF_Markups::META_ERROR );
