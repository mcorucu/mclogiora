<?php
/**
 * Shared migration postcondition checking.
 *
 * @package McLogiora
 */

namespace McLogiora\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies that a migration produced the schema it promised.
 *
 * The dbDelta function reports nothing useful about failure: it suppresses errors while
 * inspecting tables, and its return value describes intent rather than
 * outcome. `$wpdb->last_error` is also insufficient, because a statement that
 * was never executed leaves no error behind. The only trustworthy answer is
 * to look at the resulting schema.
 */
trait MigrationPostconditions {
	/**
	 * Verifies that every expected table exists.
	 *
	 * @param SchemaBuilder $schema_builder Schema builder.
	 * @param string[]      $tables Expected table names.
	 * @param string        $version Migration version, for the error message.
	 * @return true|\WP_Error
	 */
	private function verify_tables( SchemaBuilder $schema_builder, array $tables, $version ) {
		$missing = array();

		foreach ( $tables as $table ) {
			if ( ! $schema_builder->table_exists( $table ) ) {
				$missing[] = $table;
			}
		}

		if ( ! empty( $missing ) ) {
			return new \WP_Error(
				'mclogiora_migration_incomplete',
				sprintf(
					/* translators: 1: migration version, 2: comma-separated list of missing table names. */
					__( 'Database migration %1$s did not create the expected tables: %2$s', 'mclogiora' ),
					(string) $version,
					implode( ', ', $missing )
				),
				array(
					'version' => (string) $version,
					'missing' => $missing,
				)
			);
		}

		return true;
	}
}
