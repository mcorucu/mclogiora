<?php
/**
 * Clears cache entries that an import plan can repopulate before rollback.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

use McLogiora\Cache\CacheInterface;
use McLogiora\Languages\CachedLanguageRepository;
use McLogiora\Relations\CachedTranslationRelationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps rollback cache recovery targeted and persistent-cache safe.
 */
final class ImportRollbackCacheInvalidator {
	/**
	 * Cache backend.
	 *
	 * @var CacheInterface
	 */
	private $cache;

	/**
	 * Constructor.
	 *
	 * @param CacheInterface $cache Cache backend.
	 */
	public function __construct( CacheInterface $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Deletes mcLogiora cache entries that may contain uncommitted plan state.
	 *
	 * CacheInterface::delete() reaches the configured WordPress object-cache
	 * backend, so this also removes persistent entries rather than only clearing
	 * the current PHP process.
	 *
	 * @param ImportPlan $plan Rolled-back plan.
	 * @return void
	 */
	public function invalidate( ImportPlan $plan ) {
		$this->cache->delete( CachedLanguageRepository::CACHE_KEY_ALL );
		$this->cache->delete( CachedTranslationRelationRepository::CACHE_KEY_ALL );
		$group_keys = array();

		foreach ( $plan->operations() as $operation ) {
			$detail = $operation->detail();
			if ( ! isset( $detail['group_key'] ) || '' === (string) $detail['group_key'] ) {
				continue;
			}

			$group_keys[ (string) $detail['group_key'] ] = true;
		}

		foreach ( array_keys( $group_keys ) as $group_key ) {
			$this->cache->delete( CachedTranslationRelationRepository::cache_key_for_group( $group_key ) );
		}
	}
}
