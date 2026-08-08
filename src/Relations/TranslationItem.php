<?php
/**
 * Translation item entity.
 *
 * @package McLogiora
 */

namespace McLogiora\Relations;

defined( 'ABSPATH' ) || exit;

/**
 * Describes one language-specific item inside a translation group.
 */
final class TranslationItem {
	/**
	 * Content type.
	 *
	 * @var string
	 */
	private $content_type;

	/**
	 * Object identifier or future external key.
	 *
	 * @var string
	 */
	private $object_key;

	/**
	 * Language code.
	 *
	 * @var string
	 */
	private $language_code;

	/**
	 * Translation status.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Whether this item is the original/source.
	 *
	 * @var bool
	 */
	private $original;

	/**
	 * Future source content hash.
	 *
	 * @var string
	 */
	private $source_hash;

	/**
	 * Future hash of source content at translation time.
	 *
	 * @var string
	 */
	private $translated_source_hash;

	/**
	 * Future source modified timestamp.
	 *
	 * @var int
	 */
	private $source_modified;

	/**
	 * Future translation modified timestamp.
	 *
	 * @var int
	 */
	private $translation_modified;

	/**
	 * Constructor.
	 *
	 * @param string $content_type Content type.
	 * @param string $object_key Object key.
	 * @param string $language_code Language code.
	 * @param string $status Translation status.
	 * @param bool   $original Whether this is the original/source item.
	 * @param string $source_hash Future source hash.
	 * @param string $translated_source_hash Future translated source hash.
	 * @param int    $source_modified Future source modified timestamp.
	 * @param int    $translation_modified Future translation modified timestamp.
	 */
	public function __construct( $content_type, $object_key, $language_code, $status, $original = false, $source_hash = '', $translated_source_hash = '', $source_modified = 0, $translation_modified = 0 ) {
		$content_type                 = sanitize_key( (string) $content_type );
		$this->content_type           = ContentType::is_valid( $content_type ) ? $content_type : ContentType::FUTURE;
		$this->object_key             = sanitize_text_field( (string) $object_key );
		$this->language_code          = sanitize_key( $language_code );
		$this->status                 = TranslationStatus::is_valid( $status ) ? $status : TranslationStatus::MISSING;
		$this->original               = (bool) $original;
		$this->source_hash            = sanitize_text_field( (string) $source_hash );
		$this->translated_source_hash = sanitize_text_field( (string) $translated_source_hash );
		$this->source_modified        = absint( $source_modified );
		$this->translation_modified   = absint( $translation_modified );
	}

	/**
	 * Returns the content type.
	 *
	 * @return string
	 */
	public function content_type() {
		return $this->content_type;
	}

	/**
	 * Returns the generic object type.
	 *
	 * @return string
	 */
	public function object_type() {
		return $this->content_type();
	}

	/**
	 * Returns the object key.
	 *
	 * @return string
	 */
	public function object_key() {
		return $this->object_key;
	}

	/**
	 * Returns the generic object ID.
	 *
	 * @return string
	 */
	public function object_id() {
		return $this->object_key();
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
	 * Returns the status.
	 *
	 * @return string
	 */
	public function status() {
		return $this->status;
	}

	/**
	 * Returns whether this is the original item.
	 *
	 * @return bool
	 */
	public function is_original() {
		return $this->original || TranslationStatus::ORIGINAL === $this->status;
	}

	/**
	 * Returns whether this is a target translation item.
	 *
	 * @return bool
	 */
	public function is_target() {
		return ! $this->is_original();
	}

	/**
	 * Returns the future source hash.
	 *
	 * @return string
	 */
	public function source_hash() {
		return $this->source_hash;
	}

	/**
	 * Returns the future translated source hash.
	 *
	 * @return string
	 */
	public function translated_source_hash() {
		return $this->translated_source_hash;
	}

	/**
	 * Returns the future source modified timestamp.
	 *
	 * @return int
	 */
	public function source_modified() {
		return $this->source_modified;
	}

	/**
	 * Returns the future translation modified timestamp.
	 *
	 * @return int
	 */
	public function translation_modified() {
		return $this->translation_modified;
	}
}
