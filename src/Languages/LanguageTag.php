<?php
/**
 * BCP 47 language tag conversion.
 *
 * @package McLogiora
 */

namespace McLogiora\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Converts mcLogiora language metadata into a standards-compatible tag.
 *
 * Three different things are easy to confuse and are not interchangeable:
 *
 * | Concept          | Example   | Used for                          |
 * |------------------|-----------|-----------------------------------|
 * | Language code    | `tr`      | mcLogiora's own identifier, URLs  |
 * | WordPress locale | `tr_TR`   | translation files, `get_locale()` |
 * | BCP 47 tag       | `tr-TR`   | `lang` attributes, `hreflang`     |
 *
 * The locale is the richer source, so it is preferred, with the language code
 * as the fallback for a language configured without one. Underscores are never
 * valid in a BCP 47 tag, which is the single most common way multilingual
 * plugins emit `hreflang` that search engines ignore.
 *
 * WordPress ships locale variants such as `de_DE_formal` and `pt_PT_ao90`.
 * The trailing segment is dropped: it distinguishes translation files, not
 * languages, and `hreflang="de-DE-formal"` communicates nothing useful to a
 * search engine while risking rejection of the whole annotation.
 */
final class LanguageTag {
	/**
	 * Returns the BCP 47 tag for a language, or an empty string.
	 *
	 * @param Language $language Language.
	 * @return string
	 */
	public static function for_language( Language $language ) {
		$tag = self::from_locale( $language->locale() );

		if ( '' !== $tag ) {
			return $tag;
		}

		return self::from_locale( $language->code() );
	}

	/**
	 * Returns the BCP 47 tag for a WordPress locale, or an empty string.
	 *
	 * @param string $locale WordPress locale or bare language code.
	 * @return string
	 */
	public static function from_locale( $locale ) {
		$raw = trim( (string) $locale );

		if ( '' === $raw ) {
			return '';
		}

		$parts = preg_split( '/[_-]/', $raw );

		if ( ! is_array( $parts ) || empty( $parts[0] ) ) {
			return '';
		}

		$primary = strtolower( (string) $parts[0] );

		if ( ! preg_match( '/^[a-z]{2,3}$/', $primary ) ) {
			return '';
		}

		$tag = $primary;

		foreach ( array_slice( $parts, 1 ) as $part ) {
			$subtag = self::normalize_subtag( (string) $part );

			if ( '' === $subtag ) {
				/*
				 * An unrecognised segment ends the tag rather than being
				 * skipped. Emitting the language plus a later segment would
				 * silently reorder subtags, and order is meaningful.
				 */
				break;
			}

			$tag .= '-' . $subtag;
		}

		return self::is_valid( $tag ) ? $tag : '';
	}

	/**
	 * Returns whether a tag is a well-formed language, script, and region tag.
	 *
	 * @param string $tag Candidate tag.
	 * @return bool
	 */
	public static function is_valid( $tag ) {
		return 1 === preg_match( '/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|[0-9]{3}))?$/', (string) $tag );
	}

	/**
	 * Returns the OpenGraph locale form of a tag, or an empty string.
	 *
	 * OpenGraph expects `language_TERRITORY`, so a tag without a region cannot
	 * be expressed and is omitted rather than guessed at. Inventing a territory
	 * -- `en` becoming `en_US` -- states something the site never configured.
	 *
	 * @param string $tag BCP 47 tag.
	 * @return string
	 */
	public static function to_open_graph( $tag ) {
		if ( ! self::is_valid( $tag ) ) {
			return '';
		}

		$parts = explode( '-', (string) $tag );

		if ( count( $parts ) < 2 ) {
			return '';
		}

		$region = end( $parts );

		if ( ! preg_match( '/^[A-Z]{2}$/', (string) $region ) ) {
			return '';
		}

		return $parts[0] . '_' . $region;
	}

	/**
	 * Normalises one subtag to its canonical casing, or returns an empty string.
	 *
	 * @param string $part Raw subtag.
	 * @return string
	 */
	private static function normalize_subtag( $part ) {
		if ( preg_match( '/^[A-Za-z]{4}$/', $part ) ) {
			return ucfirst( strtolower( $part ) );
		}

		if ( preg_match( '/^[A-Za-z]{2}$/', $part ) ) {
			return strtoupper( $part );
		}

		if ( preg_match( '/^[0-9]{3}$/', $part ) ) {
			return $part;
		}

		return '';
	}
}
