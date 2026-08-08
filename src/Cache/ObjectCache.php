<?php
/**
 * WordPress object cache implementation.
 *
 * @package McLogiora
 */

namespace McLogiora\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Uses WordPress Object Cache as the default cache layer.
 */
final class ObjectCache implements CacheInterface {
	const GROUP = 'mclogiora';

	/**
	 * Gets a cached value.
	 *
	 * @param string $key Cache key.
	 * @return mixed|false
	 */
	public function get( $key ) {
		return wp_cache_get( sanitize_key( $key ), self::GROUP );
	}

	/**
	 * Sets a cached value.
	 *
	 * @param string $key Cache key.
	 * @param mixed  $value Cache value.
	 * @param int    $ttl Time to live in seconds.
	 * @return bool
	 */
	public function set( $key, $value, $ttl = 0 ) {
		return wp_cache_set( sanitize_key( $key ), $value, self::GROUP, absint( $ttl ) );
	}

	/**
	 * Deletes a cached value.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public function delete( $key ) {
		return wp_cache_delete( sanitize_key( $key ), self::GROUP );
	}
}
