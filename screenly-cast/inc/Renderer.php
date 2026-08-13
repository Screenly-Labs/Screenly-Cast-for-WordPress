<?php
/**
 * Renders a signage request.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

namespace ScreenlyCast;

defined( 'ABSPATH' ) || exit;

/**
 * Takes over rendering for one signage request.
 *
 * Everything here is scoped to the current request. The plugin supplies its own
 * template and its own stylesheet and leaves the site's active theme completely
 * untouched, in contrast to the previous implementation, which called
 * switch_theme() on the whole site in response to an anonymous GET and switched
 * it back when a non-signage request arrived.
 */
final class Renderer {

	/**
	 * The renderer handling the current request, for the template to reach.
	 *
	 * @var self|null
	 */
	private static ?self $current = null;

	/**
	 * Memoised result of image_id(), since resolving it may parse post content.
	 *
	 * @var int|null
	 */
	private ?int $resolved_image_id = null;

	/**
	 * Constructor.
	 *
	 * @param SignageRequest $request The resolved signage request.
	 */
	public function __construct( private readonly SignageRequest $request ) {}

	/**
	 * The renderer for the current request, if this is a signage request.
	 *
	 * @return self|null
	 */
	public static function current(): ?self {
		return self::$current;
	}

	/**
	 * Forget the current renderer.
	 *
	 * A request that is not signage must not report one, which matters because
	 * this is static: within a single PHP process (a test run, or a CLI process
	 * handling more than one query), a stale renderer would otherwise linger.
	 */
	public static function clear(): void {
		self::$current = null;
	}

