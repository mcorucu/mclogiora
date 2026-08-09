<?php
/**
 * Migration runner.
 *
 * @package McLogiora
 */

namespace McLogiora\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Runs registered migrations in version order.
 */
final class MigrationRunner {
	/**
	 * Schema builder.
	 *
	 * @var SchemaBuilder
	 */
	private $schema_builder;

	/**
	 * Database version manager.
	 *
	 * @var DatabaseVersionManager
	 */
	private $version_manager;

	/**
	 * Version checker.
	 *
	 * @var VersionChecker
	 */
	private $version_checker;

	/**
	 * Migrations.
	 *
	 * @var MigrationInterface[]
	 */
	private $migrations;

	/**
	 * Constructor.
	 *
	 * @param SchemaBuilder          $schema_builder Schema builder.
	 * @param DatabaseVersionManager $version_manager Version manager.
	 * @param VersionChecker         $version_checker Version checker.
	 * @param MigrationInterface[]   $migrations Migrations.
	 */
	public function __construct( SchemaBuilder $schema_builder, DatabaseVersionManager $version_manager, VersionChecker $version_checker, array $migrations ) {
		$this->schema_builder  = $schema_builder;
		$this->version_manager = $version_manager;
		$this->version_checker = $version_checker;
		$this->migrations      = $migrations;
	}

	/**
	 * Runs pending migrations, stopping at the first failure.
	 *
	 * @return true|\WP_Error
	 */
	public function run() {
		$migrations = $this->migrations;

		usort(
			$migrations,
			static function ( MigrationInterface $a, MigrationInterface $b ) {
				return version_compare( $a->version(), $b->version() );
			}
		);

		foreach ( $migrations as $migration ) {
			if ( ! $this->version_checker->should_run( $this->version_manager->get_version(), $migration->version() ) ) {
				continue;
			}

			$result = $migration->up( $this->schema_builder );

			/*
			 * The stored version only advances once the migration has proven
			 * its postconditions. Advancing on the mere fact that up() was
			 * called is what previously let the recorded version reach 2 while
			 * the schema was unusable, and a plugin that believes it is already
			 * upgraded never tries again.
			 */
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$this->version_manager->set_version( $migration->version() );
		}

		return true;
	}

	/**
	 * Returns whether all migrations are applied.
	 *
	 * @return bool
	 */
	public function is_current() {
		return ! $this->version_checker->should_run(
			$this->version_manager->get_version(),
			DatabaseVersionManager::CURRENT_VERSION
		);
	}
}
