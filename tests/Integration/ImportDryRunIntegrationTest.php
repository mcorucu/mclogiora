<?php
/**
 * Import dry-run integration tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Database\Installer;
use McLogiora\Database\TableNames;
use McLogiora\ImportExport\ImportPlan;
use McLogiora\ImportExport\ImportPlanner;
use McLogiora\ImportExport\PackageEncoder;
use McLogiora\ImportExport\PackageExporter;
use McLogiora\ImportExport\PackageParser;
use McLogiora\ImportExport\PlanIssue;
use McLogiora\ImportExport\PlannedOperation;
use McLogiora\ImportExport\TranslationPackage;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationRelationRepositoryInterface;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_UnitTestCase;

/**
 * Reads a real package against a real destination, and writes nothing.
 *
 * The pattern in every test is the same: build a translated site, export it,
 * then change the destination in one specific way and ask what an import would
 * do about it. That is the only honest way to qualify a planner, because the
 * cases that matter -- a slug that now matches two pages, a language slot
 * somebody else filled, a translation whose post was deleted -- are states
 * WordPress produces, not states a fixture array can describe.
 *
 * The zero-write proof runs over the whole site rather than the rows the test
 * happens to care about. A planner that quietly touched an unrelated row would
 * pass a narrower assertion.
 */
final class ImportDryRunIntegrationTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Fixture identifiers.
	 *
	 * @var array<string,int>
	 */
	private $fixture = array();

	/**
	 * Builds an installed, translated site.
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

		$this->build_fixture();
	}

	/* --------------------------------------------------------------------
	 * Same-site round trip
	 * ----------------------------------------------------------------- */

	/**
	 * A site reading its own package is asked to do nothing.
	 *
	 * @return void
	 */
	public function test_a_site_reading_its_own_package_plans_no_work() {
		$plan = $this->plan( $this->package() );

		$this->assertTrue( $plan->is_applicable() );
		$this->assertTrue( $plan->is_empty() );
		$this->assertSame( array(), $plan->errors() );
		$this->assertSame( array(), $plan->conflicts() );
		$this->assertSame( array(), $plan->unresolved() );
		$this->assertGreaterThan( 0, $plan->counts()['skip'] );
	}

	/**
	 * The exported payload describes the same relations the site holds.
	 *
	 * @return void
	 */
	public function test_the_package_describes_the_relations_the_site_holds() {
		$package    = $this->package();
		$repository = $this->container->get( TranslationRelationRepositoryInterface::class );

		$this->assertCount( 3, $package->relations() );

		foreach ( $package->relations() as $group ) {
			$live = $repository->find_group( $group->group_key() );

			$this->assertNotNull( $live, 'Group ' . $group->group_key() . ' must exist on the site.' );
			$this->assertCount( count( $live->items() ), $group->items() );
		}
	}

	/* --------------------------------------------------------------------
	 * Partial destination
	 * ----------------------------------------------------------------- */

	/**
	 * An unlinked translation is planned back into its group.
	 *
	 * @return void
	 */
	public function test_an_unlinked_translation_is_planned_as_a_link() {
		$package = $this->package();

		$this->assertTrue(
			$this->container->get( TranslationWorkflowService::class )
				->content()
				->unlink( $this->fixture['page_tr'], 'tr' )
		);

		$this->refresh_destination();

		$plan = $this->plan( $package );

		$this->assertTrue( $plan->is_applicable() );
		$this->assertCount( 1, $plan->operations_of_type( PlannedOperation::LINK_ITEM ) );

		$link = $plan->operations_of_type( PlannedOperation::LINK_ITEM )[0];

		$this->assertSame( $this->fixture['page_tr'], $link->detail()['object_id'] );
		$this->assertSame( 'tr', $link->detail()['language'] );
		$this->assertSame( 'post', $link->detail()['object_type'] );
	}

	/**
	 * A destination with no relations at all is planned from scratch.
	 *
	 * @return void
	 */
	public function test_a_destination_with_no_relations_is_planned_from_scratch() {
		$package = $this->package();

		$this->clear_relations();

		$plan = $this->plan( $package );

		$this->assertTrue( $plan->is_applicable() );
		$this->assertCount( 3, $plan->operations_of_type( PlannedOperation::CREATE_GROUP ) );
		$this->assertCount( 3, $plan->operations_of_type( PlannedOperation::LINK_ITEM ) );
		$this->assertSame( array(), $plan->conflicts() );
		$this->assertSame( array(), $plan->unresolved() );

		foreach ( $plan->operations_of_type( PlannedOperation::CREATE_GROUP ) as $operation ) {
			$this->assertGreaterThan( 0, $operation->detail()['object_id'] );
			$this->assertSame( 'original', $operation->detail()['status'] );
		}
	}

	/**
	 * A language the destination lost is planned as a creation.
	 *
	 * @return void
	 */
	public function test_a_missing_language_is_planned_as_a_creation() {
		$package = $this->package();

		$this->clear_relations();
		$this->assertTrue( $this->container->get( LanguageRepositoryInterface::class )->delete( 'tr' ) );

		$plan    = $this->plan( $package );
		$creates = $plan->operations_of_type( PlannedOperation::CREATE_LANGUAGE );

		$this->assertCount( 1, $creates );
		$this->assertSame( 'tr', $creates[0]->subject() );
		$this->assertSame( 'tr_TR', $creates[0]->detail()['locale'] );
		$this->assertTrue( $creates[0]->detail()['is_active'] );
	}

	/**
	 * A language whose metadata was edited here is a conflict, never a write.
	 *
	 * @return void
	 */
	public function test_a_locally_edited_language_is_a_conflict() {
		$package = $this->package();

		$this->container->get( LanguageRepositoryInterface::class )->update(
			new Language( 'tr', 'tr_TR', 'Turkish (local)', 'Turkish', 'ltr', LanguageStatus::INACTIVE, 7, false )
		);

		$conflict = $this->issue( $this->plan( $package ), 'language_differs' );

		$this->assertSame( PlanIssue::LEVEL_CONFLICT, $conflict->level() );
		$this->assertSame( 'tr', $conflict->context()['language'] );
		$this->assertArrayHasKey( 'native_name', $conflict->context()['differences'] );
		$this->assertSame( 'Turkce', $conflict->context()['differences']['native_name']['package'] );
		$this->assertSame( 'Turkish (local)', $conflict->context()['differences']['native_name']['site'] );
	}

	/* --------------------------------------------------------------------
	 * Conflicting destination
	 * ----------------------------------------------------------------- */

	/**
	 * A language slot somebody else filled is reported, not taken over.
	 *
	 * @return void
	 */
	public function test_an_occupied_language_slot_is_reported() {
		$package = $this->package();

		$workflows = $this->container->get( TranslationWorkflowService::class );

		$this->assertTrue( $workflows->content()->unlink( $this->fixture['page_tr'], 'tr' ) );

		$this->refresh_destination();

		$other = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Baska Sayfa',
				'post_name'   => 'baska-sayfa',
				'post_parent' => $this->fixture['parent_en'],
			)
		);

		$this->assertIsArray( $workflows->content()->link_existing( $this->fixture['page_en'], $other, 'tr' ) );

		$plan     = $this->plan( $package );
		$conflict = $this->issue( $plan, 'language_slot_occupied' );

		$this->assertSame( PlanIssue::LEVEL_CONFLICT, $conflict->level() );
		$this->assertSame( $other, $conflict->context()['site_object_id'] );
		$this->assertSame( $this->fixture['page_tr'], $conflict->context()['package_object_id'] );
		$this->assertSame( array(), $plan->operations_of_type( PlannedOperation::LINK_ITEM ) );
	}

	/**
	 * A translation whose status moved on here is reported, never rewritten.
	 *
	 * @return void
	 */
	public function test_a_status_that_moved_on_is_reported() {
		$package = $this->package();

		$this->assertInstanceOf(
			TranslationItem::class,
			$this->container->get( TranslationWorkflowService::class )
				->change_status( 'post', $this->fixture['page_tr'], 'tr', 'translated' )
		);

		$this->refresh_destination();

		$conflict = $this->issue( $this->plan( $package ), 'item_status_differs' );

		$this->assertSame( 'translated', $conflict->context()['site_status'] );
		$this->assertSame( 'needs_review', $conflict->context()['package_status'] );
		$this->assertSame( $this->fixture['page_tr'], $conflict->context()['object_id'] );
		$this->assertIsBool( $conflict->context()['transition_allowed'] );
	}

	/**
	 * An object already grouped elsewhere is never quietly moved.
	 *
	 * @return void
	 */
	public function test_an_object_grouped_elsewhere_is_reported() {
		$package = $this->package();

		$this->clear_relations();

		$workflows = $this->container->get( TranslationWorkflowService::class );
		$decoy     = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Decoy',
				'post_name'   => 'decoy',
				'post_parent' => $this->fixture['parent_en'],
			)
		);

		$this->assertIsArray( $workflows->content()->link_existing( $decoy, $this->fixture['page_tr'], 'tr' ) );

		$conflict = $this->issue( $this->plan( $package ), 'object_already_grouped' );

		$this->assertSame( PlanIssue::LEVEL_CONFLICT, $conflict->level() );
		$this->assertSame( $this->fixture['page_tr'], $conflict->context()['object_id'] );
		$this->assertSame( 'target', $conflict->context()['role'] );
	}

	/* --------------------------------------------------------------------
	 * Unresolvable references
	 * ----------------------------------------------------------------- */

	/**
	 * A deleted translation leaves an unresolved reference, not a guess.
	 *
	 * @return void
	 */
	public function test_a_deleted_object_is_reported_as_unresolved() {
		$package = $this->package();

		wp_delete_post( $this->fixture['page_tr'], true );

		$plan  = $this->plan( $package );
		$issue = $this->issue( $plan, 'locator_not_found' );

		$this->assertSame( PlanIssue::LEVEL_UNRESOLVED, $issue->level() );
		$this->assertSame( 'tr', $issue->context()['language'] );
		$this->assertSame( array(), $issue->context()['matches'] );
	}

	/**
	 * A slug that now names two pages is reported rather than chosen between.
	 *
	 * @return void
	 */
	public function test_an_ambiguous_locator_is_reported_and_never_resolved() {
		global $wpdb;

		$package = $this->package();

		$this->assertTrue(
			$this->container->get( TranslationWorkflowService::class )
				->content()
				->unlink( $this->fixture['page_tr'], 'tr' )
		);

		$this->refresh_destination();

		/*
		 * Inserted straight into the table on purpose. `wp_insert_post()` calls
		 * `wp_unique_post_slug()` and would suffix this away, but a duplicate
		 * slug is exactly the state a site reaches after a migration or a bad
		 * import, and it is the state the resolver has to notice rather than
		 * resolve. The fixture creates it; nothing in the plugin does.
		 */
		$original = get_post( $this->fixture['page_tr'] );

		$wpdb->insert( // phpcs:ignore WordPress.DB -- deliberate duplicate-slug fixture.
			$wpdb->posts,
			array(
				'post_author'  => (int) $original->post_author,
				'post_date'    => $original->post_date,
				'post_date_gmt' => $original->post_date_gmt,
				'post_content' => $original->post_content,
				'post_title'   => 'Hakkimizda (duplicate)',
				'post_status'  => 'publish',
				'post_name'    => $original->post_name,
				'post_parent'  => (int) $original->post_parent,
				'post_type'    => $original->post_type,
				'post_modified' => $original->post_modified,
				'post_modified_gmt' => $original->post_modified_gmt,
			)
		);

		$duplicate = (int) $wpdb->insert_id;

		clean_post_cache( $duplicate );

		$plan  = $this->plan( $package );
		$issue = $this->issue( $plan, 'locator_ambiguous' );

		$this->assertSame( PlanIssue::LEVEL_UNRESOLVED, $issue->level() );
		$this->assertSame(
			array( $this->fixture['page_tr'], $duplicate ),
			$issue->context()['matches']
		);
		$this->assertSame( array(), $plan->operations_of_type( PlannedOperation::LINK_ITEM ) );
	}

	/**
	 * A post type this site does not register is named as the reason.
	 *
	 * @return void
	 */
	public function test_an_unregistered_post_type_is_reported() {
		register_post_type( 'mclogiora_manual', array( 'public' => true, 'hierarchical' => false ) );

		$source = self::factory()->post->create(
			array(
				'post_type'   => 'mclogiora_manual',
				'post_status' => 'publish',
				'post_name'   => 'manual-en',
			)
		);
		$target = self::factory()->post->create(
			array(
				'post_type'   => 'mclogiora_manual',
				'post_status' => 'publish',
				'post_name'   => 'manual-tr',
			)
		);

		$this->assertIsArray(
			$this->container->get( TranslationWorkflowService::class )
				->content()
				->link_existing( $source, $target, 'tr' )
		);

		$package = $this->package();

		unregister_post_type( 'mclogiora_manual' );

		$issue = $this->issue( $this->plan( $package ), 'locator_type_unknown' );

		$this->assertSame( PlanIssue::LEVEL_UNRESOLVED, $issue->level() );
		$this->assertSame( 'source', $issue->context()['role'] );
	}

	/* --------------------------------------------------------------------
	 * Package compatibility
	 * ----------------------------------------------------------------- */

	/**
	 * A package from a future format version is refused before planning.
	 *
	 * @return void
	 */
	public function test_a_future_format_version_is_refused() {
		$data = json_decode( $this->encoded_package(), true );

		$data['manifest']['format_version'] = 2;

		$result = ( new PackageParser() )->parse( (string) wp_json_encode( $data ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_package_unsupported_version', $result->get_error_code() );
	}

	/**
	 * A different producing plugin version is a warning, never a refusal.
	 *
	 * @return void
	 */
	public function test_a_different_plugin_version_is_only_a_warning() {
		$data = json_decode( $this->encoded_package(), true );

		$data['manifest']['generator_version'] = '0.1.0';

		$plan = $this->plan( $this->parse( (string) wp_json_encode( $data ) ) );

		$this->assertTrue( $plan->is_applicable() );
		$this->assertSame( 'plugin_version_differs', $this->issue( $plan, 'plugin_version_differs' )->code() );
	}

	/**
	 * A status outside the vocabulary stops the package and plans nothing.
	 *
	 * @return void
	 */
	public function test_an_unknown_status_blocks_the_package() {
		$data = json_decode( $this->encoded_package(), true );

		$data['payload']['relations'][0]['items'][1]['status'] = 'signed-off';

		$plan = $this->plan( $this->parse( (string) wp_json_encode( $data ) ) );

		$this->assertFalse( $plan->is_applicable() );
		$this->assertSame( array(), $plan->operations() );
		$this->assertSame( 'unknown_status', $plan->errors()[0]->code() );
	}

	/* --------------------------------------------------------------------
	 * Zero writes
	 * ----------------------------------------------------------------- */

	/**
	 * Planning a package changes nothing about the destination.
	 *
	 * @return void
	 */
	public function test_planning_leaves_the_destination_byte_identical() {
		$package = $this->package();

		$this->assertTrue(
			$this->container->get( TranslationWorkflowService::class )
				->content()
				->unlink( $this->fixture['page_tr'], 'tr' )
		);

		$this->refresh_destination();

		$before = $this->site_snapshot();

		$this->plan( $package );
		$this->plan( $package );
		$this->plan( $package );

		$this->assertSame( $before, $this->site_snapshot() );
	}

	/**
	 * Planning a package against a conflicting site changes nothing either.
	 *
	 * @return void
	 */
	public function test_planning_a_conflicting_package_writes_nothing() {
		$package = $this->package();

		wp_delete_post( $this->fixture['post_tr'], true );

		$this->container->get( LanguageRepositoryInterface::class )->update(
			new Language( 'tr', 'tr_TR', 'Turkish (local)', 'Turkish', 'ltr', LanguageStatus::INACTIVE, 7, false )
		);

		$before = $this->site_snapshot();
		$plan   = $this->plan( $package );

		$this->assertNotSame( array(), $plan->conflicts() );
		$this->assertNotSame( array(), $plan->unresolved() );
		$this->assertSame( $before, $this->site_snapshot() );
	}

	/**
	 * Repeated dry runs produce exactly the same plan.
	 *
	 * @return void
	 */
	public function test_repeated_dry_runs_are_deterministic() {
		$package = $this->package();

		$this->assertTrue(
			$this->container->get( TranslationWorkflowService::class )
				->content()
				->unlink( $this->fixture['page_tr'], 'tr' )
		);

		$this->refresh_destination();

		$first  = $this->plan( $package )->to_array();
		$second = $this->plan( $package )->to_array();
		$third  = $this->plan( $this->parse( $this->encoded_package_for( $package ) ) )->to_array();

		$this->assertSame( $first, $second );
		$this->assertSame( $first, $third );
	}

	/**
	 * Parsing, validating and planning contact nothing.
	 *
	 * @return void
	 */
	public function test_reading_a_package_makes_no_outbound_request() {
		$encoded  = $this->encoded_package();
		$requests = 0;

		$counter = static function ( $preempt, $args, $url ) use ( &$requests ) {
			unset( $args, $url );
			++$requests;

			return $preempt;
		};

		add_filter( 'pre_http_request', $counter, 10, 3 );

		$this->plan( $this->parse( $encoded ) );

		remove_filter( 'pre_http_request', $counter, 10 );

		$this->assertSame( 0, $requests );
	}

	/* --------------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Creates the translated content the assertions read.
	 *
	 * @return void
	 */
	private function build_fixture() {
		$workflows = $this->container->get( TranslationWorkflowService::class );

		$this->fixture['parent_en'] = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Company',
				'post_name'   => 'company',
			)
		);

		$this->fixture['page_en'] = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'About',
				'post_name'   => 'about',
				'post_parent' => $this->fixture['parent_en'],
			)
		);

		$this->fixture['page_tr'] = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Hakkimizda',
				'post_name'   => 'hakkimizda',
				'post_parent' => $this->fixture['parent_en'],
			)
		);

		$this->fixture['post_en'] = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Hello',
				'post_name'   => 'hello',
			)
		);

		$this->fixture['post_tr'] = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Merhaba',
				'post_name'   => 'merhaba',
			)
		);

		$this->fixture['term_en'] = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'News',
				'slug'     => 'news',
			)
		);

		$this->fixture['term_tr'] = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Haberler',
				'slug'     => 'haberler',
			)
		);

		$this->assertIsArray( $workflows->content()->link_existing( $this->fixture['page_en'], $this->fixture['page_tr'], 'tr' ) );
		$this->assertIsArray( $workflows->content()->link_existing( $this->fixture['post_en'], $this->fixture['post_tr'], 'tr' ) );
		$this->assertIsArray( $workflows->taxonomy()->link_existing( $this->fixture['term_en'], 'category', $this->fixture['term_tr'], 'tr' ) );
	}

	/**
	 * Exports the current site and reads the bytes back.
	 *
	 * @return TranslationPackage
	 */
	private function package() {
		return $this->parse( $this->encoded_package() );
	}

	/**
	 * Exports the current site as JSON.
	 *
	 * @return string
	 */
	private function encoded_package() {
		return ( new PackageEncoder() )->encode( $this->container->get( PackageExporter::class )->export() );
	}

	/**
	 * Encodes an already-parsed package.
	 *
	 * @param TranslationPackage $package Package.
	 * @return string
	 */
	private function encoded_package_for( TranslationPackage $package ) {
		return ( new PackageEncoder() )->encode( $package );
	}

	/**
	 * Parses package bytes, failing the test when they are refused.
	 *
	 * @param string $json Package bytes.
	 * @return TranslationPackage
	 */
	private function parse( $json ) {
		$parsed = ( new PackageParser() )->parse( $json );

		if ( $parsed instanceof \WP_Error ) {
			$this->fail( 'Package refused: ' . $parsed->get_error_code() . ' -- ' . $parsed->get_error_message() );
		}

		return $parsed;
	}

	/**
	 * Plans a package against this site.
	 *
	 * @param TranslationPackage $package Package.
	 * @return ImportPlan
	 */
	private function plan( TranslationPackage $package ) {
		return $this->container->get( ImportPlanner::class )->plan( $package );
	}

	/**
	 * Returns the first issue with a code, failing when it is absent.
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
	 * Drops the in-request caches after a fixture changes the destination.
	 *
	 * A real site plans a package in its own request, with the object cache
	 * empty of anything the request before it read. A test that mutates the
	 * destination and then plans inside the same process does not get that for
	 * free, and the relation repository's cache decorator does not invalidate a
	 * cached group when an item is detached from it -- an in-request staleness
	 * that belongs to the relation layer and is recorded as an open item rather
	 * than fixed inside an import/export slice. Flushing here reproduces the
	 * request boundary instead of testing around it.
	 *
	 * @return void
	 */
	private function refresh_destination() {
		wp_cache_flush();
	}

	/**
	 * Removes every relation the fixture created.
	 *
	 * @return void
	 */
	private function clear_relations() {
		global $wpdb;

		$tables = $this->container->get( TableNames::class );

		/*
		 * DELETE rather than TRUNCATE. TRUNCATE causes an implicit commit in
		 * MySQL, which ends the transaction WP_UnitTestCase wraps every test
		 * in and leaks this fixture into every test that follows.
		 */
		$wpdb->query( 'DELETE FROM ' . $tables->translation_items() ); // phpcs:ignore WordPress.DB -- destination fixture.
		$wpdb->query( 'DELETE FROM ' . $tables->translation_groups() ); // phpcs:ignore WordPress.DB -- destination fixture.

		wp_cache_flush();
	}

	/**
	 * Returns a deterministic snapshot of everything a plan could disturb.
	 *
	 * @return array<string,string>
	 */
	private function site_snapshot() {
		global $wpdb;

		$tables   = $this->container->get( TableNames::class );
		$snapshot = array();

		foreach ( $tables->all() as $table ) {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . $table, ARRAY_A ); // phpcs:ignore WordPress.DB -- integration snapshot.

			$snapshot[ $table ] = md5( (string) wp_json_encode( is_array( $rows ) ? $rows : array() ) );
		}

		$snapshot['posts'] = md5(
			(string) wp_json_encode(
				$wpdb->get_results( "SELECT ID, post_type, post_status, post_name, post_parent, post_title, post_modified_gmt FROM {$wpdb->posts} ORDER BY ID", ARRAY_A ) // phpcs:ignore WordPress.DB -- integration snapshot.
			)
		);
		$snapshot['terms'] = md5(
			(string) wp_json_encode(
				$wpdb->get_results( "SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.parent, tt.count FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id ORDER BY t.term_id", ARRAY_A ) // phpcs:ignore WordPress.DB -- integration snapshot.
			)
		);
		$snapshot['options'] = md5(
			(string) wp_json_encode(
				$wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'mclogiora%' ORDER BY option_name", ARRAY_A ) // phpcs:ignore WordPress.DB -- integration snapshot.
			)
		);
		$snapshot['postmeta'] = md5(
			(string) wp_json_encode(
				$wpdb->get_results( "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} ORDER BY meta_id", ARRAY_A ) // phpcs:ignore WordPress.DB -- integration snapshot.
			)
		);

		return $snapshot;
	}
}
