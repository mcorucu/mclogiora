<?php
/**
 * In-memory database version manager.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Support;

use McLogiora\Database\DatabaseVersionManager;

/**
 * Stores the schema version in memory instead of an option.
 */
final class FakeVersionManager extends DatabaseVersionManager {
	/**
	 * Stored version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Constructor.
	 *
	 * @param string $version Initial version.
	 */
	public function __construct( $version = '0' ) {
		$this->version = (string) $version;
	}

	/**
	 * Returns the stored version.
	 *
	 * @return string
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Stores the version.
	 *
	 * @param string $version Version.
	 * @return void
	 */
	public function set_version( $version ) {
		$this->version = (string) $version;
	}
}
