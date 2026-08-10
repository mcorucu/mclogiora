<?php
/**
 * All in One SEO adapter.
 *
 * @package McLogiora
 */

namespace McLogiora\Seo\Adapters;

use McLogiora\Seo\SeoConcern;

defined( 'ABSPATH' ) || exit;

/**
 * Divides responsibility with All in One SEO.
 *
 * Only the free plugin's public filters are used. mcLogiora stays fully open
 * source and takes no dependency on any commercial edition.
 */
final class AioseoAdapter extends AbstractSeoAdapter {
	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'aioseo';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'All in One SEO';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string[]
	 */
	public function plugin_basenames() {
		return array( 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' );
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
		return array( 'aioseo_canonical_url' );
	}
}
