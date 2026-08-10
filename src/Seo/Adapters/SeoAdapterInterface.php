<?php
/**
 * SEO plugin adapter contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Seo\Adapters;

use McLogiora\Seo\CanonicalService;

defined( 'ABSPATH' ) || exit;

/**
 * Describes how one SEO plugin divides responsibility with mcLogiora.
 *
 * An adapter answers two questions and does one job. It says whether its plugin
 * is present, it says which concerns that plugin owns, and it feeds the plugin
 * mcLogiora's language facts through public hooks where public hooks exist.
 *
 * Adapters are constructed only when their plugin is detected, so an adapter
 * may reference its plugin's hooks freely. It must never reference its plugin's
 * classes, constants, or files: a plugin that is active is not necessarily
 * loaded yet at the moment an adapter registers.
 */
interface SeoAdapterInterface {
	/**
	 * Returns a stable identifier for this adapter.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Returns the human-readable plugin name.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Returns the plugin basenames that activate this adapter.
	 *
	 * @return string[]
	 */
	public function plugin_basenames();

	/**
	 * Returns the SEO concerns this plugin owns.
	 *
	 * Anything listed here is emitted by that plugin, not by mcLogiora.
	 *
	 * @return string[]
	 */
	public function owned_concerns();

	/**
	 * Hands mcLogiora's language facts to the plugin.
	 *
	 * Called once, during module registration, only when the plugin is active.
	 *
	 * @param CanonicalService $canonical Canonical URL service.
	 * @return void
	 */
	public function integrate( CanonicalService $canonical );
}
