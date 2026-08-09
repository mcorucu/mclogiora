<?php
/**
 * Migration runner safety tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Database\MigrationRunner;
use McLogiora\Database\SchemaBuilder;
use McLogiora\Database\VersionChecker;
use McLogiora\Tests\Support\FakeVersionManager;
use McLogiora\Tests\Support\FakeWpdb;
use McLogiora\Tests\Support\RecordingMigration;
use PHPUnit\Framework\TestCase;

/**
 * Covers the invariant that the stored schema version never advances past a
 * migration that did not verifiably succeed.
 *
 * This is the invariant whose absence caused the Phase 11 incident: the
 * recorded version reached 2 while the plugin considered its schema missing,
 * and a plugin that believes it is already upgraded never retries.
 */
final class MigrationRunnerTest extends TestCase {
	/**
	 * Version manager.
	 *
	 * @var FakeVersionManager
	 */
	private $versions;

	/**
	 * Schema builder.
	 *
	 * @var SchemaBuilder
	 */
	private $schema;

	/**
	 * Sets up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->versions = new FakeVersionManager( '0' );
		$this->schema   = new SchemaBuilder( new FakeWpdb() );
	}

	/**
	 * Builds a runner for the given migrations.
	 *
	 * @param RecordingMigration[] $migrations Migrations.
	 * @return MigrationRunner
	 */
	private function runner( array $migrations ) {
		return new MigrationRunner( $this->schema, $this->versions, new VersionChecker(), $migrations );
	}

	/**
	 * Asserts a successful run advances to the final version.
	 *
	 * @return void
	 */
	public function test_successful_migrations_advance_the_version() {
		$one = new RecordingMigration( '1', true );
		$two = new RecordingMigration( '2', true );

		$result = $this->runner( array( $one, $two ) )->run();

		$this->assertTrue( $result );
		$this->assertSame( '2', $this->versions->get_version() );
		$this->assertSame( 1, $one->calls );
		$this->assertSame( 1, $two->calls );
	}

	/**
	 * Asserts a failing first migration leaves the version untouched.
	 *
	 * @return void
	 */
	public function test_failed_first_migration_does_not_advance_the_version() {
		$one = new RecordingMigration( '1', false );
		$two = new RecordingMigration( '2', true );

		$result = $this->runner( array( $one, $two ) )->run();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_migration_incomplete', $result->get_error_code() );
		$this->assertSame( '0', $this->versions->get_version(), 'A failed migration must never advance the version.' );
		$this->assertSame( 0, $two->calls, 'Later migrations must not run after a failure.' );
	}

	/**
	 * Asserts a failing second migration stops at the first version.
	 *
	 * @return void
	 */
	public function test_failed_second_migration_stops_at_the_first_version() {
		$one = new RecordingMigration( '1', true );
		$two = new RecordingMigration( '2', false );

		$result = $this->runner( array( $one, $two ) )->run();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( '1', $this->versions->get_version() );
	}

	/**
	 * Asserts a failed run is retried on the next attempt.
	 *
	 * @return void
	 */
	public function test_a_failed_migration_is_retried_next_time() {
		$failing = new RecordingMigration( '1', false );

		$this->runner( array( $failing ) )->run();

		$this->assertSame( '0', $this->versions->get_version() );

		$succeeding = new RecordingMigration( '1', true );

		$this->assertTrue( $this->runner( array( $succeeding ) )->run() );
		$this->assertSame( '1', $this->versions->get_version() );
	}

	/**
	 * Asserts migrations run in version order regardless of registration.
	 *
	 * @return void
	 */
	public function test_migrations_run_in_version_order() {
		$two = new RecordingMigration( '2', true );
		$one = new RecordingMigration( '1', true );

		$this->runner( array( $two, $one ) )->run();

		$this->assertSame( array( '1', '2' ), RecordingMigration::$order );
	}

	/**
	 * Asserts an up-to-date installation runs nothing.
	 *
	 * @return void
	 */
	public function test_up_to_date_installation_is_idempotent() {
		$this->versions = new FakeVersionManager( '2' );

		$one = new RecordingMigration( '1', true );
		$two = new RecordingMigration( '2', true );

		$this->assertTrue( $this->runner( array( $one, $two ) )->run() );
		$this->assertSame( 0, $one->calls );
		$this->assertSame( 0, $two->calls );
		$this->assertSame( '2', $this->versions->get_version() );
	}

	/**
	 * Asserts an upgrade only runs the pending migration.
	 *
	 * @return void
	 */
	public function test_upgrade_runs_only_pending_migrations() {
		$this->versions = new FakeVersionManager( '1' );

		$one = new RecordingMigration( '1', true );
		$two = new RecordingMigration( '2', true );

		$this->assertTrue( $this->runner( array( $one, $two ) )->run() );
		$this->assertSame( 0, $one->calls, 'An applied migration must not run again.' );
		$this->assertSame( 1, $two->calls );
		$this->assertSame( '2', $this->versions->get_version() );
	}

	/**
	 * Asserts table_exists sees a table DESCRIBE can read.
	 *
	 * @return void
	 */
	public function test_table_exists_uses_a_check_that_sees_temporary_tables() {
		$wpdb   = new FakeWpdb();
		$schema = new SchemaBuilder( $wpdb );

		$wpdb->tables_exist = true;
		$this->assertTrue( $schema->table_exists( 'wp_mclogiora_languages' ) );

		$wpdb->tables_exist = false;
		$this->assertFalse( $schema->table_exists( 'wp_mclogiora_languages' ) );
		$this->assertFalse( $schema->table_exists( '' ) );
	}
}
