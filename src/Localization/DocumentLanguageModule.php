<?php
/**
 * Document language and request locale.
 *
 * @package McLogiora
 */

namespace McLogiora\Localization;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageTag;
use McLogiora\Routing\LanguageContextInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Makes the document say what language it is actually in.
 *
 * Phase 12 resolved the request language and translated what a visitor reads,
 * but the page still announced the site's configured locale. A screen reader
 * given `lang="en-US"` pronounces Turkish text with English rules, and a search
 * engine reading the same attribute is told the page is something it is not.
 *
 * Two separate things are corrected here, and conflating them is the usual
 * mistake:
 *
 * - The **document language**, through `language_attributes`. This is what the
 *   markup declares, and mcLogiora is authoritative about it.
 * - The **request locale**, through the `locale` filter. This is which
 *   translation files WordPress reaches for, and mcLogiora only nudges it.
 *
 * The locale filter is deliberately the narrowest mechanism that works.
 * `switch_to_locale()` would be more thorough -- it reloads already-loaded text
 * domains -- but it also rebuilds `WP_Locale`, and building `WP_Locale` calls
 * `__()` around forty times for month and weekday names. Every one of those
 * re-enters the front-end translation filter and costs a string lookup, on
 * every translated page view, to retranslate text WordPress had already
 * translated. Since WordPress 6.7 loads text domains just in time, filtering
 * `locale` reaches the same files for anything that had not already loaded,
 * which is now the common case.
 *
 * What that does not cover is documented rather than papered over: a plugin
 * that still calls `load_plugin_textdomain()` during `init` has already chosen
 * its files before any request can have a language, and no filter applied later
 * can retroactively change that.
 */
final class DocumentLanguageModule implements ModuleInterface {
	/**
	 * Language context.
	 *
	 * @var LanguageContextInterface|null
	 */
	private $context = null;

	/**
	 * Runtime readiness.
	 *
	 * @var RuntimeReadiness|null
	 */
	private $readiness = null;

	/**
	 * Registers document language hooks.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->readiness = $container->get( RuntimeReadiness::class );

		if ( $this->readiness->is_installing() ) {
			return;
		}

		$this->context = $container->get( LanguageContextInterface::class );

		add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ), 10, 2 );
		add_filter( 'locale', array( $this, 'filter_locale' ) );
	}

	/**
	 * Rewrites the `lang` and `dir` attributes of the document element.
	 *
	 * Only those two attributes are touched. Replacing the whole string would
	 * discard anything a theme or another plugin had added to it, and the
	 * filter exists precisely so several parties can contribute.
	 *
	 * @param string $output Attribute string.
	 * @param string $doctype Document type.
	 * @return string
	 */
	public function filter_language_attributes( $output, $doctype = 'html' ) {
		unset( $doctype );

		$language = $this->current_language();

		if ( ! $language instanceof Language ) {
			return $output;
		}

		$tag = LanguageTag::for_language( $language );

		if ( '' === $tag ) {
			return $output;
		}

		$output = $this->set_attribute( (string) $output, 'lang', $tag );

		return $this->set_attribute( $output, 'dir', 'rtl' === $language->direction() ? 'rtl' : 'ltr' );
	}

	/**
	 * Points WordPress at the current language's translation files.
	 *
	 * @param string $locale Current locale.
	 * @return string
	 */
	public function filter_locale( $locale ) {
		$language = $this->current_language();

		if ( ! $language instanceof Language ) {
			return $locale;
		}

		$configured = trim( (string) $language->locale() );

		return '' === $configured ? $locale : $configured;
	}

	/**
	 * Returns the current language when it is safe to ask for one.
	 *
	 * @return Language|null
	 */
	private function current_language() {
		if ( ! $this->readiness instanceof RuntimeReadiness || ! $this->readiness->is_frontend_runtime() ) {
			return null;
		}

		if ( ! $this->context instanceof LanguageContextInterface ) {
			return null;
		}

		return $this->context->current();
	}

	/**
	 * Sets one attribute in an attribute string, replacing any existing value.
	 *
	 * @param string $output Attribute string.
	 * @param string $name Attribute name.
	 * @param string $value Attribute value.
	 * @return string
	 */
	private function set_attribute( $output, $name, $value ) {
		$attribute = $name . '="' . esc_attr( $value ) . '"';
		$pattern   = '/\b' . preg_quote( $name, '/' ) . '\s*=\s*("[^"]*"|\'[^\']*\')/i';

		if ( preg_match( $pattern, $output ) ) {
			$replaced = preg_replace( $pattern, $attribute, $output, 1 );

			return is_string( $replaced ) ? $replaced : $output;
		}

		return '' === trim( $output ) ? $attribute : trim( $output ) . ' ' . $attribute;
	}
}
