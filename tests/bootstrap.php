<?php
/**
 * PHPUnit bootstrap for the Screenly Cast test suite.
 *
 * WordPress core and the PHPUnit test library both come from wp-env, which
 * exports WP_TESTS_DIR. The wp-phpunit Composer package is the fallback for
 * runs outside wp-env.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

$srly_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! file_exists( $srly_autoload ) ) {
	fwrite( STDERR, "Composer dependencies are missing. Run `composer install` first.\n" );
	exit( 1 );
}
require_once $srly_autoload;

$srly_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! is_string( $srly_tests_dir ) || '' === $srly_tests_dir ) {
	$srly_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}
$srly_tests_dir = rtrim( $srly_tests_dir, '/\\' );

if ( ! file_exists( $srly_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		sprintf(
			"Could not find the WordPress test library at %s.\nRun the suite through `bun run test:php`, which starts wp-env.\n",
			$srly_tests_dir
		)
	);
	exit( 1 );
}

require_once $srly_tests_dir . '/includes/functions.php';

/*
 * Load the plugin under test. Note that this bootstrap deliberately does NOT
 * switch the active theme: the previous bootstrap called
 * switch_theme( 'twentytwentyfour' ), which masked the theme-switching bug this
 * rewrite exists to remove. Tests assert theme stability instead.
 */
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/screenly-cast/screenly-cast.php';
	}
);

require $srly_tests_dir . '/includes/bootstrap.php';
