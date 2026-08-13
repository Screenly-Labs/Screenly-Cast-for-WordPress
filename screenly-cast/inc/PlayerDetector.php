<?php
/**
 * Recognizes a digital signage player from its request.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

namespace ScreenlyCast;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a request comes from a signage player.
 *
 * The point is to remove a manual step: a player pointed at an ordinary URL should
 * get the signage render without anyone having to append `?srly` by hand.
 *
 * This is detection, NOT a security boundary. Every signal here is a request header
 * and every one of them can be set by anybody. That is acceptable, because the only
 * thing a false positive grants is a different view of content that was already
 * public. Nothing here should ever gate access to anything.
 *
 * The signal set is deliberately narrower than signage-kit's full classifier: the
 * strong, stable signals only. A missed player still works with `?srly`, whereas a
 * false positive sends a human an unexpected page, so the trade is asymmetric and
 * this errs toward missing.
 */
final class PlayerDetector {

	/**
	 * Screenly's asset-metadata headers.
	 *
	 * The strongest signal available: the device stating what it is, rather than an
	 * inference about a user agent string. Sent when "Send metadata" is enabled on
	 * the asset.
	 *
	 * Only presence is read. The other headers in that family (screen name,
	 * location name, latitude, longitude, tags) identify a customer's premises,
	 * and this plugin has no question that needs them, so they are never read.
	 */
	private const SCREENLY_HEADERS = array(
		'HTTP_X_SCREENLY_HOSTNAME',
		'HTTP_X_SCREENLY_HARDWARE',
		'HTTP_X_SCREENLY_VERSION',
	);

	/**
	 * Android WebView package names belonging to signage players.
	 *
	 * For several vendors this is the only thing that names them: their user agent
	 * says nothing but "Android WebView".
	 *
	 * Note which are absent. `us.zoom.zoompresence` and
	 * `com.google.android.apps.notrod.webviewapp` are meeting-room devices, not
	 * signage, a Zoom Room showing a calendar is not a screen that wants a signage
	 * render, so they must not trigger this.
	 */
	private const SIGNAGE_PACKAGES = array(
		'xogo.xogoplayer',
		'com.pisignage.player2',
		'tv.ablesign.app',
		'com.iadea.player',
		'com.example.yodeck_fireos',
		'sk.mimac.slideshow',
		'com.harison.adver',
	);

	/**
	 * User agent tokens that name a signage player and nothing else.
	 *
	 * Every token here is vendor-specific: it appears on purpose-built signage
	 * hardware or player software and has no consumer equivalent, so matching one is
	 * conclusive enough to change what the visitor is shown.
	 *
	 * Meeting-room tokens (`GoogleMeetRoomDeviceWebViewApp`, `RoomOS`) are absent for
	 * the same reason as the packages above. There is deliberately no `Teams/` token
	 * either: the Teams desktop client injects it on ordinary laptops.
	 */
	private const SIGNAGE_UA_PATTERNS = array(
		'#screenly-viewer|ScreenlyWebview#',
		'#Anthias/#',
		'#BrightSign/#',
		'#A-SMIL|ADAPI#',
		'#Slideshow/#',
		'#Unifi-Connect#i',
	);

	/**
	 * Tokens shared between signage hardware and consumer devices.
	 *
	 * These are NOT acted on, and that is the whole point of keeping them here.
	 *
	 * - `WebAppManager` / `NetCast` is LG webOS, which powers LG's signage displays
	 *   *and* every LG smart TV in a living room.
	 * - Tizen is Samsung's signage platform *and* every Samsung smart TV.
	 * - `QtWebEngine` is the engine most signage players embed, and also the engine
	 *   any desktop Qt application embeds.
	 *
	 * signage-kit rates the first two as medium confidence and bare QtWebEngine as
	 * low, and is right to: for a classifier a medium-confidence guess is fine
	 * because you can filter on confidence afterwards. This is not a classifier. It
	 * changes what a person sees, and the failure is asymmetric: a player we miss
	 * still works with `?srly`, whereas someone browsing on their own television
	 * getting a signage render has no recourse and no explanation.
	 *
	 * Real Screenly and Anthias players are caught by their own tokens above, so
	 * excluding bare QtWebEngine loses only players that identify themselves as
	 * nothing in particular. A site that knows its fleet can opt these back in:
	 *
	 *     add_filter( 'screenly_cast_is_signage_player', function ( $detected ) {
	 *         return $detected || PlayerDetector::matches_ambiguous_engine();
	 *     } );
	 */
	private const AMBIGUOUS_UA_PATTERNS = array(
		'#WebAppManager|NetCast#',
		'#SMART-TV.*Tizen|Tizen.*\bTV\b#i',
		'#QtWebEngine#',
	);

