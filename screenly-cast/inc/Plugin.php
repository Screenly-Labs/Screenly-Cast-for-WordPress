<?php
/**
 * Plugin wiring.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

namespace ScreenlyCast;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's hooks.
 *
 * Deliberately thin. The previous Plugin class installed a theme into
 * wp-content/themes on every single page load and threw exceptions during
 * bootstrap; this one only attaches hooks and does no work at load time.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Private constructor; use instance().
	 */
	private function __construct() {}

	/**
	 * The shared plugin instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Attach the plugin's hooks.
	 */
	public function boot(): void {
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'pre_get_posts', array( $this, 'limit_signage_query' ) );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_player' ), 0 );
		add_action( 'template_redirect', array( $this, 'maybe_render_as_signage' ), 1 );

		/*
		 * The legacy repair switches the active theme, so it must never run from
		 * a front-end request — doing exactly that on the front end is the bug
		 * this rewrite removes. Admin and WP-CLI only.
		 */
		add_action( 'admin_init', array( Migration::class, 'maybe_run' ) );
		add_action( 'admin_notices', array( Migration::class, 'maybe_show_notice' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			add_action( 'init', array( Migration::class, 'maybe_run' ) );
		}

		( new Settings() )->register();

		add_filter(
			'plugin_action_links_' . plugin_basename( SRLY_PLUGIN_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Make `srly` a recognised public query variable.
	 *
	 * @param string[] $vars The registered query variables.
	 * @return string[] The query variables including ours.
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = SRLY_QUERY_VAR;

		return $vars;
	}

	/**
	 * Show a single entry on signage requests.
	 *
	 * A screen displays one thing at a time, so an archive or the blog home
	 * renders its most recent entry rather than a list.
	 *
	 * @param \WP_Query $query The query being prepared.
	 */
	public function limit_signage_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( ! array_key_exists( SRLY_QUERY_VAR, $query->query_vars ) ) {
			return;
		}

		$query->set( 'posts_per_page', 1 );
		$query->set( 'ignore_sticky_posts', true );
	}

	/**
	 * Send a detected signage player to the signage URL for what it asked for.
	 *
	 * A redirect rather than rendering signage in place, and the reason is caching.
	 * Returning different HTML for the same URL depending on request headers means
	 * any page cache or CDN in front of the site — and most WordPress sites have one
	 * — serves whichever version it happened to cache first to everybody. A screen
	 * would get the normal theme, or worse, a human would get the signage render.
	 * `Vary` is not a dependable answer: CDNs routinely strip or ignore it.
	 *
	 * Redirecting keeps one URL per variant, which is the property that made `?srly`
	 * work in the first place: signage HTML caches under `?srly`, ordinary HTML
	 * caches under the bare URL, and neither can be served to the wrong audience.
	 * Only the redirect itself must dodge the cache, and it is a few bytes.
	 *
	 * The cost is one extra round trip, which is nothing next to a signage dwell
	 * time of ten seconds or more.
	 */
	public function maybe_redirect_player(): void {
		// Already signage: nothing to do, and redirecting again would loop.
		if ( SignageRequest::is_requested() ) {
			return;
		}

		if ( ! Settings::auto_detect_enabled() ) {
			return;
		}

		if ( ! $this->is_redirectable_request() ) {
			return;
		}

		if ( ! PlayerDetector::is_signage_player() ) {
			return;
		}

		// nocache_headers() so an intermediary does not cache the redirect itself and
		// then send humans to the signage URL.
		nocache_headers();
		wp_safe_redirect( add_query_arg( SRLY_QUERY_VAR, '' ), 302 );
		exit;
	}

	/**
	 * Whether this request is one it is safe to redirect.
	 *
	 * Logged-in users are excluded so an editor previewing their own site is never
	 * bounced into a signage view, and because a false positive is far more annoying
	 * for someone working on the site than for an anonymous visitor.
	 *
	 * @return bool
	 */
	private function is_redirectable_request(): bool {
		if ( is_admin() || wp_doing_ajax() || is_user_logged_in() ) {
			return false;
		}

		if ( is_feed() || is_robots() || is_trackback() || is_404() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		return 'GET' === strtoupper( Request::server_string( 'REQUEST_METHOD' ) );
	}

	/**
	 * Hand the request to the signage renderer when it asked for signage.
	 */
	public function maybe_render_as_signage(): void {
		$request = SignageRequest::current();

		if ( ! $request->active ) {
			Renderer::clear();

			return;
		}

		( new Renderer( $request ) )->register();
	}

	/**
	 * Add a Settings link to the plugin row.
	 *
	 * @param string[] $links The existing action links.
	 * @return string[] The action links with ours first.
	 */
	public function add_settings_link( array $links ): array {
		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . Settings::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'screenly-cast' )
		);

		array_unshift( $links, $link );

		return $links;
	}

	/**
	 * Activation hook.
	 *
	 * Runs the legacy repair rather than stamping a version, so activating this
	 * build over an old install is itself a valid upgrade path. Migration is
	 * idempotent and no-ops on a fresh site.
	 */
	public static function on_activate(): void {
		Migration::maybe_run();
	}

	/**
	 * Deactivation hook.
	 *
	 * Note what this does *not* do: the previous implementation's deactivate()
	 * switched the site's theme and issued a redirect, and it ran on ordinary
	 * front-end page views rather than on deactivation. User data is left alone
	 * here; uninstall.php handles removal.
	 */
	public static function on_deactivate(): void {
		flush_rewrite_rules();
	}
}
