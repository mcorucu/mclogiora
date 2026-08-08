<?php
/**
 * In-memory taxonomy registry for tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Support;

use McLogiora\Taxonomies\TaxonomyRegistryInterface;
use McLogiora\Taxonomies\TranslatableTaxonomy;

/**
 * Serves a fixed set of taxonomies.
 */
final class FakeTaxonomyRegistry implements TaxonomyRegistryInterface {
	/**
	 * Translatable types.
	 *
	 * @var TranslatableTaxonomy[]
	 */
	private $translatable;

	/**
	 * Excluded types.
	 *
	 * @var TranslatableTaxonomy[]
	 */
	private $excluded;

	/**
	 * Constructor.
	 *
	 * @param TranslatableTaxonomy[] $translatable Translatable types.
	 * @param TranslatableTaxonomy[] $excluded Excluded types.
	 */
	public function __construct( array $translatable, array $excluded = array() ) {
		$this->translatable = $translatable;
		$this->excluded     = $excluded;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return TranslatableTaxonomy[]
	 */
	public function all() {
		return array_merge( $this->translatable, $this->excluded );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return TranslatableTaxonomy[]
	 */
	public function translatable() {
		return $this->translatable;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return TranslatableTaxonomy[]
	 */
	public function excluded() {
		return $this->excluded;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function is_translatable( $taxonomy ) {
		foreach ( $this->translatable as $type ) {
			if ( $type->name() === (string) $taxonomy ) {
				return true;
			}
		}

		return false;
	}
}
