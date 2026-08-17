<?php
/**
 * Cached translation relation repository.
 *
 * @package McLogiora
 */

namespace McLogiora\Relations;

use McLogiora\Cache\CacheInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Adds cache reads around a relation repository.
 */
final class CachedTranslationRelationRepository implements TranslationRelationRepositoryInterface {
	const CACHE_KEY_ALL = 'translation_groups_all';

	/**
	 * Inner repository.
	 *
	 * @var TranslationRelationRepositoryInterface
	 */
	private $repository;

	/**
	 * Cache.
	 *
	 * @var CacheInterface
	 */
	private $cache;

	/**
	 * Constructor.
	 *
	 * @param TranslationRelationRepositoryInterface $repository Inner repository.
	 * @param CacheInterface                         $cache Cache.
	 */
	public function __construct( TranslationRelationRepositoryInterface $repository, CacheInterface $cache ) {
		$this->repository = $repository;
		$this->cache      = $cache;
	}

	/**
	 * Creates an empty translation group.
	 *
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_empty_group() {
		$result = $this->repository->create_empty_group();
		$this->invalidate_after_write( $result );

		return $result;
	}

	/**
	 * Creates a placeholder group.
	 *
	 * @param TranslationItem $original Original item.
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_group_placeholder( TranslationItem $original ) {
		$result = $this->repository->create_group_placeholder( $original );
		$this->invalidate_after_write( $result );

		return $result;
	}

	/**
	 * Creates a placeholder group with a supplied key.
	 *
	 * @param string          $group_key Group key.
	 * @param TranslationItem $original Original item.
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_group_placeholder_with_key( $group_key, TranslationItem $original ) {
		$result = $this->repository->create_group_placeholder_with_key( $group_key, $original );
		$this->invalidate_after_write( $result, $group_key );

		return $result;
	}

	/**
	 * Finds a group by its key.
	 *
	 * @param string $group_key Group key.
	 * @return TranslationGroup|null
	 */
	public function find_group( $group_key ) {
		$key    = $this->group_cache_key( $group_key );
		$cached = $this->cache->get( $key );

		if ( false !== $cached ) {
			return $cached;
		}

		$group = $this->repository->find_group( $group_key );
		$this->cache->set( $key, $group );

		return $group;
	}

	/**
	 * Finds a group by internal numeric ID.
	 *
	 * @param int $group_id Internal group ID.
	 * @return TranslationGroup|null
	 */
	public function find_group_by_id( $group_id ) {
		return $this->repository->find_group_by_id( $group_id );
	}

	/**
	 * Finds a group by source item.
	 *
	 * @param TranslationItem $source Source item.
	 * @return TranslationGroup|null
	 */
	public function find_group_by_source( TranslationItem $source ) {
		return $this->repository->find_group_by_source( $source );
	}

	/**
	 * Updates group metadata.
	 *
	 * @param string $group_key Group key.
	 * @param array  $metadata Group metadata.
	 * @return TranslationGroup|\WP_Error
	 */
	public function update_group_metadata( $group_key, array $metadata ) {
		$result = $this->repository->update_group_metadata( $group_key, $metadata );
		$this->invalidate_after_write( $result, $group_key );

		return $result;
	}

	/**
	 * Archives a group without deleting data.
	 *
	 * @param string $group_key Group key.
	 * @return bool|\WP_Error
	 */
	public function archive_group( $group_key ) {
		$result = $this->repository->archive_group( $group_key );
		$this->invalidate_after_write( $result, $group_key );

		return $result;
	}

	/**
	 * Adds an item to a group.
	 *
	 * @param string          $group_key Group key.
	 * @param TranslationItem $item Translation item.
	 * @return TranslationItem|\WP_Error
	 */
	public function add_item_to_group( $group_key, TranslationItem $item ) {
		$result = $this->repository->add_item_to_group( $group_key, $item );
		$this->invalidate_after_write( $result, $group_key );

		return $result;
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
		$group_key = $this->group_key_for_item( $object_type, $object_id, $language_code );
		$result    = $this->repository->update_item_status( $object_type, $object_id, $language_code, $status );
		$this->invalidate_after_write( $result, $group_key );

		return $result;
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
		$group_key = $this->group_key_for_item( $object_type, $object_id, $language_code );
		$result    = $this->repository->update_item_language( $object_type, $object_id, $language_code, $new_language_code );
		$this->invalidate_after_write( $result, $group_key );

		return $result;
	}

