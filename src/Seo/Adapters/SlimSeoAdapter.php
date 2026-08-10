<?php
/**
 * Slim SEO adapter.
 *
 * @package McLogiora
 */

namespace McLogiora\Seo\Adapters;

use McLogiora\Seo\SeoConcern;

defined( 'ABSPATH' ) || exit;

/**
 * Divides responsibility with Slim SEO.
 *
 * Slim SEO deliberately does very little of its own: it derives canonical and
 * OpenGraph from WordPress's permalink and leaves the core sitemap in place. So
 * the sitemap stays with mcLogiora, and the adapter carries no filters at all --
 * writing code here to correct URLs that are already correct would be code with
 * no failure it prevents.
 */
final class SlimSeoAdapter extends AbstractSeoAdapter {
	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'slim-seo';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'Slim SEO';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string[]
	 */
	public function plugin_basenames() {
		return array( 'slim-seo/slim-seo.php' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string[]
	 */
	public function owned_concerns() {
		return array( SeoConcern::CANONICAL, SeoConcern::OG_LOCALE );
	}
}
