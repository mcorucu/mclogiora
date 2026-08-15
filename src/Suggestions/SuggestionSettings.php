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
 * opts into, with their own credentials and their own bill. A site that
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
}
