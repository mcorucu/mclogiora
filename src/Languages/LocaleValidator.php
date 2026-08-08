<?php
/**
 * Locale validation helper.
 *
 * @package McLogiora
 */

namespace McLogiora\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Validates locale-like strings without persistence.
 */
final class LocaleValidator {
	/**
	 * Returns whether a locale is structurally valid.
	 *
	 * @param string $locale Locale string.
	 * @return bool
	 */
	public function is_valid( $locale ) {
		return 1 === preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2})?$/', (string) $locale );
	}
}
