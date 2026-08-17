<?php
/**
 * Translation relation repository contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Relations;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for future relation persistence.
 */
interface TranslationRelationRepositoryInterface {
	/**
	 * Creates an empty translation group.
	 *
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_empty_group();

	/**
	 * Creates a placeholder group around an original item.
	 *
	 * @param TranslationItem $original Original item.
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_group_placeholder( TranslationItem $original );

	/**
	 * Creates a placeholder group while preserving a supplied group key.
	 *
	 * Import is allowed to restore the package identity, but it still enters
	 * through the same repository invariants as every other group creation.
	 *
	 * @param string          $group_key Group key.
	 * @param TranslationItem $original Original item.
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_group_placeholder_with_key( $group_key, TranslationItem $original );

	/**
	 * Finds a group by its key.
	 *
	 * @param string $group_key Group key.
	 * @return TranslationGroup|null
	 */
	public function find_group( $group_key );

	/**
	 * Finds a group by internal numeric ID.
	 *
	 * @param int $group_id Internal group ID.
	 * @return TranslationGroup|null
	 */
	public function find_group_by_id( $group_id );

	/**
	 * Finds a group by source item.
	 *
	 * @param TranslationItem $source Source item.
	 * @return TranslationGroup|null
	 */
	public function find_group_by_source( TranslationItem $source );

	/**
	 * Updates group metadata.
	 *
	 * @param string $group_key Group key.
	 * @param array  $metadata Group metadata.
	 * @return TranslationGroup|\WP_Error
	 */
	public function update_group_metadata( $group_key, array $metadata );

	/**
	 * Archives a group without deleting data.
	 *
	 * @param string $group_key Group key.
	 * @return bool|\WP_Error
	 */
	public function archive_group( $group_key );

	/**
	 * Adds an item to a group.
	 *
	 * @param string          $group_key Group key.
	 * @param TranslationItem $item Translation item.
	 * @return TranslationItem|\WP_Error
	 */
	public function add_item_to_group( $group_key, TranslationItem $item );

	/**
	 * Updates an item status.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @param string $status Translation status.
	 * @return TranslationItem|\WP_Error
	 */
	public function update_item_status( $object_type, $object_id, $language_code, $status );

	/**
	 * Updates an item language.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Current language code.
	 * @param string $new_language_code New language code.
	 * @return TranslationItem|\WP_Error
	 */
	public function update_item_language( $object_type, $object_id, $language_code, $new_language_code );

	/**
	 * Updates source metadata placeholder fields.
	 *
	 * @param TranslationItem $item Translation item.
	 * @return TranslationItem|\WP_Error
	 */
	public function update_item_source_metadata( TranslationItem $item );

	/**
	 * Finds an item by object identity and language.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return TranslationItem|null
	 */
	public function find_item( $object_type, $object_id, $language_code );

	/**
	 * Returns items for a group.
	 *
	 * @param string $group_key Group key.
	 * @return TranslationItem[]
	 */
	public function items_for_group( $group_key );

	/**
	 * Returns items by status.
	 *
	 * @param string $status Translation status.
	 * @return TranslationItem[]
	 */
	public function items_by_status( $status );

	/**
	 * Returns original item for a group.
	 *
	 * @param string $group_key Group key.
	 * @return TranslationItem|null
	 */
	public function original_for_group( $group_key );

	/**
	 * Returns whether an object is assigned to an active group.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @return bool
	 */
	public function object_is_assigned( $object_type, $object_id );

	/**
	 * Safely detaches a target item by disabling it.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return bool|\WP_Error
	 */
	public function detach_item( $object_type, $object_id, $language_code );

	/**
	 * Returns translations for an item.
	 *
	 * @param TranslationItem $item Translation item.
	 * @return TranslationItem[]
	 */
	public function translations_for_item( TranslationItem $item );

	/**
	 * Returns all placeholder groups.
	 *
	 * @return TranslationGroup[]
	 */
	public function all();

	/**
	 * Returns active group keys in a stable order, for exhaustive iteration.
	 *
	 * `all()` answers "show me some groups": it is capped and ordered by recent
	 * activity, which is right for a dashboard and wrong for anything that must
	 * see every group and see them in the same order twice. Export is that
	 * caller, so it gets a paged reader ordered by the group key itself --
	 * stable under concurrent edits in a way `updated_at` is not.
	 *
	 * @param int $limit Maximum keys to return.
	 * @param int $offset Number of keys to skip.
	 * @return string[]
	 */
	public function active_group_keys( $limit, $offset = 0 );

	/**
	 * Counts active translation groups.
	 *
	 * @return int
	 */
	public function count_groups();

	/**
	 * Counts translation items.
	 *
	 * @return int
	 */
	public function count_items();
}
