<?php
/**
 * String translation value object.
 *
 * @package McLogiora
 */

namespace McLogiora\Strings;

use McLogiora\Relations\TranslationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * A translated value for one source string in one language.
 */
final class StringTranslation {
	/**
	 * Source string identifier.
	 *
	 * @var int
	 */
	private $string_id;

	/**
	 * Language code.
	 *
	 * @var string
	 */
	private $language_code;

	/**
	 * Translated text.
	 *
	 * @var string
	 */
	private $text;

	/**
	 * Translation status.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Constructor.
	 *
	 * @param int    $string_id Source string identifier.
	 * @param string $language_code Language code.
	 * @param string $text Translated text.
	 * @param string $status Translation status.
	 */
	public function __construct( $string_id, $language_code, $text, $status = TranslationStatus::DRAFT ) {
		$this->string_id     = (int) $string_id;
		$this->language_code = (string) $language_code;
		$this->text          = (string) $text;
		$this->status        = TranslationStatus::is_valid( $status ) ? (string) $status : TranslationStatus::DRAFT;
	}

	/**
	 * Returns the source string identifier.
	 *
	 * @return int
	 */
	public function string_id() {
		return $this->string_id;
	}

	/**
	 * Returns the language code.
	 *
	 * @return string
	 */
	public function language_code() {
		return $this->language_code;
	}

	/**
	 * Returns the translated text.
	 *
	 * @return string
	 */
	public function text() {
		return $this->text;
	}

	/**
	 * Returns the translation status.
	 *
	 * @return string
	 */
	public function status() {
		return $this->status;
	}
}
