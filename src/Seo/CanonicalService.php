<?php
/**
 * Multilingual canonical URLs.
 *
 * @package McLogiora\Seo
 */

namespace McLogiora\Seo;

use McLogiora\Routing\LanguageContextInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what a translated page should canonicalize to.
 *
 * **A translated page canonicalizes to itself.** Pointing every language back
 * at the default one is the single most damaging thing a multilingual plugin
 * can do to a site: it tells search engines the translations are duplicates to
 * be ignored, and the work of translating the site disappears from results.
 *
 * WordPress already prints a canonical tag for singular requests, and since
 * Phase 12 made `get_permalink()` language-correct, that tag is *already*
 * self-referential on a translated page. There is nothing to fix there, so
 * nothing is filtered: a second opinion on singular canonical would only be a
 * chance to disagree with core, and would fight any SEO plugin that legitimately
 * customises it.
 *
 * What core does not print is a canonical for term archives, for a blog-index
 * front page, or for a static posts page. Those are the surfaces this service
 * covers, and they are exactly the ones where no duplicate can occur.
 */
final class CanonicalService {
	/**
	 * Request subject resolver.
	 *
	 * @var SeoContext
	 */
	private $seo_context;

	/**
	 * Alternate URL service.
	 *
	 * @var AlternateUrlService
	 */
	private $alternates;

	/**
	 * Language context.
	 *
	 * @var LanguageContextInterface
	 */
	private $context;

	/**
	 * Constructor.
	 *
	 * @param SeoContext               $seo_context Request subject resolver.
	 * @param AlternateUrlService      $alternates Alternate URL service.
	 * @param LanguageContextInterface $context Language context.
	 */
	public function __construct(
		SeoContext $seo_context,
		AlternateUrlService $alternates,
		LanguageContextInterface $context
	) {
		$this->seo_context = $seo_context;
		$this->alternates  = $alternates;
		$this->context     = $context;
	}

	/**
	 * Returns whether WordPress core already prints a canonical tag.
	 *
	 * @return bool
	 */
	public function core_prints_canonical() {
		return function_exists( 'is_singular' ) && is_singular() && (int) get_queried_object_id() > 0;
	}

	/**
	 * Returns the canonical URL mcLogiora should print, or an empty string.
	 *
	 * @return string
	 */
	public function canonical_url() {
		if ( $this->core_prints_canonical() ) {
			return '';
		}

		$subject = $this->seo_context->subject();

		if ( null === $subject ) {
			return '';
		}

		$url = $this->alternates->self_url( $subject );

		/**
		 * Filters the canonical URL mcLogiora prints for non-singular requests.
		 *
		 * Returning an empty string suppresses the tag.
		 *
		 * @param string     $url Canonical URL, or an empty string.
		 * @param SeoSubject $subject Request subject.
		 * @param string     $language_code Current language code.
		 */
		$filtered = apply_filters( 'mclogiora_seo_canonical_url', $url, $subject, $this->context->current_code() );

		return is_string( $filtered ) ? $filtered : '';
	}

	/**
	 * Returns the canonical URL for the current request whoever prints it.
	 *
	 * Used by SEO plugin adapters, which need the value regardless of which
	 * component ends up emitting the markup.
	 *
	 * @return string
	 */
	public function resolved_url() {
		$subject = $this->seo_context->subject();

		if ( null === $subject ) {
			return '';
		}

		return $this->alternates->self_url( $subject );
	}
}
