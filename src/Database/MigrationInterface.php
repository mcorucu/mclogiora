<?php
/**
 * Migration contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for database migrations.
 */
interface MigrationInterface {
	/**
	 * Returns the migration target database version.
	 *
	 * @return string
	 */
	public function version();

	/**
	 * Runs the migration.
	 *
	 * @param SchemaBuilder $schema_builder Schema builder.
	 * @return void
	 */
	public function up( SchemaBuilder $schema_builder );
}
