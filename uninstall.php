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

MBM_LF_Credentials::delete_all();
MBM_LF_Options::delete();

delete_transient( MBM_LF_Updater::CACHE_KEY );

delete_post_meta_by_key( MBM_LF_Post_Meta::META_MARKUP_ID );
delete_post_meta_by_key( MBM_LF_Post_Meta::META_DISABLED );
delete_post_meta_by_key( MBM_LF_Markups::META_ERROR );
