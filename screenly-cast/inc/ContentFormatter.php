<?php
/**
 * Reduces post content to something a screen can show.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

namespace ScreenlyCast;

defined( 'ABSPATH' ) || exit;

/**
 * Shapes rendered post content for a non-interactive display.
 *
 * Three things happen, in order: everything outside a small tag allowlist is
 * removed, blocks left empty by that removal are dropped, and what remains is
 * clamped to a character budget so a 10,000-word post does not get shipped to a
 * player that can only show a screenful.
 */
final class ContentFormatter {

	/**
	 * Characters of text to keep by default.
	 *
	 * Roughly a screenful at signage reading distance. The plugin's own
	 * documentation suggests assuming ~250 characters will be *read*; this is
	 * deliberately more generous, because the client-side fitter trims to the
	 * actual viewport and this only needs to stop absurd payloads.
	 */
	public const DEFAULT_CHARACTER_BUDGET = 600;

	/**
	 * Smallest remaining budget worth truncating a block into.
	 *
	 * Without this, a block that crosses the budget with only a few characters
	 * left becomes a stub like "<p>T…</p>", visual noise that reads as a
	 * rendering fault on a screen. Below this threshold the block is dropped
	 * instead.
	 */
	private const MIN_TRUNCATION_REMAINDER = 24;

	/**
	 * The tags allowed to survive in signage content.
	 *
	 * Every entry maps to an empty attribute list, which is what strips class,
	 * style and id: the signage stylesheet owns presentation, and a theme's
	 * classes mean nothing here.
	 *
	 * Two omissions are deliberate and load-bearing:
	 *
	 * - `a` is absent. kses removes a disallowed tag but keeps its inner text,
	 *   so links flatten to their label, exactly the documented behavior, and
	 *   more precise than the previous strip_tags() approach.
	 * - `img` is absent. The featured image is composed separately by the
	 *   template; inline images would break a fixed, unscrollable layout.
	 *
	 * @return array<string, array<mixed>> A kses-shaped allowlist.
	 */
	public static function allowed_tags(): array {
		$tags = array(
			'p'          => array(),
			'br'         => array(),
			'em'         => array(),
			'i'          => array(),
			'strong'     => array(),
			'b'          => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'blockquote' => array(),
			'h1'         => array(),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'h5'         => array(),
			'h6'         => array(),
			'code'       => array(),
			'pre'        => array(),
			'ins'        => array(),
			'del'        => array(),
			'sub'        => array(),
			'sup'        => array(),
			'abbr'       => array(),
		);

		/**
		 * Filters the tags allowed in signage content.
		 *
		 * Expected to be a kses-shaped allowlist: `array<string, array<string,
		 * mixed>>`. Documented as mixed because a filter is third-party code and
		 * may return anything, the guard below is what makes that safe, and typing
		 * the parameter narrowly would let static analysis conclude the guard is
		 * dead code and stop checking it.
		 *
		 * @param mixed $tags A kses-shaped allowlist.
		 */
		$filtered = apply_filters( 'screenly_cast_allowed_tags', $tags );

		if ( ! is_array( $filtered ) ) {
			return $tags;
		}

		/*
		 * Validate the shape, not just the outer type. kses expects tag names as
		 * string keys mapping to attribute arrays; anything else in there would
		 * either be ignored silently or raise an error deep inside kses, so
		 * malformed entries are dropped here where the cause is obvious.
		 */
		$validated = array();
		foreach ( $filtered as $tag => $attributes ) {
			if ( is_string( $tag ) && is_array( $attributes ) ) {
				$validated[ $tag ] = $attributes;
			}
		}

		return array() === $validated ? $tags : $validated;
	}

	/**
	 * The character budget for signage content.
	 *
	 * @return int A positive character count.
	 */
	public static function character_budget(): int {
		/**
		 * Filters how many characters of text signage content keeps.
		 *
		 * Expected to be a positive integer. Documented as mixed for the same
		 * reason as the allowlist above: without the guard, a filter returning
		 * `'900'` would make this method return a string from an `int` return type
		 * and raise a TypeError under strict_types.
		 *
		 * @param mixed $budget A positive character count.
		 */
		$filtered = apply_filters( 'screenly_cast_character_budget', self::DEFAULT_CHARACTER_BUDGET );

		if ( ! is_numeric( $filtered ) ) {
			return self::DEFAULT_CHARACTER_BUDGET;
		}

		$budget = (int) $filtered;

		return $budget > 0 ? $budget : self::DEFAULT_CHARACTER_BUDGET;
	}

