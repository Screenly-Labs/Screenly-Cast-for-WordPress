<?php
/**
 * Tests for the signage renderer.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

namespace ScreenlyCast\Tests;

use ScreenlyCast\Renderer;
use WP_UnitTestCase;

/**
 * Covers the render path, and above all that it leaves the site alone.
 */
final class RendererTest extends WP_UnitTestCase {

	/**
	 * Visit a URL in signage mode and run the template hooks.
	 *
	 * WordPress prepares the main query in go_to() but does not fire
	 * template_redirect, which is where the renderer attaches itself.
	 *
	 * @param string $url The URL to visit.
	 */
	private function go_to_signage( string $url ): void {
		$this->go_to( add_query_arg( SRLY_QUERY_VAR, '', $url ) );
		do_action( 'template_redirect' );
	}

	/**
	 * The regression this rewrite exists to prevent.
	 *
	 * Versions up to 1.0.5 called switch_theme() on the whole site in response to
	 * a `?srly` request, so an anonymous visitor changed what every other visitor
	 * saw. A signage render must not touch the active theme.
	 */
	public function test_signage_request_leaves_the_active_theme_alone(): void {
		$before_stylesheet = get_stylesheet();
		$before_template   = get_template();

		$post_id = self::factory()->post->create();
		$this->go_to_signage( (string) get_permalink( $post_id ) );

		$this->assertSame( $before_stylesheet, get_stylesheet(), 'The stylesheet changed.' );
		$this->assertSame( $before_template, get_template(), 'The template changed.' );
	}

	/**
	 * Nor may it leave the legacy option behind that drove the old switching.
	 */
	public function test_signage_request_writes_no_theme_options(): void {
		$post_id = self::factory()->post->create();
		$this->go_to_signage( (string) get_permalink( $post_id ) );

		$this->assertFalse( get_option( 'screenly_previous_theme', false ) );
	}

	/**
	 * A signage request renders through the plugin's own template.
	 */
	public function test_signage_request_uses_the_plugin_template(): void {
		$post_id = self::factory()->post->create();
		$this->go_to_signage( (string) get_permalink( $post_id ) );

		$template = apply_filters( 'template_include', 'theme/index.php' );

		$this->assertIsString( $template );
		$this->assertStringEndsWith( 'templates/signage.php', $template );
	}

	/**
	 * An ordinary request is untouched.
	 */
	public function test_normal_request_is_not_intercepted(): void {
		$post_id = self::factory()->post->create();
		$this->go_to( (string) get_permalink( $post_id ) );
		do_action( 'template_redirect' );

		$this->assertNull( Renderer::current() );
		$this->assertSame( 'theme/index.php', apply_filters( 'template_include', 'theme/index.php' ) );
	}

	/**
	 * The body classes describe the composition, because the layout depends on it.
	 */
	public function test_body_classes_distinguish_image_from_text_only(): void {
		$post_id = self::factory()->post->create();
		$this->go_to_signage( (string) get_permalink( $post_id ) );

		$renderer = Renderer::current();
		$this->assertInstanceOf( Renderer::class, $renderer );
		$this->assertContains( 'srly--text-only', $renderer->body_class_names() );
		$this->assertNotContains( 'srly--has-figure', $renderer->body_class_names() );
	}

	/**
	 * A post with a featured image composes against it.
	 */
	public function test_featured_image_is_detected(): void {
		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg',
			$post_id
		);
		set_post_thumbnail( $post_id, $attachment_id );

		$this->go_to_signage( (string) get_permalink( $post_id ) );

		$renderer = Renderer::current();
		$this->assertInstanceOf( Renderer::class, $renderer );
		$this->assertTrue( $renderer->has_featured_image() );
		$this->assertContains( 'srly--has-figure', $renderer->body_class_names() );
		$this->assertStringContainsString( 'srly-figure__image', $renderer->featured_image_markup() );
	}

	/**
	 * A post with no featured image falls back to its first content image.
	 *
	 * Closes a long-standing report: an author adds an image with "Add Media",
	 * sees it on the site, and used to get a text-only signage render because only
	 * the featured image was ever consulted.
	 */
	public function test_first_content_image_is_used_when_there_is_no_thumbnail(): void {
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);
		$post_id       = self::factory()->post->create(
			array(
				'post_content' => sprintf(
					'<p>Words.</p><figure><img class="wp-image-%1$d" src="%2$s" alt="" /></figure>',
					$attachment_id,
					esc_url( (string) wp_get_attachment_url( $attachment_id ) )
				),
			)
		);

		$this->assertSame( 0, get_post_thumbnail_id( $post_id ), 'No thumbnail expected.' );

		$this->go_to_signage( (string) get_permalink( $post_id ) );

		$renderer = Renderer::current();
		$this->assertInstanceOf( Renderer::class, $renderer );
		$this->assertTrue( $renderer->has_featured_image() );
		$this->assertStringContainsString( 'srly-figure__image', $renderer->featured_image_markup() );
		$this->assertContains( 'srly--has-figure', $renderer->body_class_names() );
	}

	/**
	 * An image hotlinked from elsewhere is not treated as the entry's image.
	 */
	public function test_remote_content_image_is_not_used(): void {
		$post_id = self::factory()->post->create(
			array( 'post_content' => '<p><img src="https://example.test/remote.png" alt="" /></p>' )
		);

		$this->go_to_signage( (string) get_permalink( $post_id ) );

		$renderer = Renderer::current();
		$this->assertInstanceOf( Renderer::class, $renderer );
		$this->assertFalse( $renderer->has_featured_image() );
	}

	/**
	 * The date can be suppressed, which a notice board wants: "posted three weeks
	 * ago" makes current information look stale.
	 */
	public function test_date_can_be_filtered_off(): void {
		$post_id = self::factory()->post->create();
		$this->go_to_signage( (string) get_permalink( $post_id ) );

		$renderer = Renderer::current();
		$this->assertInstanceOf( Renderer::class, $renderer );
		$this->assertTrue( $renderer->show_date() );

		add_filter( 'screenly_cast_show_date', '__return_false' );

		$this->assertFalse( $renderer->show_date() );
	}

	/**
	 * Signage requests must not be indexed: they are a display view, not content.
	 */
	public function test_signage_request_is_noindex(): void {
		$post_id = self::factory()->post->create();
		$this->go_to_signage( (string) get_permalink( $post_id ) );

		$robots = apply_filters( 'wp_robots', array() );

		$this->assertArrayHasKey( 'noindex', $robots );
	}

	/**
	 * A screen shows one entry, so an archive request is narrowed to one post.
	 */
	public function test_archive_requests_are_narrowed_to_one_entry(): void {
		self::factory()->post->create_many( 5 );

		$this->go_to_signage( home_url( '/' ) );

		$this->assertSame( 1, get_query_var( 'posts_per_page' ) );
	}
}
