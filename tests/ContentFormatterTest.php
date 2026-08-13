<?php
/**
 * Tests for content shaping.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

namespace ScreenlyCast\Tests;

use ScreenlyCast\ContentFormatter;
use WP_UnitTestCase;

/**
 * Covers the tag allowlist, link flattening and the character clamp.
 */
final class ContentFormatterTest extends WP_UnitTestCase {

	/**
	 * The formatter under test.
	 *
	 * @var ContentFormatter
	 */
	private ContentFormatter $formatter;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->formatter = new ContentFormatter();
	}

	/**
	 * Force a character budget for one test.
	 *
	 * @param int $budget The budget to apply.
	 */
	private function set_budget( int $budget ): void {
		add_filter(
			'screenly_cast_character_budget',
			static function () use ( $budget ): int {
				return $budget;
			}
		);
	}

	/**
	 * Links collapse to their label rather than vanishing.
	 *
	 * This is the plugin's documented behaviour — there is no interaction on a
	 * screen, so a link is just words. It works because `a` is absent from the
	 * allowlist and kses keeps a disallowed tag's inner text.
	 */
	public function test_links_flatten_to_their_text(): void {
		$result = $this->formatter->format( '<p>See <a href="https://example.com">the schedule</a> today</p>' );

		$this->assertStringNotContainsString( '<a', $result );
		$this->assertStringNotContainsString( 'example.com', $result );
		$this->assertStringContainsString( 'the schedule', $result );
	}

	/**
	 * Presentational attributes are stripped, since the signage stylesheet owns
	 * presentation and a theme's classes mean nothing here.
	 */
	public function test_attributes_are_stripped(): void {
		$result = $this->formatter->format(
			'<p class="wp-block-paragraph" style="color:red" id="x">Text</p>'
		);

		$this->assertSame( '<p>Text</p>', $result );
	}

	/**
	 * Scripts, iframes and forms have no place on an unattended display.
	 *
	 * @dataProvider dangerous_markup_provider
	 *
	 * @param string $markup   The input markup.
	 * @param string $unwanted A substring that must not survive.
	 */
	public function test_unwanted_markup_is_removed( string $markup, string $unwanted ): void {
		$result = $this->formatter->format( $markup );

		$this->assertStringNotContainsString( $unwanted, $result );
	}

	/**
	 * Markup that must not survive shaping.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function dangerous_markup_provider(): array {
		return array(
			'script'  => array( '<p>Hi</p><script>alert(1)</script>', 'alert' ),
			'iframe'  => array( '<p>Hi</p><iframe src="https://x.test"></iframe>', 'iframe' ),
			'form'    => array( '<p>Hi</p><form><input name="a"></form>', '<form' ),
			'onclick' => array( '<p onclick="alert(1)">Hi</p>', 'onclick' ),
		);
	}

	/**
	 * Inline images go: the featured image is composed separately, and an inline
	 * image would break a fixed, unscrollable layout.
	 */
	public function test_inline_images_are_removed(): void {
		$result = $this->formatter->format( '<p>Before</p><p><img src="https://x.test/a.png" alt="a"></p>' );

		$this->assertStringNotContainsString( '<img', $result );
	}

	/**
	 * Blocks emptied by sanitising are dropped rather than left as blank lines.
	 */
	public function test_empty_blocks_are_dropped(): void {
		$result = $this->formatter->format(
			'<p>Real</p><p></p><p>&nbsp;</p><ul><li></li></ul><p><br /></p>'
		);

		$this->assertSame( '<p>Real</p>', $result );
	}

	/**
	 * Non-ASCII text must survive the clamp intact.
	 *
	 * The parser assumes ISO-8859-1 unless told otherwise, so this is the test that
	 * catches the encoding regression.
	 */
	public function test_utf8_survives_clamping(): void {
		$this->set_budget( 24 );

		$result = $this->formatter->format(
			'<p>Åäö über naïve café 日本語テキスト and a good deal more text after that</p>'
		);

		$this->assertStringContainsString( 'Åäö', $result );
		$this->assertStringNotContainsString( 'Ã', $result );
		$this->assertStringContainsString( '…', $result );
	}

	/**
	 * Four-byte characters must not be cut in half.
	 */
	public function test_emoji_survive_clamping(): void {
		$this->set_budget( 14 );

		$result = $this->formatter->format( '<p>Hello 🎉🎊 world and then some more</p>' );

		$this->assertStringContainsString( '🎉', $result );
		$this->assertStringNotContainsString( 'Ã', $result );
	}

	/**
	 * Clamping drops whole blocks and leaves well-formed markup.
	 */
	public function test_clamping_drops_whole_trailing_blocks(): void {
		$this->set_budget( 12 );

		$result = $this->formatter->format( '<p>Short one</p><p>This should be gone entirely</p>' );

		$this->assertStringNotContainsString( 'gone entirely', $result );
		$this->assertSame( substr_count( $result, '<p>' ), substr_count( $result, '</p>' ) );
	}

	/**
	 * Nested lists are a case a naive regex split would mangle.
	 */
	public function test_nested_lists_are_preserved(): void {
		$markup = '<ul><li>one<ul><li>deep</li></ul></li><li>two</li></ul>';

		$this->assertSame( $markup, $this->formatter->format( $markup ) );
	}

	/**
	 * Truncation lands on a word boundary rather than mid-word.
	 */
	public function test_truncation_respects_word_boundaries(): void {
		$this->set_budget( 14 );

		$result = $this->formatter->format( '<p>alpha beta gamma delta</p>' );

		$this->assertStringContainsString( '…', $result );
		$this->assertStringNotContainsString( 'gam…', $result );
	}

	/**
	 * Content already within budget is returned untouched.
	 */
	public function test_short_content_is_untouched(): void {
		$this->assertSame( '<p>Just a headline</p>', $this->formatter->format( '<p>Just a headline</p>' ) );
	}

	/**
	 * Entities must not be double-escaped on the round trip through the parser.
	 */
	public function test_entities_are_not_double_escaped(): void {
		$result = $this->formatter->format( '<p>Tom &amp; Jerry</p>' );

		$this->assertStringContainsString( '&amp;', $result );
		$this->assertStringNotContainsString( '&amp;amp;', $result );
	}

	/**
	 * Empty input stays empty rather than producing a stray wrapper.
	 */
	public function test_empty_input_returns_empty_string(): void {
		$this->assertSame( '', $this->formatter->format( '' ) );
		$this->assertSame( '', $this->formatter->format( "   \n  " ) );
	}

	/**
	 * The budget is filterable, and a nonsensical value falls back to the default.
	 */
	public function test_invalid_budget_falls_back_to_the_default(): void {
		$this->set_budget( -5 );

		$this->assertSame(
			ContentFormatter::DEFAULT_CHARACTER_BUDGET,
			ContentFormatter::character_budget()
		);
	}

	/**
	 * The allowlist is filterable, so a site can permit a tag it needs.
	 */
	public function test_allowed_tags_are_filterable(): void {
		add_filter(
			'screenly_cast_allowed_tags',
			static function ( array $tags ): array {
				$tags['mark'] = array();
				return $tags;
			}
		);

		$this->assertStringContainsString( '<mark>', $this->formatter->format( '<p><mark>Hi</mark></p>' ) );
	}
}
