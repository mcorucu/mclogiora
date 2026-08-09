<?php
/**
 * Migration contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Database;

defined( 'ABSPATH' ) || exit;

/**
 * A single ordered schema migration.
 *
 * `up()` returns a result rather than void so the runner can tell success
 * from failure. Reporting completion without checking used to let the stored
 * schema version advance past work that had not actually happened, which is
 * the worst possible failure mode: the plugin then believes it is upgraded
 * and never tries again.
 */
interface MigrationInterface {
	/**
	 * Returns the target database version.
	 *
	 * @return string
	 */
	public function version();

	/**
	 * Returns the tables this migration must have created to be complete.
	 *
	 * @return string[]
	 */
	public function expected_tables();

	/**
	 * Runs the migration and verifies its postconditions.
	 *
	 * @param SchemaBuilder $schema_builder Schema builder.
	 * @return true|\WP_Error
	 */
	public function up( SchemaBuilder $schema_builder );
}
