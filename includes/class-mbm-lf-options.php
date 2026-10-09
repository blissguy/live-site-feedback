<?php
/**
 * Plugin preferences.
 *
 * Kept separate from credentials on purpose: these are read on every front-end
 * request, so they live in a single autoloaded option. Credentials are read only
 * when we are actually about to talk to the API, so those stay out of autoload.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the plugin's preferences.
 */
class MBM_LF_Options {

	/**
	 * Option name.
	 */
	const OPTION = 'mbm_lf_options';

	/**
	 * Cached values for this request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default preferences.
	 *
	 * @return array
	 */
	public static function defaults() {
		return [
			'enabled'           => true,
			'post_types'        => [ 'page', 'post' ],
			'visibility'        => 'capability',
			'capability'        => 'edit_posts',
			'roles'             => [],
			'position'          => 'bottom-right',
			'theme'             => 'auto',
			'default_markup_id' => '',
			'shared_thread'     => true,
			'auto_create_markups' => true,
			'rate_limits'       => [],
			'webhook_registration_id' => '',
			'last_delivery'     => [],
			'feedback_summary'  => [],
			'motion_workspace_id' => '',
			'motion_project_id' => '',
			'motion_default_assignee' => '',
			'motion_rate_limit' => 120,
			'motion_always_fallback' => false,
			'motion_new_status' => '',
			'motion_enabled'    => true,
			'motion_labels'     => [],
			'motion_last_check' => [],
			'motion_paused_by_repair' => false,
			'connected_origin'  => '',
			'lists_by_origin'   => [],
		];
	}

	/**
	 * All preferences, with defaults filled in.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, [] );

			if ( ! is_array( $stored ) ) {
				$stored = [];
			}

			self::$cache = array_merge( self::defaults(), $stored );
		}

		return self::$cache;
	}

	/**
	 * A single preference.
	 *
	 * @param string $key Preference key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Merge in new values and save.
	 *
	 * @param array $values Values to change.
	 * @return bool
	 */
	public static function update( array $values ) {
		$merged = array_merge( self::all(), $values );

		self::$cache = $merged;

		return update_option( self::OPTION, $merged );
	}

	/**
	 * Remove stored preferences.
	 */
	public static function delete() {
		self::$cache = null;

		delete_option( self::OPTION );
	}

	/**
	 * Post types the feedback bar may appear on.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = self::get( 'post_types' );

		return is_array( $types ) ? $types : [];
	}

	/**
	 * Post types an administrator is allowed to choose from.
	 *
	 * @return WP_Post_Type[]
	 */
	public static function selectable_post_types() {
		return get_post_types(
			[
				'public'  => true,
				'show_ui' => true,
			],
			'objects'
		);
	}

	/**
	 * Valid toolbar positions.
	 *
	 * @return array<string, string>
	 */
	public static function positions() {
		return [
			'bottom-right'  => __( 'Bottom right', 'mbm-live-feedback' ),
			'bottom-center' => __( 'Bottom center', 'mbm-live-feedback' ),
			'bottom-left'   => __( 'Bottom left', 'mbm-live-feedback' ),
			'top-right'     => __( 'Top right', 'mbm-live-feedback' ),
			'top-center'    => __( 'Top center', 'mbm-live-feedback' ),
			'top-left'      => __( 'Top left', 'mbm-live-feedback' ),
		];
	}

	/**
	 * Valid colour themes.
	 *
	 * @return array<string, string>
	 */
	public static function themes() {
		return [
			'auto'     => __( 'Match the visitor’s device setting', 'mbm-live-feedback' ),
			'light'    => __( 'Light', 'mbm-live-feedback' ),
			'dark'     => __( 'Dark', 'mbm-live-feedback' ),
			'inverted' => __( 'Inverted', 'mbm-live-feedback' ),
		];
	}

	/**
	 * Who the feedback bar is shown to.
	 *
	 * @return array<string, string>
	 */
	/**
	 * The permissions offered for the capability check.
	 *
	 * A ladder rather than a free-text box: the point is to pick how far down
	 * the roles feedback reaches, and typing a capability by hand is a way to
	 * silently switch the bar off for everybody.
	 *
	 * @return array<string, string>
	 */
	public static function capability_choices() {
		$choices = [
			'read'              => __( 'Anyone signed in', 'mbm-live-feedback' ),
			'edit_posts'        => __( 'Contributors and above (recommended)', 'mbm-live-feedback' ),
			'publish_posts'     => __( 'Authors and above', 'mbm-live-feedback' ),
			'edit_others_posts' => __( 'Editors and above', 'mbm-live-feedback' ),
			'manage_options'    => __( 'Administrators only', 'mbm-live-feedback' ),
		];

		/*
		 * Whatever is already stored stays selectable even when it is not one
		 * of these. A site set up by hand, or by a filter, must not have its
		 * choice quietly replaced the next time somebody saves an unrelated
		 * setting.
		 */
		$current = (string) self::get( 'capability' );

		if ( '' !== $current && ! isset( $choices[ $current ] ) ) {
			$choices[ $current ] = $current;
		}

		/**
		 * Filters the permissions offered.
		 *
		 * @param array<string, string> $choices Capability => label.
		 */
		return (array) apply_filters( 'mbm_lf_capability_choices', $choices );
	}

	public static function visibility_choices() {
		return [
			'capability' => __( 'People who can edit content (recommended)', 'mbm-live-feedback' ),
			'roles'      => __( 'Only the roles I choose', 'mbm-live-feedback' ),
			'logged_in'  => __( 'Anyone logged in to this site', 'mbm-live-feedback' ),
			'everyone'   => __( 'Every visitor, including the public', 'mbm-live-feedback' ),
		];
	}
}
