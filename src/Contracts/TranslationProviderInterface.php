<?php
/**
 * Translation provider contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for future opt-in translation suggestion providers.
 */
interface TranslationProviderInterface {
	/**
	 * Returns the provider identifier.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Returns the human-readable provider label.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Returns whether the provider is configured.
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Returns whether the language pair is supported.
	 *
	 * @param string $source_language Source language code.
	 * @param string $target_language Target language code.
	 * @return bool
	 */
	public function supports_language_pair( $source_language, $target_language );

	/**
	 * Returns a suggested translation for explicit user review.
	 *
	 * @param string $source_text Source text.
	 * @param string $source_language Source language code.
	 * @param string $target_language Target language code.
	 * @param array  $context Optional context.
	 * @return string|\WP_Error
	 */
	public function suggest( $source_text, $source_language, $target_language, array $context = array() );
}
