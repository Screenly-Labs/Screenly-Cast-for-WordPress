<?php
/**
 * Tests for signage player detection.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

namespace ScreenlyCast\Tests;

use ScreenlyCast\PlayerDetector;
use WP_UnitTestCase;

/**
 * Covers which requests are treated as coming from a signage player.
 *
 * The asymmetry matters: a missed player still works with `?srly`, whereas a false
 * positive sends a human somewhere they did not ask to go. The negative cases below
 * are therefore the more important half of this file.
 */
final class PlayerDetectorTest extends WP_UnitTestCase {

	/**
	 * Clear the request keys these tests use, before and after each case.
	 *
	 * WordPress's test case does not reset $_SERVER between tests, so a value left
	 * behind by one case would silently change the next. Clearing rather than
	 * saving and restoring keeps this from reading the superglobal at all.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->clear_managed_keys();
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		$this->clear_managed_keys();
		parent::tear_down();
	}

	/**
	 * Unset every request key these tests write.
	 */
	private function clear_managed_keys(): void {
		foreach ( $this->managed_keys() as $key ) {
			unset( $_SERVER[ $key ] );
		}
	}

	/**
	 * The request keys these tests manipulate.
	 *
	 * @return string[]
	 */
	private function managed_keys(): array {
		return array(
			'HTTP_USER_AGENT',
			'HTTP_X_REQUESTED_WITH',
			'HTTP_X_SCREENLY_HOSTNAME',
			'HTTP_X_SCREENLY_HARDWARE',
			'HTTP_X_SCREENLY_VERSION',
		);
	}

	/**
	 * The Screenly metadata headers are the strongest signal: the device saying what
	 * it is, rather than an inference about a UA string.
	 */
	public function test_screenly_metadata_header_identifies_a_player(): void {
		$_SERVER['HTTP_X_SCREENLY_HOSTNAME'] = 'srly-jmar75ko6xp651j';
		$_SERVER['HTTP_USER_AGENT']          = 'Mozilla/5.0 (X11; Linux x86_64)';

		$this->assertTrue( PlayerDetector::is_signage_player() );
	}

	/**
	 * An empty header is not a value.
	 */
	public function test_empty_metadata_header_is_ignored(): void {
		$_SERVER['HTTP_X_SCREENLY_HOSTNAME'] = '   ';
		$_SERVER['HTTP_USER_AGENT']          = 'Mozilla/5.0 (Macintosh)';

		$this->assertFalse( PlayerDetector::is_signage_player() );
	}

	/**
	 * Player user agents are recognised.
	 *
	 * @dataProvider player_user_agents
	 *
	 * @param string $user_agent The UA to test.
	 */
	public function test_player_user_agents_are_detected( string $user_agent ): void {
		$_SERVER['HTTP_USER_AGENT'] = $user_agent;

		$this->assertTrue( PlayerDetector::is_signage_player(), $user_agent );
	}

	/**
	 * User agents that must be treated as signage players.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function player_user_agents(): array {
		return array(
			'screenly'   => array( 'Mozilla/5.0 screenly-viewer/2.0' ),
			'webview'    => array( 'Mozilla/5.0 ScreenlyWebview/1.0' ),
			'anthias'    => array( 'Mozilla/5.0 Anthias/0.19' ),
			'brightsign' => array( 'Mozilla/5.0 BrightSign/8.5.48 (XT1144)' ),
			'iadea'      => array( 'Mozilla/5.0 ADAPI/2.0 (UUID:1234)' ),
			'slideshow'  => array( 'Mozilla/5.0 Slideshow/14.2' ),
			'unifi'      => array( 'Mozilla/5.0 Unifi-Connect/1.2' ),
		);
	}

	/**
	 * A device a person is actually using must never be hijacked.
	 *
	 * These are the cases that make this feature safe or unsafe to enable by
	 * default. LG webOS and Tizen power signage displays *and* every LG and Samsung
	 * smart TV; QtWebEngine is embedded by signage players *and* by desktop Qt
	 * applications. signage-kit rates those signals medium and low confidence, and
	 * acting on them would mean somebody browsing on their own television gets a
	 * signage render with no explanation and no way back.
	 *
	 * @dataProvider consumer_user_agents
	 *
	 * @param string $user_agent The consumer device UA.
	 */
	public function test_consumer_devices_are_not_players( string $user_agent ): void {
		$_SERVER['HTTP_USER_AGENT'] = $user_agent;

		$this->assertFalse( PlayerDetector::is_signage_player(), $user_agent );
	}

