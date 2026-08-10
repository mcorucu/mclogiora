<?php
/**
 * Shared SEO adapter behaviour.
 *
 * @package McLogiora
 */

namespace McLogiora\Seo\Adapters;

use McLogiora\Seo\CanonicalService;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies the canonical hand-off every adapter performs the same way.
 *
 * Every supported plugin builds its canonical URL from WordPress's own
 * permalink, which Phase 12 already made language-correct, so in practice these
 * plugins are right without help. The filter below is belt and braces for the
 * cases where a plugin has cached or rewritten the value, and it is written to
 * be harmless: it only replaces a URL when mcLogiora actually has one, and it
 * never touches a canonical an editor set by hand, because those arrive through
 * the plugin's own meta long before this filter sees them.
 */
abstract class AbstractSeoAdapter implements SeoAdapterInterface {
	/**
	 * Canonical service.
	 *
	 * @var CanonicalService|null
	 */
	protected $canonical = null;

	/**
	 * Attaches the plugin's canonical filter when it declares one.
	 *
	 * @param CanonicalService $canonical Canonical URL service.
	 * @return void
	 */
	public function integrate( CanonicalService $canonical ) {
		$this->canonical = $canonical;

		foreach ( $this->canonical_filters() as $filter ) {
			add_filter( $filter, array( $this, 'filter_canonical' ), 20 );
		}
	}

	/**
	 * Returns the plugin's public canonical filters.
	 *
	 * An empty list means the plugin is trusted to derive the canonical from
	 * the WordPress permalink, which mcLogiora has already corrected.
	 *
	 * @return string[]
	 */
	protected function canonical_filters() {
		return array();
	}

	/**
	 * Replaces a canonical URL with the translated one when there is one.
	 *
	 * @param mixed $url Canonical URL supplied by the plugin.
	 * @return mixed
	 */
	public function filter_canonical( $url ) {
		if ( ! $this->canonical instanceof CanonicalService ) {
			return $url;
		}

		$resolved = $this->canonical->resolved_url();

		return '' === $resolved ? $url : $resolved;
	}
}
