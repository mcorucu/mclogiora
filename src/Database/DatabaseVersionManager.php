<?php
/**
 * Database version management.
 *
 * @package McLogiora
 */

namespace McLogiora\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Stores database schema version independently from plugin version.
 */
final class DatabaseVersionManager {
	const OPTION_NAME = 'mclogiora_db_version';
	const CURRENT_VERSION = '1';

	/**
	 * Returns the installed database version.
	 *
	 * @return string
	 */
	public function get_version() {
		return (string) get_option( self::OPTION_NAME, '0' );
	}

	/**
	 * Updates the installed database version.
	 *
	 * @param string $version Database version.
	 * @return void
	 */
	public function set_version( $version ) {
		update_option( self::OPTION_NAME, sanitize_text_field( (string) $version ), false );
	}
}
