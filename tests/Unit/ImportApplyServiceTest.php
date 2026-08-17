<?php
/**
 * Import apply service tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Database\TransactionInterface;
use McLogiora\ImportExport\ImportApplyService;
use McLogiora\ImportExport\ImportAuthorizationInterface;
use McLogiora\ImportExport\ImportOperationExecutor;
use McLogiora\ImportExport\ImportOperationExecutorInterface;
use McLogiora\ImportExport\ImportPlan;
use McLogiora\ImportExport\ImportPlanPreconditionChecker;
use McLogiora\ImportExport\ImportPlanVerifier;
use McLogiora\ImportExport\ObjectLocator;
use McLogiora\ImportExport\PlanIssue;
use McLogiora\ImportExport\PlannedOperation;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageService;
use McLogiora\Languages\InMemoryLanguageRepository;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Languages\LocaleValidator;
use McLogiora\Languages\RtlDetector;
use McLogiora\Relations\MetadataNeedsUpdateDetector;
use McLogiora\Relations\TranslationRelationService;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Tests\Support\FakeLanguageService;
use McLogiora\Tests\Support\FakeObjectLocatorGateway;
use McLogiora\Tests\Support\FakeRelationRepository;
use PHPUnit\Framework\TestCase;

/**
 * Pins apply's safety boundary without a transport or WordPress writes.
 */
