<?php
/**
 * Taxonomy support detector.
 *
 * @package McLogiora
 */

namespace McLogiora\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Detects free-version taxonomy support.
 */
final class TaxonomySupportDetector {
	/**
	 * Returns whether a taxonomy object is supported.
	 *
	 * @param object $taxonomy_object Taxonomy object.
	 * @return bool
	 */
	public function supports( $taxonomy_object ) {
		if ( ! is_object( $taxonomy_object ) || empty( $taxonomy_object->name ) ) {
			return false;
		}

		$name = sanitize_key( $taxonomy_object->name );

		if ( in_array( $name, array( 'category', 'post_tag' ), true ) ) {
			return true;
		}

		return ! empty( $taxonomy_object->public ) && empty( $taxonomy_object->_builtin );
	}
}
