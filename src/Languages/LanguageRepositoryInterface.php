<?php
/**
 * Language repository contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for future language persistence.
 */
interface LanguageRepositoryInterface {
	/**
	 * Returns all known languages ordered for display.
	 *
	 * @return Language[]
	 */
	public function all();

	/**
	 * Finds a language by language code.
	 *
	 * @param string $code Language code.
	 * @return Language|null
	 */
	public function find_by_code( $code );

	/**
	 * Finds a language by locale.
	 *
	 * @param string $locale Locale.
	 * @return Language|null
	 */
	public function find_by_locale( $locale );

	/**
	 * Returns active languages.
	 *
	 * @return Language[]
	 */
	public function active();

	/**
	 * Returns the default language.
	 *
	 * @return Language|null
	 */
	public function default_language();

	/**
	 * Creates a language.
	 *
	 * @param Language $language Language entity.
	 * @return Language|\WP_Error
	 */
	public function create( Language $language );

	/**
	 * Updates a language.
	 *
	 * @param Language $language Language entity.
	 * @return Language|\WP_Error
	 */
	public function update( Language $language );

	/**
	 * Enables a language.
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function enable( $code );

	/**
	 * Disables a language.
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function disable( $code );

	/**
	 * Deletes a language when no integrity rule blocks it.
	 *
	 * @param string $code Language code.
	 * @return bool|\WP_Error
	 */
	public function delete( $code );

	/**
	 * Sets the default language.
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function set_default( $code );

	/**
	 * Reorders languages by language code sequence.
	 *
	 * @param string[] $language_codes Ordered language codes.
	 * @return bool|\WP_Error
	 */
	public function reorder( array $language_codes );
}
