<?php
/**
 * Rank Math adapter.
 *
 * @package McLogiora
 */

namespace McLogiora\Seo\Adapters;

use McLogiora\Seo\SeoConcern;

defined( 'ABSPATH' ) || exit;

/**
 * Divides responsibility with Rank Math.
 *
 * Rank Math owns canonical, OpenGraph, and the sitemap. As with Yoast it emits
 * no `hreflang`, so that concern stays with mcLogiora.
 */
final class RankMathAdapter extends AbstractSeoAdapter {
	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'rank-math';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'Rank Math SEO';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string[]
	 */
	public function plugin_basenames() {
		return array( 'seo-by-rank-math/rank-math.php', 'seo-by-rank-math-pro/rank-math-pro.php' );
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
		return array( 'rank_math/frontend/canonical' );
	}
}