	/**
	 * Consumer hardware that shares an engine or platform with signage.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function consumer_user_agents(): array {
		return array(
			'android phone'   => array( 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/126 Mobile Safari/537.36' ),
			'android tablet'  => array( 'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 Chrome/126 Safari/537.36' ),
			'android webview' => array( 'Mozilla/5.0 (Linux; Android 13; SM-A536B) AppleWebKit/537.36 Version/4.0 Chrome/126 Mobile Safari/537.36' ),
			'fire tv'         => array( 'Mozilla/5.0 (Linux; Android 9; AFTKA) AppleWebKit/537.36 Chrome/126 Safari/537.36' ),
			'lg smart tv'     => array( 'Mozilla/5.0 (Web0S; Linux/SmartTV) AppleWebKit/537.36 Chrome/87 Safari/537.36 WebAppManager' ),
			'samsung tv'      => array( 'Mozilla/5.0 (SMART-TV; LINUX; Tizen 7.0) AppleWebKit/537.36 Chrome/94 Safari/537.36' ),
			'desktop qt app'  => array( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 QtWebEngine/5.15.2 Chrome/87 Safari/537.36' ),
			'ipad'            => array( 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17 Safari/604.1' ),
		);
	}

	/**
	 * A site that knows its fleet can opt the ambiguous engines back in.
	 */
	public function test_ambiguous_engines_are_available_to_opt_into(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux) QtWebEngine/5.15.2 Chrome/87';

