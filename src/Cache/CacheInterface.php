<?php
/**
 * Cache contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for cache implementations.
 */
interface CacheInterface {
	/**
	 * Gets a cached value.
	 *
	 * @param string $key Cache key.
	 * @return mixed|false
	 */
	public function get( $key );

	/**
	 * Sets a cached value.
	 *
	 * @param string $key Cache key.
	 * @param mixed  $value Cache value.
	 * @param int    $ttl Time to live in seconds.
	 * @return bool
	 */
	public function set( $key, $value, $ttl = 0 );

	/**
	 * Deletes a cached value.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public function delete( $key );
}
