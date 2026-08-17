<?php
/**
 * Import apply integration tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Database\Installer;
use McLogiora\Database\TableNames;
use McLogiora\Database\TransactionInterface;
use McLogiora\ImportExport\ImportApplyService;
use McLogiora\ImportExport\ImportOperationExecutorInterface;
use McLogiora\ImportExport\ImportPlan;
use McLogiora\ImportExport\ImportPlanner;
use McLogiora\ImportExport\PackageEncoder;
use McLogiora\ImportExport\PackageExporter;
use McLogiora\ImportExport\PackageParser;
use McLogiora\ImportExport\PlannedOperation;
use McLogiora\ImportExport\TranslationPackage;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\TranslationRelationRepositoryInterface;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_UnitTestCase;

/**
 * Applies immutable plans against the real WordPress/MySQL persistence layer.
 */
final class ImportApplyIntegrationTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Source post identifier.
	*
	 * @var int
	 */
	private $source_id;

	/**
	 * Translation post identifier.
	 *
	 * @var int
	 */
	private $target_id;

	/**
	 * Sets up an installed site with one translated post.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();

		delete_option( 'mclogiora_db_version' );
		$this->container->get( Installer::class )->install();
		$this->container->get( RuntimeReadiness::class )->reset();
		create_initial_taxonomies();

		$languages = $this->container->get( LanguageRepositoryInterface::class );
		if ( ! $languages->find_by_code( 'en' ) instanceof Language ) {
			$languages->create( new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, false ) );
			$languages->set_default( 'en' );
		}

		if ( ! $languages->find_by_code( 'tr' ) instanceof Language ) {
			$languages->create( new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ) );
		}

		$this->source_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'English source',
				'post_name'   => 'import-source',
			)
		);
		$this->target_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Turkish translation',
				'post_name'   => 'import-target',
			)
		);

		$this->assertIsArray(
			$this->container->get( TranslationWorkflowService::class )
				->content()
				->link_existing( $this->source_id, $this->target_id, 'tr' )
		);
	}

	/**
	 * Applies language, group and link operations and proves the old plan is stale.
	 *
	 * @return void
	 */
	public function test_real_apply_commits_and_fresh_reads_match_the_plan() {
		global $wpdb;

		$package = $this->package();
		$this->prepare_missing_destination_language_and_relations();
		$tables = $this->container->get( TableNames::class );

		foreach ( array( $tables->languages(), $tables->translation_groups(), $tables->translation_items() ) as $table ) {
			$definition = $wpdb->get_row( "SHOW CREATE TABLE {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from TableNames.

			$this->assertSame(
				'InnoDB',
				preg_match( '/ENGINE=([A-Za-z0-9_]+)/i', (string) ( isset( $definition['Create Table'] ) ? $definition['Create Table'] : '' ), $matches ) ? $matches[1] : null
			);
		}

		$plan = $this->plan( $package );
		$this->assertSame( 1, $plan->counts()['create_language'] );
		$this->assertSame( 1, $plan->counts()['create_group'] );
		$this->assertSame( 1, $plan->counts()['link_item'] );
		$this->assertSame( array(), $plan->errors() );
		$this->assertSame( array(), $plan->conflicts() );
		$this->assertSame( array(), $plan->unresolved() );

		$result = $this->container->get( ImportApplyService::class )->apply( $plan );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 3, $result->applied_operations() );
		$this->assertSame( false, $result->rolled_back() );

		$group_key = $package->relations()[0]->group_key();
		wp_cache_flush();
		$language = $this->container->get( LanguageRepositoryInterface::class )->find_by_code( 'tr' );
		$group    = $this->container->get( TranslationRelationRepositoryInterface::class )->find_group( $group_key );

		$this->assertInstanceOf( Language::class, $language );
		$this->assertSame( 'tr_TR', $language->locale() );
		$this->assertNotNull( $group );
		$this->assertSame( $group_key, $group->group_key() );
		$this->assertCount( 2, $group->items() );
		$this->assertNotNull( $this->container->get( TranslationRelationRepositoryInterface::class )->find_item( 'post', (string) $this->target_id, 'tr' ) );

		$old_result = $this->container->get( ImportApplyService::class )->apply( $plan );
		$this->assertFalse( $old_result->is_success() );
		$this->assertSame( 'import_plan_stale', $old_result->issues()[0]->code() );

		$fresh_plan = $this->plan( $package );
		$this->assertTrue( $fresh_plan->is_empty() );
		$fresh_result = $this->container->get( ImportApplyService::class )->apply( $fresh_plan );
		$this->assertTrue( $fresh_result->is_success() );
		$this->assertSame( 4, $fresh_result->skipped_operations() );
	}

	/**
	 * Rolls back exact real DB state after group and link writes.
	 *
	 * @return void
	 */
	public function test_real_apply_rolls_back_after_group_and_link_writes() {
		$package = $this->package();
		$this->prepare_missing_destination_language_and_relations();
		$before = $this->site_snapshot();

		foreach ( array( 1, 2, 3 ) as $failure_call ) {
			$result = $this->apply_with_failure( $this->plan( $package ), $failure_call );

			$this->assertFalse( $result->is_success() );
			$this->assertTrue( $result->rolled_back() );
			$this->assertSame( 'import_apply_failed', $result->issues()[0]->code() );
			$this->assertSame( $before, $this->site_snapshot() );
		}
	}

	/**
	 * Returns the real package as bytes parsed back into the transport model.
	 *
	 * @return TranslationPackage
	 */
	private function package() {
		$encoded = ( new PackageEncoder() )->encode( $this->container->get( PackageExporter::class )->export() );
		$package = ( new PackageParser() )->parse( $encoded );

		if ( is_wp_error( $package ) ) {
			$this->fail( $package->get_error_message() );
		}

		return $package;
	}

	/**
	 * Makes the package's language and relation rows absent at the destination.
	 *
	 * @return void
	 */
	private function prepare_missing_destination_language_and_relations() {
		global $wpdb;

		$tables = $this->container->get( TableNames::class );
		$wpdb->query( 'DELETE FROM ' . $tables->translation_items() ); // phpcs:ignore WordPress.DB -- integration fixture.
		$wpdb->query( 'DELETE FROM ' . $tables->translation_groups() ); // phpcs:ignore WordPress.DB -- integration fixture.
		$this->assertTrue( $this->container->get( LanguageRepositoryInterface::class )->delete( 'tr' ) );
		wp_cache_flush();
	}

	/**
	 * Plans a package against the real destination.
	 *
	 * @param TranslationPackage $package Package.
	 * @return ImportPlan
	 */
	private function plan( TranslationPackage $package ) {
		return $this->container->get( ImportPlanner::class )->plan( $package );
	}

	/**
	 * Applies a plan with a failure after one real operation has written.
	 *
	 * @param ImportPlan $plan Plan.
	 * @param int         $fail_on_call Executor call after which to fail.
	 * @return \McLogiora\ImportExport\ImportApplyResult
	 */
	private function apply_with_failure( ImportPlan $plan, $fail_on_call ) {
		$inner = $this->container->get( ImportOperationExecutorInterface::class );
		$executor = new class( $inner, $fail_on_call ) implements ImportOperationExecutorInterface {
			/** @var ImportOperationExecutorInterface */
			private $inner;
			/** @var int */
			private $fail_on_call;
			/** @var int */
			private $calls = 0;

			public function __construct( ImportOperationExecutorInterface $inner, $fail_on_call ) {
				$this->inner        = $inner;
				$this->fail_on_call = (int) $fail_on_call;
			}

			public function execute( PlannedOperation $operation ) {
				$result = $this->inner->execute( $operation );
				++$this->calls;

				return $this->calls === $this->fail_on_call
					? new \WP_Error( 'import_apply_failed', 'Qualification failure after a real write.' )
					: $result;
			}
		};

		return ( new ImportApplyService(
			$this->container->get( \McLogiora\ImportExport\ImportAuthorizationInterface::class ),
			$this->container->get( \McLogiora\ImportExport\ImportPlanPreconditionChecker::class ),
			$executor,
			$this->container->get( \McLogiora\ImportExport\ImportPlanVerifier::class ),
			$this->container->get( TransactionInterface::class )
		) )->apply( $plan );
	}

	/**
	 * Hashes all plugin and WordPress rows relevant to this fixture.
	 *
	 * @return array<string,string>
	 */
	private function site_snapshot() {
		global $wpdb;

		$tables   = $this->container->get( TableNames::class );
		$snapshot = array();

		foreach ( $tables->all() as $table ) {
			$snapshot[ $table ] = md5( (string) wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . $table, ARRAY_A ) ) ); // phpcs:ignore WordPress.DB -- integration snapshot.
		}

		$snapshot['posts'] = md5( (string) wp_json_encode( $wpdb->get_results( "SELECT ID, post_name, post_status, post_title FROM {$wpdb->posts} ORDER BY ID", ARRAY_A ) ) ); // phpcs:ignore WordPress.DB -- integration snapshot.
		$snapshot['options'] = md5( (string) wp_json_encode( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'mclogiora%' ORDER BY option_name", ARRAY_A ) ) ); // phpcs:ignore WordPress.DB -- integration snapshot.

		return $snapshot;
	}
}
