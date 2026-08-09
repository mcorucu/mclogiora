<?php
/**
 * In-memory cache for tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Support;

use McLogiora\Cache\CacheInterface;

/**
 * Records cache activity so invalidation can be asserted.
 */
final class ArrayCache implements CacheInterface {
	/**
	 * Stored values.
	 *
	 * @var array<string,mixed>
	 */
	private $values = array();

	/**
	 * Keys deleted through this cache.
	 *
	 * @var string[]
	 */
	public $deleted = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Cache key.
	 * @return mixed
	 */
	public function get( $key ) {
		return array_key_exists( (string) $key, $this->values ) ? $this->values[ (string) $key ] : false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Cache key.
	 * @param mixed  $value Value.
	 * @param int    $ttl Time to live.
	 * @return bool
	 */
	public function set( $key, $value, $ttl = 0 ) {
		unset( $ttl );
		$this->values[ (string) $key ] = $value;

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public function delete( $key ) {
		$this->deleted[] = (string) $key;
		unset( $this->values[ (string) $key ] );

		return true;
	}
}
