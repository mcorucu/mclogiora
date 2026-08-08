<?php
/**
 * Language status constants.
 *
 * @package McLogiora
 */

namespace McLogiora\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Defines language availability states.
 */
final class LanguageStatus {
	const ACTIVE = 'active';
	const INACTIVE = 'inactive';

	/**
	 * Returns all supported statuses.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array(
			self::ACTIVE,
			self::INACTIVE,
		);
	}

	/**
	 * Returns whether a status is supported.
	 *
	 * @param string $status Status key.
	 * @return bool
	 */
	public static function is_valid( $status ) {
		return in_array( $status, self::all(), true );
	}
}
