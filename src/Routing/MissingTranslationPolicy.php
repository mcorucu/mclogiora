<?php
/**
 * Missing translation behaviour.
 *
 * @package McLogiora
 */

namespace McLogiora\Routing;

defined( 'ABSPATH' ) || exit;

/**
 * Describes what a language switcher does when a translation is absent.
 *
 * This deliberately never includes an option to link to the source content
 * under a translated URL. Serving English text at /tr/ is a lie to the reader
 * and to search engines, and it is exactly the behaviour that gets
 * multilingual sites penalised for duplicate content.
 */
final class MissingTranslationPolicy {
	/**
	 * Do not offer the language at all.
	 */
	const HIDE = 'hide';

	/**
	 * Offer the language, linking to its home page.
	 */
	const HOME = 'home';

	/**
	 * Show the language as unavailable, without a link.
	 */
	const DISABLE = 'disable';

	/**
	 * Returns all supported policies.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array( self::HIDE, self::HOME, self::DISABLE );
	}

	/**
	 * Returns whether a policy is supported.
	 *
	 * @param string $policy Policy key.
	 * @return bool
	 */
	public static function is_valid( $policy ) {
		return in_array( (string) $policy, self::all(), true );
	}
}
