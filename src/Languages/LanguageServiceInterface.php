<?php
/**
 * Language service contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for language domain reads.
 */
interface LanguageServiceInterface {
	/**
	 * Returns the default language.
	 *
	 * @return Language|null
	 */
	public function get_default_language();

	/**
	 * Returns active languages.
	 *
	 * @return Language[]
	 */
	public function get_active_languages();

	/**
	 * Returns all languages.
	 *
	 * @return Language[]
	 */
	public function get_languages();

	/**
	 * Finds a language by language code.
	 *
	 * @param string $code Language code.
	 * @return Language|null
	 */
	public function get_language_by_code( $code );

	/**
	 * Finds a language by locale.
	 *
	 * @param string $locale Locale.
	 * @return Language|null
	 */
	public function get_language_by_locale( $locale );

	/**
	 * Creates a language from sanitized domain input.
	 *
	 * @param array<string, mixed> $data Language data.
	 * @return Language|\WP_Error
	 */
	public function create_language( array $data );

	/**
	 * Updates a language from sanitized domain input.
	 *
	 * @param string               $code Language code.
	 * @param array<string, mixed> $data Language data.
	 * @return Language|\WP_Error
	 */
	public function update_language( $code, array $data );

	/**
	 * Enables a language.
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function enable_language( $code );

	/**
	 * Disables a language.
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function disable_language( $code );

	/**
	 * Deletes a language when safe.
	 *
	 * @param string $code Language code.
	 * @return bool|\WP_Error
	 */
	public function delete_language( $code );

	/**
	 * Sets the default language.
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function set_default_language( $code );

	/**
	 * Reorders languages.
	 *
	 * @param array<string, mixed> $ordered_codes Ordered language codes.
	 * @return bool|\WP_Error
	 */
	public function reorder_languages( array $ordered_codes );
}
