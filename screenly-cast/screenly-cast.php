<?php
/**
 * Plugin Name:       Screenly Cast
 * Plugin URI:        https://github.com/Screenly-Labs/Screenly-Cast-for-WordPress
 * Description:       Renders posts, pages and image media in a layout built for digital signage. Append <code>?srly</code> to any URL.
 * Version:           2026.8.1
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Screenly, Inc
 * Author URI:        https://www.screenly.io
 * License:           AGPL-3.0-only
 * License URI:       https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain:       screenly-cast
 * Domain Path:       /languages
 * Update URI:        https://wordpress.org/plugins/screenly-cast/
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

namespace ScreenlyCast;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin version.
 *
 * Written by `bun run version:sync` from package.json, do not edit by hand. It
 * cache-busts the enqueued signage assets, which matters on players that cache
 * aggressively. Until this rewrite the constant shipped as the literal string
 * 'VERSION_PLACEHOLDER', because nothing ever substituted it, so asset
 * cache-busting never actually worked.
 */
define( 'SRLY_VERSION', '2026.8.1' );

define( 'SRLY_PLUGIN_FILE', __FILE__ );
define( 'SRLY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SRLY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * The query variable that switches a request into signage mode.
 */
define( 'SRLY_QUERY_VAR', 'srly' );

/*
 * PSR-4 autoloader for the ScreenlyCast namespace.
 *
 * The plugin has no runtime Composer dependencies, so it ships no vendor
 * directory, Composer is a development-only tool here. This keeps the shipped
 * plugin exactly equal to the files in this directory.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = __DIR__ . '/inc/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( Plugin::class, 'on_activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'on_deactivate' ) );

Plugin::instance()->boot();
