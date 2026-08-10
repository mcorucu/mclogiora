<?php
/**
 * WordPress core sitemap integration.
 *
 * @package McLogiora
 */

namespace McLogiora\Seo;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Routing\TranslatedUrlGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Corrects the URLs WordPress core puts in its sitemap.
 *
 * There is a subtle failure here that is easy to miss. Core builds each entry
 * with `get_permalink()`, and Phase 12's permalink filter prefixes URLs with
 * the language of *the current request*. A sitemap request has no language
 * prefix, so the current language is the default one, and every entry -- including
 * the Turkish ones -- comes out unprefixed. Search engines would be handed a
 * list of URLs that either 404 or serve the wrong language.
 *
 * So each entry is rewritten to the language the object itself belongs to,
 * through `TranslatedUrlGenerator`, which stays the only thing that decides what
 * a translated URL looks like.
 *
 * **Alternates are deliberately not added.** `xhtml:link` annotations require an
 * `xmlns:xhtml` declaration on the `<urlset>` element, and WordPress core offers
 * no supported way to add one. Emitting the links without the namespace would
 * produce invalid XML, which is worse than omitting them: an invalid sitemap can
 * be rejected as a whole. The document-head `hreflang` annotations carry the
 * same information, and they are the form search engines document as sufficient.
 *
 * No parallel sitemap is generated and no second index is created. Translated
 * content is ordinary WordPress content, already listed by the core providers.
 */
final class SitemapIntegration implements ModuleInterface {
	/**
	 * URL generator.
	 *
	 * @var TranslatedUrlGenerator|null
	 */
	private $urls = null;

	/**
	 * Runtime readiness.
	 *
	 * @var RuntimeReadiness|null
	 */
	private $readiness = null;

	/**
	 * Compatibility manager.
	 *
	 * @var SeoCompatibilityManager|null
	 */
	private $compatibility = null;

	/**
	 * Registers sitemap filters.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->readiness = $container->get( RuntimeReadiness::class );

		if ( $this->readiness->is_installing() ) {
			return;
		}

		$this->compatibility = $container->get( SeoCompatibilityManager::class );
		$this->urls          = $container->get( TranslatedUrlGenerator::class );

		add_filter( 'wp_sitemaps_posts_entry', array( $this, 'filter_post_entry' ), 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies_entry', array( $this, 'filter_term_entry' ), 10, 3 );
	}

	/**
	 * Points a post entry at the post's own language URL.
	 *
	 * @param array<string,mixed> $entry Sitemap entry.
	 * @param mixed               $post Post object.
	 * @return array<string,mixed>
	 */
	public function filter_post_entry( $entry, $post = null ) {
		if ( ! is_array( $entry ) || ! $this->applies() || ! $post instanceof \WP_Post ) {
			return $entry;
		}

		$url = $this->urls->own_post_url( (int) $post->ID );

		if ( '' !== $url ) {
			$entry['loc'] = $url;
		}

		return $entry;
	}

	/**
	 * Points a term entry at the term's own language URL.
	 *
	 * @param array<string,mixed> $entry Sitemap entry.
	 * @param mixed               $term Term object or identifier.
	 * @param string              $taxonomy Taxonomy name.
	 * @return array<string,mixed>
	 */
	public function filter_term_entry( $entry, $term = null, $taxonomy = '' ) {
		if ( ! is_array( $entry ) || ! $this->applies() ) {
			return $entry;
		}

		$term_id = $term instanceof \WP_Term ? (int) $term->term_id : (int) $term;

		if ( $term_id <= 0 || '' === (string) $taxonomy ) {
			return $entry;
		}

		$url = $this->urls->own_term_url( $term_id, (string) $taxonomy );

		if ( '' !== $url ) {
			$entry['loc'] = $url;
		}

		return $entry;
	}

	/**
	 * Returns whether sitemap URLs should be corrected.
	 *
	 * @return bool
	 */
	private function applies() {
		if ( ! $this->readiness instanceof RuntimeReadiness || ! $this->readiness->is_schema_ready() ) {
			return false;
		}

		if ( ! $this->urls instanceof TranslatedUrlGenerator ) {
			return false;
		}

		return $this->compatibility instanceof SeoCompatibilityManager
			&& $this->compatibility->owns( SeoConcern::SITEMAP );
	}
}
