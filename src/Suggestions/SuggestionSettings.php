<?php
/**
 * Translation suggestion settings.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the site's suggestion configuration.
 *
 * The master switch defaults to off, and that default is the feature's most
 * important property. mcLogiora is a translation plugin that works completely
 * without any provider; suggestions are an optional accelerator a site owner
 * opts into, with a WordPress-managed AI connection or their own dedicated
 * service credential. A site that
 * updates to this version and does nothing sends no traffic anywhere, which
 * is both the WordPress.org expectation for an external service and the only
 * defensible default for a feature that spends someone's money.
 */
final class SuggestionSettings {
	/**
	 * Option holding the master switch.
	 */
	const OPTION_ENABLED = 'mclogiora_suggestions_enabled';

	/**
	 * Option holding the active provider identifier.
	 */
	const OPTION_PROVIDER = 'mclogiora_suggestions_provider';

	/**
	 * Option holding the request timeout in seconds.
	 */
	const OPTION_TIMEOUT = 'mclogiora_suggestions_timeout';

	/**
	 * Default request timeout in seconds.
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * Returns whether translation suggestions are switched on.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) get_option( self::OPTION_ENABLED, false );
	}

	/**
	 * Returns the identifier of the provider the owner selected.
	 *
	 * @return string Empty when none has been chosen.
	 */
	public function provider_id() {
		return (string) get_option( self::OPTION_PROVIDER, '' );
	}

	/**
	 * Returns the configured request timeout in seconds.
	 *
	 * @return int
	 */
	public function timeout() {
		$timeout = (int) get_option( self::OPTION_TIMEOUT, self::DEFAULT_TIMEOUT );

		return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
	}

	/**
	 * Turns translation suggestions on or off for the whole site.
	 *
	 * @param bool $enabled Whether suggestions are allowed.
	 * @return void
	 */
	public function set_enabled( $enabled ) {
		update_option( self::OPTION_ENABLED, (bool) $enabled ? 1 : 0, false );
	}

	/**
	 * Records the provider the owner chose.
	 *
	 * Changing the provider deliberately touches nothing else. The dedicated
	 * service credential remains stored, while WordPress AI configuration stays
	 * owned by Core.
	 *
	 * @param string $provider_id Provider identifier, or an empty string.
	 * @return void
	 */
	public function set_provider( $provider_id ) {
		$provider_id = sanitize_key( (string) $provider_id );

		if ( '' === $provider_id ) {
			delete_option( self::OPTION_PROVIDER );

			return;
		}

		update_option( self::OPTION_PROVIDER, $provider_id, false );
	}
}
