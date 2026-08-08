<?php
/**
 * Version comparison helper.
 *
 * @package McLogiora
 */

namespace McLogiora\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Compares database versions safely.
 */
final class VersionChecker {
	/**
	 * Returns whether migration to a version should run.
	 *
	 * @param string $installed Installed database version.
	 * @param string $target Target database version.
	 * @return bool
	 */
	public function should_run( $installed, $target ) {
		return version_compare( (string) $installed, (string) $target, '<' );
	}
}
