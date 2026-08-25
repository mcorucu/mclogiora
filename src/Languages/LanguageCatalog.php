<?php
/**
 * Bundled, offline language catalog.
 *
 * @package McLogiora
 */

namespace McLogiora\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Provides one canonical set of language choices to every admin surface.
 */
final class LanguageCatalog {
	/**
	 * Returns validated bundled definitions, filtered for extensions.
	 *
	 * The filter receives arrays rather than domain objects so extensions can
	 * contribute simple configuration without depending on an internal class.
	 * Invalid or duplicate definitions are ignored before they reach the UI.
	 *
	 * @return LanguageDefinition[]
	 */
	public static function all() {
		$raw = array(
			array( 'tr', 'tr_TR', 'tr', 'Türkçe', 'Turkish', 'ltr', '' ),
			array( 'en', 'en_US', 'en-US', 'English', 'English', 'ltr', 'United States' ),
			array( 'en-gb', 'en_GB', 'en-GB', 'English', 'English', 'ltr', 'United Kingdom' ),
			array( 'de', 'de_DE', 'de', 'Deutsch', 'German', 'ltr', '' ),
			array( 'es', 'es_ES', 'es', 'Español', 'Spanish', 'ltr', '' ),
			array( 'fr', 'fr_FR', 'fr', 'Français', 'French', 'ltr', '' ),
			array( 'it', 'it_IT', 'it', 'Italiano', 'Italian', 'ltr', '' ),
			array( 'pt', 'pt_PT', 'pt-PT', 'Português', 'Portuguese', 'ltr', 'Portugal' ),
			array( 'pt-br', 'pt_BR', 'pt-BR', 'Português', 'Portuguese', 'ltr', 'Brazil' ),
			array( 'nl', 'nl_NL', 'nl', 'Nederlands', 'Dutch', 'ltr', '' ),
			array( 'pl', 'pl_PL', 'pl', 'Polski', 'Polish', 'ltr', '' ),
			array( 'cs', 'cs_CZ', 'cs', 'Čeština', 'Czech', 'ltr', '' ),
			array( 'sk', 'sk_SK', 'sk', 'Slovenčina', 'Slovak', 'ltr', '' ),
			array( 'hu', 'hu_HU', 'hu', 'Magyar', 'Hungarian', 'ltr', '' ),
			array( 'ro', 'ro_RO', 'ro', 'Română', 'Romanian', 'ltr', '' ),
			array( 'bg', 'bg_BG', 'bg', 'Български', 'Bulgarian', 'ltr', '' ),
			array( 'el', 'el_GR', 'el', 'Ελληνικά', 'Greek', 'ltr', '' ),
			array( 'ru', 'ru_RU', 'ru', 'Русский', 'Russian', 'ltr', '' ),
			array( 'uk', 'uk', 'uk', 'Українська', 'Ukrainian', 'ltr', '' ),
			array( 'sv', 'sv_SE', 'sv', 'Svenska', 'Swedish', 'ltr', '' ),
			array( 'da', 'da_DK', 'da', 'Dansk', 'Danish', 'ltr', '' ),
			array( 'no', 'nb_NO', 'nb', 'Norsk bokmål', 'Norwegian Bokmål', 'ltr', '' ),
			array( 'fi', 'fi', 'fi', 'Suomi', 'Finnish', 'ltr', '' ),
			array( 'ca', 'ca', 'ca', 'Català', 'Catalan', 'ltr', '' ),
			array( 'ar', 'ar', 'ar', 'العربية', 'Arabic', 'rtl', '' ),
			array( 'he', 'he_IL', 'he', 'עברית', 'Hebrew', 'rtl', '' ),
			array( 'fa', 'fa_IR', 'fa', 'فارسی', 'Persian', 'rtl', '' ),
			array( 'ur', 'ur', 'ur', 'اردو', 'Urdu', 'rtl', '' ),
			array( 'hi', 'hi_IN', 'hi', 'हिन्दी', 'Hindi', 'ltr', '' ),
			array( 'bn', 'bn_BD', 'bn', 'বাংলা', 'Bengali', 'ltr', '' ),
			array( 'zh', 'zh_CN', 'zh-CN', '中文', 'Chinese', 'ltr', 'China' ),
			array( 'zh-tw', 'zh_TW', 'zh-TW', '中文', 'Chinese', 'ltr', 'Taiwan' ),
			array( 'ja', 'ja', 'ja', '日本語', 'Japanese', 'ltr', '' ),
			array( 'ko', 'ko_KR', 'ko', '한국어', 'Korean', 'ltr', '' ),
			array( 'id', 'id_ID', 'id', 'Bahasa Indonesia', 'Indonesian', 'ltr', '' ),
			array( 'vi', 'vi', 'vi', 'Tiếng Việt', 'Vietnamese', 'ltr', '' ),
			array( 'th', 'th', 'th', 'ภาษาไทย', 'Thai', 'ltr', '' ),
			array( 'sw', 'sw', 'sw', 'Kiswahili', 'Swahili', 'ltr', '' ),
			array( 'af', 'af', 'af', 'Afrikaans', 'Afrikaans', 'ltr', '' ),
			array( 'et', 'et', 'et', 'Eesti', 'Estonian', 'ltr', '' ),
			array( 'lv', 'lv', 'lv', 'Latviešu', 'Latvian', 'ltr', '' ),
			array( 'lt', 'lt', 'lt', 'Lietuvių', 'Lithuanian', 'ltr', '' ),
		);

		if ( function_exists( 'apply_filters' ) ) {
			$raw = apply_filters( 'mclogiora_language_catalog', $raw );
		}

		$definitions = array();
		$seen        = array();
		$locales     = array();

		foreach ( is_array( $raw ) ? $raw : array() as $item ) {
			$definition = self::definition_from_value( $item );

			if ( ! $definition instanceof LanguageDefinition ) {
				continue;
			}

			$identity = strtolower( $definition->code() );
			$locale   = strtolower( $definition->locale() );

			if ( isset( $seen[ $identity ] ) || isset( $locales[ $locale ] ) ) {
				continue;
			}

			$seen[ $identity ]  = true;
			$locales[ $locale ] = true;
			$definitions[]      = $definition;
		}

		return $definitions;
	}

