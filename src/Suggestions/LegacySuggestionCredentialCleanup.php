<?php
/**
 * Removes credentials for providers no longer shipped by mcLogiora.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Performs the 1.0.2 cleanup once after an upgrade.
 *
 * The old vendor adapters owned their own options. WordPress AI Client now
 * owns AI-provider credentials, while DeepL remains an explicit mcLogiora
 * integration. Only the old mcLogiora-owned option names are removed here;
 * WordPress Connector options and constants are outside this class's scope.
 */
final class LegacySuggestionCredentialCleanup {
	/**
	 * Marker proving the cleanup has completed.
	 */
	const OPTION_COMPLETED = 'mclogiora_suggestion_legacy_cleanup_102';

	/**
	 * Provider identifiers retired in 1.0.2.
	 *
	 * @var string[]
	 */
	const RETIRED_PROVIDERS = array( 'openai', 'anthropic', 'gemini' );

	/**
	 * Runs the cleanup if it has not already run.
	 *
	 * @return bool Whether this invocation performed the cleanup.
	 */
	public function run() {
		if ( get_option( self::OPTION_COMPLETED, false ) ) {
			return false;
		}

		foreach ( self::RETIRED_PROVIDERS as $provider_id ) {
			delete_option( CredentialStore::OPTION_PREFIX . sanitize_key( $provider_id ) );
			delete_option( 'mclogiora_suggestion_model_' . sanitize_key( $provider_id ) );
		}

		$selected_provider = (string) get_option( SuggestionSettings::OPTION_PROVIDER, '' );

		if ( in_array( $selected_provider, self::RETIRED_PROVIDERS, true ) ) {
			delete_option( SuggestionSettings::OPTION_PROVIDER );
		}

		update_option( self::OPTION_COMPLETED, 1, false );

		return true;
	}
}
