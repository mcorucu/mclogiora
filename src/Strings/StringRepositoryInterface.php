<?php
/**
 * String repository contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Strings;

defined( 'ABSPATH' ) || exit;

/**
 * Stores registered source strings and their translations.
 */
interface StringRepositoryInterface {
	/**
	 * Registers a source string, or refreshes it if already known.
	 *
	 * Must be idempotent: registering the same text, domain, and context
	 * twice updates the existing row rather than creating a duplicate.
	 *
	 * @param StringSource $source Source string.
	 * @return StringSource|\WP_Error
	 */
	public function register( StringSource $source );

	/**
	 * Finds a source string by its identity hash.
	 *
	 * @param string $hash Identity hash.
	 * @return StringSource|null
	 */
	public function find_by_hash( $hash );

	/**
	 * Finds a source string by internal identifier.
	 *
	 * @param int $id Internal identifier.
	 * @return StringSource|null
	 */
	public function find( $id );

	/**
	 * Returns source strings matching optional filters.
	 *
	 * @param array<string,mixed> $filters Filters: search, text_domain, source_type, limit, offset.
	 * @return StringSource[]
	 */
	public function query( array $filters = array() );

	/**
	 * Returns the number of registered source strings.
	 *
	 * @return int
	 */
	public function count_strings();

	/**
	 * Marks every string of a source type as stale before a rescan.
	 *
	 * @param string $source_type Source type.
	 * @param string $source_reference Optional reference prefix.
	 * @return int
	 */
	public function mark_scope_stale( $source_type, $source_reference = '' );

	/**
	 * Saves a translation for a source string.
	 *
	 * @param StringTranslation $translation Translation.
	 * @return StringTranslation|\WP_Error
	 */
	public function save_translation( StringTranslation $translation );

	/**
	 * Finds a translation for a source string in a language.
	 *
	 * @param int    $string_id Source string identifier.
	 * @param string $language_code Language code.
	 * @return StringTranslation|null
	 */
	public function find_translation( $string_id, $language_code );

	/**
	 * Returns all translations for a source string.
	 *
	 * @param int $string_id Source string identifier.
	 * @return StringTranslation[]
	 */
	public function translations_for( $string_id );

	/**
	 * Deletes a translation.
	 *
	 * @param int    $string_id Source string identifier.
	 * @param string $language_code Language code.
	 * @return bool|\WP_Error
	 */
	public function delete_translation( $string_id, $language_code );
}
