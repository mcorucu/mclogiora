<?php
/**
 * Validation helpers.
 *
 * @package McLogiora
 */

namespace McLogiora\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Common validation helpers for later modules.
 */
final class Validation {
	/**
	 * Sanitizes a machine key.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function key( $value ) {
		return sanitize_key( (string) $value );
	}

	/**
	 * Sanitizes a slug-like value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function slug( $value ) {
		return sanitize_title( (string) $value );
	}

	/**
	 * Sanitizes plain text.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function text( $value ) {
		return sanitize_text_field( (string) $value );
	}

	/**
	 * Casts a value to an absolute integer.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function absint( $value ) {
		return absint( $value );
	}

	/**
	 * Casts a value to a boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function boolean( $value ) {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}
}
