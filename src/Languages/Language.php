<?php
/**
 * Language entity.
 *
 * @package McLogiora
 */

namespace McLogiora\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable language value object for the foundation layer.
 */
final class Language {
	/**
	 * Language code.
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
	 * Native language name.
	 *
	 * @var string
	 */
	private $native_name;

	/**
	 * English language name.
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
	 * Status.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Display order.
	 *
	 * @var int
	 */
	private $order;

	/**
	 * Whether this is the default language.
	 *
	 * @var bool
	 */
	private $default;

	/**
	 * Constructor.
	 *
	 * @param string $code Language code.
	 * @param string $locale Locale.
	 * @param string $native_name Native language name.
	 * @param string $english_name English language name.
	 * @param string $direction Text direction.
	 * @param string $status Status.
	 * @param int    $order Display order.
	 * @param bool   $default Whether this is the default language.
	 */
	public function __construct( $code, $locale, $native_name, $english_name, $direction, $status, $order, $default = false ) {
		$this->code         = sanitize_key( $code );
		$this->locale       = sanitize_text_field( $locale );
		$this->native_name  = sanitize_text_field( $native_name );
		$this->english_name = sanitize_text_field( $english_name );
		$this->direction    = 'rtl' === strtolower( (string) $direction ) ? 'rtl' : 'ltr';
		$this->status       = LanguageStatus::is_valid( $status ) ? $status : LanguageStatus::INACTIVE;
		$this->order        = absint( $order );
		$this->default      = (bool) $default;
	}

	/**
	 * Returns the language code.
	 *
	 * @return string
	 */
	public function code() {
		return $this->code;
	}

	/**
	 * Returns the locale.
	 *
	 * @return string
	 */
	public function locale() {
		return $this->locale;
	}

	/**
	 * Returns the native name.
	 *
	 * @return string
	 */
	public function native_name() {
		return $this->native_name;
	}

	/**
	 * Returns the English name.
	 *
	 * @return string
	 */
	public function english_name() {
		return $this->english_name;
	}

	/**
	 * Returns the text direction.
	 *
	 * @return string
	 */
	public function direction() {
		return $this->direction;
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
	 * Returns the display order.
	 *
	 * @return int
	 */
	public function order() {
		return $this->order;
	}

	/**
	 * Returns whether this language is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return LanguageStatus::ACTIVE === $this->status;
	}

	/**
	 * Returns whether this language is the default.
	 *
	 * @return bool
	 */
	public function is_default() {
		return $this->default;
	}
}
