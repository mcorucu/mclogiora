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
	 * Builds the registry with the core adapters and any filtered additions.
	 *
	 * Named rather than inlined into the container so the extension filter has
	 * one reachable, testable entry point, matching how the widget adapter
	 * registry is built. A hook that can only be exercised through a cached
	 * container entry cannot be qualified as a contract.
	 *
	 * @return self
	 */
	public static function with_core_adapters() {
		$registry = new self();

		/*
		 * All three are registered unconditionally and each reports its own
		 * availability, so a site without Elementor, Beaver Builder or ACF
		 * loads them without touching any of those plugins' classes.
		 */
		$registry->add( new ElementorPayloadAdapter() );
		$registry->add( new BeaverBuilderPayloadAdapter() );
		$registry->add( new AcfPayloadAdapter() );

		/**
		 * Filters the additional translation payload adapters to register.
		 *
		 * An adapter gives a newly created translation the starting state its
		 * builder expects. It copies structure, never meaning; nothing here
		 * translates text.
		 *
		 * The filtered value always starts as an empty array, so this hook only
		 * ever adds adapters. The core adapters are registered before it runs
		 * and cannot be removed through it. Entries that do not implement the
		 * interface are ignored, as is a non-array return.
		 *
		 * @since 0.13.0
		 *
		 * @param TranslationPayloadAdapterInterface[] $extra Adapters to add. Empty by default.
		 * @param PayloadAdapterRegistry               $registry Registry the adapters are added to.
		 */
		$extra = apply_filters( 'mclogiora_register_payload_adapters', array(), $registry );

		if ( is_array( $extra ) ) {
			foreach ( $extra as $adapter ) {
				if ( $adapter instanceof TranslationPayloadAdapterInterface ) {
					$registry->add( $adapter );
				}
			}
		}

		return $registry;
	}

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
