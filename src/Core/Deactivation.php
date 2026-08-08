<?php
/**
 * Deactivation lifecycle.
 *
 * @package McLogiora
 */

namespace McLogiora\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Handles deactivation without deleting data or touching options in Phase 02.
 */
final class Deactivation {
	/**
	 * Runs on plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		do_action( 'mclogiora_deactivated' );
	}
}
