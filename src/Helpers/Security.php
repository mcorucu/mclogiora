<?php
/**
 * Security helpers.
 *
 * @package McLogiora
 */

namespace McLogiora\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Common security helpers for later modules.
 */
final class Security {
	/**
	 * Verifies a nonce value.
	 *
	 * @param string $nonce Nonce value.
	 * @param string $action Nonce action.
	 * @return bool
	 */
	public static function verify_nonce( $nonce, $action ) {
		return is_string( $nonce ) && '' !== $nonce && false !== wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Checks whether the current user has a capability.
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	public static function current_user_can( $capability ) {
		return is_string( $capability ) && '' !== $capability && current_user_can( $capability );
	}

	/**
	 * Creates a nonce field.
	 *
	 * @param string $action Nonce action.
	 * @param string $name Nonce field name.
	 * @return string
	 */
	public static function nonce_field( $action, $name = '_wpnonce' ) {
		return wp_nonce_field( $action, $name, true, false );
	}
}
