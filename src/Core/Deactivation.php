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
		/**
		 * Fires when mcLogiora is deactivated.
		 *
		 * Deactivation deletes nothing: no table is dropped, no option is
		 * removed, and every translation relation survives. Data removal is the
		 * uninstall routine's job and is user-controlled. Consumers of this hook
		 * should follow the same rule.
		 *
		 * @since 0.1.0
		 */
		do_action( 'mclogiora_deactivated' );
	}
}
