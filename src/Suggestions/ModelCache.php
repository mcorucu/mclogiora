<?php
/**
 * Local cache of provider model choices.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers the models a provider offered, so the screen never has to ask.
 *
 * The settings screen must render without touching the network, and a model
 * dropdown needs a list of models. This holds that list between explicit
 * refreshes.
 *
 * ## What is kept, and what is not
 *
 * Only the normalised triple the screen actually draws: identifier, label and
 * whether it is worth highlighting. Raw provider responses are not stored --
 * they carry pricing, capability flags, ownership and timestamps that nothing
 * here reads, and storing a third party's full payload in the options table is
 * a liability with no upside.
 *
 * ## Expiry never changes what the owner chose
 *
 * The cache holds *available* models. The owner's *selected* model lives
 * elsewhere, in the provider's own option, and nothing in this class can touch
 * it. That separation is deliberate: if a cache expiry could clear a
 * selection, a site would silently stop being able to generate suggestions
 * after a week of nobody visiting the settings screen. An expired cache means
 * the dropdown has nothing to offer until the owner refreshes, and nothing
 * else.
 */
final class ModelCache {
	/**
	 * Transient name prefix.
	 */
	const PREFIX = 'mclogiora_suggestion_models_';

	/**
	 * How long a refreshed list is considered current, in seconds.
	 *
	 * Twelve hours. Model catalogues change on the order of weeks, and the
	 * list is not secret, so this is about keeping the screen honest rather
	 * than about protecting anything. Nothing refreshes it automatically when
	 * it lapses.
	 */
	const LIFETIME = 43200;

	/**
	 * Stores a normalised model list for a provider.
	 *
	 * @param string                                                    $provider_id Provider identifier.
	 * @param array<int,array{id:string,label:string,recommended:bool}> $models Normalised models.
	 * @return void
	 */
	public function put( $provider_id, array $models ) {
		$normalised = array();

		foreach ( $models as $model ) {
			if ( ! isset( $model['id'] ) || '' === (string) $model['id'] ) {
				continue;
			}

			$normalised[] = array(
				'id'          => (string) $model['id'],
				'label'       => isset( $model['label'] ) ? (string) $model['label'] : (string) $model['id'],
				'recommended' => ! empty( $model['recommended'] ),
			);
		}

		set_transient( $this->key( $provider_id ), $normalised, self::LIFETIME );
	}

	/**
	 * Returns the cached model list, or an empty list.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return array<int,array{id:string,label:string,recommended:bool}>
	 */
	public function get( $provider_id ) {
		$cached = get_transient( $this->key( $provider_id ) );

		return is_array( $cached ) ? $cached : array();
	}

	/**
	 * Returns whether a model identifier is one the provider offered.
	 *
	 * The settings screen validates a submitted model against this rather than
	 * accepting whatever the browser sent. A model identifier goes into an API
	 * request path, so "some string the form posted" is not an acceptable
	 * source for it.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $model_id Model identifier to check.
	 * @return bool
	 */
	public function offers( $provider_id, $model_id ) {
		$model_id = (string) $model_id;

		if ( '' === $model_id ) {
			return false;
		}

		foreach ( $this->get( $provider_id ) as $model ) {
			if ( $model['id'] === $model_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Forgets a provider's cached list.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return void
	 */
	public function forget( $provider_id ) {
		delete_transient( $this->key( $provider_id ) );
	}

	/**
	 * Returns the transient name for a provider.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return string
	 */
	private function key( $provider_id ) {
		return self::PREFIX . sanitize_key( (string) $provider_id );
	}
}
