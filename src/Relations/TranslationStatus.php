<?php
/**
 * Translation status constants.
 *
 * @package McLogiora
 */

namespace McLogiora\Relations;

defined( 'ABSPATH' ) || exit;

/**
 * Defines translation relation statuses.
 */
final class TranslationStatus {
	const ORIGINAL          = 'original';
	const MISSING           = 'missing';
	const DRAFT             = 'draft';
	const TRANSLATED        = 'translated';
	const NEEDS_REVIEW      = 'needs_review';
	const NEEDS_UPDATE      = 'needs_update';
	const MACHINE_SUGGESTED = 'machine_suggested';
	const DISABLED          = 'disabled';

	/**
	 * Returns all supported statuses.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array(
			self::ORIGINAL,
			self::MISSING,
			self::DRAFT,
			self::TRANSLATED,
			self::NEEDS_REVIEW,
			self::NEEDS_UPDATE,
			self::MACHINE_SUGGESTED,
			self::DISABLED,
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
