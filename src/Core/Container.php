<?php
/**
 * Minimal service container.
 *
 * @package McLogiora
 */

namespace McLogiora\Core;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Stores shared services and lazy factories.
 */
final class Container {
	/**
	 * Service factories.
	 *
	 * @var array<string, callable|object|string|int|float|bool|array|null>
	 */
	private $entries = array();

	/**
	 * Resolved service instances.
	 *
	 * @var array<string, mixed>
	 */
	private $resolved = array();

	/**
	 * Adds a service or factory.
	 *
	 * @param string $id Service identifier.
	 * @param mixed  $entry Service instance, scalar, array, or factory.
	 * @return void
	 */
	public function set( $id, $entry ) {
		$this->entries[ $id ] = $entry;
		unset( $this->resolved[ $id ] );
	}

	/**
	 * Returns whether a service exists.
	 *
	 * @param string $id Service identifier.
	 * @return bool
	 */
	public function has( $id ) {
		return array_key_exists( $id, $this->entries );
	}

	/**
	 * Resolves a service.
	 *
	 * @param string $id Service identifier.
	 * @return mixed
	 * @throws InvalidArgumentException When the service is not registered.
	 */
	public function get( $id ) {
		if ( array_key_exists( $id, $this->resolved ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! $this->has( $id ) ) {
			throw new InvalidArgumentException( esc_html( sprintf( 'Service "%s" is not registered.', $id ) ) );
		}

		$entry = $this->entries[ $id ];

		if ( is_callable( $entry ) ) {
			$entry = $entry( $this );
		}

		$this->resolved[ $id ] = $entry;

		return $entry;
	}
}
