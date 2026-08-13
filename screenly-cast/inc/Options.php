<?php
/**
 * Typed reads of WordPress options.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

namespace ScreenlyCast;

defined( 'ABSPATH' ) || exit;

/**
 * Reads options as a known type.
 *
 * WordPress returns mixed from get_option(): an option can hold anything a
 * previous version of a plugin, or a hand-edited row, put there. Casting that
 * straight to a scalar is
 * what this class exists to avoid: `(string) $array` yields the literal text
 * "Array" and raises a notice, and `(int) $array` yields 1, so a blind cast turns
 * bad data into plausible-looking data. Narrowing explicitly means an unexpected
 * value falls back to something safe instead.
 */
final class Options {

	/**
	 * Read an option as a string.
	 *
	 * @param string $name     Option name.
	 * @param string $fallback Value to use when the option is absent or not a string.
	 * @return string The option value.
	 */
	public static function get_string( string $name, string $fallback = '' ): string {
		$value = get_option( $name, $fallback );

		return is_string( $value ) ? $value : $fallback;
	}

	/**
	 * Read an option as an integer.
	 *
	 * Numeric strings are accepted, because WordPress stores options as strings and
	 * an integer option read back is often `'42'`.
	 *
	 * @param string $name     Option name.
	 * @param int    $fallback Value to use when the option is absent or not numeric.
	 * @return int The option value.
	 */
	public static function get_int( string $name, int $fallback = 0 ): int {
		$value = get_option( $name, $fallback );

		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) && is_numeric( $value ) ) {
			return (int) $value;
		}

		return $fallback;
	}
}
