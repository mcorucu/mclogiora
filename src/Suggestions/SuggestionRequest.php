<?php
/**
 * Translation suggestion request value object.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Describes one piece of text a user has explicitly asked to have suggested.
 *
 * Immutable on purpose. A request travels from the editor through the service
 * to a provider and is used again afterwards to verify what came back, so
 * anything able to mutate it in flight would make that verification worthless.
 *
 * The object carries the smallest set of facts a provider needs and nothing
 * else. It holds no post identifier, no user identifier, no site URL and no
 * relation data: a provider is given text and languages, so a provider cannot
 * leak what it was never told.
 */
final class SuggestionRequest {
	/**
	 * Plain text with no markup.
	 */
	const FORMAT_TEXT = 'text';

	/**
	 * Text that may contain inline HTML which must survive translation.
	 */
	const FORMAT_HTML = 'html';

	/**
	 * Text to translate.
	 *
	 * @var string
	 */
	private $source_text;

	/**
	 * Source language code.
	 *
	 * @var string
	 */
	private $source_language;

	/**
	 * Target language code.
	 *
	 * @var string
	 */
	private $target_language;

	/**
	 * Source locale, for providers that want a region.
	 *
	 * @var string
	 */
	private $source_locale;

	/**
	 * Target locale, for providers that want a region.
	 *
	 * @var string
	 */
	private $target_locale;

	/**
	 * Text format.
	 *
	 * @var string
	 */
	private $format;

	/**
	 * Surface identifier, such as `post_title`.
	 *
	 * @var string
	 */
	private $surface;

	/**
	 * Untranslated hint that helps disambiguate the source text.
	 *
	 * @var string
	 */
	private $context;

	/**
	 * Builds a request.
	 *
	 * @param string $source_text Text to translate.
	 * @param string $source_language Source language code.
	 * @param string $target_language Target language code.
	 * @param array  $options Optional locales, format, surface and context.
	 */
	public function __construct( $source_text, $source_language, $target_language, array $options = array() ) {
		$this->source_text     = (string) $source_text;
		$this->source_language = (string) $source_language;
		$this->target_language = (string) $target_language;
		$this->source_locale   = isset( $options['source_locale'] ) ? (string) $options['source_locale'] : '';
		$this->target_locale   = isset( $options['target_locale'] ) ? (string) $options['target_locale'] : '';
		$this->surface         = isset( $options['surface'] ) ? (string) $options['surface'] : '';
		$this->context         = isset( $options['context'] ) ? (string) $options['context'] : '';

		$format       = isset( $options['format'] ) ? (string) $options['format'] : self::FORMAT_TEXT;
		$this->format = self::FORMAT_HTML === $format ? self::FORMAT_HTML : self::FORMAT_TEXT;
	}

	/**
	 * Returns the text to translate.
	 *
	 * @return string
	 */
	public function source_text() {
		return $this->source_text;
	}

	/**
	 * Returns the source language code.
	 *
	 * @return string
	 */
	public function source_language() {
		return $this->source_language;
	}

	/**
	 * Returns the target language code.
	 *
	 * @return string
	 */
	public function target_language() {
		return $this->target_language;
	}

	/**
	 * Returns the source locale, or an empty string.
	 *
	 * @return string
	 */
	public function source_locale() {
		return $this->source_locale;
	}

	/**
	 * Returns the target locale, or an empty string.
	 *
	 * @return string
	 */
	public function target_locale() {
		return $this->target_locale;
	}

	/**
	 * Returns the text format.
	 *
	 * @return string
	 */
	public function format() {
		return $this->format;
	}

	/**
	 * Returns whether the text may contain markup.
	 *
	 * @return bool
	 */
	public function is_html() {
		return self::FORMAT_HTML === $this->format;
	}

	/**
	 * Returns the surface identifier.
	 *
	 * @return string
	 */
	public function surface() {
		return $this->surface;
	}

	/**
	 * Returns the untranslated context hint.
	 *
	 * @return string
	 */
	public function context() {
		return $this->context;
	}

	/**
	 * Returns a copy with different text, keeping every other fact.
	 *
	 * Used by the placeholder shield, which rewrites the text into a masked
	 * form before a provider sees it. Returning a copy rather than mutating
	 * keeps the original available to verify the result against.
	 *
	 * @param string $source_text Replacement text.
	 * @return self
	 */
	public function with_source_text( $source_text ) {
		$clone = clone $this;

		$clone->source_text = (string) $source_text;

		return $clone;
	}
}
