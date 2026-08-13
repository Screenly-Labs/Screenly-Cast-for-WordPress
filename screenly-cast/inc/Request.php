<?php
/**
 * Typed reads of the current request.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

namespace ScreenlyCast;

defined( 'ABSPATH' ) || exit;

/**
 * Reads request values as strings.
 *
 * This is the only place in the plugin that touches $_SERVER, deliberately. Every
 * value in there is attacker-controlled and typed mixed, so each read needs the
 * same three steps in the same order (narrow, unslash, sanitize), and having one
 * implementation means there is one thing to review rather than one per call site.
 */
final class Request {

	/**
	 * Longest value worth looking at.
	 *
	 * Long enough for any real user agent, short enough that a hostile sender cannot
	 * push a large payload into the pattern matching that consumes these.
	 */
	private const MAX_LENGTH = 512;

	/**
	 * Read a request value as a trimmed string.
	 *
	 * @param string $key The $_SERVER key, e.g. 'HTTP_USER_AGENT'.
	 * @return string The value, or an empty string when absent, not a string, or over-long.
	 */
	public static function server_string( string $key ): string {
		/*
		 * The narrowing has to precede unslashing and sanitizing, because $_SERVER
		 * values are mixed and both wp_unslash() and sanitize_text_field() want a
		 * string. The sniff cannot follow dataflow across statements, so it reads the
		 * access itself as unsanitized; sanitizing happens three lines down. The
		 * alternative is to hide the narrowing from static analysis, which is a worse
		 * trade than one reviewed suppression in one file.
		 */
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Narrowed below, then unslashed and sanitized.
		$raw = $_SERVER[ $key ] ?? '';

		if ( ! is_string( $raw ) ) {
			return '';
		}

		$value = trim( sanitize_text_field( wp_unslash( $raw ) ) );

		return strlen( $value ) <= self::MAX_LENGTH ? $value : '';
	}
}
