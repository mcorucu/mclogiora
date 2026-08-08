<?php
/**
 * In-memory translation relation repository.
 *
 * @package McLogiora
 */

namespace McLogiora\Relations;

defined( 'ABSPATH' ) || exit;

/**
 * Provides mock relation records without database persistence.
 */
final class InMemoryTranslationRelationRepository implements TranslationRelationRepositoryInterface {
	/**
	 * Mock groups.
	 *
	 * @var TranslationGroup[]
	 */
	private $groups;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->groups = array(
			new TranslationGroup(
				'group-post-42',
				array(
					new TranslationItem( ContentType::POST, '42', 'en', TranslationStatus::ORIGINAL, true, 'source-a', 'source-a', 1720000000, 1720000000 ),
					new TranslationItem( ContentType::POST, '87', 'tr', TranslationStatus::TRANSLATED, false, 'source-a', 'source-a', 1720000000, 1720003600 ),
					new TranslationItem( ContentType::POST, '103', 'de', TranslationStatus::NEEDS_UPDATE, false, 'source-b', 'source-a', 1720007200, 1720000100 ),
				)
			),
			new TranslationGroup(
				'group-term-9',
				array(
					new TranslationItem( ContentType::TERM, '9', 'en', TranslationStatus::ORIGINAL, true, 'term-source-a', 'term-source-a', 1720010000, 1720010000 ),
					new TranslationItem( ContentType::TERM, '15', 'tr', TranslationStatus::NEEDS_REVIEW, false, 'term-source-a', 'term-source-a', 1720010000, 1720010600 ),
				)
			),
			new TranslationGroup(
				'group-string-site-title',
				array(
					new TranslationItem( ContentType::STRING, 'site-title', 'en', TranslationStatus::ORIGINAL, true, '', '', 0, 0 ),
					new TranslationItem( ContentType::STRING, 'site-title-tr', 'tr', TranslationStatus::MACHINE_SUGGESTED, false, '', '', 0, 0 ),
				)
			),
		);
	}

	/**
	 * Creates an empty translation group.
	 *
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_empty_group() {
		$group          = new TranslationGroup( 'group-memory-' . ( count( $this->groups ) + 1 ), array() );
		$this->groups[] = $group;

		return $group;
	}

	/**
	 * Creates a placeholder group around an original item.
	 *
	 * @param TranslationItem $original Original item.
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_group_placeholder( TranslationItem $original ) {
		if ( $this->object_is_assigned( $original->object_type(), $original->object_id() ) ) {
			return new \WP_Error( 'mclogiora_relation_object_assigned', __( 'This object is already assigned to an active translation group.', 'mclogiora' ) );
		}

		$group = new TranslationGroup(
			'placeholder-' . $original->object_type() . '-' . $original->object_id(),
			array(
				new TranslationItem(
					$original->object_type(),
					$original->object_id(),
					$original->language_code(),
					TranslationStatus::ORIGINAL,
					true,
					$original->source_hash(),
					$original->translated_source_hash(),
					$original->source_modified(),
					$original->translation_modified()
				),
			)
		);

		$this->groups[] = $group;

		return $group;
	}

	/**
	 * Finds a group by its key.
	 *
	 * @param string $group_key Group key.
	 * @return TranslationGroup|null
	 */
	public function find_group( $group_key ) {
		foreach ( $this->groups as $group ) {
			if ( $group->group_key() === sanitize_key( $group_key ) ) {
				return $group;
			}
		}

		return null;
	}

	/**
	 * Finds a group by internal numeric ID.
	 *
	 * @param int $group_id Internal group ID.
	 * @return TranslationGroup|null
	 */
	public function find_group_by_id( $group_id ) {
		$index = absint( $group_id ) - 1;

		return isset( $this->groups[ $index ] ) ? $this->groups[ $index ] : null;
	}

	/**
	 * Finds a group by source item.
	 *
	 * @param TranslationItem $source Source item.
	 * @return TranslationGroup|null
	 */
	public function find_group_by_source( TranslationItem $source ) {
		foreach ( $this->groups as $group ) {
			$original = $group->original();

			if (
				$original instanceof TranslationItem
				&& $original->object_type() === $source->object_type()
				&& $original->object_id() === $source->object_id()
				&& $original->language_code() === $source->language_code()
			) {
				return $group;
			}
		}

		return null;
	}

	/**
	 * Updates group metadata.
	 *
	 * @param string $group_key Group key.
	 * @param array  $metadata Group metadata.
	 * @return TranslationGroup|\WP_Error
	 */
	public function update_group_metadata( $group_key, array $metadata ) {
		$group = $this->find_group( $group_key );

		if ( ! $group instanceof TranslationGroup ) {
			return new \WP_Error( 'mclogiora_relation_group_not_found', __( 'The translation group could not be found.', 'mclogiora' ) );
		}

		return $group;
	}

	/**
	 * Archives a group without deleting data.
	 *
	 * @param string $group_key Group key.
	 * @return bool|\WP_Error
	 */
	public function archive_group( $group_key ) {
		return $this->find_group( $group_key ) instanceof TranslationGroup ? true : new \WP_Error( 'mclogiora_relation_group_not_found', __( 'The translation group could not be found.', 'mclogiora' ) );
	}

	/**
	 * Adds an item to a group.
	 *
	 * @param string          $group_key Group key.
	 * @param TranslationItem $item Translation item.
	 * @return TranslationItem|\WP_Error
	 */
	public function add_item_to_group( $group_key, TranslationItem $item ) {
		$group = $this->find_group( $group_key );

		if ( ! $group instanceof TranslationGroup ) {
			return new \WP_Error( 'mclogiora_relation_group_not_found', __( 'The translation group could not be found.', 'mclogiora' ) );
		}

		if ( $item->is_original() && $group->original() instanceof TranslationItem ) {
			return new \WP_Error( 'mclogiora_relation_original_exists', __( 'A translation group can only have one original item.', 'mclogiora' ) );
		}

		foreach ( $group->items() as $existing ) {
			if ( $existing->language_code() === $item->language_code() && TranslationStatus::DISABLED !== $existing->status() ) {
				return new \WP_Error( 'mclogiora_relation_language_exists', __( 'This translation group already has an item for that language.', 'mclogiora' ) );
			}
		}

		if ( $this->object_is_assigned( $item->object_type(), $item->object_id() ) ) {
			return new \WP_Error( 'mclogiora_relation_object_assigned', __( 'This object is already assigned to an active translation group.', 'mclogiora' ) );
		}

		$this->replace_group(
			new TranslationGroup(
				$group->group_key(),
				array_merge( $group->items(), array( $item ) )
			)
		);

		return $item;
	}

	/**
	 * Updates an item status.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @param string $status Translation status.
	 * @return TranslationItem|\WP_Error
	 */
	public function update_item_status( $object_type, $object_id, $language_code, $status ) {
		if ( ! TranslationStatus::is_valid( $status ) ) {
			return new \WP_Error( 'mclogiora_relation_status_invalid', __( 'The translation status is invalid.', 'mclogiora' ) );
		}

		$item = $this->find_item( $object_type, $object_id, $language_code );

		if ( ! $item instanceof TranslationItem ) {
			return new \WP_Error( 'mclogiora_relation_item_not_found', __( 'The translation item could not be found.', 'mclogiora' ) );
		}

		if ( $item->is_original() && TranslationStatus::ORIGINAL !== $status ) {
			return new \WP_Error( 'mclogiora_relation_original_status_locked', __( 'The original item status cannot be changed in this phase.', 'mclogiora' ) );
		}

		if ( ! $item->is_original() && TranslationStatus::ORIGINAL === $status ) {
			return new \WP_Error( 'mclogiora_relation_original_status_invalid', __( 'Only the source item may use the original status.', 'mclogiora' ) );
		}

		return $this->replace_item(
			$item,
			new TranslationItem(
				$item->object_type(),
				$item->object_id(),
				$item->language_code(),
				$status,
				$item->is_original(),
				$item->source_hash(),
				$item->translated_source_hash(),
				$item->source_modified(),
				$item->translation_modified()
			)
		);
	}

	/**
	 * Updates an item language.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Current language code.
	 * @param string $new_language_code New language code.
	 * @return TranslationItem|\WP_Error
	 */
	public function update_item_language( $object_type, $object_id, $language_code, $new_language_code ) {
		$item = $this->find_item( $object_type, $object_id, $language_code );

		if ( ! $item instanceof TranslationItem ) {
			return new \WP_Error( 'mclogiora_relation_item_not_found', __( 'The translation item could not be found.', 'mclogiora' ) );
		}

		return $this->replace_item(
			$item,
			new TranslationItem(
				$item->object_type(),
				$item->object_id(),
				$new_language_code,
				$item->status(),
				$item->is_original(),
				$item->source_hash(),
				$item->translated_source_hash(),
				$item->source_modified(),
				$item->translation_modified()
			)
		);
	}

	/**
	 * Updates source metadata placeholder fields.
	 *
	 * @param TranslationItem $item Translation item.
	 * @return TranslationItem|\WP_Error
	 */
	public function update_item_source_metadata( TranslationItem $item ) {
		$existing = $this->find_item( $item->object_type(), $item->object_id(), $item->language_code() );

		if ( ! $existing instanceof TranslationItem ) {
			return new \WP_Error( 'mclogiora_relation_item_not_found', __( 'The translation item could not be found.', 'mclogiora' ) );
		}

		return $this->replace_item( $existing, $item );
	}

	/**
	 * Finds an item by object identity and language.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return TranslationItem|null
	 */
	public function find_item( $object_type, $object_id, $language_code ) {
		foreach ( $this->groups as $group ) {
			foreach ( $group->items() as $item ) {
				if (
					$item->object_type() === sanitize_key( $object_type )
					&& $item->object_id() === sanitize_text_field( (string) $object_id )
					&& $item->language_code() === sanitize_key( $language_code )
				) {
					return $item;
				}
			}
		}

		return null;
	}

	/**
	 * Returns items for a group.
	 *
	 * @param string $group_key Group key.
	 * @return TranslationItem[]
	 */
	public function items_for_group( $group_key ) {
		$group = $this->find_group( $group_key );

		return $group instanceof TranslationGroup ? $group->items() : array();
	}

	/**
	 * Returns items by status.
	 *
	 * @param string $status Translation status.
	 * @return TranslationItem[]
	 */
	public function items_by_status( $status ) {
		$items = array();

		foreach ( $this->groups as $group ) {
			foreach ( $group->items() as $item ) {
				if ( $item->status() === $status ) {
					$items[] = $item;
				}
			}
		}

		return $items;
	}

	/**
	 * Returns original item for a group.
	 *
	 * @param string $group_key Group key.
	 * @return TranslationItem|null
	 */
	public function original_for_group( $group_key ) {
		$group = $this->find_group( $group_key );

		return $group instanceof TranslationGroup ? $group->original() : null;
	}

	/**
	 * Returns whether an object is assigned to an active group.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @return bool
	 */
	public function object_is_assigned( $object_type, $object_id ) {
		foreach ( $this->groups as $group ) {
			foreach ( $group->items() as $item ) {
				if (
					$item->object_type() === sanitize_key( $object_type )
					&& $item->object_id() === sanitize_text_field( (string) $object_id )
					&& TranslationStatus::DISABLED !== $item->status()
				) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Safely detaches a target item by disabling it.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return bool|\WP_Error
	 */
	public function detach_item( $object_type, $object_id, $language_code ) {
		$item = $this->find_item( $object_type, $object_id, $language_code );

		if ( ! $item instanceof TranslationItem ) {
			return new \WP_Error( 'mclogiora_relation_item_not_found', __( 'The translation item could not be found.', 'mclogiora' ) );
		}

		if ( $item->is_original() ) {
			return new \WP_Error( 'mclogiora_relation_detach_original', __( 'The original item cannot be detached in this phase.', 'mclogiora' ) );
		}

		$result = $this->update_item_status( $item->object_type(), $item->object_id(), $item->language_code(), TranslationStatus::DISABLED );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Returns translations for an item.
	 *
	 * @param TranslationItem $item Translation item.
	 * @return TranslationItem[]
	 */
	public function translations_for_item( TranslationItem $item ) {
		foreach ( $this->groups as $group ) {
			if ( $group->contains( $item ) ) {
				return $group->targets();
			}
		}

		return array();
	}

	/**
	 * Returns all placeholder groups.
	 *
	 * @return TranslationGroup[]
	 */
	public function all() {
		return $this->groups;
	}

	/**
	 * Counts active translation groups.
	 *
	 * @return int
	 */
	public function count_groups() {
		return count( $this->groups );
	}

	/**
	 * Counts translation items.
	 *
	 * @return int
	 */
	public function count_items() {
		$count = 0;

		foreach ( $this->groups as $group ) {
			$count += count( $group->items() );
		}

		return $count;
	}

	/**
	 * Replaces a group.
	 *
	 * @param TranslationGroup $replacement Replacement group.
	 * @return void
	 */
	private function replace_group( TranslationGroup $replacement ) {
		foreach ( $this->groups as $index => $group ) {
			if ( $group->group_key() === $replacement->group_key() ) {
				$this->groups[ $index ] = $replacement;
				return;
			}
		}
	}

	/**
	 * Replaces an item.
	 *
	 * @param TranslationItem $old Old item.
	 * @param TranslationItem $new New item.
	 * @return TranslationItem
	 */
	private function replace_item( TranslationItem $old, TranslationItem $new ) {
		foreach ( $this->groups as $group_index => $group ) {
			$items = array();
			$found = false;

			foreach ( $group->items() as $item ) {
				if (
					$item->object_type() === $old->object_type()
					&& $item->object_id() === $old->object_id()
					&& $item->language_code() === $old->language_code()
				) {
					$items[] = $new;
					$found   = true;
				} else {
					$items[] = $item;
				}
			}

			if ( $found ) {
				$this->groups[ $group_index ] = new TranslationGroup( $group->group_key(), $items );
				return $new;
			}
		}

		return $new;
	}
}
