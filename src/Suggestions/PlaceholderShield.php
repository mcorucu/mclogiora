<?php
/**
 * Placeholder protection for translation suggestions.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Hides the parts of a string that must survive translation byte-for-byte.
 *
 * A translation model is asked to change words. Some things inside a string
 * are not words: `%1$s` is an argument slot that `sprintf()` will fill,
 * `[gallery id="4"]` is a shortcode WordPress will execute, `&nbsp;` is an
 * entity a browser will render, and a URL is an address. Translating any of
 * them produces output that is not merely worse -- it is broken. A translated
 * `%s` throws a `ValueError` in PHP 8, a translated shortcode name renders as
 * literal text on the page, and a translated URL is a dead link.
 *
 * So they are replaced with opaque tokens before a provider sees the text, and
 * put back afterwards.
 *
 * ## The token is not the safety mechanism
 *
 * It would be comfortable to believe a sufficiently strange token cannot be
 * translated. That belief is what makes this class dangerous to write
 * carelessly: a model that decides to "helpfully" localise a token, drop one
 * it thinks is redundant, or emit two where there was one will do so no matter
 * how the token is spelled, and no instruction makes that impossible.
 *
 * The safety mechanism is {@see self::verify()}, which compares what came back
 * against what was sent and reports any token that was dropped, added,
 * duplicated or altered. A suggestion that fails verification is refused
 * before it can reach content. The token's job is only to make tampering
 * detectable, not to prevent it.
 *
 * ## Why the token carries a nonce
 *
 * Source text can legitimately contain anything, including something that
 * looks like a token. A per-instance nonce means a token can never collide
 * with content that was already there, so verification cannot be fooled by a
 * string that happens to mention `[[MCQ_0]]`.
 */
final class PlaceholderShield {
	/**
	 * Ordered patterns for the constructs that must never be translated.
	 *
	 * Order matters. Shortcodes and URLs are consumed first because they can
	 * contain sequences the later patterns would otherwise match inside them --
	 * a URL query string can hold a `%2F`, and a shortcode attribute can hold
	 * braces. The escaped `%%` is taken before the conversion specifiers so a
	 * literal percent sign is never mistaken for the start of one.
	 *
	 * @var string[]
	 */
	private static $patterns = array(
		// Shortcodes, opening, closing and self-contained.
		'#\[/?[a-zA-Z0-9_-]+(?:[^\]\[]*)?\]#',

		// Absolute URLs.
		'#https?://[^\s<>"\'\]]+#',

		// HTML entities, named and numeric.
		'/&(?:[a-zA-Z][a-zA-Z0-9]{1,31}|#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6});/',

		// Handlebars-style template variables.
		'/\{\{[^{}]{1,100}\}\}/',

		// Single-brace named placeholders.
		'/\{[a-zA-Z0-9_.\-]{1,60}\}/',

		// An escaped literal percent sign.
		'/%%/',

		// printf conversion specifiers, positional and flagged forms included.
		'/%(?:[0-9]+\$)?[-+ 0#\']*[0-9]*(?:\.[0-9]+)?[bcdeEfFgGosuxX]/',
	);

	/**
	 * Collision-proof nonce embedded in every token.
	 *
	 * @var string
	 */
	private $nonce;

	/**
	 * Protected fragments keyed by token.
	 *
	 * @var array<string,string>
	 */
	private $map = array();

	/**
	 * Builds a shield.
	 *
	 * @param string $nonce Fixed nonce. Generated when omitted; supplied by
	 *                      tests so token strings are deterministic.
	 */
	public function __construct( $nonce = '' ) {
		$nonce = (string) $nonce;

		$this->nonce = '' !== $nonce ? $nonce : substr( md5( uniqid( 'mclogiora', true ) ), 0, 6 );
	}

	/**
	 * Replaces every protected construct with a token.
	 *
	 * @param string $text Source text.
	 * @return string Masked text.
	 */
	public function protect( $text ) {
		$text      = (string) $text;
		$this->map = array();
		$index     = 0;
		$nonce     = $this->nonce;
		$map       = &$this->map;

		foreach ( self::$patterns as $pattern ) {
			$text = preg_replace_callback(
				$pattern,
				static function ( $matches ) use ( &$index, &$map, $nonce ) {
					$token = sprintf( '[[MCQ_%s_%d]]', $nonce, $index );
					++$index;

					$map[ $token ] = $matches[0];

					return $token;
				},
				$text
			);

			if ( null === $text ) {
				/*
				 * A catastrophic backtrack or a malformed subject returns null
				 * rather than throwing. Continuing would silently drop the
				 * text, so the caller is told nothing could be protected and
				 * the suggestion is refused upstream.
				 */
				$this->map = array();

				return '';
			}
		}

		return $text;
	}

	/**
	 * Returns the tokens currently protected, in issue order.
	 *
	 * @return string[]
	 */
	public function tokens() {
		return array_keys( $this->map );
	}

	/**
	 * Returns whether anything needed protecting.
	 *
	 * @return bool
	 */
	public function has_placeholders() {
		return array() !== $this->map;
	}

	/**
	 * Checks a provider's answer for tampering with the tokens.
	 *
	 * Counts rather than presence, because a duplicated token is as damaging
	 * as a missing one: `sprintf()` given two `%s` slots and one argument
	 * fails exactly as loudly as one given none.
	 *
	 * @param string $translated Text returned by the provider.
	 * @return string[] Human-readable problems. Empty when the text is sound.
	 */
	public function verify( $translated ) {
		$translated = (string) $translated;
		$problems   = array();

		foreach ( $this->map as $token => $original ) {
			$expected = 1;
			$actual   = substr_count( $translated, $token );

			if ( $actual === $expected ) {
				continue;
			}

			if ( 0 === $actual ) {
				$problems[] = sprintf(
					/* translators: %s: the placeholder that was lost, such as %1$s. */
					__( 'The provider removed %s from the translation.', 'mclogiora' ),
					$original
				);

				continue;
			}

			$problems[] = sprintf(
				/* translators: 1: the placeholder, 2: how many times it came back. */
				__( 'The provider returned %1$s %2$d times instead of once.', 'mclogiora' ),
				$original,
				$actual
			);
		}

		/*
		 * A token that is not in the map but matches the token shape means the
		 * provider invented one. Restoring would leave visible scaffolding in
		 * published content, so it is caught here instead.
		 */
		$pattern = '/\[\[MCQ_' . preg_quote( $this->nonce, '/' ) . '_[0-9]+\]\]/';

		if ( preg_match_all( $pattern, $translated, $found ) ) {
			foreach ( array_unique( $found[0] ) as $token ) {
				if ( ! isset( $this->map[ $token ] ) ) {
					$problems[] = __( 'The provider invented a placeholder that was not in the source text.', 'mclogiora' );
					break;
				}
			}
		}

		return $problems;
	}

	/**
	 * Puts the protected constructs back.
	 *
	 * Call only after {@see self::verify()} has been consulted. Restoring an
	 * unverified string is how a broken placeholder reaches content.
	 *
	 * @param string $translated Text returned by the provider.
	 * @return string
	 */
	public function restore( $translated ) {
		if ( array() === $this->map ) {
			return (string) $translated;
		}

		return str_replace( array_keys( $this->map ), array_values( $this->map ), (string) $translated );
	}

	/**
	 * Returns the token-to-original map.
	 *
	 * Providers that protect text their own way -- a translation service with
	 * a native "do not translate this" markup, for instance -- need the pairs
	 * to build their own representation.
	 *
	 * @return array<string,string>
	 */
	public function map() {
		return $this->map;
	}
}
