<?php
/**
 * Schema builder.
 *
 * @package McLogiora
 */

namespace McLogiora\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Builds schema SQL and delegates execution to dbDelta.
 */
final class SchemaBuilder {
	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb WordPress database object.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Returns database charset/collation SQL.
	 *
	 * @return string
	 */
	public function charset_collate() {
		return $this->wpdb->get_charset_collate();
	}

	/**
	 * Creates or updates tables through dbDelta.
	 *
	 * @param string[] $schema_sql SQL statements.
	 * @return array
	 */
	public function apply( array $schema_sql ) {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$results = array();

		foreach ( $schema_sql as $sql ) {
			$results[] = dbDelta( $sql );
		}

		return $results;
	}

	/**
	 * Returns whether a table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	public function table_exists( $table ) {
		$db    = $this->wpdb;
		$table = (string) $table;

		if ( '' === $table ) {
			return false;
		}

		/*
		 * DESCRIBE is used rather than SHOW TABLES because SHOW TABLES cannot
		 * see temporary tables. That is not a hypothetical: the WordPress
		 * integration test suite rewrites every CREATE TABLE into CREATE
		 * TEMPORARY TABLE so tests stay isolated, so a SHOW TABLES check
		 * reports the plugin's own freshly created tables as missing. Since
		 * every repository gates its reads and writes on this method, that
		 * made a working schema look completely absent.
		 *
		 * DESCRIBE answers the question actually being asked -- "can I use
		 * this table?" -- for permanent and temporary tables alike. Errors are
		 * suppressed because a missing table is an expected answer here, not a
		 * fault worth logging.
		 */
		$suppress = $db->suppress_errors();
		$columns  = $db->get_results( "DESCRIBE {$table};" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name comes from TableNames as $wpdb->prefix plus a hard-coded suffix; MySQL cannot parameterize an identifier, and schema existence must not be cached.
		$db->suppress_errors( $suppress );

		return is_array( $columns ) && ! empty( $columns );
	}

	/**
	 * Returns the column names of a table, or an empty array when absent.
	 *
	 * Used by migration postconditions to assert that a table is not merely
	 * present but actually has the columns the migration intended to create.
	 *
	 * @param string $table Table name.
	 * @return string[]
	 */
	public function table_columns( $table ) {
		$db    = $this->wpdb;
		$table = (string) $table;

		if ( '' === $table ) {
			return array();
		}

		$suppress = $db->suppress_errors();
		$rows     = $db->get_results( "DESCRIBE {$table};" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name comes from TableNames; schema reads must not be cached.
		$db->suppress_errors( $suppress );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$columns = array();

		foreach ( $rows as $row ) {
			if ( isset( $row->Field ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DESCRIBE returns a Field column.
				$columns[] = (string) $row->Field; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DESCRIBE returns a Field column.
			}
		}

		return $columns;
	}
}