final class ImportApplyServiceTest extends TestCase {
	/**
	 * Applies a plan in exact operation order and preserves the package key.
	 *
	 * @return void
	 */
	public function test_successful_apply_creates_group_and_link() {
		$relations = new FakeRelationRepository();
		$objects   = new FakeObjectLocatorGateway();
		$objects->add_post( 10, 'post', 'source' );
		$objects->add_post( 20, 'post', 'translation' );
		$languages = new FakeLanguageService( $this->languages() );
		$service   = $this->service( $languages, $relations, $objects );

		$result = $service->apply( $this->plan() );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 2, $result->applied_operations() );
		$this->assertSame( false, $result->rolled_back() );
		$this->assertNotNull( $relations->find_group( '11111111-1111-4111-8111-111111111111' ) );
		$this->assertSame( 2, count( $relations->find_group( '11111111-1111-4111-8111-111111111111' )->items() ) );
		$this->assertSame( 'translation', $objects->describe_post( 20 )['slug'] );
	}

	/**
	 * Creates an imported language through the language domain service.
	 *
	 * @return void
	 */
	public function test_successful_apply_creates_language_from_package_fields() {
		$languages = new LanguageService( new InMemoryLanguageRepository(), new LocaleValidator(), new RtlDetector() );
		$relations = new FakeRelationRepository();
		$objects   = new FakeObjectLocatorGateway();
		$service   = $this->service( $languages, $relations, $objects );
		$plan      = new ImportPlan(
			array(
				new PlannedOperation(
					PlannedOperation::CREATE_LANGUAGE,
					'fr',
					array(
						'code'        => 'fr',
						'locale'      => 'fr_FR',
						'native_name' => 'Français',
						'english_name'=> 'French',
						'direction'   => 'ltr',
						'is_active'   => true,
						'is_default'  => false,
						'order'       => 4,
					)
				),
			),
			array()
		);

		$result = $service->apply( $plan );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 1, $result->applied_operations() );
		$this->assertSame( 'fr_FR', $languages->get_language_by_code( 'fr' )->locale() );
	}

	/**
	 * Rejects a plan with a conflict before opening a transaction.
	 *
	 * @return void
	 */
	public function test_conflict_plan_is_refused_without_writes() {
		$relations = new FakeRelationRepository();
		$objects   = new FakeObjectLocatorGateway();
		$service   = $this->service( new FakeLanguageService( $this->languages() ), $relations, $objects );
		$plan      = new ImportPlan( array(), array( new PlanIssue( PlanIssue::LEVEL_CONFLICT, 'language_differs', 'Conflict.' ) ) );

		$result = $service->apply( $plan );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'import_plan_not_applicable', $result->issues()[0]->code() );
		$this->assertNull( $relations->find_group( '11111111-1111-4111-8111-111111111111' ) );
	}

	/**
	 * Rejects locator drift and performs no writes.
	 *
	 * @return void
	 */
	public function test_stale_locator_is_rejected_before_writes() {
		$relations = new FakeRelationRepository();
		$objects   = new FakeObjectLocatorGateway();
		$objects->add_post( 10, 'post', 'changed-after-dry-run' );
		$service = $this->service( new FakeLanguageService( $this->languages() ), $relations, $objects );

		$result = $service->apply( $this->plan() );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'import_plan_stale', $result->issues()[0]->code() );
		$this->assertSame( 'locator_drift', $result->issues()[0]->context()['reason'] );
		$this->assertNull( $relations->find_group( '11111111-1111-4111-8111-111111111111' ) );
	}

	/**
	 * Rolls back a failure after the link operation has already written.
	 *
	 * @return void
	 */
	public function test_failure_after_link_rolls_back_exact_relation_state() {
		$relations = new FakeRelationRepository();
		$objects   = new FakeObjectLocatorGateway();
		$objects->add_post( 10, 'post', 'source' );
		$objects->add_post( 20, 'post', 'translation' );
		$real_executor = new ImportOperationExecutor(
			new FakeLanguageService( $this->languages() ),
			new TranslationRelationService( $relations, new MetadataNeedsUpdateDetector() )
		);
		$executor = new class( $real_executor ) implements ImportOperationExecutorInterface {
			private $inner;
			private $calls = 0;
			public function __construct( ImportOperationExecutorInterface $inner ) { $this->inner = $inner; }
			public function execute( PlannedOperation $operation ) {
				$result = $this->inner->execute( $operation );
				++$this->calls;
				return 2 === $this->calls ? new \WP_Error( 'injected_failure', 'Injected test failure.' ) : $result;
			}
		};
		$service = $this->service( new FakeLanguageService( $this->languages() ), $relations, $objects, $executor );

		$result = $service->apply( $this->plan() );

		$this->assertFalse( $result->is_success() );
		$this->assertTrue( $result->rolled_back() );
		$this->assertSame( 'injected_failure', $result->issues()[0]->code() );
		$this->assertNull( $relations->find_group( '11111111-1111-4111-8111-111111111111' ) );
	}

	/**
	 * A re-apply of the old plan is stale while a fresh dry run is all skips.
	 *
	 * @return void
	 */
	public function test_reapply_is_stale_and_fresh_plan_can_skip() {
		$relations = new FakeRelationRepository();
		$objects   = new FakeObjectLocatorGateway();
		$objects->add_post( 10, 'post', 'source' );
		$objects->add_post( 20, 'post', 'translation' );
		$languages = new FakeLanguageService( $this->languages() );
		$service   = $this->service( $languages, $relations, $objects );

		$this->assertTrue( $service->apply( $this->plan() )->is_success() );
		$old_result = $service->apply( $this->plan() );

		$this->assertFalse( $old_result->is_success() );
		$this->assertSame( 'import_plan_stale', $old_result->issues()[0]->code() );

		$fresh = new ImportPlan(
			array(
				new PlannedOperation( PlannedOperation::SKIP, $key = '11111111-1111-4111-8111-111111111111:en', array( 'kind' => 'relation_item', 'reason' => 'source_present', 'group_key' => '11111111-1111-4111-8111-111111111111', 'object_type' => 'post', 'object_id' => 10, 'language' => 'en', 'status' => TranslationStatus::ORIGINAL, 'locator' => ObjectLocator::for_post( 'post', 'source' )->to_array() ) ),
				new PlannedOperation( PlannedOperation::SKIP, $key . ':tr', array( 'kind' => 'relation_item', 'reason' => 'item_present', 'group_key' => '11111111-1111-4111-8111-111111111111', 'object_type' => 'post', 'object_id' => 20, 'language' => 'tr', 'status' => TranslationStatus::TRANSLATED, 'locator' => ObjectLocator::for_post( 'post', 'translation' )->to_array() ) ),
			),
			array()
		);
		$fresh_result = $service->apply( $fresh );
		$this->assertTrue( $fresh_result->is_success() );
		$this->assertSame( 2, $fresh_result->skipped_operations() );
	}

	private function service( $languages, $relations, $objects, $executor = null ) {
		$transaction = new class( $relations ) implements TransactionInterface {
			private $relations;
			public $started = false;
			public function __construct( FakeRelationRepository $relations ) { $this->relations = $relations; }
			public function begin() { $this->started = true; return true; }
			public function commit() { $this->started = false; return true; }
			public function rollback() { $this->started = false; $this->relations->archive_group( '11111111-1111-4111-8111-111111111111' ); return true; }
		};
		$executor = $executor ? $executor : new ImportOperationExecutor( $languages, new TranslationRelationService( $relations, new MetadataNeedsUpdateDetector() ) );
		$authorization = new class implements ImportAuthorizationInterface {
			public function validate_manage_capability() { return true; }
		};

		return new ImportApplyService(
			$authorization,
			new ImportPlanPreconditionChecker( $languages, $relations, $objects ),
			$executor,
			new ImportPlanVerifier( $languages, $relations, $objects ),
			$transaction
		);
	}

	private function plan() {
		$source_locator = ObjectLocator::for_post( 'post', 'source' )->to_array();
		$target_locator = ObjectLocator::for_post( 'post', 'translation' )->to_array();
		$key            = '11111111-1111-4111-8111-111111111111';

		return new ImportPlan(
			array(
				new PlannedOperation( PlannedOperation::CREATE_GROUP, $key, array( 'group_key' => $key, 'object_type' => 'post', 'object_id' => 10, 'language' => 'en', 'status' => TranslationStatus::ORIGINAL, 'locator' => $source_locator ) ),
				new PlannedOperation( PlannedOperation::LINK_ITEM, $key . ':tr', array( 'group_key' => $key, 'object_type' => 'post', 'object_id' => 20, 'language' => 'tr', 'status' => TranslationStatus::TRANSLATED, 'locator' => $target_locator ) ),
			),
			array()
		);
	}

	private function languages() {
		return array(
			new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, true ),
			new Language( 'tr', 'tr_TR', 'Türkçe', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ),
		);
	}
}
