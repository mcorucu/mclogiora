<?php
/**
 * Schema installation integration tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Database\Installer;
use McLogiora\Database\MigrationRunner;
use McLogiora\Database\Migrations\Migration001InitialSchema;
use McLogiora\Database\SchemaBuilder;
use McLogiora\Database\TableNames;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use WP_UnitTestCase;

/**
 * Proves that the schema actually installs, upgrades, and fails safely.
 *
 * These tests exist because Phase 11 shipped a migration layer that reported
 * completion while the plugin believed its tables were missing. The real
 * cause was that SchemaBuilder checked for tables with SHOW TABLES, which
 * cannot see the temporary tables the WordPress test suite creates. The
 * checks below assert the outcome rather than the report.
 */
final class SchemaInstallationTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Sets up the plugin services.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();

		delete_option( 'mclogiora_db_version' );
	}

	/**
	 * Asserts a fresh installation creates the full current schema.
	 *
	 * @return void
	 */
	public function test_fresh_install_creates_the_full_schema() {
		$result = $this->container->get( Installer::class )->install();

		$this->assertTrue( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( '2', (string) get_option( 'mclogiora_db_version' ) );

		$schema = $this->container->get( SchemaBuilder::class );

		foreach ( $this->container->get( TableNames::class )->all() as $table ) {
			$this->assertTrue( $schema->table_exists( $table ), "Missing table: {$table}" );
		}
	}

	/**
	 * Asserts a fresh installation leaves the repositories usable.
	 *
	 * @return void
	 */
	public function test_repositories_work_after_a_fresh_install() {
		$this->assertTrue( $this->container->get( Installer::class )->install() );

		$languages = $this->container->get( LanguageRepositoryInterface::class );

		$created = $languages->create(
			new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false )
		);

		$this->assertNotWPError( $created );
		$this->assertInstanceOf( Language::class, $languages->find_by_code( 'tr' ) );
	}

	/**
	 * Asserts installing twice is safe and changes nothing.
	 *
	 * @return void
	 */
	public function test_installation_is_idempotent() {
		$installer = $this->container->get( Installer::class );

		$this->assertTrue( $installer->install() );

		$languages = $this->container->get( LanguageRepositoryInterface::class );
		$languages->create( new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ) );

		$this->assertTrue( $installer->install(), 'A second install must succeed.' );
		$this->assertTrue( $installer->install(), 'A third install must succeed.' );

		$this->assertSame( '2', (string) get_option( 'mclogiora_db_version' ) );
		$this->assertInstanceOf(
			Language::class,
			$languages->find_by_code( 'tr' ),
			'Re-running migrations must not recreate tables or drop data.'
		);
	}

	/**
	 * Asserts an upgrade from schema version 1 adds only the new tables.
	 *
	 * @return void
	 */
	public function test_upgrade_from_version_one_adds_phase_eleven_tables() {
		$schema = $this->container->get( SchemaBuilder::class );
		$tables = $this->container->get( TableNames::class );

		// Simulate a legitimate pre-Phase-11 installation.
		$migration_one = new Migration001InitialSchema( $tables );

		$this->assertTrue( $migration_one->up( $schema ) );

		update_option( 'mclogiora_db_version', '1', false );

		$languages = $this->container->get( LanguageRepositoryInterface::class );
		$languages->create( new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ) );

		$this->assertFalse(
			$schema->table_exists( $tables->strings() ),
			'The Phase 11 tables should not exist before the upgrade.'
		);

		$result = $this->container->get( MigrationRunner::class )->run();

		$this->assertTrue( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( '2', (string) get_option( 'mclogiora_db_version' ) );

		foreach ( $tables->all() as $table ) {
			$this->assertTrue( $schema->table_exists( $table ), "Missing table after upgrade: {$table}" );
		}

		$this->assertInstanceOf(
			Language::class,
			$languages->find_by_code( 'tr' ),
			'Data created before the upgrade must survive it.'
		);
	}

	/**
	 * Asserts a migration reports failure when its tables are missing.
	 *
	 * @return void
	 */
	public function test_migration_reports_failure_when_postconditions_fail() {
		$tables = $this->container->get( TableNames::class );

		$migration = new Migration001InitialSchema( $tables );

		/*
		 * A schema builder pointed at a database where the statements cannot
		 * take effect must report failure rather than silent success. dbDelta
		 * swallows the error, so the postcondition check is what catches it.
		 */
		$broken = new SchemaBuilder( new AlwaysMissingWpdb() );

		$result = $migration->up( $broken );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_migration_incomplete', $result->get_error_code() );

		$data = $result->get_error_data();

		$this->assertIsArray( $data );
		$this->assertNotEmpty( $data['missing'] );
	}

	/**
	 * Asserts every migration declares the tables it creates.
	 *
	 * @return void
	 */
	public function test_migrations_declare_their_expected_tables() {
		$tables   = $this->container->get( TableNames::class );
		$declared = array_merge(
			( new Migration001InitialSchema( $tables ) )->expected_tables(),
			( new \McLogiora\Database\Migrations\Migration002TranslationDomains( $tables ) )->expected_tables()
		);

		sort( $declared );
		$all = $tables->all();
		sort( $all );

		$this->assertSame( $all, $declared, 'Every managed table must be owned by a migration.' );
	}

	/**
	 * Asserts the created schema has the intended structure, not just tables.
	 *
	 * @return void
	 */
	public function test_created_schema_has_the_intended_structure() {
		global $wpdb;

		$this->assertTrue( $this->container->get( Installer::class )->install() );

		$tables = $this->container->get( TableNames::class );
		$schema = $this->container->get( SchemaBuilder::class );

		$this->assertSame(
			array(
				'id',
				'string_hash',
				'source_text',
				'text_domain',
				'context',
				'source_type',
				'source_reference',
				'source_line',
				'is_stale',
				'first_seen_at',
				'last_seen_at',
			),
			$schema->table_columns( $tables->strings() )
		);

		$indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . $tables->strings() );
		$by_name = array();

		foreach ( (array) $indexes as $index ) {
			$by_name[ $index->Key_name ][] = $index->Column_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- SHOW INDEX column names.
		}

		$this->assertArrayHasKey( 'PRIMARY', $by_name, 'The strings table needs a primary key.' );
		$this->assertSame( array( 'id' ), $by_name['PRIMARY'] );
		$this->assertArrayHasKey( 'string_hash', $by_name, 'String identity must be unique.' );
		$this->assertArrayHasKey( 'text_domain', $by_name );

		$unique = $wpdb->get_results( "SHOW INDEX FROM {$tables->string_translations()} WHERE Key_name = 'string_language'" );

		$this->assertCount( 2, (array) $unique, 'One translation per string per language must be enforced.' );
	}

	/**
	 * Asserts the schema is created under the server's own SQL mode.
	 *
	 * @return void
	 */
	public function test_schema_installs_under_the_server_sql_mode() {
		global $wpdb;

		$mode = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );

		$this->assertTrue(
			$this->container->get( Installer::class )->install(),
			"Installation failed under sql_mode: {$mode}"
		);

		$this->assertSame( '', (string) $wpdb->last_error );
	}
}

/**
 * A wpdb double whose tables never materialise.
 *
 * Models the failure the postcondition check exists to catch: statements are
 * accepted, nothing reports an error, and no table appears.
 */
// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- test support class kept beside its only consumer.
final class AlwaysMissingWpdb {
	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wptests_';

	/**
	 * Last error.
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * Returns charset and collation SQL.
	 *
	 * @return string
	 */
	public function get_charset_collate() {
		return '';
	}

	/**
	 * Suppresses errors.
	 *
	 * @param bool $suppress Whether to suppress.
	 * @return bool
	 */
	public function suppress_errors( $suppress = true ) {
		unset( $suppress );

		return false;
	}

	/**
	 * Returns no rows, so no table ever appears to exist.
	 *
	 * @param string $query Query.
	 * @return array<int,object>
	 */
	public function get_results( $query ) {
		unset( $query );

		return array();
	}

	/**
	 * Returns nothing.
	 *
	 * @param string $query Query.
	 * @return null
	 */
	public function get_var( $query ) {
		unset( $query );

		return null;
	}

	/**
	 * Accepts any query without effect.
	 *
	 * @param string $query Query.
	 * @return int
	 */
	public function query( $query ) {
		unset( $query );

		return 0;
	}
}
