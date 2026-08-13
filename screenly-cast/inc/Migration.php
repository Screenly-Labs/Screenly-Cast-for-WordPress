<?php
/**
 * One-time repair of installs from the theme-switching era.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

namespace ScreenlyCast;

defined( 'ABSPATH' ) || exit;

/**
 * Repairs the state older versions of this plugin left behind.
 *
 * Versions up to 1.0.5 responded to a `?srly` request by calling switch_theme()
 * on the entire site, recording the previous theme in an option, and switching
 * back when a request arrived without the parameter. A site could therefore be
 * sitting on the bundled theme right now — for every visitor — with a copied
 * theme directory in wp-content/themes and several stale options.
 *
 * This runs once per version, from the admin or WP-CLI only. It never runs on a
 * front-end request: changing the active theme in response to an anonymous GET
 * is the exact defect being repaired.
 */
final class Migration {

	public const VERSION_OPTION = 'screenly_cast_version';

	/**
	 * Records which theme was restored, so the next admin page view can say so.
	 */
	public const NOTICE_OPTION = 'screenly_cast_restored_theme';

	private const LOCK_TRANSIENT = 'screenly_cast_migrating';

	private const LEGACY_THEME = 'screenly-cast';

	/**
	 * Options older versions created and nothing reads any more.
	 */
	private const LEGACY_OPTIONS = array(
		'screenly_previous_theme',
		'screenly_cast_enabled',
		'screenly_cast_cache_duration',
	);

	/**
	 * Legacy logo options, in preference order.
	 *
	 * There are two because the old code disagreed with itself: the theme's
	 * header.php read 'screenly_options_logo', while the settings file that was
	 * never loaded would have written 'screenly_cast_logo'. Either may hold a
	 * value a site owner set by hand, so both are checked.
	 */
	private const LEGACY_LOGO_OPTIONS = array(
		'screenly_options_logo',
		'screenly_cast_logo',
	);

	/**
	 * Run the repair if this version has not been recorded yet.
	 */
	public static function maybe_run(): void {
		if ( SRLY_VERSION === Options::get_string( self::VERSION_OPTION ) ) {
			return;
		}

		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			self::restore_active_theme();
			self::migrate_logo_option();
			self::remove_legacy_theme_directory();
			self::delete_legacy_options();

			// The removed custom post type registered rewrite rules that are now
			// stale.
			flush_rewrite_rules();

			update_option( self::VERSION_OPTION, SRLY_VERSION );
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * Take a short-lived lock so two admin requests do not migrate at once.
	 *
	 * Every step is idempotent, so the lock is a courtesy rather than a
	 * correctness requirement.
	 *
	 * @return bool Whether the lock was acquired.
	 */
	private static function acquire_lock(): bool {
		if ( false !== get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}

		set_transient( self::LOCK_TRANSIENT, time(), 5 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Switch away from the bundled theme if the site is stuck on it.
	 */
	private static function restore_active_theme(): void {
		if ( self::LEGACY_THEME !== get_stylesheet() ) {
			return;
		}

		foreach ( self::restore_candidates() as $slug ) {
			if ( '' === $slug || self::LEGACY_THEME === $slug ) {
				continue;
			}

			if ( wp_get_theme( $slug )->exists() ) {
				switch_theme( $slug );
				update_option( self::NOTICE_OPTION, $slug );

				return;
			}
		}
	}

	/**
	 * Themes to try when restoring, best first.
	 *
	 * @return string[] Theme slugs.
	 */
	private static function restore_candidates(): array {
		$candidates = array( Options::get_string( 'screenly_previous_theme' ) );

		if ( defined( 'WP_DEFAULT_THEME' ) ) {
			$candidates[] = WP_DEFAULT_THEME;
		}

		// Last resort: anything installed that is not ours, so the site renders.
		foreach ( array_keys( wp_get_themes() ) as $slug ) {
			$candidates[] = $slug;
		}

		return $candidates;
	}

	/**
	 * Carry a legacy logo setting into the current option.
	 */
	private static function migrate_logo_option(): void {
		if ( Options::get_int( Settings::LOGO_ID_OPTION ) > 0 ) {
			return;
		}

		foreach ( self::LEGACY_LOGO_OPTIONS as $option_name ) {
			$url = Options::get_string( $option_name );

			if ( '' === $url ) {
				continue;
			}

			$attachment_id = attachment_url_to_postid( $url );

			if ( $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ) {
				update_option( Settings::LOGO_ID_OPTION, $attachment_id );
			} else {
				// Unmatched in the media library; keep the URL rather than
				// silently dropping a logo the owner configured.
				update_option( Settings::LOGO_URL_OPTION, esc_url_raw( $url ) );
			}

			return;
		}
	}

	/**
	 * Remove the theme directory older versions copied into wp-content/themes.
	 *
	 * Guarded twice over: the theme must still be inactive, and its style header
	 * must identify it as the copy this plugin made. A site owner who edited that
	 * directory, or who happens to have an unrelated theme of the same slug, does
	 * not lose it.
	 */
	private static function remove_legacy_theme_directory(): void {
		$theme = wp_get_theme( self::LEGACY_THEME );

		if ( ! $theme->exists() ) {
			return;
		}

		if ( self::LEGACY_THEME === get_stylesheet() || self::LEGACY_THEME === get_template() ) {
			return;
		}

		if ( 'Screenly Cast' !== $theme->get( 'Name' ) || 'Screenly, Inc' !== $theme->get( 'Author' ) ) {
			return;
		}

		if ( ! function_exists( 'delete_theme' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		// delete_theme() handles filesystem credentials properly, which the
		// hand-rolled recursive unlink it replaces did not.
		delete_theme( self::LEGACY_THEME );
	}

	/**
	 * Delete options no longer used.
	 *
	 * Posts of the removed `screenly_cast` post type are deliberately left in
	 * place: they are user content, and deleting them silently during an update
	 * would be indefensible even though nothing renders them any more.
	 */
	private static function delete_legacy_options(): void {
		foreach ( array_merge( self::LEGACY_OPTIONS, self::LEGACY_LOGO_OPTIONS ) as $option_name ) {
			delete_option( $option_name );
		}
	}

	/**
	 * Tell the administrator the active theme was changed back.
	 */
	public static function maybe_show_notice(): void {
		$restored = Options::get_string( self::NOTICE_OPTION );

		if ( '' === $restored ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		delete_option( self::NOTICE_OPTION );

		$theme = wp_get_theme( $restored );
		$name  = $theme->exists() ? $theme->get( 'Name' ) : $restored;

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Screenly Cast:', 'screenly-cast' ),
			esc_html(
				sprintf(
					/* translators: %s: the theme name that was reactivated. */
					__(
						'This site was left using the bundled Screenly Cast theme by an earlier version of the plugin. The active theme has been switched back to %s. Signage rendering no longer changes your theme.',
						'screenly-cast'
					),
					$name
				)
			)
		);
	}
}
