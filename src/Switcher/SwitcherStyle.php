<?php
/**
 * Language switcher presentation styles.
 *
 * @package McLogiora
 */

namespace McLogiora\Switcher;

defined( 'ABSPATH' ) || exit;

/**
 * Supported switcher presentation modes.
 */
final class SwitcherStyle {
	const INLINE   = 'inline';
	const DROPDOWN = 'dropdown';
	const COMPACT  = 'compact';
	const PILLS    = 'pills';

	/**
	 * Returns all supported styles.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array( self::INLINE, self::DROPDOWN, self::COMPACT, self::PILLS );
	}

	/**
	 * Returns whether a style is supported.
	 *
	 * @param string $style Style key.
	 * @return bool
	 */
	public static function is_valid( $style ) {
		return in_array( (string) $style, self::all(), true );
	}

	/**
	 * Returns the human-readable labels for each style.
	 *
	 * @return array<string,string>
	 */
	public static function labels() {
		return array(
			self::INLINE   => __( 'Inline list', 'mclogiora' ),
			self::DROPDOWN => __( 'Dropdown', 'mclogiora' ),
			self::COMPACT  => __( 'Compact current language', 'mclogiora' ),
			self::PILLS    => __( 'Language code pills', 'mclogiora' ),
		);
	}
}
