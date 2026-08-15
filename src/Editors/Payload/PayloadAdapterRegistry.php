<?php
/**
 * Translation payload adapter registry.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors\Payload;

defined( 'ABSPATH' ) || exit;

/**
 * Runs whichever payload adapters apply to a newly created translation.
 *
 * Adapters are asked in registration order and the first failure stops the
 * run, so a caller gets one error rather than a partially prepared draft to
 * reason about. Builders beyond Elementor arrive in Phase 15 by registering
 * here; nothing in the workflow names a builder.
 */
final class PayloadAdapterRegistry {
	/**
	 * Registered adapters.
	 *
	 * @var TranslationPayloadAdapterInterface[]
	 */
	private $adapters = array();

	/**
	 * Adds an adapter.
	 *
	 * @param TranslationPayloadAdapterInterface $adapter Adapter.
	 * @return void
	 */
	public function add( TranslationPayloadAdapterInterface $adapter ) {
		$this->adapters[ $adapter->id() ] = $adapter;
	}

	/**
	 * Returns every registered adapter.
	 *
	 * @return TranslationPayloadAdapterInterface[]
	 */
	public function all() {
		return array_values( $this->adapters );
	}

	/**
	 * Returns the adapters whose integration is installed.
	 *
	 * @return TranslationPayloadAdapterInterface[]
	 */
	public function available() {
		return array_values(
			array_filter(
				$this->adapters,
				static function ( TranslationPayloadAdapterInterface $adapter ) {
					return $adapter->is_available();
				}
			)
		);
	}

	/**
	 * Prepares a freshly created translation.
	 *
	 * @param int $source_id Source post identifier.
	 * @param int $target_id Newly created translation identifier.
	 * @return true|\WP_Error
	 */
	public function prepare( $source_id, $target_id ) {
		foreach ( $this->available() as $adapter ) {
			if ( ! $adapter->applies_to( $source_id ) ) {
				continue;
			}

			$result = $adapter->copy( $source_id, $target_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}
}
