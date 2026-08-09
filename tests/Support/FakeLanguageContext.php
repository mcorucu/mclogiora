<?php
/**
 * Controllable language context for tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Support;

use McLogiora\Languages\Language;
use McLogiora\Routing\LanguageContextInterface;

/**
 * Reports a language chosen by the test rather than by a request.
 */
final class FakeLanguageContext implements LanguageContextInterface {
	/**
	 * Available languages.
	 *
	 * @var Language[]
	 */
	private $languages;

	/**
	 * Current language code.
	 *
	 * @var string
	 */
	private $current;

	/**
	 * Default language code.
	 *
	 * @var string
	 */
	private $default;

	/**
	 * Constructor.
	 *
	 * @param Language[] $languages Available languages.
	 * @param string     $current Current language code.
	 * @param string     $default Default language code.
	 */
	public function __construct( array $languages, $current = 'en', $default = 'en' ) {
		$this->languages = $languages;
		$this->current   = (string) $current;
		$this->default   = (string) $default;
	}

	/**
	 * Sets the current language.
	 *
	 * @param string $code Language code.
	 * @return void
	 */
	public function set_current( $code ) {
		$this->current = (string) $code;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Language|null
	 */
	public function current() {
		return $this->find( $this->current );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function current_code() {
		return $this->current;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Language|null
	 */
	public function default_language() {
		return $this->find( $this->default );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function requested_code() {
		return $this->current === $this->default ? '' : $this->current;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_default() {
		return $this->current === $this->default;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Language[]
	 */
	public function available() {
		return $this->languages;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	public function is_routable( $code ) {
		return null !== $this->find( (string) $code );
	}

	/**
	 * Finds a language by code.
	 *
	 * @param string $code Language code.
	 * @return Language|null
	 */
	private function find( $code ) {
		foreach ( $this->languages as $language ) {
			if ( $language->code() === (string) $code ) {
				return $language;
			}
		}

		return null;
	}
}