	/**
	 * Finds a catalog entry by internal code or locale.
	 *
	 * @param string $value Code or locale.
	 * @return LanguageDefinition|null
	 */
	public static function find( $value ) {
		$value = strtolower( trim( (string) $value ) );

		foreach ( self::all() as $definition ) {
			if ( strtolower( $definition->code() ) === $value || strtolower( $definition->locale() ) === $value ) {
				return $definition;
			}
		}

		return null;
	}

	/**
	 * Validates the normalized catalog shape.
	 *
	 * Suggests a catalog entry from the WordPress site locale.
	 *
	 * Exact locale matches are preferred. A bare language fallback is returned
	 * only when it is unambiguous in the bundled catalog.
	 *
	 * @return LanguageDefinition|null
	 */
	public static function suggested_for_site() {
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : '';
		$locale = strtolower( trim( $locale ) );

		if ( '' === $locale ) {
			return null;
		}

		$base       = preg_replace( '/[_-].*$/', '', $locale );
		$candidates = array();

		foreach ( self::all() as $definition ) {
			if ( strtolower( $definition->locale() ) === $locale ) {
				return $definition;
			}

			if ( strtolower( strtok( $definition->locale(), '_' ) ) === $base ) {
				$candidates[] = $definition;
			}
		}

		return 1 === count( $candidates ) ? $candidates[0] : null;
	}

	/**
	 * Converts a catalog code into persistence data.
	 *
	 * @param string $code Catalog code or locale.
	 * @param bool   $default Whether it should be default.
	 * @param int    $order Display order.
	 * @return array<string,mixed>|null
	 */
	public static function language_data( $code, $default = false, $order = 0 ) {
		$definition = self::find( $code );

		return $definition instanceof LanguageDefinition ? $definition->language_data( $default, $order ) : null;
	}

	/**
	 * Validates one filter-provided definition.
	 *
	 * @param mixed $item Candidate definition.
	 * @return LanguageDefinition|null
	 */
	private static function definition_from_value( $item ) {
		if ( $item instanceof LanguageDefinition ) {
			return self::is_valid( $item ) ? $item : null;
		}

		if ( ! is_array( $item ) ) {
			return null;
		}

		$values = array_values( $item );

		if ( isset( $item['code'] ) ) {
			$values = array(
				isset( $item['code'] ) ? $item['code'] : '',
				isset( $item['locale'] ) ? $item['locale'] : '',
				isset( $item['hreflang'] ) ? $item['hreflang'] : '',
				isset( $item['native_name'] ) ? $item['native_name'] : '',
				isset( $item['english_name'] ) ? $item['english_name'] : '',
				isset( $item['direction'] ) ? $item['direction'] : 'ltr',
				isset( $item['region'] ) ? $item['region'] : '',
			);
		}

		if ( count( $values ) < 6 ) {
			return null;
		}

		$definition = new LanguageDefinition( $values[0], $values[1], $values[2], $values[3], $values[4], $values[5], isset( $values[6] ) ? $values[6] : '' );

		return self::is_valid( $definition ) ? $definition : null;
	}

	/**
	 * Checks whether a definition has valid normalized metadata.
	 *
	 * @param LanguageDefinition $definition Candidate.
	 * @return bool
	 */
	private static function is_valid( LanguageDefinition $definition ) {
		return '' !== $definition->code()
			&& 1 === preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2})?$/', $definition->locale() )
			&& LanguageTag::is_valid( $definition->hreflang() )
			&& '' !== $definition->native_name()
			&& '' !== $definition->english_name()
			&& in_array( $definition->direction(), array( 'ltr', 'rtl' ), true );
	}
}