	/**
	 * Automated clients, which must never be treated as players.
	 *
	 * A crawler receiving a signage render would be served stripped content marked
	 * noindex in place of a real page, which is both an SEO problem and a form of
	 * cloaking. Checked before anything else.
	 */
	private const BOT_PATTERN = '#bot\b|crawler|spider|slurp|curl|wget|python-requests|Go-http-client|HeadlessChrome|PhantomJS|GPTBot|ClaudeBot|Applebot|AdsBot-Google|GoogleOther|Googlebot|bingbot|Bytespider|facebookexternalhit|StatusCake|Lavf|AppleCoreMedia#i';

	/**
	 * Whether this request comes from a signage player.
	 *
	 * @return bool
	 */
	public static function is_signage_player(): bool {
		$detected = self::detect();

		/**
		 * Filters whether the current request is treated as a signage player.
		 *
		 * Useful for widening detection to a player this plugin does not know, or
		 * for narrowing it if a device on your network is matching by accident.
		 *
		 * @param mixed $detected Whether a player was detected.
		 */
		$filtered = apply_filters( 'screenly_cast_is_signage_player', $detected );

		// A plain cast; enumerating falsy values missed the string '0', which is what
		// get_option() returns for a saved-off boolean.
		return (bool) $filtered;
	}

	/**
	 * Run the detection signals in order of strength.
	 *
	 * @return bool
	 */
	private static function detect(): bool {
		$user_agent = self::header( 'HTTP_USER_AGENT' );

		// Bots first: a crawler must never be mistaken for a screen.
		if ( '' !== $user_agent && 1 === preg_match( self::BOT_PATTERN, $user_agent ) ) {
			return false;
		}

		if ( self::has_screenly_metadata() ) {
			return true;
		}

		if ( self::has_signage_package() ) {
			return true;
		}

		if ( '' === $user_agent ) {
			return false;
		}

		foreach ( self::SIGNAGE_UA_PATTERNS as $pattern ) {
			if ( 1 === preg_match( $pattern, $user_agent ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the user agent matches an engine shared with consumer devices.
	 *
	 * Public so a site that knows its own fleet can opt into it through the
	 * `screenly_cast_is_signage_player` filter. Not consulted by default, see
	 * AMBIGUOUS_UA_PATTERNS for why.
	 *
	 * @return bool
	 */
	public static function matches_ambiguous_engine(): bool {
		$user_agent = self::header( 'HTTP_USER_AGENT' );

		if ( '' === $user_agent || 1 === preg_match( self::BOT_PATTERN, $user_agent ) ) {
			return false;
		}

		foreach ( self::AMBIGUOUS_UA_PATTERNS as $pattern ) {
			if ( 1 === preg_match( $pattern, $user_agent ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether any Screenly metadata header is present.
	 *
	 * @return bool
	 */
	private static function has_screenly_metadata(): bool {
		foreach ( self::SCREENLY_HEADERS as $key ) {
			if ( '' !== self::header( $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether X-Requested-With names a signage player's package.
	 *
	 * @return bool
	 */
	private static function has_signage_package(): bool {
		$package = self::header( 'HTTP_X_REQUESTED_WITH' );

		return '' !== $package && in_array( $package, self::SIGNAGE_PACKAGES, true );
	}

	/**
	 * Read a request header as a trimmed string.
	 *
	 * @param string $key The $_SERVER key.
	 * @return string The value, or an empty string.
	 */
	private static function header( string $key ): string {
		return Request::server_string( $key );
	}
}
