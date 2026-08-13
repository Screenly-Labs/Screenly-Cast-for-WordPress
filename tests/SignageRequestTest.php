<?php
/**
 * Tests for signage request detection.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

namespace ScreenlyCast\Tests;

use ScreenlyCast\SignageRequest;
use WP_UnitTestCase;

/**
 * Covers when a request counts as signage, and what it knows about itself.
 */
final class SignageRequestTest extends WP_UnitTestCase {

	/**
	 * A bare `srly`, with no `=` and no value, is enough — the parameter is
	 * valueless by design, which is why detection tests for the key's presence
	 * rather than reading its value.
	 *
	 * Note the URLs here are built as explicit query strings rather than by
	 * appending to get_permalink(): the test environment uses plain permalinks, so
	 * get_permalink() already returns `?p=N` and appending `?srly` would produce
	 * `?p=N?srly`, in which `srly` is not a parameter at all.
	 */
	public function test_valueless_parameter_activates_signage(): void {
		$post_id = self::factory()->post->create();
		$this->go_to( '/?p=' . $post_id . '&srly' );

		$this->assertTrue( SignageRequest::is_requested() );
		$this->assertTrue( SignageRequest::current()->active );
	}

	/**
	 * `&srly` alongside other parameters works too, which is the documented
	 * multi-parameter form.
	 */
	public function test_parameter_alongside_others_activates_signage(): void {
		$post_id = self::factory()->post->create();
		$this->go_to(
			add_query_arg(
				array(
					'foo'  => '1',
					'srly' => '',
				),
				(string) get_permalink( $post_id )
			)
		);

		$this->assertTrue( SignageRequest::current()->active );
	}

	/**
	 * Without the parameter, nothing happens.
	 */
	public function test_absent_parameter_leaves_signage_inactive(): void {
		$post_id = self::factory()->post->create();
		$this->go_to( (string) get_permalink( $post_id ) );

		$this->assertFalse( SignageRequest::is_requested() );
		$this->assertFalse( SignageRequest::current()->active );
	}

	/**
	 * A singular request knows which entry it is showing.
	 */
	public function test_singular_request_captures_the_post_id(): void {
		$post_id = self::factory()->post->create();
		$this->go_to( '/?p=' . $post_id . '&srly' );

		$this->assertSame( $post_id, SignageRequest::current()->post_id );
		$this->assertFalse( SignageRequest::current()->is_attachment );
	}

	/**
	 * An attachment is flagged, because it composes differently: the image is the
	 * subject rather than a backdrop.
	 */
	public function test_attachment_request_is_flagged(): void {
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);
		$this->go_to( '/?attachment_id=' . $attachment_id . '&srly' );

		$request = SignageRequest::current();

		$this->assertTrue( $request->active );
		$this->assertTrue( $request->is_attachment );
		$this->assertSame( $attachment_id, $request->post_id );
	}

	/**
	 * A feed must stay a feed: signage rendering would corrupt the XML.
	 */
	public function test_feed_requests_are_never_signage(): void {
		$this->go_to( home_url( '/?feed=rss2&srly' ) );

		$this->assertFalse( SignageRequest::current()->active );
	}

	/**
	 * An archive request is signage but has no single post of its own.
	 */
	public function test_archive_request_has_no_post_id(): void {
		self::factory()->post->create();
		$this->go_to( home_url( '/?srly' ) );

		$request = SignageRequest::current();

		$this->assertTrue( $request->active );
		$this->assertNull( $request->post_id );
	}
}
