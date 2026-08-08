<?php
/**
 * Taxonomy registry.
 *
 * @package McLogiora
 */

namespace McLogiora\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Reads WordPress taxonomy registration metadata without mutating terms.
 */
final class TaxonomyRegistry implements TaxonomyRegistryInterface {
	/**
	 * Support detector.
	 *
	 * @var TaxonomySupportDetector
	 */
	private $support_detector;

	/**
	 * Exclusion rules.
	 *
	 * @var TaxonomyExclusionRules
	 */
	private $exclusion_rules;

	/**
	 * Constructor.
	 *
	 * @param TaxonomySupportDetector $support_detector Support detector.
	 * @param TaxonomyExclusionRules  $exclusion_rules Exclusion rules.
	 */
	public function __construct( TaxonomySupportDetector $support_detector, TaxonomyExclusionRules $exclusion_rules ) {
		$this->support_detector = $support_detector;
		$this->exclusion_rules  = $exclusion_rules;
	}

	/**
	 * Returns all discovered taxonomies.
	 *
	 * @return TranslatableTaxonomy[]
	 */
	public function all() {
		if ( ! function_exists( 'get_taxonomies' ) ) {
			return $this->fallback_taxonomies();
		}

		$objects    = get_taxonomies( array(), 'objects' );
		$taxonomies = array();

		foreach ( $objects as $object ) {
			if ( ! is_object( $object ) || empty( $object->name ) ) {
				continue;
			}

			$name        = sanitize_key( $object->name );
			$label       = ! empty( $object->label ) ? $object->label : $name;
			$public      = ! empty( $object->public );
			$built_in    = ! empty( $object->_builtin );
			$reason      = $this->exclusion_rules->reason_for( $name );
			$supported   = $this->support_detector->supports( $object );
			$translate   = $public && $supported && '' === $reason;
			$taxonomies[] = new TranslatableTaxonomy( $name, $label, $public, $built_in, $translate, $reason );
		}

		usort(
			$taxonomies,
			static function ( TranslatableTaxonomy $a, TranslatableTaxonomy $b ) {
				return strcmp( $a->name(), $b->name() );
			}
		);

		return $taxonomies;
	}

	/**
	 * Returns translatable taxonomies.
	 *
	 * @return TranslatableTaxonomy[]
	 */
	public function translatable() {
		return array_values(
			array_filter(
				$this->all(),
				static function ( TranslatableTaxonomy $taxonomy ) {
					return $taxonomy->is_translatable();
				}
			)
		);
	}

	/**
	 * Returns excluded taxonomies.
	 *
	 * @return TranslatableTaxonomy[]
	 */
	public function excluded() {
		return array_values(
			array_filter(
				$this->all(),
				static function ( TranslatableTaxonomy $taxonomy ) {
					return '' !== $taxonomy->exclusion_reason();
				}
			)
		);
	}

	/**
	 * Returns whether a taxonomy is translatable.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function is_translatable( $taxonomy ) {
		$taxonomy = sanitize_key( $taxonomy );

		foreach ( $this->translatable() as $candidate ) {
			if ( $candidate->name() === $taxonomy ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns fallback taxonomies before WordPress taxonomies are available.
	 *
	 * @return TranslatableTaxonomy[]
	 */
	private function fallback_taxonomies() {
		return array(
			new TranslatableTaxonomy( 'category', __( 'Categories', 'mclogiora' ), true, true, true ),
			new TranslatableTaxonomy( 'post_tag', __( 'Tags', 'mclogiora' ), true, true, true ),
		);
	}
}
