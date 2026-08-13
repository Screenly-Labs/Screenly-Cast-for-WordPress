<?php
/**
 * Describes whether the current request should render as signage.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

namespace ScreenlyCast;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable description of the signage state of one request.
 *
 * Resolving this once, into a value that cannot change, is the whole point. The
 * previous implementation re-derived signage state at several hook points and
 * mutated global site state (the active theme) as a side effect, so two requests
 * arriving together could interfere with each other. Nothing here writes.
 */
final class SignageRequest {

	/**
	 * Constructor.
	 *
	 * @param bool     $active        Whether this request renders as signage.
	 * @param int|null $post_id       The queried post, when the request is singular.
	 * @param bool     $is_attachment Whether the queried object is an attachment.
	 */
	private function __construct(
		public readonly bool $active,
		public readonly ?int $post_id,
		public readonly bool $is_attachment
	) {}

	/**
	 * A request that should render normally.
	 *
	 * @return self
	 */
	public static function inactive(): self {
		return new self( false, null, false );
	}

	/**
	 * Resolve the signage state of the current main query.
	 *
	 * @return self
	 */
	public static function current(): self {
		if ( is_admin() || is_feed() || is_robots() || is_trackback() ) {
			return self::inactive();
		}

		if ( ! self::is_requested() ) {
			return self::inactive();
		}

		$post_id = 0;
		if ( is_singular() || is_attachment() ) {
			$post_id = get_queried_object_id();
		}

		return new self( true, $post_id > 0 ? $post_id : null, is_attachment() );
	}

	/**
	 * Whether the signage query variable is present on the main query.
	 *
	 * The variable is valueless by design (`?srly` is enough), so presence is
	 * what matters rather than the value. That is why this uses
	 * array_key_exists() rather than get_query_var(), which cannot distinguish
	 * an absent variable from an empty one.
	 *
	 * @return bool
	 */
	public static function is_requested(): bool {
		global $wp_query;

		if ( ! $wp_query instanceof \WP_Query ) {
			return false;
		}

		return array_key_exists( SRLY_QUERY_VAR, $wp_query->query_vars );
	}
}
