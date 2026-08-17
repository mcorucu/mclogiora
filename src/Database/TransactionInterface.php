<?php
/**
 * Database transaction boundary.
 *
 * @package McLogiora
 */

namespace McLogiora\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps transaction control injectable without exposing persistence details to
 * application services.
 */
interface TransactionInterface {
	/**
	 * Starts a transaction.
	 *
	 * @return bool
	 */
	public function begin();

	/**
	 * Commits a transaction.
	 *
	 * @return bool
	 */
	public function commit();

	/**
	 * Rolls back a transaction.
	 *
	 * @return bool
	 */
	public function rollback();
}
