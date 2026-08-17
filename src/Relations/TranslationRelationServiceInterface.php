<?php
/**
 * Translation relation service contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Relations;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for relation domain reads and placeholders.
 */
interface TranslationRelationServiceInterface {
	/**
	 * Creates an empty translation group.
	 *
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_empty_group();

	/**
	 * Creates a placeholder group.
	 *
	 * @param TranslationItem $original Original item.
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_group_placeholder( TranslationItem $original );

	/**
	 * Creates a placeholder group with a supplied key.
	 *
	 * @param string          $group_key Group key.
	 * @param TranslationItem $original Original item.
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_group_placeholder_with_key( $group_key, TranslationItem $original );

	/**
	 * Creates a group from a source object.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_group_from_source_object( $object_type, $object_id, $language_code );

	/**
	 * Attaches an existing object as a translation item.
	 *
	 * @param string $group_key Group key.
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @param string $status Translation status.
	 * @return TranslationItem|\WP_Error
	 */
	public function attach_existing_object_as_translation( $group_key, $object_type, $object_id, $language_code, $status = TranslationStatus::DRAFT );

	/**
	 * Safely detaches an item.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return bool|\WP_Error
	 */
	public function detach_item_safely( $object_type, $object_id, $language_code );

	/**
	 * Returns the translation set for an object.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @return TranslationGroup|null
	 */
	public function get_translation_set_for_object( $object_type, $object_id );

	/**
	 * Returns missing languages for an object.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @return string[]
	 */
	public function get_missing_languages_for_object( $object_type, $object_id );

	/**
	 * Marks an item with a status.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @param string $status Translation status.
	 * @return TranslationItem|\WP_Error
	 */
	public function mark_status( $object_type, $object_id, $language_code, $status );

	/**
	 * Marks an item as needing review.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return TranslationItem|\WP_Error
	 */
	public function mark_needs_review( $object_type, $object_id, $language_code );

	/**
	 * Marks an item as needing update.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return TranslationItem|\WP_Error
	 */
	public function mark_needs_update( $object_type, $object_id, $language_code );

	/**
	 * Marks an item as translated.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return TranslationItem|\WP_Error
	 */
	public function mark_translated( $object_type, $object_id, $language_code );

	/**
	 * Marks an item as machine suggested.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return TranslationItem|\WP_Error
	 */
	public function mark_machine_suggested( $object_type, $object_id, $language_code );

	/**
	 * Returns a group by source item.
	 *
	 * @param TranslationItem $source Source item.
	 * @return TranslationGroup|null
	 */
	public function get_group_by_source_placeholder( TranslationItem $source );

	/**
	 * Returns translations for an item.
	 *
	 * @param TranslationItem $item Translation item.
	 * @return TranslationItem[]
	 */
	public function get_translations_for_item_placeholder( TranslationItem $item );

	/**
	 * Determines missing languages for a group.
	 *
	 * @param TranslationGroup $group Translation group.
	 * @param string[]         $language_codes Language codes.
	 * @return string[]
	 */
	public function determine_missing_languages_placeholder( TranslationGroup $group, array $language_codes );

	/**
	 * Determines outdated translation items.
	 *
	 * @param TranslationGroup $group Translation group.
	 * @return TranslationItem[]
	 */
	public function determine_outdated_translations_placeholder( TranslationGroup $group );

	/**
	 * Returns placeholder groups.
	 *
	 * @return TranslationGroup[]
	 */
	public function get_placeholder_groups();
}
