<?php
/**
 * Import dry-run planner tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Core\RuntimeReadiness;
use McLogiora\Database\DatabaseVersionManager;
use McLogiora\ImportExport\ImportPlan;
use McLogiora\ImportExport\ImportPlanner;
use McLogiora\ImportExport\PackageEncoder;
use McLogiora\ImportExport\PackageExporter;
use McLogiora\ImportExport\PackageParser;
use McLogiora\ImportExport\PackageValidator;
use McLogiora\ImportExport\PlanIssue;
use McLogiora\ImportExport\PlannedOperation;
use McLogiora\ImportExport\TranslationPackage;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Tests\Support\FakeLanguageService;
use McLogiora\Tests\Support\FakeObjectLocatorGateway;
use McLogiora\Tests\Support\FakeRelationRepository;
use McLogiora\Workflows\TranslationStatusTransitions;
use PHPUnit\Framework\TestCase;

/**
 * Pins what a dry run reports, and pins that it reports rather than acts.
 *
 * Every test here builds a real package by exporting a source site and reading
 * the bytes back through the parser, rather than hand-writing a package array.
 * A planner tested against arrays that no exporter would produce proves the
 * planner agrees with the test author, which is not the property that matters.
 */
final class ImportPlannerTest extends TestCase {
	/**
	 * Destination languages.
	 *
	 * @var Language[]
	 */
	private $destination_languages = array();

	/**
	 * Destination relations.
	 *
	 * @var FakeRelationRepository
	 */
	private $destination_relations;

	/**
	 * Destination content.
	 *
	 * @var FakeObjectLocatorGateway
	 */
	private $destination_objects;

	/**
	 * Marks the schema as installed and resets the destination.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['mclogiora_test_options'][ DatabaseVersionManager::OPTION_NAME ] = '2';

		$this->destination_languages = array();
		$this->destination_relations = new FakeRelationRepository();
		$this->destination_objects   = new FakeObjectLocatorGateway();
	}

	/**
	 * Clears the option store.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['mclogiora_test_options'] = array();

		parent::tearDown();
	}

	/**
	 * A package read back against its own site plans no work at all.
	 *
	 * @return void
	 */
	public function test_same_site_round_trip_plans_nothing() {
		$package = $this->source_package();

		$this->give_destination_the_source_site();

		$plan = $this->plan( $package );

		$this->assertTrue( $plan->is_applicable() );
		$this->assertTrue( $plan->is_empty() );
		$this->assertSame( array(), $plan->errors() );
		$this->assertSame( array(), $plan->conflicts() );
		$this->assertSame( array(), $plan->unresolved() );
		$this->assertSame(
			array(
				'create_language' => 0,
				'create_group'    => 0,
				'link_item'       => 0,
				'skip'            => 6,
				'error'           => 0,
				'conflict'        => 0,
				'unresolved'      => 0,
				'warning'         => 0,
			),
			$plan->counts()
		);
	}

	/**
	 * Planning the same package twice produces the same plan.
	 *
	 * @return void
	 */
	public function test_repeated_planning_is_deterministic() {
		$package = $this->source_package();

		$this->give_destination_the_source_site();

		$planner = $this->planner();

		$this->assertSame(
			$planner->plan( $package )->to_array(),
			$planner->plan( $package )->to_array()
		);
		$this->assertSame(
			$this->planner()->plan( $package )->to_array(),
			$this->planner()->plan( $package )->to_array()
		);
	}

	/**
	 * An empty destination gets a plan that would build the whole package.
	 *
	 * @return void
	 */
	public function test_empty_destination_plans_creates_and_links() {
		$package = $this->source_package();

		$this->give_destination_the_content();
		$this->destination_languages = array();

		$plan = $this->plan( $package );

		$this->assertTrue( $plan->is_applicable() );
		$this->assertSame( array( 'en', 'tr' ), $this->subjects( $plan, PlannedOperation::CREATE_LANGUAGE ) );
		$this->assertSame(
			array( 'group-post-0001', 'group-term-0002' ),
			$this->subjects( $plan, PlannedOperation::CREATE_GROUP )
		);
		$this->assertSame(
			array( 'group-post-0001:tr', 'group-term-0002:tr' ),
			$this->subjects( $plan, PlannedOperation::LINK_ITEM )
		);
		$this->assertSame( array(), $plan->conflicts() );
		$this->assertSame( array(), $plan->unresolved() );
	}

