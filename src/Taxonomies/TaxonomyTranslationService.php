<?php
/**
 * Taxonomy translation service.
 *
 * @package McLogiora
 */

namespace McLogiora\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Placeholder taxonomy translation service.
 */
final class TaxonomyTranslationService implements TaxonomyTranslationServiceInterface {
	/**
	 * Taxonomy registry.
	 *
	 * @var TaxonomyRegistryInterface
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param TaxonomyRegistryInterface $registry Taxonomy registry.
	 */
	public function __construct( TaxonomyRegistryInterface $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Returns whether a taxonomy is translatable.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function is_taxonomy_translatable( $taxonomy ) {
		return $this->registry->is_translatable( $taxonomy );
	}

	/**
	 * Returns translatable taxonomies.
	 *
	 * @return TranslatableTaxonomy[]
	 */
	public function get_translatable_taxonomies() {
		return $this->registry->translatable();
	}

	/**
	 * Returns excluded taxonomies.
	 *
	 * @return TranslatableTaxonomy[]
	 */
	public function get_excluded_taxonomies() {
		return $this->registry->excluded();
	}

	/**
	 * Returns a read-only support overview.
	 *
	 * @return array<string, int>
	 */
	public function get_support_overview() {
		return array(
			'translatable' => count( $this->get_translatable_taxonomies() ),
			'excluded'     => count( $this->get_excluded_taxonomies() ),
		);
	}
}
