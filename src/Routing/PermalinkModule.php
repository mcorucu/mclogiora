<?php
/**
 * Multilingual permalink filters.
 *
 * @package McLogiora
 */

namespace McLogiora\Routing;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;

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
	 * Request context guard.
	 *
	 * @var RequestContextGuard|null
	 */
	private $guard = null;

	/**
	 * Registers permalink filters.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->context = $container->get( LanguageContextInterface::class );
		$this->urls    = $container->get( TranslatedUrlGenerator::class );
		$this->guard   = $container->get( RequestContextGuard::class );

		add_filter( 'post_link', array( $this, 'filter_link' ), 10, 1 );
		add_filter( 'page_link', array( $this, 'filter_link' ), 10, 1 );
		add_filter( 'post_type_link', array( $this, 'filter_link' ), 10, 1 );
		add_filter( 'term_link', array( $this, 'filter_link' ), 10, 1 );
		add_filter( 'home_url', array( $this, 'filter_home_url' ), 10, 2 );
	}

	/**
	 * Adds the current language prefix to an object link.
	 *
	 * @param string $link Generated link.
	 * @return string
	 */
	public function filter_link( $link ) {
		if ( ! $this->should_filter() ) {
			return $link;
		}

		return $this->urls->apply_prefix( (string) $link, $this->context->current_code() );
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
		if ( ! $this->should_filter() ) {
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
	 * @return bool
	 */
	private function should_filter() {
		if ( PermalinkFilters::suspended() ) {
			return false;
		}

		if ( ! $this->guard->applies() ) {
			return false;
		}

		return ! $this->context->is_default() || $this->urls->language_has_prefix( $this->context->current_code() );
	}
}
