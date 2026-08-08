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
		$table = (string) $table;

		return $table === $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
