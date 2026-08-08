<?php
/**
 * Media metadata translation value object.
 *
 * @package McLogiora
 */

namespace McLogiora\Media;

use McLogiora\Relations\TranslationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Language-specific textual metadata for one shared attachment.
 *
 * There is no translated file here, and deliberately so: the binary is shared
 * across every language.
 */
final class MediaTranslation {
	/**
	 * Attachment identifier.
	 *
	 * @var int
	 */
	private $attachment_id;

	/**
	 * Language code.
	 *
	 * @var string
	 */
	private $language_code;

	/**
	 * Translated title.
	 *
	 * @var string
	 */
	private $title;

	/**
	 * Translated alternative text.
	 *
	 * @var string
	 */
	private $alt_text;

	/**
	 * Translated caption.
	 *
	 * @var string
	 */
	private $caption;

	/**
	 * Translated description.
	 *
	 * @var string
	 */
	private $description;

	/**
	 * Translation status.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Constructor.
	 *
	 * @param int    $attachment_id Attachment identifier.
	 * @param string $language_code Language code.
	 * @param string $title Translated title.
	 * @param string $alt_text Translated alternative text.
	 * @param string $caption Translated caption.
	 * @param string $description Translated description.
	 * @param string $status Translation status.
	 */
	public function __construct( $attachment_id, $language_code, $title = '', $alt_text = '', $caption = '', $description = '', $status = TranslationStatus::DRAFT ) {
		$this->attachment_id = (int) $attachment_id;
		$this->language_code = (string) $language_code;
		$this->title         = (string) $title;
		$this->alt_text      = (string) $alt_text;
		$this->caption       = (string) $caption;
		$this->description   = (string) $description;
		$this->status        = TranslationStatus::is_valid( $status ) ? (string) $status : TranslationStatus::DRAFT;
	}

	/**
	 * Returns the attachment identifier.
	 *
	 * @return int
	 */
	public function attachment_id() {
		return $this->attachment_id;
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
	 * Returns the translated title.
	 *
	 * @return string
	 */
	public function title() {
		return $this->title;
	}

	/**
	 * Returns the translated alternative text.
	 *
	 * @return string
	 */
	public function alt_text() {
		return $this->alt_text;
	}

	/**
	 * Returns the translated caption.
	 *
	 * @return string
	 */
	public function caption() {
		return $this->caption;
	}

	/**
	 * Returns the translated description.
	 *
	 * @return string
	 */
	public function description() {
		return $this->description;
	}

	/**
	 * Returns the translation status.
	 *
	 * @return string
	 */
	public function status() {
		return $this->status;
	}

	/**
	 * Returns whether every translated field is empty.
	 *
	 * @return bool
	 */
	public function is_empty() {
		return '' === trim( $this->title . $this->alt_text . $this->caption . $this->description );
	}
}
