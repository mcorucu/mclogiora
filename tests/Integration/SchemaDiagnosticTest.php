<?php
/**
 * Temporary schema diagnostics.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Database\MigrationRunner;
use McLogiora\Database\TableNames;
use WP_UnitTestCase;

/**
 * Proves where the schema installation actually diverges.
 */
final class SchemaDiagnosticTest extends WP_UnitTestCase {
	/**
	 * Reports how the database sees the plugin tables after migration.
	 *
	 * @return void
	 */
	public function test_report_schema_visibility() {
		global $wpdb;

		$container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();

		delete_option( 'mclogiora_db_version' );
		$container->get( MigrationRunner::class )->run();

		$tables = $container->get( TableNames::class );
		$table  = $tables->languages();

		$suppress   = $wpdb->suppress_errors();
		$describe   = $wpdb->get_results( "DESCRIBE {$table};" );
		$select     = $wpdb->get_var( "SELECT COUNT(*) FROM {$table};" );
		$last_error = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		$show_tables = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$sql_mode    = $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
		$version     = $wpdb->get_var( 'SELECT VERSION()' );

		$this->fail(
			sprintf(
				"DIAGNOSTIC\n table: %s\n stored version: %s\n DESCRIBE columns: %d\n SELECT COUNT: %s\n SHOW TABLES LIKE: %s\n last_error: %s\n server: %s\n sql_mode: %s",
				$table,
				(string) get_option( 'mclogiora_db_version', '(unset)' ),
				is_array( $describe ) ? count( $describe ) : -1,
				null === $select ? '(null)' : (string) $select,
				null === $show_tables ? '(null)' : (string) $show_tables,
				'' === $last_error ? '(none)' : $last_error,
				(string) $version,
				(string) $sql_mode
			)
		);
	}
}