	/**
	 * A planned operation carries the destination identifiers an apply needs.
	 *
	 * @return void
	 */
	public function test_operations_carry_resolved_destination_identifiers() {
		$package = $this->source_package();

		$this->give_destination_the_content();

		$plan   = $this->plan( $package );
		$create = $plan->operations_of_type( PlannedOperation::CREATE_GROUP )[0];
		$link   = $plan->operations_of_type( PlannedOperation::LINK_ITEM )[0];

		$this->assertSame(
			array(
				'group_key'   => 'group-post-0001',
				'object_type' => 'post',
				'object_id'   => 11,
				'language'    => 'en',
				'status'      => 'original',
				'locator'     => array(
					'kind'      => 'post',
					'post_type' => 'page',
					'slug'      => 'about',
					'ancestors' => array( 'company' ),
				),
			),
			$create->detail()
		);
		$this->assertSame( 12, $link->detail()['object_id'] );
		$this->assertSame( 'translated', $link->detail()['status'] );
	}

	/**
	 * A language that exists here with different metadata is a conflict.
	 *
	 * @return void
	 */
	public function test_a_differing_language_is_reported_and_never_overwritten() {
		$package = $this->source_package();

		$this->give_destination_the_content();
		$this->destination_languages = array(
			new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, true ),
			new Language( 'tr', 'tr_TR', 'Turkish (site)', 'Turkish', 'ltr', LanguageStatus::INACTIVE, 4, false ),
		);

		$plan     = $this->plan( $package );
		$conflict = $this->conflict( $plan, 'language_differs' );

