<?php
/**
 * One bundled language catalog definition.
 *
 * @package McLogiora
 */

namespace McLogiora\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable, display-ready language metadata.
 */
final class LanguageDefinition {
	/**
	 * Internal language code.
	 *
	 * @var string
	 */
	private $code;
	/**
	 * WordPress locale.
	 *
	 * @var string
	 */
	private $locale;
	/**
	 * Hreflang tag.
	 *
	 * @var string
	 */
	private $hreflang;
	/**
	 * Native display name.
	 *
	 * @var string
	 */
	private $native_name;
	/**
	 * English display name.
	 *
	 * @var string
	 */
	private $english_name;
	/**
	 * Text direction.
	 *
	 * @var string
	 */
	private $direction;
	/**
	 * Region label.
	 *
	 * @var string
	 */
	private $region;

	/**
	 * Creates a language definition.
	 *
	 * @param string $code Internal language code.
	 * @param string $locale WordPress locale.
	 * @param string $hreflang BCP-47 tag.
	 * @param string $native_name Native display name.
	 * @param string $english_name English display name.
	 * @param string $direction Text direction.
	 * @param string $region Optional region label.
	 */
	public function __construct( $code, $locale, $hreflang, $native_name, $english_name, $direction = 'ltr', $region = '' ) {
		$this->code         = sanitize_key( $code );
		$this->locale       = sanitize_text_field( $locale );
		$this->hreflang     = sanitize_text_field( $hreflang );
		$this->native_name  = sanitize_text_field( $native_name );
		$this->english_name = sanitize_text_field( $english_name );
		$this->direction    = 'rtl' === strtolower( (string) $direction ) ? 'rtl' : 'ltr';
		$this->region       = sanitize_text_field( $region );
	}

	/** Returns the internal code. @return string */
	public function code() {
		return $this->code; }
	/** Returns the WordPress locale. @return string */
	public function locale() {
		return $this->locale; }
	/** Returns the hreflang tag. @return string */
	public function hreflang() {
		return $this->hreflang; }
	/** Returns the native display name. @return string */
	public function native_name() {
		return $this->native_name; }
	/** Returns the English display name. @return string */
	public function english_name() {
		return $this->english_name; }
	/** Returns the text direction. @return string */
	public function direction() {
		return $this->direction; }
	/** Returns the region label. @return string */
	public function region() {
		return $this->region; }

	/**
	 * Returns the compact user-facing label.
	 *
	 * @return string
	 */
	public function display_name() {
		$name = $this->native_name;

		if ( $this->english_name !== $this->native_name ) {
			$name .= ' (' . $this->english_name . ')';
		}

		if ( '' !== $this->region ) {
			$name .= ' — ' . $this->region;
		}

		return $name;
	}

	/**
	 * Converts the definition into the existing persistence contract.
	 *
	 * @param bool $default Whether it should be the default language.
	 * @param int  $order Display order.
	 * @return array<string,mixed>
	 */
	public function language_data( $default = false, $order = 0 ) {
		return array(
			'code'         => $this->code,
			'locale'       => $this->locale,
			'native_name'  => $this->native_name,
			'english_name' => $this->english_name,
			'direction'    => $this->direction,
			'status'       => LanguageStatus::ACTIVE,
			'order'        => absint( $order ),
			'default'      => (bool) $default,
		);
	}
}
