<?php
/**
 * Taxonomy registry contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for reading supported taxonomy metadata.
 */
interface TaxonomyRegistryInterface {
	/**
	 * Returns all discovered taxonomies.
	 *
	 * @return TranslatableTaxonomy[]
	 */
	public function all();

	/**
	 * Returns translatable taxonomies.
	 *
	 * @return TranslatableTaxonomy[]
	 */
	public function translatable();

	/**
	 * Returns excluded taxonomies.
	 *
	 * @return TranslatableTaxonomy[]
	 */
	public function excluded();

	/**
	 * Returns whether a taxonomy is translatable.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function is_translatable( $taxonomy );
}
