<?php
/**
 * Minimal wpdb double for repository tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Support;

/**
 * Emulates only the wpdb surface the language repository uses.
 *
 * This is not a database. It exists so the create() contract can be tested,
 * including the read-back failure path, which is difficult to provoke against
 * a real database.
 */
final class FakeWpdb {
	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Rows returned by get_row() before an insert.
	 *
	 * @var object[]
	 */
	public $rows = array();

	/**
	 * Rows returned by get_row() after an insert.
	 *
	 * @var object[]
	 */
	public $rows_after_insert = array();

	/**
	 * Result returned by insert().
	 *
	 * @var int|false
	 */
	public $insert_result = 1;

	/**
	 * Whether an insert has occurred.
	 *
	 * @var bool
	 */
	public $inserted = false;

	/**
	 * Returns a prepared statement string.
	 *
	 * @param string $query Query.
	 * @param mixed  ...$args Arguments.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[sd]/', (string) $arg, (string) $query, 1 );
		}

		return (string) $query;
	}

	/**
	 * Whether DESCRIBE should report the table as existing.
	 *
	 * @var bool
	 */
	public $tables_exist = true;

	/**
	 * Suppresses errors, mirroring wpdb's toggle.
	 *
	 * @param bool $suppress Whether to suppress.
	 * @return bool
	 */
	public function suppress_errors( $suppress = true ) {
		unset( $suppress );

		return false;
	}

	/**
	 * Escapes LIKE wildcards.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * Returns a scalar. Table existence checks always succeed.
	 *
	 * @param string $query Query.
	 * @return string
	 */
	public function get_var( $query ) {
		if ( 0 === strpos( (string) $query, 'SHOW TABLES LIKE ' ) ) {
			return stripslashes( substr( (string) $query, strlen( 'SHOW TABLES LIKE ' ) ) );
		}

		return '';
	}

	/**
	 * Returns the first available row, or null.
	 *
	 * @param string $query Query.
	 * @return object|null
	 */
	public function get_row( $query ) {
		unset( $query );

		$pool = $this->inserted ? $this->rows_after_insert : $this->rows;

		return empty( $pool ) ? null : $pool[0];
	}

	/**
	 * Returns all available rows.
	 *
	 * @param string $query Query.
	 * @return object[]
	 */
	public function get_results( $query ) {
		if ( 0 === strpos( (string) $query, 'DESCRIBE ' ) ) {
			return $this->tables_exist ? array( (object) array( 'Field' => 'id' ) ) : array();
		}

		return $this->inserted ? $this->rows_after_insert : $this->rows;
	}

	/**
	 * Records an insert.
	 *
	 * @param string               $table Table name.
	 * @param array<string,mixed>  $data Row data.
	 * @param array<int,string>    $format Formats.
	 * @return int|false
	 */
	public function insert( $table, $data, $format = array() ) {
		unset( $table, $data, $format );

		if ( false === $this->insert_result ) {
			return false;
		}

		$this->inserted = true;

		return $this->insert_result;
	}

	/**
	 * Records an update.
	 *
	 * @param string              $table Table name.
	 * @param array<string,mixed> $data Row data.
	 * @param array<string,mixed> $where Where clause.
	 * @param array<int,string>   $format Formats.
	 * @param array<int,string>   $where_format Where formats.
	 * @return int
	 */
	public function update( $table, $data, $where, $format = array(), $where_format = array() ) {
		unset( $table, $data, $where, $format, $where_format );

		return 1;
	}

	/**
	 * Records a delete.
	 *
	 * @param string              $table Table name.
	 * @param array<string,mixed> $where Where clause.
	 * @param array<int,string>   $where_format Where formats.
	 * @return int
	 */
	public function delete( $table, $where, $where_format = array() ) {
		unset( $table, $where, $where_format );

		return 1;
	}

	/**
	 * Runs a query.
	 *
	 * @param string $query Query.
	 * @return int
	 */
	public function query( $query ) {
		unset( $query );

		return 1;
	}
}
