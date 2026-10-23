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
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mbm-lf-user-meta.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mbm-lf-markups.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mbm-lf-updater.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mbm-lf-threads.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mbm-lf-motion-client.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mbm-lf-motion-queue.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mbm-lf-motion-tasks.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mbm-lf-motion-watch.php';

/*
 * Tell MarkUp.io to stop notifying this site before the credentials go.
 *
 * Skipping this would leave a registration pointing at an endpoint that no
 * longer exists, and MarkUp.io would keep trying to deliver to it. Done by hand
 * rather than through the API client, because only this file is loaded during
 * an uninstall. Best effort: a failure here must never stop the uninstall.
 *
 * Only a registration pointing at this site's own address is removed. On a
 * copy of another site the one on record can be the other site's, and removing
 * it would quietly stop that site hearing about feedback.
 */
$mbm_lf_registration = MBM_LF_Options::get( 'webhook_registration_id' );
$mbm_lf_api_key      = MBM_LF_Credentials::get( 'api_key' );
$mbm_lf_workspace    = MBM_LF_Credentials::get( 'workspace_id' );

if ( is_string( $mbm_lf_registration ) && '' !== $mbm_lf_registration && '' !== $mbm_lf_api_key && '' !== $mbm_lf_workspace ) {
	$mbm_lf_headers = [
		'Authorization'      => 'Bearer ' . $mbm_lf_api_key,
		'Markup-API-Version' => '2023-02-22',
	];

	$mbm_lf_list = wp_remote_get(
		'https://api.markup.io/api/v2/webhook-registrations?workspaceId=' . rawurlencode( $mbm_lf_workspace ),
		[
			'timeout' => 10,
			'headers' => $mbm_lf_headers,
		]
	);

	$mbm_lf_body = is_wp_error( $mbm_lf_list ) ? [] : json_decode( wp_remote_retrieve_body( $mbm_lf_list ), true );
	$mbm_lf_regs = isset( $mbm_lf_body['data']['data'] ) && is_array( $mbm_lf_body['data']['data'] ) ? $mbm_lf_body['data']['data'] : [];
	$mbm_lf_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

	foreach ( $mbm_lf_regs as $mbm_lf_reg ) {
		if (
			isset( $mbm_lf_reg['id'], $mbm_lf_reg['url'] )
			&& $mbm_lf_registration === (string) $mbm_lf_reg['id']
			&& strtolower( (string) wp_parse_url( $mbm_lf_reg['url'], PHP_URL_HOST ) ) === $mbm_lf_host
		) {
			wp_remote_request(
				'https://api.markup.io/api/v2/webhook-registrations/' . rawurlencode( $mbm_lf_registration ),
				[
					'method'  => 'DELETE',
					'timeout' => 10,
					'headers' => $mbm_lf_headers,
				]
			);
		}
	}
}

MBM_LF_Credentials::delete_all();
MBM_LF_Options::delete();

delete_transient( MBM_LF_Updater::CACHE_KEY );
delete_transient( MBM_LF_Threads::CACHE_KEY );

wp_clear_scheduled_hook( 'mbm_lf_refresh_feedback' );

( new MBM_LF_Motion_Client() )->flush_caches();

MBM_LF_Motion_Queue::drop();
MBM_LF_Motion_Watch::forget();
delete_option( 'mbm_lf_repair_report' );
delete_transient( 'mbm_lf_health' );
delete_option( MBM_LF_Motion_Tasks::LINKS );

delete_post_meta_by_key( MBM_LF_Post_Meta::META_MARKUP_ID );
delete_post_meta_by_key( MBM_LF_Post_Meta::META_DISABLED );
delete_post_meta_by_key( MBM_LF_Post_Meta::META_ENABLED );
delete_post_meta_by_key( MBM_LF_Markups::META_ERROR );

// There is no delete_user_meta_by_key(), so this is the equivalent: the final
// argument makes it match on key alone, across every user.
delete_metadata( 'user', 0, MBM_LF_User_Meta::META_DISABLED, '', true );
