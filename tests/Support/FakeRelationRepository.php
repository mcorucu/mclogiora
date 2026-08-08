<?php
/**
 * In-memory relation repository for tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Support;

use McLogiora\Relations\TranslationGroup;
use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationRelationRepositoryInterface;
use McLogiora\Relations\TranslationStatus;

/**
 * Stores relation records in memory and enforces the same integrity rules
 * the database repository enforces: one item per language per group, and one
 * active group assignment per object.
 */
final class FakeRelationRepository implements TranslationRelationRepositoryInterface {
	/**
	 * Items keyed by group key.
	 *
	 * @var array<string,TranslationItem[]>
	 */
	private $groups = array();

	/**
	 * Next generated group key.
	 *
	 * @var int
	 */
	private $sequence = 0;

	/**
	 * Forces the next add_item_to_group() call to fail.
	 *
	 * @var \WP_Error|null
	 */
	public $add_item_error = null;

	/**
	 * {@inheritDoc}
	 *
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_empty_group() {
		++$this->sequence;
		$key                  = 'group-' . $this->sequence;
		$this->groups[ $key ] = array();

		return new TranslationGroup( $key, array() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param TranslationItem $original Original item.
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_group_placeholder( TranslationItem $original ) {
		$group = $this->create_empty_group();

		return $this->add_item_to_group( $group->group_key(), $original ) instanceof \WP_Error
			? new \WP_Error( 'mclogiora_group_failed', 'Group creation failed.' )
			: new TranslationGroup( $group->group_key(), $this->groups[ $group->group_key() ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $group_key Group key.
	 * @return TranslationGroup|null
	 */
	public function find_group( $group_key ) {
		if ( ! isset( $this->groups[ (string) $group_key ] ) ) {
			return null;
		}

		return new TranslationGroup( (string) $group_key, $this->groups[ (string) $group_key ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $group_id Group ID.
	 * @return TranslationGroup|null
	 */
	public function find_group_by_id( $group_id ) {
		return $this->find_group( 'group-' . (int) $group_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param TranslationItem $source Source item.
	 * @return TranslationGroup|null
	 */
	public function find_group_by_source( TranslationItem $source ) {
		foreach ( $this->groups as $key => $items ) {
			foreach ( $items as $item ) {
				if ( $item->is_original()
					&& $item->object_type() === $source->object_type()
					&& $item->object_id() === $source->object_id() ) {
					return new TranslationGroup( $key, $items );
				}
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $group_key Group key.
	 * @param array<string,mixed>  $metadata Metadata.
	 * @return TranslationGroup|\WP_Error
	 */
	public function update_group_metadata( $group_key, array $metadata ) {
		unset( $metadata );
		$group = $this->find_group( $group_key );

		return null === $group ? new \WP_Error( 'mclogiora_group_missing', 'Group not found.' ) : $group;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $group_key Group key.
	 * @return bool|\WP_Error
	 */
	public function archive_group( $group_key ) {
		unset( $this->groups[ (string) $group_key ] );

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string          $group_key Group key.
	 * @param TranslationItem $item Item.
	 * @return TranslationItem|\WP_Error
	 */
	public function add_item_to_group( $group_key, TranslationItem $item ) {
		if ( $this->add_item_error instanceof \WP_Error ) {
			return $this->add_item_error;
		}

		$group_key = (string) $group_key;

		if ( ! isset( $this->groups[ $group_key ] ) ) {
			return new \WP_Error( 'mclogiora_group_missing', 'Group not found.' );
		}

		foreach ( $this->groups[ $group_key ] as $existing ) {
			if ( $existing->language_code() === $item->language_code() ) {
				return new \WP_Error( 'mclogiora_language_slot_taken', 'Language already present in group.' );
			}
		}

		if ( $this->object_is_assigned( $item->object_type(), $item->object_id() ) ) {
			return new \WP_Error( 'mclogiora_object_already_related', 'Object already assigned.' );
		}

		$this->groups[ $group_key ][] = $item;

		return $item;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @param string $status Status.
	 * @return TranslationItem|\WP_Error
	 */
	public function update_item_status( $object_type, $object_id, $language_code, $status ) {
		foreach ( $this->groups as $key => $items ) {
			foreach ( $items as $index => $item ) {
				if ( $item->object_type() === (string) $object_type
					&& $item->object_id() === (string) $object_id
					&& $item->language_code() === (string) $language_code ) {

					$updated = new TranslationItem(
						$item->content_type(),
						$item->object_key(),
						$item->language_code(),
						(string) $status,
						$item->is_original(),
						$item->source_hash(),
						$item->translated_source_hash(),
						$item->source_modified(),
						$item->translation_modified()
					);

					$this->groups[ $key ][ $index ] = $updated;

					return $updated;
				}
			}
		}

		return new \WP_Error( 'mclogiora_item_missing', 'Item not found.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @param string $new_language_code New language code.
	 * @return TranslationItem|\WP_Error
	 */
	public function update_item_language( $object_type, $object_id, $language_code, $new_language_code ) {
		unset( $object_type, $object_id, $language_code, $new_language_code );

		return new \WP_Error( 'mclogiora_not_supported', 'Not used in tests.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param TranslationItem $item Item.
	 * @return TranslationItem|\WP_Error
	 */
	public function update_item_source_metadata( TranslationItem $item ) {
		foreach ( $this->groups as $key => $items ) {
			foreach ( $items as $index => $existing ) {
				if ( $existing->object_type() === $item->object_type()
					&& $existing->object_id() === $item->object_id()
					&& $existing->language_code() === $item->language_code() ) {
					$this->groups[ $key ][ $index ] = $item;

					return $item;
				}
			}
		}

		return new \WP_Error( 'mclogiora_item_missing', 'Item not found.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return TranslationItem|null
	 */
	public function find_item( $object_type, $object_id, $language_code ) {
		foreach ( $this->groups as $items ) {
			foreach ( $items as $item ) {
				if ( $item->object_type() === (string) $object_type
					&& $item->object_id() === (string) $object_id
					&& $item->language_code() === (string) $language_code ) {
					return $item;
				}
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $group_key Group key.
	 * @return TranslationItem[]
	 */
	public function items_for_group( $group_key ) {
		return isset( $this->groups[ (string) $group_key ] ) ? $this->groups[ (string) $group_key ] : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $status Status.
	 * @return TranslationItem[]
	 */
	public function items_by_status( $status ) {
		$found = array();

		foreach ( $this->groups as $items ) {
			foreach ( $items as $item ) {
				if ( $item->status() === (string) $status ) {
					$found[] = $item;
				}
			}
		}

		return $found;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $group_key Group key.
	 * @return TranslationItem|null
	 */
	public function original_for_group( $group_key ) {
		foreach ( $this->items_for_group( $group_key ) as $item ) {
			if ( $item->is_original() ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @return bool
	 */
	public function object_is_assigned( $object_type, $object_id ) {
		foreach ( $this->groups as $items ) {
			foreach ( $items as $item ) {
				if ( $item->object_type() === (string) $object_type && $item->object_id() === (string) $object_id ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return bool|\WP_Error
	 */
	public function detach_item( $object_type, $object_id, $language_code ) {
		foreach ( $this->groups as $key => $items ) {
			foreach ( $items as $index => $item ) {
				if ( $item->object_type() === (string) $object_type
					&& $item->object_id() === (string) $object_id
					&& $item->language_code() === (string) $language_code ) {

					if ( $item->is_original() && count( $items ) > 1 ) {
						return new \WP_Error(
							'mclogiora_cannot_detach_source',
							'The source cannot be detached while translations remain.'
						);
					}

					unset( $this->groups[ $key ][ $index ] );
					$this->groups[ $key ] = array_values( $this->groups[ $key ] );

					return true;
				}
			}
		}

		return new \WP_Error( 'mclogiora_item_missing', 'Item not found.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param TranslationItem $item Item.
	 * @return TranslationItem[]
	 */
	public function translations_for_item( TranslationItem $item ) {
		foreach ( $this->groups as $items ) {
			foreach ( $items as $existing ) {
				if ( $existing->object_type() === $item->object_type() && $existing->object_id() === $item->object_id() ) {
					return $items;
				}
			}
		}

		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return TranslationGroup[]
	 */
	public function all() {
		$groups = array();

		foreach ( $this->groups as $key => $items ) {
			$groups[] = new TranslationGroup( $key, $items );
		}

		return $groups;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function count_groups() {
		return count( $this->groups );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function count_items() {
		$total = 0;

		foreach ( $this->groups as $items ) {
			$total += count( $items );
		}

		return $total;
	}

	/**
	 * Seeds a group with items, bypassing validation.
	 *
	 * @param string            $group_key Group key.
	 * @param TranslationItem[] $items Items.
	 * @return void
	 */
	public function seed_group( $group_key, array $items ) {
		$this->groups[ (string) $group_key ] = $items;
	}

	/**
	 * Returns a default draft status for convenience.
	 *
	 * @return string
	 */
	public function default_status() {
		return TranslationStatus::DRAFT;
	}
}