		$this->assertFalse( PlayerDetector::is_signage_player(), 'Not detected by default.' );
		$this->assertTrue( PlayerDetector::matches_ambiguous_engine(), 'Available to opt into.' );
	}

	/**
	 * Opting in must not also opt crawlers in.
	 */
	public function test_ambiguous_match_still_excludes_bots(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1) QtWebEngine/5.15';

		$this->assertFalse( PlayerDetector::matches_ambiguous_engine() );
	}

	/**
	 * Android WebView packages are recognised, since for several vendors the user
	 * agent says nothing but "Android WebView".
	 */
	public function test_signage_package_is_detected(): void {
		$_SERVER['HTTP_USER_AGENT']       = 'Mozilla/5.0 (Linux; Android 9) AppleWebKit/537.36';
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'com.pisignage.player2';

		$this->assertTrue( PlayerDetector::is_signage_player() );
	}

	/**
	 * A meeting-room device is not a signage player.
	 *
	 * A Zoom Room or a Google Meet display showing a room calendar has no business
	 * being redirected to a signage render, and both ship the same Android WebView
	 * shape as the players above.
	 *
	 * @dataProvider meeting_room_requests
	 *
	 * @param string $key   The server key to set.
	 * @param string $value The value to set.
	 */
	public function test_meeting_room_devices_are_not_players( string $key, string $value ): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 9) AppleWebKit/537.36';
		$_SERVER[ $key ]            = $value;

		$this->assertFalse( PlayerDetector::is_signage_player(), $value );
	}

	/**
	 * Meeting-room devices, which must not match.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function meeting_room_requests(): array {
		return array(
			'zoom package' => array( 'HTTP_X_REQUESTED_WITH', 'us.zoom.zoompresence' ),
			'meet package' => array( 'HTTP_X_REQUESTED_WITH', 'com.google.android.apps.notrod.webviewapp' ),
			'meet ua'      => array( 'HTTP_USER_AGENT', 'Mozilla/5.0 GoogleMeetRoomDeviceWebViewApp/1.0' ),
			'cisco roomos' => array( 'HTTP_USER_AGENT', 'Mozilla/5.0 RoomOS/11.5' ),
		);
	}

	/**
	 * Crawlers must never be redirected.
	 *
	 * Serving a crawler a stripped, noindex render in place of a real page is both an
	 * SEO problem and a form of cloaking, so the bot check runs before everything
	 * else — including before the Screenly headers, which a crawler would not send
	 * but which must not be able to override this either.
	 *
	 * @dataProvider bot_user_agents
	 *
	 * @param string $user_agent The crawler UA.
	 */
	public function test_bots_are_never_players( string $user_agent ): void {
		$_SERVER['HTTP_USER_AGENT'] = $user_agent;

		$this->assertFalse( PlayerDetector::is_signage_player(), $user_agent );
	}

	/**
	 * Automated clients that must not match.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function bot_user_agents(): array {
		return array(
			'googlebot' => array( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ),
			'bingbot'   => array( 'Mozilla/5.0 (compatible; bingbot/2.0)' ),
			'gptbot'    => array( 'Mozilla/5.0 (compatible; GPTBot/1.0)' ),
			'claudebot' => array( 'Mozilla/5.0 (compatible; ClaudeBot/1.0)' ),
			'facebook'  => array( 'facebookexternalhit/1.1' ),
			'curl'      => array( 'curl/8.4.0' ),
		);
	}

	/**
	 * Ordinary browsers are left alone.
	 *
	 * @dataProvider browser_user_agents
	 *
	 * @param string $user_agent The browser UA.
	 */
	public function test_browsers_are_not_players( string $user_agent ): void {
		$_SERVER['HTTP_USER_AGENT'] = $user_agent;

		$this->assertFalse( PlayerDetector::is_signage_player(), $user_agent );
	}

	/**
	 * Everyday user agents that must not match.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function browser_user_agents(): array {
		return array(
			'chrome'  => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126 Safari/537.36' ),
			'safari'  => array( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17 Safari/605.1.15' ),
			'firefox' => array( 'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0' ),
			'iphone'  => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1' ),
			'android' => array( 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/126 Mobile Safari/537.36' ),
		);
	}

	/**
	 * A missing user agent is not a player.
	 */
	public function test_absent_user_agent_is_not_a_player(): void {
		$this->assertFalse( PlayerDetector::is_signage_player() );
	}

	/**
	 * The classic AJAX X-Requested-With value must not be mistaken for a package.
	 */
	public function test_xmlhttprequest_is_not_a_package(): void {
		$_SERVER['HTTP_USER_AGENT']       = 'Mozilla/5.0 (Windows NT 10.0) Chrome/126';
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

		$this->assertFalse( PlayerDetector::is_signage_player() );
	}

	/**
	 * An absurdly long header is discarded rather than fed to the matchers.
	 */
	public function test_oversized_header_is_discarded(): void {
		$_SERVER['HTTP_USER_AGENT'] = str_repeat( 'a', 600 ) . ' screenly-viewer';

		$this->assertFalse( PlayerDetector::is_signage_player() );
	}

	/**
	 * Detection is filterable, both to widen and to narrow it.
	 */
	public function test_detection_is_filterable(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/126';

		add_filter( 'screenly_cast_is_signage_player', '__return_true' );
		$this->assertTrue( PlayerDetector::is_signage_player() );

		remove_filter( 'screenly_cast_is_signage_player', '__return_true' );
		add_filter( 'screenly_cast_is_signage_player', '__return_false' );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 screenly-viewer/2.0';
		$this->assertFalse( PlayerDetector::is_signage_player() );
	}
}