	/**
	 * Shape rendered content for display.
	 *
	 * @param string $html Rendered post content.
	 * @return string Signage-safe HTML.
	 */
	public function format( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$html = $this->remove_raw_text_elements( $html );
		$html = wp_kses( $html, self::allowed_tags() );
		$html = $this->remove_empty_blocks( $html );

		return $this->clamp( $html, self::character_budget() );
	}

	/**
	 * Remove elements whose contents are code, along with those contents.
	 *
	 * This has to run *before* kses, and it is not optional. kses drops a
	 * disallowed tag but keeps its inner text, which is exactly what makes links
	 * flatten to their label, and exactly wrong for a script or a style block,
	 * whose bodies would otherwise be printed on the screen as words. An admin can
	 * put a raw HTML block in a post, so this is reachable in practice.
	 *
	 * @param string $html Rendered content.
	 * @return string Content without code-bearing elements.
	 */
	private function remove_raw_text_elements( string $html ): string {
		$patterns = array(
			// Paired elements, contents included.
			'#<(script|style|template|noscript|svg|math|iframe|object|canvas)\b[^>]*>.*?</\1\s*>#is',

			/*
			 * An unclosed opener would otherwise leave the rest of the document as
			 * its "contents" once the tag itself is stripped.
			 *
			 * The (?<!/) is load-bearing: without it `[^>]*` happily consumes the
			 * trailing slash of a self-closing `<svg />` (which a Custom HTML block
			 * does produce) and `.*$` then eats every remaining character of the
			 * post. Properly closed elements are already handled by the pattern
			 * above, so this only needs to catch a genuinely dangling opener.
			 */
			'#<(script|style|template|noscript|svg|math|iframe|object|canvas)\b[^>]*(?<!/)>.*$#is',
		);

		foreach ( $patterns as $pattern ) {
			$cleaned = preg_replace( $pattern, '', $html );
			if ( is_string( $cleaned ) ) {
				$html = $cleaned;
			}
		}

		return $html;
	}

	/**
	 * Drop blocks that contain nothing after sanitizing.
	 *
	 * Removing an embed, figure or image leaves its wrapper behind, and an empty
	 * paragraph still occupies a line in a layout with no room to spare.
	 *
	 * @param string $html Sanitized HTML.
	 * @return string HTML without empty blocks.
	 */
	private function remove_empty_blocks( string $html ): string {
		$patterns = array(
			// A block whose content is only whitespace, nbsp or line breaks.
			'#<(p|blockquote|li|h[1-6]|pre|code)>(?:\s|&nbsp;|\xc2\xa0|<br\s*/?>)*</\1>#i',
			// A list whose items have all been removed.
			'#<(ul|ol)>\s*</\1>#i',
		);

		// Repeat until stable: emptying the items of a list empties the list.
		do {
			$total = 0;
			foreach ( $patterns as $pattern ) {
				$replaced = preg_replace( $pattern, '', $html, -1, $count );
				if ( null !== $replaced ) {
					$html   = $replaced;
					$total += $count;
				}
			}
		} while ( $total > 0 );

		return trim( $html );
	}

	/**
	 * Clamp content to a character budget without producing invalid markup.
	 *
	 * Whole blocks are dropped once the budget is spent, and the block that
	 * crosses the budget has its text truncated at a word boundary. Working at
	 * block granularity is what keeps the result well-formed.
	 *
	 * @param string $html   Sanitized HTML.
	 * @param int    $budget Characters of text to keep.
	 * @return string Clamped HTML.
	 */
	private function clamp( string $html, int $budget ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		if ( self::length( wp_strip_all_tags( $html ) ) <= $budget ) {
			return $html;
		}

		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );

