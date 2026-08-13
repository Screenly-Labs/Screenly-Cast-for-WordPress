<?php
/**
 * Tests for the legacy repair.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

namespace ScreenlyCast\Tests;

use ScreenlyCast\Migration;
use ScreenlyCast\Settings;
use WP_UnitTestCase;

/**
 * Covers repairing installs left behind by the theme-switching era.
 */
final class MigrationTest extends WP_UnitTestCase {

	/**
	 * The theme that was genuinely active before a test meddled with it.
	 *
	 * @var string
	 */
	private string $real_theme;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->real_theme = get_stylesheet();

		/*
		 * The repair switches themes and deletes options, so it requires an
		 * administrator. Without a current user it correctly refuses to run, which
		 * is asserted separately below.
		 */
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		delete_option( Migration::VERSION_OPTION );
		delete_option( Migration::NOTICE_OPTION );
		delete_transient( 'screenly_cast_migrating' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		update_option( 'stylesheet', $this->real_theme );
		update_option( 'template', $this->real_theme );

		parent::tear_down();
	}

	/**
	 * Pretend the site is stuck on the bundled theme.
	 *
	 * The theme's files no longer ship, so the stuck state is simulated at the
	 * option level, which is exactly the state the old switch_theme() left.
	 */
	private function pretend_stuck_on_bundled_theme(): void {
		update_option( 'stylesheet', 'screenly-cast' );
		update_option( 'template', 'screenly-cast' );
	}

	/**
	 * Without an administrator, the repair does nothing at all.
	 *
	 * This is the guard that matters most. admin-ajax.php fires admin_init before
	 * it inspects `action` or any nonce and is reachable unauthenticated, so
	 * without a capability check an anonymous POST could have triggered
	 * switch_theme(), reintroducing, by another route, the exact bug this whole
	 * rewrite removes.
	 */
	public function test_does_nothing_without_capability(): void {
		wp_set_current_user( 0 );
		update_option( 'screenly_previous_theme', $this->real_theme );
		$this->pretend_stuck_on_bundled_theme();

		Migration::maybe_run();

		$this->assertSame( 'screenly-cast', get_stylesheet(), 'The theme must not change.' );
		$this->assertFalse( get_option( Migration::VERSION_OPTION, false ) );
	}

	/**
	 * A subscriber is not an administrator.
	 */
	public function test_does_nothing_for_an_unprivileged_user(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->pretend_stuck_on_bundled_theme();

		Migration::maybe_run();

		$this->assertSame( 'screenly-cast', get_stylesheet() );
	}

	/**
	 * The version is recorded before the directory removal is attempted.
	 *
	 * Removal is the one step that can fail without returning: delete_theme()
	 * hands off to request_filesystem_credentials() on hosts that need FTP
	 * details, which exits mid-request. Stamping afterwards meant such a host
	 * would repeat the entire migration on every admin page load, forever.
	 */
	public function test_version_is_recorded_even_when_the_directory_cannot_be_removed(): void {
		Migration::maybe_run();

		$this->assertSame( SRLY_VERSION, get_option( Migration::VERSION_OPTION ) );
	}

	/**
	 * The headline case: a site serving the bundled theme to everybody gets its
	 * own theme back.
	 */
	public function test_restores_the_previous_theme_when_stuck(): void {
		update_option( 'screenly_previous_theme', $this->real_theme );
		$this->pretend_stuck_on_bundled_theme();

		Migration::maybe_run();

		$this->assertSame( $this->real_theme, get_stylesheet() );
	}

	/**
	 * Restoring works even when the recorded previous theme is missing.
	 */
	public function test_restores_something_usable_without_a_recorded_theme(): void {
		delete_option( 'screenly_previous_theme' );
		$this->pretend_stuck_on_bundled_theme();

		Migration::maybe_run();

		$this->assertNotSame( 'screenly-cast', get_stylesheet() );
	}

