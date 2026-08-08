<?php
/**
 * Taxonomy translation service contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for taxonomy translation foundation reads.
 */
interface TaxonomyTranslationServiceInterface {
	/**
	 * Returns whether a taxonomy is translatable.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function is_taxonomy_translatable( $taxonomy );

	/**
	 * Returns translatable taxonomies.
	 *
	 * @return TranslatableTaxonomy[]
	 */
	public function get_translatable_taxonomies();

	/**
	 * Returns excluded taxonomies.
	 *
	 * @return TranslatableTaxonomy[]
	 */
	public function get_excluded_taxonomies();

	/**
	 * Returns a read-only support overview.
	 *
	 * @return array<string, int>
	 */
	public function get_support_overview();
}