		$this->assertSame( 'tr', $conflict->context()['language'] );
		$this->assertSame(
			array( 'native_name', 'is_active', 'order' ),
			array_keys( $conflict->context()['differences'] )
		);
		$this->assertSame( array(), $plan->operations_of_type( PlannedOperation::CREATE_LANGUAGE ) );
	}

	/**
	 * Two sites disagreeing about the default language is reported once.
	 *
	 * @return void
	 */
	public function test_a_default_language_disagreement_is_reported() {
		$package = $this->source_package();

		$this->give_destination_the_content();
		$this->destination_languages = array(
			new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, false ),
			new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, true ),
		);

		$conflict = $this->conflict( $this->plan( $package ), 'default_language_differs' );

		$this->assertSame( 'en', $conflict->context()['package_default'] );
		$this->assertSame( 'tr', $conflict->context()['site_default'] );
	}

	/**
	 * A locator that names nothing here is unresolved, not invented.
	 *
	 * @return void
	 */
	public function test_a_missing_object_is_unresolved() {
		$package = $this->source_package();

		$this->give_destination_the_languages();
		$this->destination_objects->add_post( 11, 'page', 'about', array( 'company' ) );
		$this->destination_objects->add_term( 31, 'category', 'news' );
		$this->destination_objects->add_term( 32, 'category', 'haberler' );

		$plan  = $this->plan( $package );
		$issue = $this->issue( $plan, 'locator_not_found' );

		$this->assertSame( PlanIssue::LEVEL_UNRESOLVED, $issue->level() );
		$this->assertSame( 'tr', $issue->context()['language'] );
		$this->assertSame( 'target', $issue->context()['role'] );
		$this->assertSame( array(), $issue->context()['matches'] );
		$this->assertSame( array( 'group-post-0001:tr' ), $this->missing_links( $plan ) );
	}

	/**
	 * A locator matching two objects is reported rather than guessed at.
	 *
	 * @return void
	 */
	public function test_an_ambiguous_locator_is_reported_and_never_resolved() {
		$package = $this->source_package();

		$this->give_destination_the_content();
		$this->destination_objects->add_post( 99, 'page', 'hakkimizda', array( 'sirket' ) );

		$plan  = $this->plan( $package );
		$issue = $this->issue( $plan, 'locator_ambiguous' );

		$this->assertSame( array( 12, 99 ), $issue->context()['matches'] );
		$this->assertSame( array( 'group-post-0001:tr' ), $this->missing_links( $plan ) );
	}

	/**
	 * The ancestor path is what separates two same-slug pages.
	 *
	 * @return void
	 */
	public function test_a_same_slug_page_under_another_parent_does_not_match() {
		$package = $this->source_package();

		$this->give_destination_the_languages();
		$this->destination_objects->add_post( 11, 'page', 'about', array( 'company' ) );
		$this->destination_objects->add_post( 12, 'page', 'hakkimizda', array( 'baska' ) );
		$this->destination_objects->add_term( 31, 'category', 'news' );
		$this->destination_objects->add_term( 32, 'category', 'haberler' );

		$plan = $this->plan( $package );

		$this->assertSame( 'locator_not_found', $this->issue( $plan, 'locator_not_found' )->code() );
	}

	/**
	 * A draft with no slug yet cannot be addressed, and the plan says so.
	 *
	 * @return void
	 */
	public function test_an_object_without_a_slug_is_reported_as_incomplete() {
		$source_objects = $this->source_objects();
		$source_objects->add_post( 12, 'page', '', array( 'sirket' ) );

		$package = $this->source_package( null, $source_objects );

		$this->give_destination_the_content();

		$issue = $this->issue( $this->plan( $package ), 'locator_incomplete' );

		$this->assertSame( PlanIssue::LEVEL_UNRESOLVED, $issue->level() );
		$this->assertSame( 'tr', $issue->context()['language'] );
	}

	/**
	 * An item the exporter could not address at all is reported as absent.
	 *
	 * @return void
	 */
	public function test_an_item_exported_without_a_locator_is_reported() {
		$source = $this->source_relations();
		$source->seed_group(
			'group-gone-0003',
			array(
				new TranslationItem( ContentType::POST, '11', 'en', TranslationStatus::ORIGINAL, true ),
				new TranslationItem( ContentType::POST, '4242', 'tr', TranslationStatus::DRAFT, false ),
			)
		);

		$package = $this->source_package( $source );

		$this->give_destination_the_content();

		$issue = $this->issue( $this->plan( $package ), 'locator_absent' );

		$this->assertNull( $issue->context()['locator'] );
	}

	/**
	 * A post type this site does not register is named as the reason.
	 *
	 * @return void
	 */
	public function test_an_unregistered_post_type_is_reported() {
		$package = $this->source_package();

		$this->give_destination_the_content();
		$this->destination_objects->set_post_types( array( 'post' ) );

		$issue = $this->issue( $this->plan( $package ), 'locator_type_unknown' );

		$this->assertSame( 'source', $issue->context()['role'] );
	}

	/**
	 * A language slot filled by another object is a conflict, not an overwrite.
	 *
	 * @return void
	 */
	public function test_an_occupied_language_slot_is_a_conflict() {
		$package = $this->source_package();

		$this->give_destination_the_content();
		$this->destination_objects->add_post( 77, 'page', 'baska-sayfa', array( 'sirket' ) );
		$this->destination_relations->seed_group(
			'group-post-0001',
			array(
				new TranslationItem( ContentType::POST, '11', 'en', TranslationStatus::ORIGINAL, true ),
				new TranslationItem( ContentType::POST, '77', 'tr', TranslationStatus::TRANSLATED, false ),
			)
		);

		$plan     = $this->plan( $package );
		$conflict = $this->conflict( $plan, 'language_slot_occupied' );

		$this->assertSame( 77, $conflict->context()['site_object_id'] );
		$this->assertSame( 12, $conflict->context()['package_object_id'] );
		$this->assertSame( array( 'group-post-0001:tr' ), $this->missing_links( $plan ) );
	}

	/**
	 * The same object in the same slot with another status is a conflict.
	 *
	 * @return void
	 */
	public function test_a_status_disagreement_is_reported_with_the_domain_verdict() {
		$package = $this->source_package();

		$this->give_destination_the_content();
		$this->destination_relations->seed_group(
			'group-post-0001',
			array(
				new TranslationItem( ContentType::POST, '11', 'en', TranslationStatus::ORIGINAL, true ),
				new TranslationItem( ContentType::POST, '12', 'tr', TranslationStatus::DRAFT, false ),
			)
		);

		$conflict = $this->conflict( $this->plan( $package ), 'item_status_differs' );

		$this->assertSame( 'draft', $conflict->context()['site_status'] );
		$this->assertSame( 'translated', $conflict->context()['package_status'] );
		$this->assertTrue( $conflict->context()['transition_allowed'] );
		$this->assertNull( $conflict->context()['transition_error'] );
	}

	/**
	 * A status difference the lifecycle forbids says which rule forbids it.
	 *
	 * @return void
	 */
	public function test_a_forbidden_status_difference_names_the_domain_error() {
		$source = $this->source_relations();
		$source->seed_group(
			'group-post-0001',
			array(
				new TranslationItem( ContentType::POST, '11', 'en', TranslationStatus::ORIGINAL, true ),
				new TranslationItem( ContentType::POST, '12', 'tr', TranslationStatus::MACHINE_SUGGESTED, false ),
			)
		);

		$package = $this->source_package( $source );

		$this->give_destination_the_content();
		$this->destination_relations->seed_group(
			'group-post-0001',
			array(
				new TranslationItem( ContentType::POST, '11', 'en', TranslationStatus::ORIGINAL, true ),
				new TranslationItem( ContentType::POST, '12', 'tr', TranslationStatus::TRANSLATED, false ),
			)
		);

		$conflict = $this->conflict( $this->plan( $package ), 'item_status_differs' );

		$this->assertFalse( $conflict->context()['transition_allowed'] );
		$this->assertSame( 'mclogiora_invalid_status_transition', $conflict->context()['transition_error'] );
	}

	/**
	 * An object already in another group is never quietly relinked.
	 *
	 * @return void
	 */
	public function test_an_object_in_another_group_is_a_conflict() {
		$package = $this->source_package();

		$this->give_destination_the_content();
		$this->destination_relations->seed_group(
			'group-elsewhere-9999',
			array( new TranslationItem( ContentType::POST, '11', 'en', TranslationStatus::ORIGINAL, true ) )
		);

		$plan     = $this->plan( $package );
		$conflict = $this->conflict( $plan, 'object_already_grouped' );

		$this->assertSame( 'source', $conflict->context()['role'] );
		$this->assertSame( 11, $conflict->context()['object_id'] );
		$this->assertSame( array( 'group-term-0002' ), $this->subjects( $plan, PlannedOperation::CREATE_GROUP ) );
	}

	/**
	 * A group whose source moved still has its targets considered.
	 *
	 * @return void
	 */
	public function test_targets_are_still_planned_when_the_group_exists_and_the_source_moved() {
		$package = $this->source_package();

		$this->give_destination_the_languages();
		$this->destination_objects->add_post( 12, 'page', 'hakkimizda', array( 'sirket' ) );
		$this->destination_objects->add_term( 31, 'category', 'news' );
		$this->destination_objects->add_term( 32, 'category', 'haberler' );
		$this->destination_relations->seed_group( 'group-post-0001', array() );

		$plan = $this->plan( $package );

		$this->assertSame( 'locator_not_found', $this->issue( $plan, 'locator_not_found' )->code() );
		$this->assertContains( 'group-post-0001:tr', $this->subjects( $plan, PlannedOperation::LINK_ITEM ) );
	}

	/**
	 * A term related to a term in another taxonomy is refused.
	 *
	 * @return void
	 */
	public function test_a_taxonomy_mismatch_is_a_conflict() {
		$source_objects = $this->source_objects();
		$source_objects->add_term( 32, 'post_tag', 'haberler' );

		$package = $this->source_package( null, $source_objects );

		$this->give_destination_the_content();

		$conflict = $this->conflict( $this->plan( $package ), 'group_taxonomy_mismatch' );

		$this->assertSame( 'category', $conflict->context()['source_taxonomy'] );
		$this->assertSame( 'post_tag', $conflict->context()['target_taxonomy'] );
	}

	/**
	 * A post related to a term is refused.
	 *
	 * @return void
	 */
	public function test_an_object_type_mismatch_is_a_conflict() {
		$source = $this->source_relations();
		$source->seed_group(
			'group-mixed-0004',
			array(
				new TranslationItem( ContentType::POST, '11', 'en', TranslationStatus::ORIGINAL, true ),
				new TranslationItem( ContentType::TERM, '32', 'tr', TranslationStatus::DRAFT, false ),
			)
		);

		$package = $this->source_package( $source );

		$this->give_destination_the_content();

		$conflict = $this->conflict( $this->plan( $package ), 'group_object_type_mismatch' );

		$this->assertSame( 'post', $conflict->context()['source_object_type'] );
		$this->assertSame( 'term', $conflict->context()['target_object_type'] );
	}

	/**
	 * A language nobody has cannot receive a translation.
	 *
	 * @return void
	 */
	public function test_a_language_neither_side_has_blocks_the_item() {
		$source = $this->source_relations();
		$source->seed_group(
			'group-post-0001',
			array(
				new TranslationItem( ContentType::POST, '11', 'en', TranslationStatus::ORIGINAL, true ),
				new TranslationItem( ContentType::POST, '12', 'de', TranslationStatus::DRAFT, false ),
			)
		);

		$package = $this->source_package( $source );

		$this->give_destination_the_content();

		$conflict = $this->conflict( $this->plan( $package ), 'language_missing' );

		$this->assertSame( 'de', $conflict->context()['language'] );
	}

	/**
	 * A status outside the domain vocabulary stops the whole package.
	 *
	 * @return void
	 */
	public function test_an_unknown_status_blocks_the_package_and_plans_nothing() {
		$package = $this->package_with_status( 'approved-ish' );

		$this->give_destination_the_content();

		$plan = $this->plan( $package );

		$this->assertFalse( $plan->is_applicable() );
		$this->assertSame( array(), $plan->operations() );
		$this->assertSame( 'unknown_status', $plan->errors()[0]->code() );
	}

	/**
	 * With no schema installed nothing is planned at all.
	 *
	 * @return void
	 */
	public function test_a_site_without_the_schema_plans_nothing() {
		$package = $this->source_package();

		$this->give_destination_the_content();
		unset( $GLOBALS['mclogiora_test_options'][ DatabaseVersionManager::OPTION_NAME ] );

		$plan = $this->plan( $package );

		$this->assertFalse( $plan->is_applicable() );
		$this->assertSame( array(), $plan->operations() );
		$this->assertSame( 'schema_not_installed', $plan->errors()[0]->code() );
	}

	/**
	 * A different producing plugin version is a warning and nothing more.
	 *
	 * @return void
	 */
	public function test_a_different_plugin_version_is_only_a_warning() {
		$package = $this->source_package( null, null, '0.9.0' );

		$this->give_destination_the_source_site();

		$plan = $this->plan( $package );

		$this->assertTrue( $plan->is_applicable() );
		$this->assertSame( 'plugin_version_differs', $plan->warnings()[0]->code() );
		$this->assertSame( '0.9.0', $plan->warnings()[0]->context()['package_plugin_version'] );
	}

	/**
	 * Planning writes nothing to the destination.
	 *
	 * @return void
	 */
	public function test_planning_leaves_the_destination_untouched() {
		$package = $this->source_package();

		$this->give_destination_the_content();
		$this->destination_relations->seed_group(
			'group-elsewhere-9999',
			array( new TranslationItem( ContentType::POST, '11', 'en', TranslationStatus::ORIGINAL, true ) )
		);

		$before = $this->destination_fingerprint();

		$this->plan( $package );
		$this->plan( $package );
		$this->plan( $package );

		$this->assertSame( $before, $this->destination_fingerprint() );
	}

	/**
	 * Reading a plan twice cannot change it.
	 *
	 * @return void
	 */
	public function test_reading_a_plan_is_inert() {
		$package = $this->source_package();

		$this->give_destination_the_content();

		$plan  = $this->plan( $package );
		$first = $plan->to_array();

		$plan->operations();
		$plan->issues();
		$plan->counts();
		$plan->is_applicable();

		$this->assertSame( $first, $plan->to_array() );
	}

	/**
	 * Builds the source site's relation fixture.
	 *
	 * @return FakeRelationRepository
	 */
	private function source_relations() {
		$relations = new FakeRelationRepository();
		$relations->seed_group(
			'group-post-0001',
			array(
				new TranslationItem( ContentType::POST, '11', 'en', TranslationStatus::ORIGINAL, true ),
				new TranslationItem( ContentType::POST, '12', 'tr', TranslationStatus::TRANSLATED, false ),
			)
		);
		$relations->seed_group(
			'group-term-0002',
			array(
				new TranslationItem( ContentType::TERM, '31', 'en', TranslationStatus::ORIGINAL, true ),
				new TranslationItem( ContentType::TERM, '32', 'tr', TranslationStatus::NEEDS_REVIEW, false ),
			)
		);

		return $relations;
	}

	/**
	 * Builds the source site's content fixture.
	 *
	 * @return FakeObjectLocatorGateway
	 */
	private function source_objects() {
		$objects = new FakeObjectLocatorGateway();
		$objects->add_post( 11, 'page', 'about', array( 'company' ) );
		$objects->add_post( 12, 'page', 'hakkimizda', array( 'sirket' ) );
		$objects->add_term( 31, 'category', 'news' );
		$objects->add_term( 32, 'category', 'haberler' );

		return $objects;
	}

	/**
	 * Builds the source site's language fixture.
	 *
	 * @return Language[]
	 */
	private function source_languages() {
		return array(
			new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, true ),
			new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ),
		);
	}

	/**
	 * Exports a package from the source fixture and reads it back.
	 *
	 * @param FakeRelationRepository|null   $relations Relation fixture.
	 * @param FakeObjectLocatorGateway|null $objects Content fixture.
	 * @param string                        $plugin_version Producing plugin version.
	 * @return TranslationPackage
	 */
	private function source_package( $relations = null, $objects = null, $plugin_version = '0.15.0' ) {
		$exporter = new PackageExporter(
			new FakeLanguageService( $this->source_languages() ),
			$relations instanceof FakeRelationRepository ? $relations : $this->source_relations(),
			$objects instanceof FakeObjectLocatorGateway ? $objects : $this->source_objects(),
			$plugin_version
		);

		return $this->reread( $exporter->export() );
	}

	/**
	 * Builds a package whose relation items carry a given status.
	 *
	 * @param string $status Status to write into the package.
	 * @return TranslationPackage
	 */
	private function package_with_status( $status ) {
		$exported = ( new PackageEncoder() )->encode( $this->source_package() );
		$data     = json_decode( $exported, true );

		$data['payload']['relations'][0]['items'][1]['status'] = $status;

		$parsed = ( new PackageParser() )->parse( (string) wp_json_encode( $data ) );

		$this->assertNotInstanceOf( \WP_Error::class, $parsed );

		return $parsed;
	}

	/**
	 * Encodes and reparses a package, so tests use the real reading path.
	 *
	 * @param TranslationPackage $package Package.
	 * @return TranslationPackage
	 */
	private function reread( TranslationPackage $package ) {
		$parsed = ( new PackageParser() )->parse( ( new PackageEncoder() )->encode( $package ) );

		if ( $parsed instanceof \WP_Error ) {
			$this->fail( 'Exported package was refused by the parser: ' . $parsed->get_error_code() );
		}

		return $parsed;
	}

	/**
	 * Gives the destination the same content as the source, but no relations.
	 *
	 * @return void
	 */
	private function give_destination_the_content() {
		$this->give_destination_the_languages();

		$this->destination_objects->add_post( 11, 'page', 'about', array( 'company' ) );
		$this->destination_objects->add_post( 12, 'page', 'hakkimizda', array( 'sirket' ) );
		$this->destination_objects->add_term( 31, 'category', 'news' );
		$this->destination_objects->add_term( 32, 'category', 'haberler' );
	}

	/**
	 * Gives the destination the source's languages.
	 *
	 * @return void
	 */
	private function give_destination_the_languages() {
		$this->destination_languages = $this->source_languages();
	}

	/**
	 * Makes the destination identical to the source site.
	 *
	 * @return void
	 */
	private function give_destination_the_source_site() {
		$this->give_destination_the_content();

		$this->destination_relations = $this->source_relations();
	}

	/**
	 * Builds a planner over the current destination fixture.
	 *
	 * @return ImportPlanner
	 */
	private function planner() {
		return new ImportPlanner(
			new PackageValidator( new RuntimeReadiness(), '0.15.0' ),
			new FakeLanguageService( $this->destination_languages ),
			$this->destination_relations,
			$this->destination_objects,
			new TranslationStatusTransitions()
		);
	}

	/**
	 * Plans a package against the current destination fixture.
	 *
	 * @param TranslationPackage $package Package.
	 * @return ImportPlan
	 */
	private function plan( TranslationPackage $package ) {
		return $this->planner()->plan( $package );
	}

	/**
	 * Returns the subjects of every operation of a type.
	 *
	 * @param ImportPlan $plan Plan.
	 * @param string     $type Operation type.
	 * @return string[]
	 */
	private function subjects( ImportPlan $plan, $type ) {
		$subjects = array();

		foreach ( $plan->operations_of_type( $type ) as $operation ) {
			$subjects[] = $operation->subject();
		}

		return $subjects;
	}

	/**
	 * Returns the link subjects the package wanted but the plan does not carry.
	 *
	 * @param ImportPlan $plan Plan.
	 * @return string[]
	 */
	private function missing_links( ImportPlan $plan ) {
		$planned = $this->subjects( $plan, PlannedOperation::LINK_ITEM );
		$wanted  = array( 'group-post-0001:tr', 'group-term-0002:tr' );

		return array_values( array_diff( $wanted, $planned ) );
	}

	/**
	 * Returns the single issue with a code, failing when it is absent.
	 *
	 * @param ImportPlan $plan Plan.
	 * @param string     $code Issue code.
	 * @return PlanIssue
	 */
	private function issue( ImportPlan $plan, $code ) {
		foreach ( $plan->issues() as $issue ) {
			if ( $issue->code() === $code ) {
				return $issue;
			}
		}

		$this->fail( 'No issue with code ' . $code . ' in plan: ' . (string) wp_json_encode( $plan->to_array()['issues'] ) );
	}

	/**
	 * Returns the single conflict with a code.
	 *
	 * @param ImportPlan $plan Plan.
	 * @param string     $code Issue code.
	 * @return PlanIssue
	 */
	private function conflict( ImportPlan $plan, $code ) {
		$issue = $this->issue( $plan, $code );

		$this->assertSame( PlanIssue::LEVEL_CONFLICT, $issue->level() );

		return $issue;
	}

	/**
	 * Returns a deterministic fingerprint of the destination state.
	 *
	 * @return string
	 */
	private function destination_fingerprint() {
		$state = array();

		foreach ( $this->destination_relations->active_group_keys( 100, 0 ) as $key ) {
			$rows = array();

			foreach ( $this->destination_relations->find_group( $key )->items() as $item ) {
				$rows[] = implode(
					'|',
					array( $item->content_type(), $item->object_key(), $item->language_code(), $item->status() )
				);
			}

			$state[ $key ] = $rows;
		}

		foreach ( $this->destination_languages as $language ) {
			$state[ 'lang:' . $language->code() ] = array( $language->locale(), $language->status(), $language->order() );
		}

		return md5( (string) wp_json_encode( $state ) );
	}
}
