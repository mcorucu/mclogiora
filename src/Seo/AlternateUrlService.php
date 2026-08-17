<?php
/**
 * Alternate language URLs.
 *
 * @package McLogiora
 */

namespace McLogiora\Seo;

use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageTag;
use McLogiora\Relations\ContentType;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Routing\TranslatedUrlGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the set of language alternates for the current request.
 *
 * Every URL here comes from `TranslatedUrlGenerator`, which is the only thing
 * in the plugin allowed to decide what a translated URL looks like. That
 * matters more for `hreflang` than anywhere else: an annotation pointing at a
 * URL that 404s is worse than no annotation, because it tells a search engine a
 * translation exists and then fails to produce it.
 *
 * A language appears only when a translation genuinely exists and a URL can be
 * produced for it. Nothing is ever fabricated by pattern.
 */
final class AlternateUrlService {
	/**
	 * Language context.
	 *
	 * @var LanguageContextInterface
	 */
	private $context;

	/**
	 * URL generator.
	 *
	 * @var TranslatedUrlGenerator
	 */
	private $urls;

	/**
	 * Memoised alternates per subject.
	 *
	 * @var array<string,array<string,array{language:Language,tag:string,url:string}>>
	 */
	private $memo = array();

	/**
	 * Constructor.
	 *
	 * @param LanguageContextInterface $context Language context.
	 * @param TranslatedUrlGenerator   $urls URL generator.
	 */
	public function __construct( LanguageContextInterface $context, TranslatedUrlGenerator $urls ) {
		$this->context = $context;
		$this->urls    = $urls;
	}

	/**
	 * Returns alternates for a subject, keyed by language code.
	 *
	 * The order follows the configured language order, so the emitted markup is
	 * stable between requests and diffs of a rendered page stay readable.
	 *
	 * @param SeoSubject $subject Request subject.
	 * @return array<string,array{language:Language,tag:string,url:string}>
	 */
	public function alternates( SeoSubject $subject ) {
		$key = $subject->kind() . ':' . $subject->object_id() . ':' . $subject->taxonomy();

		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}

		$alternates = array();
		$seen_tags  = array();

		foreach ( $this->context->available() as $language ) {
			if ( ! $language instanceof Language ) {
				continue;
			}

			$tag = LanguageTag::for_language( $language );

			if ( '' === $tag || isset( $seen_tags[ $tag ] ) ) {
				/*
				 * Two languages resolving to the same tag would emit competing
				 * annotations for one code, which search engines treat as an
				 * error for the whole set. The health check reports it; the
				 * output simply keeps the first.
				 */
				continue;
			}

			$url = $this->url_for( $subject, $language->code() );

			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$seen_tags[ $tag ]               = true;
			$alternates[ $language->code() ] = array(
				'language' => $language,
				'tag'      => $tag,
				'url'      => $url,
			);
		}

		$this->memo[ $key ] = $alternates;

		return $alternates;
	}

	/**
	 * Returns the x-default URL for a subject, or an empty string.
	 *
	 * The x-default annotation names the page to serve a visitor whose language
	 * the site does not offer, so it points at the default language. When that
	 * language has no equivalent for this subject there is nothing honest to
	 * point at, and the annotation is omitted rather than aimed at a guess.
	 *
	 * @param SeoSubject $subject Request subject.
	 * @return string
	 */
	public function x_default_url( SeoSubject $subject ) {
		$default = $this->context->default_language();

		if ( ! $default instanceof Language ) {
			return '';
		}

		$alternates = $this->alternates( $subject );
		$url        = isset( $alternates[ $default->code() ] ) ? $alternates[ $default->code() ]['url'] : '';

		/**
		 * Filters the x-default URL for the current subject.
		 *
		 * Returning an empty string omits the annotation. A non-string return
		 * is treated as an empty string rather than printed.
		 *
		 * The value arrives already empty when the default language has no
		 * equivalent for this subject, because there is nothing honest to point
		 * at. Filling it in aims visitors at a guess.
		 *
		 * Only four methods on `$subject` are contract -- `kind()`,
		 * `object_id()`, `taxonomy()` and `is_home()`. `kind()` returns `post`,
		 * `term` or `home`. The class itself is internal and may gain or lose
		 * anything else.
		 *
		 * @since 0.12.0
		 *
		 * @param string     $url Default-language URL, or an empty string.
		 * @param SeoSubject $subject Request subject.
		 */
		$filtered = apply_filters( 'mclogiora_seo_x_default_url', $url, $subject );

		return is_string( $filtered ) ? $filtered : '';
	}

	/**
	 * Returns the current language's own URL for a subject.
	 *
	 * The self-referential annotation is built from the same source as every
	 * other alternate. Deriving it separately is how a page ends up declaring
	 * one URL to be itself and another to be its own language's version.
	 *
	 * @param SeoSubject $subject Request subject.
	 * @return string
	 */
	public function self_url( SeoSubject $subject ) {
		$alternates = $this->alternates( $subject );
		$current    = $this->context->current_code();

		return isset( $alternates[ $current ] ) ? $alternates[ $current ]['url'] : '';
	}

	/**
	 * Clears memoised alternates.
	 *
	 * @return void
	 */
	public function reset() {
		$this->memo = array();
	}

	/**
	 * Returns the URL of a subject in one language, or null.
	 *
	 * @param SeoSubject $subject Request subject.
	 * @param string     $language_code Language code.
	 * @return string|null
	 */
	private function url_for( SeoSubject $subject, $language_code ) {
		if ( $subject->is_home() ) {
			return $this->urls->home_url_for( $language_code );
		}

		if ( ContentType::TERM === $subject->kind() ) {
			return $this->urls->term_url( $subject->object_id(), $subject->taxonomy(), $language_code );
		}

		return $this->urls->post_url( $subject->object_id(), $language_code );
	}
}
