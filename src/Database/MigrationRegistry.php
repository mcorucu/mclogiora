<?php
/**
 * Migration registry.
 *
 * @package McLogiora
 */

namespace McLogiora\Database;

use McLogiora\Database\Migrations\Migration001InitialSchema;
use McLogiora\Database\Migrations\Migration002TranslationDomains;

defined( 'ABSPATH' ) || exit;

/**
 * The one list of schema migrations.
 *
 * There are two paths into the installer: plugin activation, through
 * `InstallerFactory`, and the running application, through the service
 * container. When each kept its own list, one of them fell behind. Activation
 * shipped knowing only about Migration001, so a real site got the Phase 10
 * tables and none of the Phase 11 ones, and string, media, and widget
 * translation silently had nowhere to store anything.
 *
 * A single list is the fix. `MigrationRegistryTest` additionally asserts that
 * the highest version here matches the current database version, so adding a
 * migration and forgetting to register it fails a test rather than a site.
 */
final class MigrationRegistry {
	/**
	 * Returns every migration, in no particular order.
	 *
	 * The runner sorts them by version; ordering here is not significant.
	 *
	 * @param TableNames $tables Table names.
	 * @return MigrationInterface[]
	 */
	public static function all( TableNames $tables ) {
		return array(
			new Migration001InitialSchema( $tables ),
			new Migration002TranslationDomains( $tables ),
		);
	}

	/**
	 * Returns the highest version any registered migration targets.
	 *
	 * @param TableNames $tables Table names.
	 * @return string
	 */
	public static function highest_version( TableNames $tables ) {
		$highest = '0';

		foreach ( self::all( $tables ) as $migration ) {
			if ( version_compare( $migration->version(), $highest, '>' ) ) {
				$highest = $migration->version();
			}
		}

		return $highest;
	}
}
