<?php
/**
 * Resolves what the current request is about.
 *
 * @package McLogiora
 */

namespace McLogiora\Seo;

use McLogiora\Content\ContentTypeRegistryInterface;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Taxonomies\TaxonomyRegistryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the current WordPress query into an SEO subject.
 *
 * This class asks WordPress's own conditional tags and nothing else. It never
 * looks at `REQUEST_URI`, never re-parses a path, and never decides what
 * language a request is in -- `LanguageContext` owns that, and a second opinion
 * would eventually differ from the first.
 */
final class SeoContext {
	/**
	 * Runtime readiness.
	 *
	 * @var RuntimeReadiness
	 */
	private $readiness;

	/**
	 * Translatable content types.
	 *
	 * @var ContentTypeRegistryInterface
	 */
	private $content_types;

	/**
	 * Translatable taxonomies.
	 *
	 * @var TaxonomyRegistryInterface
	 */
	private $taxonomies;

	/**
	 * Memoised subject, or false before first resolution.
	 *
	 * @var SeoSubject|null|false
	 */
	private $subject = false;

	/**
	 * Constructor.
	 *
	 * @param RuntimeReadiness             $readiness Runtime readiness.
	 * @param ContentTypeRegistryInterface $content_types Content type registry.
	 * @param TaxonomyRegistryInterface    $taxonomies Taxonomy registry.
	 */
	public function __construct(
		RuntimeReadiness $readiness,
		ContentTypeRegistryInterface $content_types,
		TaxonomyRegistryInterface $taxonomies
	) {
		$this->readiness     = $readiness;
		$this->content_types = $content_types;
		$this->taxonomies    = $taxonomies;
	}

	/**
	 * Returns whether mcLogiora may emit SEO output for this request.
	 *
	 * @return bool
	 */
	public function applies() {
		if ( ! $this->readiness->is_frontend_runtime() ) {
			return false;
		}

		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return false;
		}

		if ( function_exists( 'is_404' ) && is_404() ) {
			return false;
		}

		if ( function_exists( 'is_robots' ) && is_robots() ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns the subject of the current request, or null.
	 *
	 * @return SeoSubject|null
	 */
	public function subject() {
		if ( false !== $this->subject ) {
			return $this->subject;
		}

		$this->subject = $this->resolve();

		return $this->subject;
	}

	/**
	 * Clears the memoised subject.
	 *
	 * @return void
	 */
	public function reset() {
		$this->subject = false;
	}

	/**
	 * Works out the subject from the current query.
	 *
	 * @return SeoSubject|null
	 */
	private function resolve() {
		if ( ! $this->applies() ) {
			return null;
		}

		/*
		 * The posts page is checked before is_singular(), because a static
		 * posts page is not a singular request even though a page object backs
		 * it, and its translated equivalent is that page's translation.
		 */
		if ( function_exists( 'is_home' ) && is_home() && ! is_front_page() ) {
			$posts_page = (int) get_option( 'page_for_posts' );

			return $posts_page > 0 ? SeoSubject::post( $posts_page ) : SeoSubject::home();
		}

		if ( function_exists( 'is_singular' ) && is_singular() ) {
			$post = get_queried_object();

			if ( ! $post instanceof \WP_Post || ! $this->content_types->is_translatable( $post->post_type ) ) {
				return null;
			}

			return SeoSubject::post( (int) $post->ID );
		}

		if ( function_exists( 'is_front_page' ) && is_front_page() ) {
			return SeoSubject::home();
		}

		if ( function_exists( 'is_category' ) && ( is_category() || is_tag() || is_tax() ) ) {
			$term = get_queried_object();

			if ( ! $term instanceof \WP_Term || ! $this->taxonomies->is_translatable( $term->taxonomy ) ) {
				return null;
			}

			return SeoSubject::term( (int) $term->term_id, (string) $term->taxonomy );
		}

		return null;
	}
}
