<?php
/**
 * SEO plugin compatibility.
 *
 * @package McLogiora
 */

namespace McLogiora\Seo;

use McLogiora\Compatibility\PluginDetector;
use McLogiora\Seo\Adapters\AioseoAdapter;
use McLogiora\Seo\Adapters\RankMathAdapter;
use McLogiora\Seo\Adapters\SeoAdapterInterface;
use McLogiora\Seo\Adapters\SlimSeoAdapter;
use McLogiora\Seo\Adapters\TheSeoFrameworkAdapter;
use McLogiora\Seo\Adapters\YoastAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Works out who owns which piece of SEO output.
 *
 * The rule is that two plugins must never write the same tag. A page with two
 * canonical tags is a page whose canonical a search engine may ignore
 * entirely, which is a worse outcome than either plugin acting alone.
 *
 * Where a supported SEO plugin is present, mcLogiora steps back from the
 * concerns that plugin owns and hands it the language facts instead. It does
 * *not* step back from `hreflang`, because none of these plugins produces it
 * for a multilingual site and stepping back would simply delete the feature.
 *
 * An unknown SEO plugin does not silence anything. Guessing that some
 * unrecognised plugin owns canonical would break working sites to protect a
 * hypothetical one; the health check reports the possibility instead, and the
 * `mclogiora_seo_owns_concern` filter lets a site settle it explicitly.
 */
final class SeoCompatibilityManager {
	/**
	 * Plugin detector.
	 *
	 * @var PluginDetector
	 */
	private $plugins;

	/**
	 * Adapters for detected plugins, or null before detection.
	 *
	 * @var SeoAdapterInterface[]|null
	 */
	private $active = null;

	/**
	 * Constructor.
	 *
	 * @param PluginDetector $plugins Plugin detector.
	 */
	public function __construct( PluginDetector $plugins ) {
		$this->plugins = $plugins;
	}

	/**
	 * Returns every adapter mcLogiora knows about, detected or not.
	 *
	 * @return SeoAdapterInterface[]
	 */
	public function known_adapters() {
		return array(
			new YoastAdapter(),
			new RankMathAdapter(),
			new AioseoAdapter(),
			new TheSeoFrameworkAdapter(),
			new SlimSeoAdapter(),
		);
	}

	/**
	 * Returns adapters whose plugin is actually active.
	 *
	 * @return SeoAdapterInterface[]
	 */
	public function active_adapters() {
		if ( null !== $this->active ) {
			return $this->active;
		}

		$active = array();

		foreach ( $this->known_adapters() as $adapter ) {
			foreach ( $adapter->plugin_basenames() as $basename ) {
				if ( $this->plugins->is_active( $basename ) ) {
					$active[] = $adapter;
					break;
				}
			}
		}

		$this->active = $active;

		return $this->active;
	}

	/**
	 * Returns whether mcLogiora should emit output for a concern.
	 *
	 * @param string $concern Concern identifier.
	 * @return bool
	 */
	public function owns( $concern ) {
		$owns = true;

		if ( SeoConcern::is_valid( $concern ) ) {
			foreach ( $this->active_adapters() as $adapter ) {
				if ( in_array( (string) $concern, $adapter->owned_concerns(), true ) ) {
					$owns = false;
					break;
				}
			}
		}

		/**
		 * Filters whether mcLogiora emits output for one SEO concern.
		 *
		 * Two plugins must never write the same tag. mcLogiora stands down
		 * automatically for the SEO plugins it recognises, and this filter is
		 * how a site settles ownership for one it does not.
		 *
		 * Returning `false` removes that output entirely. For `hreflang` that
		 * usually means the site has no language annotation at all, since none
		 * of the common SEO plugins produces one.
		 *
		 * `$concern` is one of `canonical`, `hreflang`, `og_locale`, `sitemap`.
		 * An unrecognised concern arrives with `$owns` already `true`.
		 *
		 * @since 0.12.0
		 *
		 * @param bool   $owns Whether mcLogiora owns the concern.
		 * @param string $concern Concern identifier.
		 */
		return (bool) apply_filters( 'mclogiora_seo_owns_concern', $owns, (string) $concern );
	}

	/**
	 * Returns which adapter, if any, has taken a concern.
	 *
	 * @param string $concern Concern identifier.
	 * @return SeoAdapterInterface|null
	 */
	public function owner_of( $concern ) {
		foreach ( $this->active_adapters() as $adapter ) {
			if ( in_array( (string) $concern, $adapter->owned_concerns(), true ) ) {
				return $adapter;
			}
		}

		return null;
	}

	/**
	 * Hands language facts to every detected SEO plugin.
	 *
	 * @param CanonicalService $canonical Canonical URL service.
	 * @return void
	 */
	public function integrate( CanonicalService $canonical ) {
		foreach ( $this->active_adapters() as $adapter ) {
			$adapter->integrate( $canonical );
		}
	}

	/**
	 * Returns active plugins that look like SEO plugins but have no adapter.
	 *
	 * Reported, never acted upon.
	 *
	 * @return string[]
	 */
	public function unrecognised_seo_plugins() {
		$known = array();

		foreach ( $this->known_adapters() as $adapter ) {
			foreach ( $adapter->plugin_basenames() as $basename ) {
				$known[] = $basename;
			}
		}

		$suspects = array();

		foreach ( $this->plugins->active_plugins() as $basename ) {
			if ( in_array( $basename, $known, true ) ) {
				continue;
			}

			if ( preg_match( '#(^|[/-])seo([/-]|$)#i', (string) $basename ) ) {
				$suspects[] = (string) $basename;
			}
		}

		return $suspects;
	}
}
