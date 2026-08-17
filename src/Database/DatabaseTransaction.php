<?php
/**
 * WordPress database transaction boundary.
 *
 * @package McLogiora
 */

namespace McLogiora\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Controls a transaction on the plugin's relational database connection.
 */
final class DatabaseTransaction implements TransactionInterface {
	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Whether this boundary currently owns an open transaction.
	 *
	 * @var bool
	 */
	private $active = false;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb WordPress database object.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * {@inheritDoc}
	 */
	public function begin() {
		if ( $this->active ) {
			return false;
		}

		$result       = $this->wpdb->query( 'START TRANSACTION' );
		$this->active = false !== $result;

		return $this->active;
	}

	/**
	 * {@inheritDoc}
	 */
	public function commit() {
		if ( ! $this->active ) {
			return false;
		}

		$result       = $this->wpdb->query( 'COMMIT' );
		$this->active = false;

		return false !== $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function rollback() {
		if ( ! $this->active ) {
			return false;
		}

		$result       = $this->wpdb->query( 'ROLLBACK' );
		$this->active = false;

		return false !== $result;
	}
}
