<?php
/**
 * Feature flag foundation.
 *
 * @package McLogiora
 */

namespace McLogiora\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks future module availability without persisting settings.
 */
final class FeatureFlags {
	/**
	 * In-memory feature availability.
	 *
	 * @var array<string, bool>
	 */
	private $features = array(
		'language_manager'      => true,
		'setup_wizard'          => true,
		'translation_relations' => true,
		'content_foundation'    => true,
		'taxonomy_foundation'   => true,
		'rest_api'              => false,
		'ajax'                  => false,
		'switchers'             => false,
		'seo'                   => false,
		'builders'              => false,
		'external_services'     => false,
	);

	/**
	 * Returns whether a feature is enabled for this phase.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public function is_enabled( $feature ) {
		$feature = sanitize_key( (string) $feature );

		if ( ! array_key_exists( $feature, $this->features ) ) {
			return false;
		}

		/*
		 * Internal. Deliberately NOT part of the public developer API, and it
		 * carries no @since for that reason.
		 *
		 * Nothing in the plugin calls is_enabled(), and the table above has
		 * fallen out of step with what shipped: switchers, SEO, builders and
		 * external services are all listed false and all exist. Publishing a
		 * filter over a table nobody reads and nothing maintains would document
		 * a promise that is already untrue. See
		 * docs/architecture/developer-api.md.
		 */
		return (bool) apply_filters( 'mclogiora_feature_enabled', $this->features[ $feature ], $feature );
	}

	/**
	 * Returns all feature states.
	 *
	 * @return array<string, bool>
	 */
	public function all() {
		return $this->features;
	}
}
