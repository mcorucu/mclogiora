<?php
/**
 * Public developer API.
 *
 * These functions are the supported way to read mcLogiora's language
 * configuration and translation relations from a theme or another plugin. They
 * return plain arrays and scalars, never domain objects, and they never write.
 *
 * See `docs/architecture/developer-api.md` for the published contract, the
 * stability rules, and what is deliberately not part of it.
 *
 * @package McLogiora
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'mclogiora_get_languages' ) ) {
	/**
	 * Returns the configured languages.
	 *
	 * Active languages in display order by default:
	 *
	 *     foreach ( mclogiora_get_languages() as $language ) {
	 *         echo esc_html( $language['native_name'] );
	 *     }
	 *
	 * @since 0.16.0
	 *
	 * @param array<string,mixed> $args Optional. `status` accepts `active`
	 *                                  (default) or `all`.
	 * @return array<int,array<string,mixed>> Language records. Empty before the
	 *                                        plugin boots or when no language
	 *                                        is configured.
	 */
	function mclogiora_get_languages( array $args = array() ) {
		return \McLogiora\Api\PublicApi::instance()->languages( $args );
	}
}

if ( ! function_exists( 'mclogiora_get_default_language' ) ) {
	/**
	 * Returns the site default language.
	 *
	 * @since 0.16.0
	 *
	 * @return array<string,mixed>|null Language record, or null when none is
	 *                                  configured.
	 */
	function mclogiora_get_default_language() {
		return \McLogiora\Api\PublicApi::instance()->default_language();
	}
}

if ( ! function_exists( 'mclogiora_get_current_language' ) ) {
	/**
	 * Returns the language of the current request.
	 *
	 * Falls back to the site default on a configured site, so this is null only
	 * when mcLogiora has no languages at all.
	 *
	 * @since 0.16.0
	 *
	 * @return array<string,mixed>|null Language record, or null.
	 */
	function mclogiora_get_current_language() {
		return \McLogiora\Api\PublicApi::instance()->current_language();
	}
}

if ( ! function_exists( 'mclogiora_get_translation' ) ) {
	/**
	 * Returns the translated object ID for a language.
	 *
	 *     $tr = mclogiora_get_translation( get_the_ID(), 'post', 'tr' );
	 *
	 * The return value is a relation record, not permission to display that
	 * object. Apply your own status and capability checks before rendering it.
	 *
	 * @since 0.16.0
	 *
	 * @param int    $object_id Object identifier.
	 * @param string $object_type Relation object type, such as `post` or `term`.
	 * @param string $language Target language code.
	 * @return int|null Translated object ID, or null when untranslated.
	 */
	function mclogiora_get_translation( $object_id, $object_type, $language ) {
		return \McLogiora\Api\PublicApi::instance()->translation( $object_id, $object_type, $language );
	}
}

if ( ! function_exists( 'mclogiora_get_translation_group' ) ) {
	/**
	 * Returns the translation group an object belongs to.
	 *
	 * The returned array has `group_key`, `object_type`, `source` and
	 * `translations`, the last keyed by language code.
	 *
	 * @since 0.16.0
	 *
	 * @param int    $object_id Object identifier.
	 * @param string $object_type Relation object type.
	 * @return array<string,mixed>|null Group record, or null when the object is
	 *                                  not in a translation group.
	 */
	function mclogiora_get_translation_group( $object_id, $object_type ) {
		return \McLogiora\Api\PublicApi::instance()->translation_group( $object_id, $object_type );
	}
}

if ( ! function_exists( 'mclogiora_get_language_url' ) ) {
	/**
	 * Returns the URL of an object in a language.
	 *
	 * With no object, returns the language home URL:
	 *
	 *     $home = mclogiora_get_language_url( 'tr' );
	 *     $post = mclogiora_get_language_url( 'tr', get_the_ID() );
	 *     $term = mclogiora_get_language_url( 'tr', $term_id, 'term', 'category' );
	 *
	 * Null means the translation does not exist. A plausible-looking URL is
	 * never invented for missing content.
	 *
	 * @since 0.16.0
	 *
	 * @param string   $language Target language code.
	 * @param int|null $object_id Optional. Object identifier.
	 * @param string   $object_type Optional. Relation object type. Default `post`.
	 * @param string   $taxonomy Optional. Taxonomy name. Required for terms.
	 * @return string|null URL, or null when there is no translation.
	 */
	function mclogiora_get_language_url( $language, $object_id = null, $object_type = 'post', $taxonomy = '' ) {
		return \McLogiora\Api\PublicApi::instance()->language_url( $language, $object_id, $object_type, $taxonomy );
	}
}