	/**
	 * Updates source metadata placeholder fields.
	 *
	 * @param TranslationItem $item Translation item.
	 * @return TranslationItem|\WP_Error
	 */
	public function update_item_source_metadata( TranslationItem $item ) {
		$group_key = $this->group_key_for_item( $item->object_type(), $item->object_id(), $item->language_code() );
		$result    = $this->repository->update_item_source_metadata( $item );
		$this->invalidate_after_write( $result, $group_key );

		return $result;
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
		return $this->repository->find_item( $object_type, $object_id, $language_code );
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
		return $this->repository->items_by_status( $status );
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
		return $this->repository->object_is_assigned( $object_type, $object_id );
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
		$group_key = $this->group_key_for_item( $object_type, $object_id, $language_code );
		$result    = $this->repository->detach_item( $object_type, $object_id, $language_code );
		$this->invalidate_after_write( $result, $group_key );

		return $result;
	}

	/**
	 * Returns translations for an item.
	 *
	 * @param TranslationItem $item Translation item.
	 * @return TranslationItem[]
	 */
	public function translations_for_item( TranslationItem $item ) {
		return $this->repository->translations_for_item( $item );
	}

	/**
	 * Returns all groups.
	 *
	 * @return TranslationGroup[]
	 */
	public function all() {
		$cached = $this->cache->get( self::CACHE_KEY_ALL );

		if ( false !== $cached ) {
			return $cached;
		}

		$groups = $this->repository->all();
		$this->cache->set( self::CACHE_KEY_ALL, $groups );

		return $groups;
	}

	/**
	 * Returns active group keys in a stable order.
	 *
	 * Deliberately not cached. Its only caller walks every page exactly once
	 * during an export, so a cache entry per page would be written and never
	 * read again, and would go stale the moment a group is created.
	 *
	 * @param int $limit Maximum keys to return.
	 * @param int $offset Number of keys to skip.
	 * @return string[]
	 */
	public function active_group_keys( $limit, $offset = 0 ) {
		return $this->repository->active_group_keys( $limit, $offset );
	}

	/**
	 * Counts active translation groups.
	 *
	 * @return int
	 */
	public function count_groups() {
		return $this->repository->count_groups();
	}

	/**
	 * Counts translation items.
	 *
	 * @return int
	 */
	public function count_items() {
		return $this->repository->count_items();
	}

	/**
	 * Invalidates relation cache after successful writes.
	 *
	 * @param mixed  $result Write result.
	 * @param string $group_key Group key.
	 * @return void
	 */
	private function invalidate_after_write( $result, $group_key = '' ) {
		if ( is_wp_error( $result ) ) {
			return;
		}

		$this->cache->delete( self::CACHE_KEY_ALL );

		if ( '' !== $group_key ) {
			$this->cache->delete( $this->group_cache_key( $group_key ) );
		}

		if ( $result instanceof TranslationGroup ) {
			$this->cache->delete( $this->group_cache_key( $result->group_key() ) );
		}
	}

	/**
	 * Finds the group containing an item before an item mutation.
	 *
	 * Item writes return the item rather than its group key, while the group
	 * cache is keyed by that UUID. Enumerating the existing stable group-key
	 * reader keeps this lookup inside the repository boundary and works with a
	 * persistent object cache without relying on process-local state.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return string
	 */
	private function group_key_for_item( $object_type, $object_id, $language_code ) {
		$item = $this->repository->find_item( $object_type, $object_id, $language_code );

		if ( ! $item instanceof TranslationItem ) {
			return '';
		}

		$limit     = 100;
		$offset    = 0;
		$keys      = array();
		$key_count = 0;

		do {
			$keys = $this->repository->active_group_keys( $limit, $offset );

			foreach ( $keys as $group_key ) {
				$group = $this->repository->find_group( $group_key );

				if ( $group instanceof TranslationGroup && $group->contains( $item ) ) {
					return $group->group_key();
				}
			}

			$offset   += count( $keys );
			$key_count = count( $keys );
		} while ( $key_count === $limit );

		return '';
	}

	/**
	 * Builds a group cache key.
	 *
	 * @param string $group_key Group key.
	 * @return string
	 */
	private function group_cache_key( $group_key ) {
		return self::cache_key_for_group( $group_key );
	}

	/**
	 * Returns the stable cache key for one translation group.
	 *
	 * @param string $group_key Group key.
	 * @return string
	 */
	public static function cache_key_for_group( $group_key ) {
		return 'translation_group_' . md5( (string) $group_key );
	}
}
