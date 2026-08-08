<?php
/**
 * String source type constants.
 *
 * @package McLogiora
 */

namespace McLogiora\Strings;

defined( 'ABSPATH' ) || exit;

/**
 * Describes where a registered source string came from.
 */
final class StringSourceType {
	const THEME  = 'theme';
	const PLUGIN = 'plugin';
	const CORE   = 'core';
	const MANUAL = 'manual';

	/**
	 * Returns all supported source types.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array( self::THEME, self::PLUGIN, self::CORE, self::MANUAL );
	}

	/**
	 * Returns whether a source type is supported.
	 *
	 * @param string $type Source type.
	 * @return bool
	 */
	public static function is_valid( $type ) {
		return in_array( $type, self::all(), true );
	}
}
