<?php
/**
 * Tests for the settings screen's options.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

namespace ScreenlyCast\Tests;

use ReflectionClass;
use ScreenlyCast\Migration;
use ScreenlyCast\Settings;
use WP_UnitTestCase;

/**
 * Covers how the plugin's options are saved and cleaned up.
 */
final class SettingsTest extends WP_UnitTestCase {

	/**
	 * Register the settings, since the save path runs through their callbacks.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Settings() )->register_settings();
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		delete_option( Settings::AUTO_DETECT_OPTION );
		parent::tear_down();
	}

	/**
	 * Unchecking the box turns detection off.
	 *
	 * An unchecked checkbox is omitted from the POST body entirely, which is the
	 * classic way for a boolean setting to become impossible to turn off: the
	 * handler sees no value and leaves the old one in place. That failure is real
	 * for a hand-rolled form handler, but not for the Settings API: options.php
	 * iterates the options *registered* to the page rather than the keys that were
	 * posted, and calls update_option( $option, null ) for any it does not find.
	 *
	 * So the behavior depends entirely on the sanitize callback mapping null to
	 * false, which is what this asserts. The line below is exactly what options.php
	 * does with an absent key.
	 */
	public function test_absent_checkbox_turns_detection_off(): void {
		update_option( Settings::AUTO_DETECT_OPTION, true );
		$this->assertTrue( Settings::auto_detect_enabled(), 'precondition: detection is on' );

		update_option( Settings::AUTO_DETECT_OPTION, null );

		$this->assertFalse(
			Settings::auto_detect_enabled(),
			'A submission without the checkbox should turn detection off'
		);
	}

	/**
	 * Detection defaults to on when the option has never been saved.
	 */
	public function test_detection_defaults_to_on(): void {
		delete_option( Settings::AUTO_DETECT_OPTION );

		$this->assertTrue( Settings::auto_detect_enabled() );
	}

	/**
	 * Every option the plugin defines is removed on uninstall.
	 *
	 * Uninstall.php lists the option names as literal strings, and has to: during
	 * uninstall the plugin is never bootstrapped, so its autoloader is not
	 * registered and the constants below cannot be read. That is a real constraint
	 * rather than an oversight, but it does mean the list is a copy, and a copy
	 * with nothing checking it against the original is a leak waiting for the next
	 * option to be added. This is that check.
	 *
	 * It runs the real uninstall routine rather than reading its source, so an
	 * option named in the file but never actually deleted fails here too.
	 */
	public function test_uninstall_removes_every_option_the_plugin_defines(): void {
		$defined = array();
		foreach ( array( Settings::class, Migration::class ) as $class ) {
			foreach ( ( new ReflectionClass( $class ) )->getConstants() as $name => $value ) {
				if ( str_ends_with( $name, '_OPTION' ) && is_string( $value ) ) {
					$defined[ $value ] = "{$class}::{$name}";
				}
			}
		}

		$this->assertNotEmpty( $defined, 'No option constants were found to check' );

		foreach ( array_keys( $defined ) as $option ) {
			update_option( $option, 'set-by-test' );
		}

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'screenly-cast/screenly-cast.php' );
		}
		require dirname( __DIR__ ) . '/screenly-cast/uninstall.php';

		foreach ( $defined as $option => $source ) {
			$this->assertFalse(
				get_option( $option, false ),
				"{$source} survives uninstall"
			);
		}
	}
}
