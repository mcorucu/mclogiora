<?php
/**
 * Database installer.
 *
 * @package McLogiora
 */

namespace McLogiora\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates installation and migrations.
 */
final class Installer {
	/**
	 * Migration runner.
	 *
	 * @var MigrationRunner
	 */
	private $migration_runner;

	/**
	 * Constructor.
	 *
	 * @param MigrationRunner $migration_runner Migration runner.
	 */
	public function __construct( MigrationRunner $migration_runner ) {
		$this->migration_runner = $migration_runner;
	}

	/**
	 * Installs or upgrades the database schema.
	 *
	 * @return void
	 */
	public function install() {
		$this->migration_runner->run();
	}
}
