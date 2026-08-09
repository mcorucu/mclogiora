<?php
/**
 * Request language context.
 *
 * @package McLogiora
 */

namespace McLogiora\Routing;

use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Languages\LanguageStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and holds the language for the current request.
 *
 * The resolved language is computed once and then reused. Recomputing it per
 * call would be wasteful, but more importantly it would allow the answer to
 * change mid-request, which is how a page ends up with content in one language
 * and navigation in another.
 *
 * Resolution is deliberately narrow. It considers exactly one signal: the
 * language prefix mcLogiora itself put in the URL. It does not look at IP
 * addresses, does not call a geolocation service, and does not silently
 * redirect based on Accept-Language. A visitor who asked for a URL gets that
 * URL, and a visitor who did not ask for a language gets the site default.
 */
final class LanguageContext implements LanguageContextInterface {
	/**
	 * Language service.
	 *
	 * @var LanguageServiceInterface
	 */
	private $languages;

	/**
	 * Language code the request explicitly asked for.
	 *
	 * @var string
	 */
	private $requested_code = '';

	/**
	 * Resolved language, or false when not yet resolved.
	 *
	 * @var Language|null|false
	 */
	private $resolved = false;

	/**
	 * Cached active languages.
	 *
	 * @var Language[]|null
	 */
	private $active = null;

	/**
	 * Constructor.
	 *
	 * @param LanguageServiceInterface $languages Language service.
	 */
	public function __construct( LanguageServiceInterface $languages ) {
		$this->languages = $languages;
	}

	/**
	 * Records the language code carried by the current request.
	 *
	 * The value is treated as untrusted: it only becomes the current language
	 * if it matches an active configured language. Arbitrary request text can
	 * never become a language.
	 *
	 * @param string $code Requested language code.
	 * @return void
	 */
	public function set_requested_code( $code ) {
		$code = $this->normalize( $code );

		$this->requested_code = $this->is_routable( $code ) ? $code : '';
		$this->resolved       = false;
	}

	/**
	 * Returns the language for the current request.
	 *
	 * @return Language|null
	 */
	public function current() {
		if ( false !== $this->resolved ) {
			return $this->resolved;
		}

		if ( '' !== $this->requested_code ) {
			$language = $this->languages->get_language_by_code( $this->requested_code );

			if ( $language instanceof Language && LanguageStatus::ACTIVE === $language->status() ) {
				$this->resolved = $language;

				return $this->resolved;
			}
		}

		$this->resolved = $this->default_language();

		return $this->resolved;
	}

	/**
	 * Returns the current language code.
	 *
	 * @return string
	 */
	public function current_code() {
		$language = $this->current();

		return $language instanceof Language ? $language->code() : '';
	}

	/**
	 * Returns the site default language.
	 *
	 * @return Language|null
	 */
	public function default_language() {
		$default = $this->languages->get_default_language();

		return $default instanceof Language ? $default : null;
	}

	/**
	 * Returns the language code the request explicitly asked for.
	 *
	 * @return string
	 */
	public function requested_code() {
		return $this->requested_code;
	}

	/**
	 * Returns whether the current language is the site default.
	 *
	 * @return bool
	 */
	public function is_default() {
		$current = $this->current();
		$default = $this->default_language();

		if ( ! $current instanceof Language || ! $default instanceof Language ) {
			return true;
		}

		return $current->code() === $default->code();
	}

	/**
	 * Returns the active languages available for routing.
	 *
	 * @return Language[]
	 */
	public function available() {
		if ( null === $this->active ) {
			$this->active = $this->languages->get_active_languages();
		}

		return $this->active;
	}

	/**
	 * Returns whether a language code is an active, routable language.
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	public function is_routable( $code ) {
		$code = $this->normalize( $code );

		if ( '' === $code ) {
			return false;
		}

		foreach ( $this->available() as $language ) {
			if ( $language instanceof Language && $language->code() === $code ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalises a candidate language code.
	 *
	 * @param string $code Raw code.
	 * @return string
	 */
	private function normalize( $code ) {
		$code = strtolower( trim( (string) $code ) );

		/*
		 * Language codes are short and alphanumeric with optional hyphens.
		 * Anything else is not a code, and rejecting it here means the rest of
		 * the routing layer never sees hostile input.
		 */
		if ( ! preg_match( '/^[a-z0-9]{2,8}(-[a-z0-9]{2,8})?$/', $code ) ) {
			return '';
		}

		return $code;
	}

	/**
	 * Clears memoised state.
	 *
	 * Used by tests and by language configuration changes.
	 *
	 * @return void
	 */
	public function reset() {
		$this->resolved = false;
		$this->active   = null;
	}
}
