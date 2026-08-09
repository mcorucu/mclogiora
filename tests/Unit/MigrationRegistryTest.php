<?php
/**
 * Migration registry tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Database\DatabaseVersionManager;
use McLogiora\Database\MigrationInterface;
use McLogiora\Database\MigrationRegistry;
use McLogiora\Database\TableNames;
use McLogiora\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the registered migrations and the current version in step.
 *
 * Activation and the running application both build a migration runner. When
 * each kept its own list, activation fell a migration behind: a real site got
 * the Phase 10 tables and none of the Phase 11 ones, so string, media, and
 * widget translation had nowhere to store anything. Nothing failed loudly,
 * because the integration suite installed through the other path.
 */
final class MigrationRegistryTest extends TestCase {
	/**
	 * Returns table names backed by a fake wpdb.
	 *
	 * @return TableNames
	 */
	private function tables() {
		return new TableNames( new FakeWpdb() );
	}

	/**
	 * Asserts the registry is not empty and holds only migrations.
	 *
	 * @return void
	 */
	public function test_registry_lists_migrations() {
		$migrations = MigrationRegistry::all( $this->tables() );

		$this->assertNotEmpty( $migrations );

		foreach ( $migrations as $migration ) {
			$this->assertInstanceOf( MigrationInterface::class, $migration );
		}
	}

	/**
	 * Asserts every registered migration targets a distinct version.
	 *
	 * @return void
	 */
	public function test_migration_versions_are_unique() {
		$versions = array();

		foreach ( MigrationRegistry::all( $this->tables() ) as $migration ) {
			$versions[] = $migration->version();
		}

		$this->assertSame( $versions, array_values( array_unique( $versions ) ) );
	}

	/**
	 * Asserts the registry reaches the version the plugin claims to be at.
	 *
	 * This is the assertion that would have caught the activation defect:
	 * adding a migration and forgetting to register it, or bumping the current
	 * version without a migration behind it, both fail here.
	 *
	 * @return void
	 */
	public function test_registry_reaches_the_current_database_version() {
		$this->assertSame(
			DatabaseVersionManager::CURRENT_VERSION,
			MigrationRegistry::highest_version( $this->tables() ),
			'Every database version must have a registered migration behind it.'
		);
	}

	/**
	 * Asserts every migration declares the tables it must create.
	 *
	 * @return void
	 */
	public function test_every_migration_declares_expected_tables() {
		foreach ( MigrationRegistry::all( $this->tables() ) as $migration ) {
			$this->assertNotEmpty(
				$migration->expected_tables(),
				'Migration ' . $migration->version() . ' declares no tables, so completion cannot be verified.'
			);
		}
	}
}