		/*
		 * The XML declaration is what tells libxml the fragment is UTF-8. Without
		 * it libxml assumes ISO-8859-1 and mangles any non-ASCII text, and the
		 * usual mb_convert_encoding( ..., 'HTML-ENTITIES' ) workaround is
		 * deprecated as of PHP 8.2.
		 */
		$loaded = $dom->loadHTML(
			'<?xml encoding="utf-8" ?><div>' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $this->first_element( $dom );

		if ( false === $loaded || ! $root instanceof \DOMElement ) {
			// Never return more than asked for; fall back to plain text.
			return '<p>' . esc_html( self::truncate( wp_strip_all_tags( $html ), $budget ) ) . '</p>';
		}

		$used = 0;
		$node = $root->firstChild;

		while ( $node instanceof \DOMNode ) {
			$next   = $node->nextSibling;
			$length = self::length( $node->textContent );

			$remaining = $budget - $used;

			if ( $remaining <= 0 ) {
				$root->removeChild( $node );
			} elseif (
				$used + $length > $budget
				&& $used > 0
				&& $remaining < self::MIN_TRUNCATION_REMAINDER
			) {
				/*
				 * Too little budget left to say anything useful, so drop the
				 * block. Guarded on $used > 0: when nothing has been emitted yet
				 * the first block is always truncated instead, because dropping
				 * it would leave the screen blank.
				 */
				$root->removeChild( $node );
				$used = $budget;
			} elseif ( $used + $length > $budget ) {
				$truncated = self::truncate( $node->textContent, $remaining );

				if ( $node instanceof \DOMElement ) {
					while ( $node->firstChild instanceof \DOMNode ) {
						$node->removeChild( $node->firstChild );
					}

					$node->appendChild( $dom->createTextNode( $truncated ) );
				} else {
					/*
					 * Bare character data at the top level, which happens routinely:
					 * kses removes a disallowed wrapper but keeps its text, so a
					 * table, figure or div block collapses to a text node with no
					 * element around it, wpautop has already run by then, so
					 * nothing re-wraps it.
					 *
					 * The element branch above is wrong for these. A DOMText has no
					 * children, so the loop does nothing, and appendChild() on a text
					 * node neither throws nor changes its value, it silently does
					 * nothing, the full text survives, and the character budget is
					 * not enforced at all. Assigning nodeValue is what actually
					 * truncates it.
					 */
					$node->nodeValue = $truncated;
				}

				$used = $budget;
			} else {
				$used += $length;
			}

			$node = $next;
		}

		return $this->inner_html( $root );
	}

	/**
	 * The first element node of a document.
	 *
	 * The parser is given an XML declaration, so documentElement is not
	 * necessarily the wrapper div.
	 *
	 * @param \DOMDocument $dom The parsed document.
	 * @return \DOMElement|null The wrapper element, if present.
	 */
	private function first_element( \DOMDocument $dom ): ?\DOMElement {
		foreach ( $dom->childNodes as $node ) {
			if ( $node instanceof \DOMElement ) {
				return $node;
			}
		}

		return null;
	}

	/**
	 * Serialize the children of an element.
	 *
	 * @param \DOMElement $element The wrapper element.
	 * @return string The inner HTML.
	 */
	private function inner_html( \DOMElement $element ): string {
		$document = $element->ownerDocument;

		if ( ! $document instanceof \DOMDocument ) {
			return '';
		}

		$html = '';
		foreach ( $element->childNodes as $child ) {
			$html .= (string) $document->saveHTML( $child );
		}

		return trim( $html );
	}

	/**
	 * Truncate text at a word boundary.
	 *
	 * @param string $text  Plain text.
	 * @param int    $limit Maximum characters.
	 * @return string The truncated text, with an ellipsis when shortened.
	 */
	private static function truncate( string $text, int $limit ): string {
		$collapsed = preg_replace( '/\s+/u', ' ', $text );
		$text      = trim( is_string( $collapsed ) ? $collapsed : $text );

		if ( $limit <= 0 ) {
			return '';
		}

		if ( self::length( $text ) <= $limit ) {
			return $text;
		}

		$slice = self::substring( $text, 0, $limit );
		$break = self::last_space( $slice );

		if ( null !== $break && $break > 0 ) {
			$slice = self::substring( $slice, 0, $break );
		}

		return rtrim( $slice, " \t\n\r\0\x0B.,;:!?-" ) . '…';
	}

	/**
	 * Character count, preferring mbstring when available.
	 *
	 * @param string $value The string to measure.
	 * @return int The number of characters.
	 */
	private static function length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' );
		}

		return strlen( $value );
	}

	/**
	 * Substring, preferring mbstring when available.
	 *
	 * @param string $value  The source string.
	 * @param int    $start  Start offset.
	 * @param int    $length Length in characters.
	 * @return string The substring.
	 */
	private static function substring( string $value, int $start, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, $start, $length, 'UTF-8' );
		}

		return substr( $value, $start, $length );
	}

	/**
	 * Offset of the last space in a string.
	 *
	 * @param string $value The string to search.
	 * @return int|null The offset, or null when there is no space.
	 */
	private static function last_space( string $value ): ?int {
		$position = function_exists( 'mb_strrpos' )
			? mb_strrpos( $value, ' ', 0, 'UTF-8' )
			: strrpos( $value, ' ' );

		return false === $position ? null : $position;
	}
}
