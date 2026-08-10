<?php
/**
 * Yoast SEO adapter.
 *
 * @package McLogiora
 */

namespace McLogiora\Seo\Adapters;

use McLogiora\Seo\SeoConcern;

defined( 'ABSPATH' ) || exit;

/**
 * Divides responsibility with Yoast SEO.
 *
 * Yoast writes its own canonical tag, its own OpenGraph block, and replaces the
 * WordPress core sitemap wholesale, so all three are its concerns. It does not
 * emit `hreflang`, which stays with mcLogiora -- suppressing it here because an
 * SEO plugin happens to be installed would leave the site with no language
 * annotation at all, and that is the one thing only mcLogiora can supply.
 */
final class YoastAdapter extends AbstractSeoAdapter {
	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'yoast';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'Yoast SEO';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string[]
	 */
	public function plugin_basenames() {
		return array( 'wordpress-seo/wp-seo.php', 'wordpress-seo-premium/wp-seo-premium.php' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string[]
	 */
	public function owned_concerns() {
		return array( SeoConcern::CANONICAL, SeoConcern::OG_LOCALE, SeoConcern::SITEMAP );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string[]
	 */
	protected function canonical_filters() {
		return array( 'wpseo_canonical' );
	}
}
