<?php
/**
 * The SEO Framework adapter.
 *
 * @package McLogiora
 */

namespace McLogiora\Seo\Adapters;

use McLogiora\Seo\SeoConcern;

defined( 'ABSPATH' ) || exit;

/**
 * Divides responsibility with The SEO Framework.
 *
 * The SEO Framework builds its canonical from the WordPress permalink, which
 * Phase 12 already made language-correct, so no filter is attached. Claiming a
 * hook whose name might drift between major versions would be worse than
 * relying on behaviour that is stable by construction.
 */
final class TheSeoFrameworkAdapter extends AbstractSeoAdapter {
	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'the-seo-framework';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'The SEO Framework';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string[]
	 */
	public function plugin_basenames() {
		return array( 'autodescription/autodescription.php' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string[]
	 */
	public function owned_concerns() {
		return array( SeoConcern::CANONICAL, SeoConcern::OG_LOCALE, SeoConcern::SITEMAP );
	}
}
