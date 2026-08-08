<?php
/**
 * Widget translation value object.
 *
 * @package McLogiora
 */

namespace McLogiora\Widgets;

use McLogiora\Relations\TranslationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Translated field values for one widget instance in one language.
 */
final class WidgetTranslation {
	/**
	 * Widget instance key.
	 *
	 * @var string
	 */
	private $widget_key;

	/**
	 * Adapter identifier.
	 *
	 * @var string
	 */
	private $adapter_id;

	/**
	 * Language code.
	 *
	 * @var string
	 */
	private $language_code;

	/**
	 * Translated field values.
	 *
	 * @var array<string,string>
	 */
	private $fields;

	/**
	 * Translation status.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Constructor.
	 *
	 * @param string               $widget_key Widget instance key.
	 * @param string               $adapter_id Adapter identifier.
	 * @param string               $language_code Language code.
	 * @param array<string,string> $fields Translated field values.
	 * @param string               $status Translation status.
	 */
	public function __construct( $widget_key, $adapter_id, $language_code, array $fields, $status = TranslationStatus::DRAFT ) {
		$this->widget_key    = (string) $widget_key;
		$this->adapter_id    = (string) $adapter_id;
		$this->language_code = (string) $language_code;
		$this->fields        = array();
		$this->status        = TranslationStatus::is_valid( $status ) ? (string) $status : TranslationStatus::DRAFT;

		foreach ( $fields as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$this->fields[ (string) $key ] = (string) $value;
			}
		}
	}

	/**
	 * Returns the widget instance key.
	 *
	 * @return string
	 */
	public function widget_key() {
		return $this->widget_key;
	}

	/**
	 * Returns the adapter identifier.
	 *
	 * @return string
	 */
	public function adapter_id() {
		return $this->adapter_id;
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
	 * Returns the translated field values.
	 *
	 * @return array<string,string>
	 */
	public function fields() {
		return $this->fields;
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
		foreach ( $this->fields as $value ) {
			if ( '' !== trim( $value ) ) {
				return false;
			}
		}

		return true;
	}
}
