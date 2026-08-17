<?php
/**
 * Portable language record.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * One language as a package carries it.
 *
 * The field names are the ones the public developer API and the REST routes
 * already publish, and that is not a coincidence. An operator comparing an
 * export against `GET /mclogiora/v1/languages` should find one vocabulary
 * rather than two, and a second set of names for the same eight facts is a
 * second thing to keep in step.
 *
 * The internal row identifier and the raw `LanguageStatus` constant are both
 * absent. The first is persistence identity that means nothing off its own
 * site; the second is an internal vocabulary that `is_active` already answers.
 */
final class PackageLanguage {
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
	 * Native name.
	 *
	 * @var string
	 */
	private $native_name;

	/**
	 * English name.
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
	 * Whether the language is active.
	 *
	 * @var bool
	 */
	private $is_active;

	/**
	 * Whether the language is the site default.
	 *
	 * @var bool
	 */
	private $is_default;

	/**
	 * Display order.
	 *
	 * @var int
	 */
	private $order;

	/**
	 * Constructor.
	 *
	 * @param string $code Language code.
	 * @param string $locale Locale.
	 * @param string $native_name Native name.
	 * @param string $english_name English name.
	 * @param string $direction Text direction.
	 * @param bool   $is_active Whether the language is active.
	 * @param bool   $is_default Whether the language is the default.
	 * @param int    $order Display order.
	 */
	public function __construct( $code, $locale, $native_name, $english_name, $direction, $is_active, $is_default, $order ) {
		$this->code         = (string) $code;
		$this->locale       = (string) $locale;
		$this->native_name  = (string) $native_name;
		$this->english_name = (string) $english_name;
		$this->direction    = 'rtl' === (string) $direction ? 'rtl' : 'ltr';
		$this->is_active    = (bool) $is_active;
		$this->is_default   = (bool) $is_default;
		$this->order        = (int) $order;
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
	 * Returns whether the language is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->is_active;
	}

	/**
	 * Returns whether the language is the default.
	 *
	 * @return bool
	 */
	public function is_default() {
		return $this->is_default;
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
	 * Returns the package representation with a fixed key order.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'code'         => $this->code,
			'locale'       => $this->locale,
			'native_name'  => $this->native_name,
			'english_name' => $this->english_name,
			'direction'    => $this->direction,
			'is_active'    => $this->is_active,
			'is_default'   => $this->is_default,
			'order'        => $this->order,
		);
	}
}
