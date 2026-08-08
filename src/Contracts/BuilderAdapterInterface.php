<?php
/**
 * Builder adapter contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for future editor and page-builder adapters.
 */
interface BuilderAdapterInterface {
	/**
	 * Returns the adapter identifier.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Returns the human-readable adapter label.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Returns whether the builder is available in the current site.
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Returns whether this adapter supports the object type.
	 *
	 * @param string $object_type Object type.
	 * @return bool
	 */
	public function supports_object_type( $object_type );

	/**
	 * Detects translatable fields for an object.
	 *
	 * @param int $object_id Object ID.
	 * @return array
	 */
	public function detect_translatable_fields( $object_id );

	/**
	 * Copies source structure into a target object.
	 *
	 * @param int   $source_id Source object ID.
	 * @param int   $target_id Target object ID.
	 * @param array $context Optional context.
	 * @return bool
	 */
	public function copy_source_structure( $source_id, $target_id, array $context = array() );

	/**
	 * Extracts normalized translation segments.
	 *
	 * @param int   $object_id Object ID.
	 * @param array $context Optional context.
	 * @return array
	 */
	public function extract_segments( $object_id, array $context = array() );

	/**
	 * Applies normalized translated segments.
	 *
	 * @param int   $object_id Object ID.
	 * @param array $segments Translated segments.
	 * @param array $context Optional context.
	 * @return bool
	 */
	public function apply_translated_segments( $object_id, array $segments, array $context = array() );

	/**
	 * Returns adapter-specific status for an object.
	 *
	 * @param int $object_id Object ID.
	 * @return array
	 */
	public function get_status( $object_id );
}
