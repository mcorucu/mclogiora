<?php
/**
 * Multilingual permalink filters.
 *
 * @package McLogiora
 */

namespace McLogiora\Routing;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Relations\ContentType;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the language prefix to WordPress-generated URLs.
 *
 * Only the current language's prefix is added. These filters do not swap one
 * object for another: a link to post 12 stays a link to post 12. Choosing a
 * different language's equivalent is what the switcher does, through the URL
 * generator, on explicit request.
 */
final class PermalinkModule implements ModuleInterface {
	/**
	 * Language context.
	 *
	 * @var LanguageContextInterface|null
	 */
	private $context = null;

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
	 * Registers permalink filters.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->readiness = $container->get( RuntimeReadiness::class );

		/*
		 * Nothing about a URL is multilingual while WordPress is building its
		 * own tables, and the filters would only ever answer "not yet". Not
		 * registering them at all is the cheapest possible install-time
		 * behaviour and removes the surface entirely.
		 */
		if ( $this->readiness->is_installing() ) {
			return;
		}

		$this->context = $container->get( LanguageContextInterface::class );
		$this->urls    = $container->get( TranslatedUrlGenerator::class );

		add_filter( 'post_link', array( $this, 'filter_post_link' ), 10, 2 );
		add_filter( 'page_link', array( $this, 'filter_page_link' ), 10, 2 );
		add_filter( 'post_type_link', array( $this, 'filter_post_link' ), 10, 2 );
		add_filter( 'term_link', array( $this, 'filter_term_link' ), 10, 2 );
		add_filter( 'home_url', array( $this, 'filter_home_url' ), 10, 2 );
	}

	/**
	 * Adds a post's own language prefix to its link.
	 *
	 * `post_link` and `post_type_link` both hand over the post object.
	 *
	 * @param string        $link Generated link.
	 * @param \WP_Post|null $post Post the link belongs to.
	 * @return string
	 */
	public function filter_post_link( $link, $post = null ) {
		$post_id = $post instanceof \WP_Post ? (int) $post->ID : 0;

		return $this->filter_object_link( (string) $link, ContentType::POST, $post_id );
	}

	/**
	 * Adds a page's own language prefix to its link.
	 *
	 * `page_link` differs from its siblings: WordPress passes the post ID
	 * rather than the post object.
	 *
	 * @param string $link Generated link.
	 * @param int    $post_id Page identifier.
	 * @return string
	 */
	public function filter_page_link( $link, $post_id = 0 ) {
		return $this->filter_object_link( (string) $link, ContentType::POST, (int) $post_id );
	}

	/**
	 * Adds a term's own language prefix to its link.
	 *
	 * @param string        $link Generated link.
	 * @param \WP_Term|null $term Term the link belongs to.
	 * @return string
	 */
	public function filter_term_link( $link, $term = null ) {
		$term_id = $term instanceof \WP_Term ? (int) $term->term_id : 0;

		return $this->filter_object_link( (string) $link, ContentType::TERM, $term_id );
	}

	/**
	 * Prefixes an object link with the language that object belongs to.
	 *
	 * A stored object's language is a property of the object, established by
	 * its translation relation. It is not a property of whoever happens to be
	 * browsing. Prefixing with the request language instead meant a Turkish
	 * translation linked from an English page produced an English-route URL,
	 * which then served Turkish content under an English canonical -- the same
	 * root cause the sitemap needed its own correction for in Phase 13.
	 *
	 * An object with no relation belongs to the default language, and keeps
	 * whatever URL the request context already produced.
	 *
	 * @param string $link Generated link.
	 * @param string $object_type Relation content type.
	 * @param int    $object_id Object identifier.
	 * @return string
	 */
	private function filter_object_link( $link, $object_type, $object_id ) {
		if ( ! $this->should_filter() ) {
			return $link;
		}

		$language = $object_id > 0
			? $this->urls->language_for_object( $object_type, $object_id )
			: null;

		if ( null === $language ) {
			if ( ! $this->request_language_prefixes() ) {
				return $link;
			}

			return $this->urls->apply_prefix( $link, $this->context->current_code() );
		}

		return $this->urls->retarget_prefix( $link, (string) $language );
	}

	/**
	 * Adds the current language prefix to home URLs with a path.
	 *
	 * The bare home URL is left alone. Prefixing it unconditionally would
	 * change asset and API base URLs that have nothing to do with content
	 * language.
	 *
	 * @param string $url Generated URL.
	 * @param string $path Requested path.
	 * @return string
	 */
	public function filter_home_url( $url, $path ) {
		if ( ! $this->should_filter() || ! $this->request_language_prefixes() ) {
			return $url;
		}

		if ( '' === (string) $path || '/' === (string) $path ) {
			return $url;
		}

		return $this->urls->apply_prefix( (string) $url, $this->context->current_code() );
	}

	/**
	 * Returns whether URL filtering applies right now.
	 *
	 * This covers only the guards that have nothing to do with language: the
	 * recursion suspension and the runtime readiness gate. Which language a
	 * URL gets is decided per object, not here.
	 *
	 * @return bool
	 */
	private function should_filter() {
		if ( PermalinkFilters::suspended() ) {
			return false;
		}

		return $this->readiness->is_frontend_runtime();
	}

	/**
	 * Returns whether the current request's language contributes a prefix.
	 *
	 * Used for URLs that genuinely belong to the request rather than to a
	 * stored object -- home URLs, and objects with no translation relation.
	 *
	 * @return bool
	 */
	private function request_language_prefixes() {
		return ! $this->context->is_default() || $this->urls->language_has_prefix( $this->context->current_code() );
	}
}
