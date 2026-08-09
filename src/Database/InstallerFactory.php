<?php
/**
 * Installer factory.
 *
 * @package McLogiora
 */

namespace McLogiora\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Builds installer dependencies for activation and upgrades.
 */
final class InstallerFactory {
	/**
	 * Creates an installer.
	 *
	 * @return Installer
	 */
	public static function create() {
		global $wpdb;

		$tables          = new TableNames( $wpdb );
		$schema_builder  = new SchemaBuilder( $wpdb );
		$version_manager = new DatabaseVersionManager();
		$runner          = new MigrationRunner(
			$schema_builder,
			$version_manager,
			new VersionChecker(),
			MigrationRegistry::all( $tables )
		);

		return new Installer( $runner );
	}
}