	/**
	 * Take over rendering for this request.
	 */
	public function register(): void {
		self::$current = $this;

		add_filter( 'template_include', array( $this, 'template' ), PHP_INT_MAX );
		add_filter( 'redirect_canonical', array( $this, 'allow_attachment_render' ) );
		add_filter( 'show_admin_bar', '__return_false', PHP_INT_MAX );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_filter( 'the_content', array( $this, 'format_content' ), PHP_INT_MAX );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), PHP_INT_MAX );

		$this->quieten_document_head();
	}

	/**
	 * Render through the plugin's own template.
	 *
	 * @param string $template The template WordPress resolved.
	 * @return string The template to use.
	 */
	public function template( string $template ): string {
		/*
		 * Removed here rather than alongside the other head cleanup: with a block
		 * theme, core adds this inside locate_block_template(), which runs as part
		 * of the template_include chain, after template_redirect, where the rest
		 * of the cleanup happens. Removing it any earlier silently does nothing,
		 * and the document ends up with two viewport tags.
		 *
		 * The priority matters too: core hooks it at 0, and remove_action() only
		 * matches the priority it was added with.
		 */
		remove_action( 'wp_head', '_block_template_viewport_meta_tag', 0 );

		$candidate = SRLY_PLUGIN_DIR . 'templates/signage.php';

		return is_readable( $candidate ) ? $candidate : $template;
	}

	/**
	 * Keep attachment URLs renderable in signage mode.
	 *
	 * Since WordPress 6.4 the `wp_attachment_pages_enabled` option is off on new
	 * installs, and redirect_canonical() sends an attachment URL straight to the
	 * image file. That silently killed a documented feature of this plugin,
	 * appending `?srly` to a media URL to cast it, because the request never
	 * reaches a template at all; it just returns the raw file.
	 *
	 * Cancelling the canonical redirect for signage requests on attachments
	 * restores it, without asking the site to turn attachment pages back on
	 * globally for ordinary visitors.
	 *
	 * @param string|false $redirect_url The URL core wants to redirect to.
	 * @return string|false The URL, or false to stay put.
	 */
	public function allow_attachment_render( string|false $redirect_url ): string|false {
		if ( $this->request->is_attachment ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Shape post content for a screen.
	 *
	 * Typed `mixed` rather than `string` on purpose. The value reaching a
	 * `the_content` filter is a string in practice, but any other plugin may have
	 * filtered it to something else first, and under strict_types a declared
	 * `string` would turn that into a fatal error on the front end.
	 *
	 * @param mixed $content The rendered content.
	 * @return string Signage-safe content.
	 */
	public function format_content( mixed $content ): string {
		// Narrowed rather than cast: casting an array to string yields the literal
		// text "Array", which would then be rendered on a screen.
		$html = is_scalar( $content ) ? (string) $content : '';

		return ( new ContentFormatter() )->format( $html );
	}

	/**
	 * Enqueue the signage stylesheet and fitter, and nothing else.
	 */
	public function enqueue_assets(): void {
		$this->dequeue_other_assets();

		$css = SRLY_PLUGIN_DIR . 'assets/dist/signage.css';
		if ( is_readable( $css ) ) {
			wp_enqueue_style(
				'screenly-cast',
				SRLY_PLUGIN_URL . 'assets/dist/signage.css',
				array(),
				SRLY_VERSION
			);
		}

		$js = SRLY_PLUGIN_DIR . 'assets/dist/signage.js';
		if ( is_readable( $js ) ) {
			wp_enqueue_script(
				'screenly-cast',
				SRLY_PLUGIN_URL . 'assets/dist/signage.js',
				array(),
				SRLY_VERSION,
				true
			);
		}
	}

	/**
	 * Remove every other enqueued style and script.
	 *
	 * A signage render must not inherit the active theme's CSS, and every extra
	 * request is one a player on a slow connection has to make before the screen
	 * is right. Runs at PHP_INT_MAX so it sees everything other code enqueued.
	 */
	private function dequeue_other_assets(): void {
		$styles = wp_styles();
		// Copy the queue: dequeueing mutates it.
		foreach ( array_values( $styles->queue ) as $handle ) {
			if ( 'screenly-cast' !== $handle ) {
				wp_dequeue_style( $handle );
			}
		}

		$scripts = wp_scripts();
		foreach ( array_values( $scripts->queue ) as $handle ) {
			if ( 'screenly-cast' !== $handle ) {
				wp_dequeue_script( $handle );
			}
		}
	}

	/**
	 * Strip the parts of wp_head() that mean nothing on a screen.
	 *
	 * Emoji detection, feed links, oEmbed discovery, the generator tag, shortlinks
	 * and adjacent-post links are all either dead weight or extra requests. Block
	 * theme global styles go too, since the theme's design is not in play here.
	 */
	private function quieten_document_head(): void {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_head', 'wp_enqueue_emoji_styles' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'feed_links', 2 );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_action( 'wp_head', 'wp_resource_hints', 2 );
		remove_action( 'wp_head', 'rest_output_link_wp_head' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head' );

		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
		remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_classic_theme_styles' );
		remove_action( 'wp_body_open', 'wp_global_styles_render_svg_filters' );

		/*
		 * The following are printed directly by core rather than enqueued, so the
		 * dequeue pass above cannot see them. Each was found leaking into a real
		 * signage render:
		 *
		 * - The block-template viewport tag duplicates the one this template
		 *   already emits, and ours is the canonical signage form.
		 * - Theme font faces pull the *theme's* webfonts (Manrope, Fira Code and
		 *   the like) into a document that uses none of them: two wasted font
		 *   downloads on a player that may be on a slow link.
		 * - Customizer CSS and the custom-logo styles are theme design, which has
		 *   no bearing here.
		 * - Speculation rules prefetch links, and there is nothing to click.
		 * - The site icon is several icon requests for a favicon no one can see.
		 */
		remove_action( 'wp_head', '_block_template_viewport_meta_tag' );
		remove_action( 'wp_head', 'wp_print_font_faces', 50 );
		remove_action( 'wp_head', 'wp_print_font_faces_from_style_variations', 50 );
		remove_action( 'wp_head', 'wp_custom_css_cb', 101 );
		remove_action( 'wp_head', '_custom_logo_header_styles' );
		remove_action( 'wp_head', 'locale_stylesheet' );
		remove_action( 'wp_head', 'wp_site_icon', 99 );
		remove_action( 'wp_footer', 'wp_print_speculation_rules' );
	}

	/**
	 * Extra body classes describing the composition.
	 *
	 * The layout depends on whether there is an image to compose against, so the
	 * stylesheet needs to know.
	 *
	 * @return string[] Class names.
	 */
	public function body_class_names(): array {
		$classes = array( 'srly' );

		if ( $this->has_featured_image() ) {
			$classes[] = 'srly--has-figure';
		} else {
			$classes[] = 'srly--text-only';
		}

		if ( $this->request->is_attachment ) {
			$classes[] = 'srly--attachment';
		}

		return $classes;
	}

	/**
	 * Whether this request has an image to compose against.
	 *
	 * @return bool
	 */
	public function has_featured_image(): bool {
		return $this->image_id() > 0;
	}

	/**
	 * The attachment to compose the render against.
	 *
	 * Resolved in priority order: the attachment itself when the request *is* an
	 * attachment, then the post's featured image, then the first image in the post
	 * content.
	 *
	 * That last fallback exists because of a long-standing report: a user adds an
	 * image with the "Add Media" button, sees it on the site, then gets a text-only
	 * signage render because the plugin only ever looked at the featured image.
	 * From the author's side they added an image to the post and expect to see it,
	 * and a signage render with no picture when the post has one is simply wrong.
	 *
	 * @return int An attachment ID, or 0 when the entry has no usable image.
	 */
	private function image_id(): int {
		if ( null !== $this->resolved_image_id ) {
			return $this->resolved_image_id;
		}

		$this->resolved_image_id = 0;

		/*
		 * The post in the loop, not the one on the request.
		 *
		 * SignageRequest only records a post_id for singular and attachment
		 * requests, but the template runs the loop regardless, so `?srly` on the
		 * blog index or a category archive, which is an obvious thing to point a
		 * screen at, rendered the latest post's title, date and body while
		 * silently dropping its image and classing the body text-only. The same
		 * post via its permalink got the full photographic composition.
		 */
		$post_id = $this->request->post_id;

		if ( null === $post_id ) {
			$in_loop = get_the_ID();
			$post_id = is_int( $in_loop ) && $in_loop > 0 ? $in_loop : null;
		}

		if ( null === $post_id ) {
			return 0;
		}

		if ( $this->request->is_attachment ) {
			$this->resolved_image_id = wp_attachment_is_image( $post_id ) ? $post_id : 0;

			return $this->resolved_image_id;
		}

		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( is_int( $thumbnail_id ) && $thumbnail_id > 0 ) {
			$this->resolved_image_id = $thumbnail_id;

			return $this->resolved_image_id;
		}

		$this->resolved_image_id = $this->first_content_image_id( $post_id );

		return $this->resolved_image_id;
	}

	/**
	 * The first usable image in a post's content.
	 *
	 * Uses WP_HTML_Tag_Processor rather than a regex over the markup, so malformed
	 * or unusually attributed HTML cannot throw it off. The `wp-image-<id>` class
	 * WordPress adds is preferred; a bare src is matched back to the media library
	 * as a fallback, which also means images hotlinked from elsewhere are ignored
	 * rather than rendered.
	 *
	 * @param int $post_id The post to inspect.
	 * @return int An attachment ID, or 0.
	 */
	private function first_content_image_id( int $post_id ): int {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || '' === $post->post_content ) {
			return 0;
		}

		$processor = new \WP_HTML_Tag_Processor( $post->post_content );

		while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			$class = $processor->get_attribute( 'class' );
			if ( is_string( $class ) && 1 === preg_match( '/\bwp-image-(\d+)\b/', $class, $matches ) ) {
				$candidate = (int) $matches[1];
				if ( wp_attachment_is_image( $candidate ) ) {
					return $candidate;
				}
			}

			$src = $processor->get_attribute( 'src' );
			if ( is_string( $src ) && '' !== $src ) {
				$candidate = attachment_url_to_postid( $src );
				if ( $candidate > 0 && wp_attachment_is_image( $candidate ) ) {
					return $candidate;
				}
			}
		}

		return 0;
	}

	/**
	 * The featured image markup.
	 *
	 * An <img> with a srcset rather than a CSS background-image: it lets a player
	 * on a constrained connection pick a smaller file, and it removes the inline
	 * style attribute the previous theme hand-assembled, the source of one of
	 * its escaping bugs.
	 *
	 * @return string Image markup, or an empty string when there is none.
	 */
	public function featured_image_markup(): string {
		$image_id = $this->image_id();

		if ( 0 === $image_id ) {
			return '';
		}

		return wp_get_attachment_image(
			$image_id,
			'full',
			false,
			array( 'class' => 'srly-figure__image' )
		);
	}

	/**
	 * Whether to show the publication date.
	 *
	 * Requested repeatedly: a notice board showing "posted three weeks ago" makes
	 * current information look stale.
	 *
	 * @return bool
	 */
	public function show_date(): bool {
		/**
		 * Filters whether the publication date appears on signage renders.
		 *
		 * @param mixed $show Whether to show the date.
		 */
		$filtered = apply_filters( 'screenly_cast_show_date', true );

		/*
		 * A plain cast rather than enumerating falsy values. The enumeration this
		 * replaces missed the string '0', which is exactly what WordPress hands
		 * back from get_option() for a saved-off boolean, so a site wiring this
		 * filter to an option got the opposite of what it asked for.
		 */
		return (bool) $filtered;
	}

	/**
	 * The configured brand logo markup.
	 *
	 * @return string Logo markup, or an empty string when none is configured.
	 */
	public function logo_markup(): string {
		return Settings::logo_markup();
	}
}
