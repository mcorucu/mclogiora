<?php
/**
 * Translation suggestion provider registry.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

use McLogiora\Contracts\TranslationProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the available suggestion providers and lets sites add their own.
 *
 * Registration says nothing about whether a provider will ever be used. Every
 * built-in provider is registered on every site, reports its own configured
 * state, and stays completely inert until an owner enables suggestions and a
 * user asks for a suggestion. Registering is not enabling.
 */
final class ProviderRegistry {
	/**
	 * Registered providers keyed by identifier.
	 *
	 * @var array<string,TranslationProviderInterface>
	 */
	private $providers = array();

	/**
	 * Adds a provider, replacing any earlier one with the same identifier.
	 *
	 * @param TranslationProviderInterface $provider Provider instance.
	 * @return void
	 */
	public function add( TranslationProviderInterface $provider ) {
		$id = (string) $provider->get_id();

		if ( '' === $id ) {
			return;
		}

		$this->providers[ $id ] = $provider;
	}

	/**
	 * Returns every registered provider.
	 *
	 * @return array<string,TranslationProviderInterface>
	 */
	public function all() {
		return $this->providers;
	}

	/**
	 * Returns one provider by identifier.
	 *
	 * @param string $id Provider identifier.
	 * @return TranslationProviderInterface|null
	 */
	public function find( $id ) {
		$id = (string) $id;

		return isset( $this->providers[ $id ] ) ? $this->providers[ $id ] : null;
	}

	/**
	 * Returns whether a provider is registered.
	 *
	 * @param string $id Provider identifier.
	 * @return bool
	 */
	public function has( $id ) {
		return null !== $this->find( $id );
	}

	/**
	 * Returns only the providers that are ready to be used.
	 *
	 * @return array<string,TranslationProviderInterface>
	 */
	public function configured() {
		$configured = array();

		foreach ( $this->providers as $id => $provider ) {
			if ( $provider->is_configured() ) {
				$configured[ $id ] = $provider;
			}
		}

		return $configured;
	}
}
