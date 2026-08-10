<?php
/**
 * OpenGraph locale metadata.
 *
 * @package McLogiora
 */

namespace McLogiora\Seo;

use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageTag;
use McLogiora\Routing\LanguageContextInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies `og:locale` and its alternates.
 *
 * OpenGraph wants `language_TERRITORY`, which is not the same shape as a BCP 47
 * tag and not the same as a WordPress locale either. A language configured
 * without a territory cannot be expressed in that shape at all, and is omitted
 * rather than given an invented one: turning `en` into `en_US` asserts a
 * territory the site never chose.
 *
 * mcLogiora is not a social-metadata plugin. It contributes the language facts
 * it uniquely knows and nothing else -- no `og:title`, no `og:image`, no
 * `og:description`.
 */
final class OpenGraphLocaleService {
	/**
	 * Language context.
	 *
	 * @var LanguageContextInterface
	 */
	private $context;

	/**
	 * Alternate URL service.
	 *
	 * @var AlternateUrlService
	 */
	private $alternates;

	/**
	 * Constructor.
	 *
	 * @param LanguageContextInterface $context Language context.
	 * @param AlternateUrlService      $alternates Alternate URL service.
	 */
	public function __construct( LanguageContextInterface $context, AlternateUrlService $alternates ) {
		$this->context    = $context;
		$this->alternates = $alternates;
	}

	/**
	 * Returns the OpenGraph locale for the current language.
	 *
	 * @return string
	 */
	public function current_locale() {
		$language = $this->context->current();

		if ( ! $language instanceof Language ) {
			return '';
		}

		return LanguageTag::to_open_graph( LanguageTag::for_language( $language ) );
	}

	/**
	 * Returns OpenGraph locales for the other languages of a subject.
	 *
	 * Only languages that genuinely have this content are listed, for the same
	 * reason `hreflang` only lists real translations: an alternate locale is a
	 * claim that the content exists in that language.
	 *
	 * @param SeoSubject $subject Request subject.
	 * @return string[]
	 */
	public function alternate_locales( SeoSubject $subject ) {
		$current = $this->context->current_code();
		$locales = array();

		foreach ( $this->alternates->alternates( $subject ) as $code => $alternate ) {
			if ( $code === $current ) {
				continue;
			}

			$locale = LanguageTag::to_open_graph( $alternate['tag'] );

			if ( '' !== $locale && ! in_array( $locale, $locales, true ) ) {
				$locales[] = $locale;
			}
		}

		return $locales;
	}
}
