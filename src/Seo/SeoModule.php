<?php
/**
 * Multilingual SEO head output.
 *
 * @package McLogiora
 */

namespace McLogiora\Seo;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Core\RuntimeReadiness;

defined( 'ABSPATH' ) || exit;

/**
 * Emits the multilingual metadata mcLogiora is responsible for.
 *
 * Routing owns URLs; SEO consumes them. Nothing in this namespace builds a URL,
 * parses a request path, or decides what language a request is in. Every value
 * printed here came from `TranslatedUrlGenerator` and `LanguageContext`, which
 * is what stops a page's canonical, its alternates, and its switcher from
 * quietly disagreeing about the same URL.
 *
 * mcLogiora is not becoming an SEO plugin. There are no titles here, no meta
 * descriptions, no robots directives, no schema. The only things emitted are
 * facts about language that no general SEO plugin can know.
 */
final class SeoModule implements ModuleInterface {
	/**
	 * Runtime readiness.
	 *
	 * @var RuntimeReadiness|null
	 */
	private $readiness = null;

	/**
	 * Request subject resolver.
	 *
	 * @var SeoContext|null
	 */
	private $seo_context = null;

	/**
	 * Alternate URL service.
	 *
	 * @var AlternateUrlService|null
	 */
	private $alternates = null;

	/**
	 * Canonical service.
	 *
	 * @var CanonicalService|null
	 */
	private $canonical = null;

	/**
	 * OpenGraph locale service.
	 *
	 * @var OpenGraphLocaleService|null
	 */
	private $open_graph = null;

	/**
	 * Compatibility manager.
	 *
	 * @var SeoCompatibilityManager|null
	 */
	private $compatibility = null;

	/**
	 * Registers SEO head output.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->readiness = $container->get( RuntimeReadiness::class );

		if ( $this->readiness->is_installing() ) {
			return;
		}

		$this->seo_context   = $container->get( SeoContext::class );
		$this->alternates    = $container->get( AlternateUrlService::class );
		$this->canonical     = $container->get( CanonicalService::class );
		$this->open_graph    = $container->get( OpenGraphLocaleService::class );
		$this->compatibility = $container->get( SeoCompatibilityManager::class );

		$this->compatibility->integrate( $this->canonical );

		add_action( 'wp_head', array( $this, 'render' ), 2 );
	}

	/**
	 * Prints the multilingual head block.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! $this->seo_context instanceof SeoContext || ! $this->seo_context->applies() ) {
			return;
		}

		$subject = $this->seo_context->subject();

		if ( null === $subject ) {
			return;
		}

		$this->render_canonical();
		$this->render_alternates( $subject );
		$this->render_open_graph( $subject );
	}

	/**
	 * Prints a canonical tag for the surfaces core leaves uncovered.
	 *
	 * @return void
	 */
	private function render_canonical() {
		if ( ! $this->compatibility->owns( SeoConcern::CANONICAL ) ) {
			return;
		}

		$url = $this->canonical->canonical_url();

		if ( '' === $url ) {
			return;
		}

		echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
	}

	/**
	 * Prints the alternate language annotations.
	 *
	 * @param SeoSubject $subject Request subject.
	 * @return void
	 */
	private function render_alternates( SeoSubject $subject ) {
		if ( ! $this->compatibility->owns( SeoConcern::HREFLANG ) ) {
			return;
		}

		$alternates = $this->alternates->alternates( $subject );

		/*
		 * A single-language result is one entry pointing at itself, which tells
		 * a search engine nothing it did not already know. Emitting it would be
		 * noise on every page of a site that has not finished translating.
		 */
		if ( count( $alternates ) < 2 ) {
			return;
		}

		foreach ( $alternates as $alternate ) {
			printf(
				'<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n",
				esc_attr( $alternate['tag'] ),
				esc_url( $alternate['url'] )
			);
		}

		$x_default = $this->alternates->x_default_url( $subject );

		if ( '' !== $x_default ) {
			echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $x_default ) . '" />' . "\n";
		}
	}

	/**
	 * Prints OpenGraph locale metadata.
	 *
	 * @param SeoSubject $subject Request subject.
	 * @return void
	 */
	private function render_open_graph( SeoSubject $subject ) {
		if ( ! $this->compatibility->owns( SeoConcern::OG_LOCALE ) ) {
			return;
		}

		/**
		 * Filters whether mcLogiora prints OpenGraph locale metadata.
		 *
		 * Themes that build their own OpenGraph block can switch this off
		 * rather than end up with two `og:locale` tags.
		 *
		 * @param bool $enabled Whether to print the metadata.
		 */
		if ( ! apply_filters( 'mclogiora_seo_output_open_graph_locale', true ) ) {
			return;
		}

		$locale = $this->open_graph->current_locale();

		if ( '' === $locale ) {
			return;
		}

		echo '<meta property="og:locale" content="' . esc_attr( $locale ) . '" />' . "\n";

		foreach ( $this->open_graph->alternate_locales( $subject ) as $alternate ) {
			echo '<meta property="og:locale:alternate" content="' . esc_attr( $alternate ) . '" />' . "\n";
		}
	}
}
