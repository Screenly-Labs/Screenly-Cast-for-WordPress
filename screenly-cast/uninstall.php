<?php
/**
 * Removes the plugin's data on uninstall.
 *
 * There was previously no uninstall handler at all, so every option the plugin
 * created was left in the database forever.
 *
 * Posts of the old `screenly_cast` custom post type are deliberately NOT deleted.
 * They are user content, and an uninstall routine quietly destroying posts would
 * be far worse than leaving rows behind.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

// Only ever reached by WordPress's uninstall flow.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Every option this plugin has ever created, current and legacy.
 *
 * Listed literally rather than loaded through the plugin's classes: during
 * uninstall the plugin is not bootstrapped, so its autoloader is not registered.
 */
$srly_options = array(
	// Current.
	'screenly_cast_logo_id',
	'screenly_cast_logo_url',
	'screenly_cast_auto_detect',
	'screenly_cast_version',
	'screenly_cast_restored_theme',
	// Legacy, from versions up to 1.0.5.
	'screenly_previous_theme',
	'screenly_cast_enabled',
	'screenly_cast_cache_duration',
	'screenly_options_logo',
	'screenly_cast_logo',
);

if ( is_multisite() ) {
	$srly_site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);

	foreach ( $srly_site_ids as $srly_site_id ) {
		switch_to_blog( $srly_site_id );

		foreach ( $srly_options as $srly_option ) {
			delete_option( $srly_option );
		}

		delete_transient( 'screenly_cast_migrating' );
		restore_current_blog();
	}
} else {
	foreach ( $srly_options as $srly_option ) {
		delete_option( $srly_option );
	}

	delete_transient( 'screenly_cast_migrating' );
}