	/**
	 * A restore is announced, because silently changing the active theme would be
	 * alarming to discover later.
	 */
	public function test_restoring_records_a_notice(): void {
		update_option( 'screenly_previous_theme', $this->real_theme );
		$this->pretend_stuck_on_bundled_theme();

		Migration::maybe_run();

		$this->assertSame( $this->real_theme, get_option( Migration::NOTICE_OPTION ) );
	}

	/**
	 * A site that was never stuck is left exactly as it is.
	 */
	public function test_leaves_a_healthy_site_alone(): void {
		$before = get_stylesheet();

		Migration::maybe_run();

		$this->assertSame( $before, get_stylesheet() );
		$this->assertFalse( get_option( Migration::NOTICE_OPTION, false ) );
	}

	/**
	 * Stale options are cleared.
	 */
	public function test_legacy_options_are_deleted(): void {
		update_option( 'screenly_previous_theme', $this->real_theme );
		update_option( 'screenly_cast_enabled', true );
		update_option( 'screenly_cast_cache_duration', 3600 );

		Migration::maybe_run();

		$this->assertFalse( get_option( 'screenly_previous_theme', false ) );
		$this->assertFalse( get_option( 'screenly_cast_enabled', false ) );
		$this->assertFalse( get_option( 'screenly_cast_cache_duration', false ) );
	}

	/**
	 * A logo URL that matches a media item becomes an attachment ID, which is what
	 * gives the render a srcset.
	 */
	public function test_legacy_logo_url_becomes_an_attachment_id(): void {
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);
		update_option( 'screenly_options_logo', wp_get_attachment_url( $attachment_id ) );

		Migration::maybe_run();

		$this->assertSame( $attachment_id, (int) get_option( Settings::LOGO_ID_OPTION ) );
	}

	/**
	 * An unmatched logo URL is kept rather than silently dropped, the site owner
	 * configured it deliberately.
	 */
	public function test_unmatched_logo_url_is_preserved(): void {
		update_option( 'screenly_options_logo', 'https://cdn.example.test/logo.png' );

		Migration::maybe_run();

		$this->assertSame(
			'https://cdn.example.test/logo.png',
			get_option( Settings::LOGO_URL_OPTION )
		);
	}

	/**
	 * The option the never-loaded settings file would have written is also checked.
	 */
	public function test_the_other_legacy_logo_option_is_also_migrated(): void {
		update_option( 'screenly_cast_logo', 'https://cdn.example.test/other.png' );

		Migration::maybe_run();

		$this->assertSame(
			'https://cdn.example.test/other.png',
			get_option( Settings::LOGO_URL_OPTION )
		);
	}

	/**
	 * Content is never destroyed. Posts of the removed custom post type stay put,
	 * even though nothing renders them any more.
	 */
	public function test_legacy_post_type_content_is_not_deleted(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'screenly_cast' ) );

		Migration::maybe_run();

		$this->assertNotNull( get_post( $post_id ) );
	}

	/**
	 * Running twice must be safe: it fires on every admin request until recorded.
	 */
	public function test_migration_is_idempotent(): void {
		update_option( 'screenly_previous_theme', $this->real_theme );
		$this->pretend_stuck_on_bundled_theme();

		Migration::maybe_run();
		$after_first = get_stylesheet();

		delete_transient( 'screenly_cast_migrating' );
		Migration::maybe_run();

		$this->assertSame( $after_first, get_stylesheet() );
	}

	/**
	 * Once recorded, the repair does not run again.
	 */
	public function test_version_is_recorded_so_it_runs_once(): void {
		Migration::maybe_run();

		$this->assertSame( SRLY_VERSION, get_option( Migration::VERSION_OPTION ) );

		// A second pass must not undo a theme the owner has since chosen.
		$this->pretend_stuck_on_bundled_theme();
		delete_transient( 'screenly_cast_migrating' );
		Migration::maybe_run();

		$this->assertSame( 'screenly-cast', get_stylesheet() );
	}
}
