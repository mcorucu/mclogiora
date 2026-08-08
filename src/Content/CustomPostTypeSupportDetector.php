<?php
/**
 * Custom post type support detector.
 *
 * @package McLogiora
 */

namespace McLogiora\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Detects public custom post type support.
 */
final class CustomPostTypeSupportDetector {
	/**
	 * Returns whether a post type object is a supported public CPT.
	 *
	 * @param object $post_type_object Post type object.
	 * @return bool
	 */
	public function supports( $post_type_object ) {
		if ( ! is_object( $post_type_object ) || empty( $post_type_object->name ) ) {
			return false;
		}

		return ! empty( $post_type_object->public ) && empty( $post_type_object->_builtin );
	}
}
