<?php
/**
 * WP-CLI read contract integration tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Api\PublicApi;
use McLogiora\Cli\CliModule;
use McLogiora\Cli\CliProjection;
use McLogiora\Cli\LanguageCommand;
use McLogiora\Cli\RelationCommand;
use McLogiora\Cli\TranslationCommand;
use McLogiora\Core\Application;
use McLogiora\Core\Container;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Database\Installer;
use McLogiora\Database\TableNames;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Workflows\TranslationWorkflowService;
use ReflectionClass;
use WP_UnitTestCase;

/**
 * Covers the parts of the CLI slice a PHPUnit run can honestly prove.
 *
 * WP-CLI's classes are present here as a development dependency, but nothing
 * dispatches a command: there is no runner, no argument parser and no exit
 * status. Faking one would produce a suite that passes while the commands are
 * broken. So the split is deliberate: the argument parsing and the row
 * projection — the parts that decide what an operator may ask for and what
 * comes back — are exercised here against real WordPress data, and the command
 * dispatch, formatting and exit codes are qualified by running
 * `wp mclogiora …` against real installations of both WordPress builds.
 *
 * The registration test is the one that matters most here: it proves a web
 * request registers nothing, which is the property that would otherwise fail
 * silently on every site that never runs a command.
 */
final class CliReadContractTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Public read API.
	 *
	 * @var PublicApi
	 */
	private $api;

	/**
	 * Shared parsing and projection.
	 *
	 * @var CliProjection
	 */
	private $projection;

	/**
	 * Sets up an installed, three-language site.
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

		$languages = $this->container->get( LanguageRepositoryInterface::class );

		if ( ! $languages->find_by_code( 'en' ) instanceof Language ) {
			$languages->create( new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, false ) );
			$languages->set_default( 'en' );
		}

		if ( ! $languages->find_by_code( 'tr' ) instanceof Language ) {
			$languages->create( new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ) );
		}

		if ( ! $languages->find_by_code( 'de' ) instanceof Language ) {
			$languages->create( new Language( 'de', 'de_DE', 'Deutsch', 'German', 'ltr', LanguageStatus::INACTIVE, 2, false ) );
		}

		delete_option( RoutingSettings::OPTION_NAME );

		if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
			$this->set_permalink_structure( '/%postname%/' );
		}

		create_initial_taxonomies();

		$context = $this->container->get( LanguageContextInterface::class );
		$context->reset();
		$context->set_requested_code( '' );

		$this->api        = new PublicApi( $this->container );
		$this->projection = new CliProjection( $this->api );
	}

	/* --------------------------------------------------------------------
	 * Registration
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts a non-CLI request registers nothing and constructs nothing.
	 *
	 * This is the property that would otherwise break silently: every site
	 * that never runs a command still boots this module on every page load.
	 *
	 * @return void
	 */
	public function test_a_non_cli_request_registers_nothing() {
		$this->assertFalse(
			$this->container->get( RuntimeReadiness::class )->is_cli(),
			'The PHPUnit run is not a WP-CLI run, which is what makes this test meaningful.'
		);

		/*
		 * WP-CLI's classes are loaded in this harness as a development
		 * dependency, which is exactly what gives the assertion teeth: a
		 * missing guard would successfully register the commands here.
		 */
		$this->assertTrue( class_exists( '\WP_CLI', false ) );

		$module = new CliModule();

		$module->register( $this->container );

		$this->assertNotContains(
			CliModule::ROOT,
			array_keys( \WP_CLI::get_root_command()->get_subcommands() ),
			'A web request must register no mcLogiora command.'
		);
	}

	/**
	 * Asserts registering with an empty container is still safe.
	 *
	 * @return void
	 */
	public function test_registration_is_safe_without_services() {
		$module = new CliModule();

		$module->register( new Container() );

		$this->assertNotContains(
			CliModule::ROOT,
			array_keys( \WP_CLI::get_root_command()->get_subcommands() )
		);
	}

	/**
	 * Asserts the command classes need no WP-CLI class to exist.
	 *
	 * They deliberately do not extend `WP_CLI_Command`, so a site without
	 * WP-CLI can autoload them without fatalling.
	 *
	 * @return void
	 */
	public function test_command_classes_extend_no_wp_cli_class() {
		foreach ( array( LanguageCommand::class, RelationCommand::class, TranslationCommand::class ) as $class ) {
			$reflection = new \ReflectionClass( $class );

			$this->assertFalse( $reflection->getParentClass(), $class . ' must not extend a WP-CLI class.' );
			$this->assertInstanceOf( $class, new $class( $this->api ) );
		}
	}

	/**
	 * Asserts no command class can reach a repository or the database.
	 *
	 * @return void
	 */
	public function test_no_command_receives_a_repository() {
		/*
		 * The mutation commands take the workflow service as well, which is the
		 * point: writes go through the same application service REST calls. What
		 * must never appear is a repository or wpdb, so the check is on the
		 * whole constructor rather than a single expected type.
		 */
		$allowed = array( PublicApi::class, TranslationWorkflowService::class, '' );

		foreach ( array( LanguageCommand::class, RelationCommand::class, TranslationCommand::class ) as $class ) {
			$constructor = ( new ReflectionClass( $class ) )->getConstructor();

			$this->assertNotNull( $constructor );

			foreach ( $constructor->getParameters() as $parameter ) {
				$type = $parameter->getType();
				$name = $type instanceof \ReflectionNamedType ? $type->getName() : '';

				$this->assertContains( $name, $allowed, $class . ' must not receive a repository.' );
				$this->assertStringNotContainsString( 'Repository', $name );
				$this->assertNotSame( 'wpdb', $name );
			}
		}
	}

	/* --------------------------------------------------------------------
	 * Mutation command surface
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts the mutation commands exist and creation commands do not.
	 *
	 * Slice 3 owns creation; a `create` method appearing here early would be
	 * registered by WP-CLI the moment it was written.
	 *
	 * @return void
	 */
	public function test_the_command_surface_is_the_intended_one() {
		$relation    = $this->public_methods( RelationCommand::class );
		$translation = $this->public_methods( TranslationCommand::class );
		$language    = $this->public_methods( LanguageCommand::class );

		sort( $relation );
		sort( $translation );

		$this->assertSame( array( 'get', 'link', 'unlink' ), $relation );
		$this->assertSame( array( 'get', 'status' ), $translation );
		$this->assertSame( array( 'list_' ), $language );

		foreach ( array( 'create', 'create_translation', 'new', 'add', 'delete' ) as $absent ) {
			$this->assertNotContains( $absent, $relation, 'relation ' . $absent . ' belongs to a later slice.' );
			$this->assertNotContains( $absent, $translation, 'translation ' . $absent . ' belongs to a later slice.' );
		}
	}

	/**
	 * Asserts the mutation commands accept the workflow service.
	 *
	 * @return void
	 */
	public function test_the_mutation_commands_take_the_workflow_service() {
		foreach ( array( RelationCommand::class, TranslationCommand::class ) as $class ) {
			$parameters = ( new ReflectionClass( $class ) )->getConstructor()->getParameters();

			$this->assertCount( 2, $parameters, $class . ' takes the reader and the workflow service.' );
			$this->assertSame( 'workflows', $parameters[1]->getName() );
			$this->assertTrue( $parameters[1]->isOptional(), 'A site without workflows must still construct the command.' );
		}

		$this->assertCount(
			1,
			( new ReflectionClass( LanguageCommand::class ) )->getConstructor()->getParameters(),
			'The language command reads only; it needs no workflow service.'
		);
	}

	/**
	 * Asserts the status vocabulary is the canonical one, with no aliases.
	 *
	 * A status that exists on one transport and not another is how an operator
	 * learns two vocabularies for one concept.
	 *
	 * @return void
	 */
	public function test_the_status_vocabulary_is_canonical() {
		foreach ( TranslationStatus::all() as $status ) {
			$this->assertSame( $status, $this->projection->status( $status ) );
		}

		$this->assertNotContains( 'approved', TranslationStatus::all() );
		$this->assertNotContains( 'done', TranslationStatus::all() );
		$this->assertNotContains( 'complete', TranslationStatus::all() );
	}

	/**
	 * Asserts a workflow refusal keeps its domain code visible.
	 *
	 * The same refusal must stay identifiable whether it arrives from CLI,
	 * REST or an admin screen.
	 *
	 * @return void
	 */
	public function test_workflow_refusals_keep_their_domain_code() {
		$result = $this->container->get( TranslationWorkflowService::class )
			->change_status( ContentType::POST, 999999, 'tr', TranslationStatus::TRANSLATED );

		$this->assertWPError( $result );
		$this->assertSame( 'mclogiora_translation_item_not_found', $result->get_error_code() );
		$this->assertStringNotContainsString( 'SELECT', $result->get_error_message() );
		$this->assertStringNotContainsString( 'wpdb', $result->get_error_message() );
	}

	/**
	 * Asserts an unauthenticated run is refused by the workflow, not the CLI.
	 *
	 * Running `wp` without --user leaves no current user, and the refusal has
	 * to come from the domain rather than from a check the transport invented.
	 *
	 * @return void
	 */
	public function test_an_unauthenticated_run_is_refused_by_the_workflow() {
		$pair = $this->translated_post();

		wp_set_current_user( 0 );

		$workflows = $this->container->get( TranslationWorkflowService::class );

		foreach (
			array(
				$workflows->change_status( ContentType::POST, $pair['target'], 'tr', TranslationStatus::NEEDS_REVIEW ),
				$workflows->content()->unlink( $pair['target'], 'tr' ),
				$workflows->taxonomy()->unlink( 1, 'tr' ),
			) as $result
		) {
			$this->assertWPError( $result );
			$this->assertSame( 'mclogiora_cannot_manage_translations', $result->get_error_code() );
		}
	}

	/**
	 * Returns the public method names of a command class.
	 *
	 * @param string $class Class name.
	 * @return string[]
	 */
	private function public_methods( $class ) {
		$names = array();

		foreach ( ( new ReflectionClass( $class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( '__construct' === $method->getName() ) {
				continue;
			}

			$names[] = $method->getName();
		}

		return $names;
	}

	/* --------------------------------------------------------------------
	 * Field vocabulary
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts the published fields match the REST vocabulary exactly.
	 *
	 * An operator who has read one interface should not have to relearn
	 * `native_name` as `label` because a table looked nicer that way.
	 *
	 * @return void
	 */
	public function test_the_field_vocabulary_matches_the_rest_contract() {
		$this->assertSame(
			array( 'code', 'locale', 'tag', 'native_name', 'english_name', 'direction', 'is_active', 'is_default', 'order', 'home_url' ),
			CliProjection::LANGUAGE_FIELDS
		);

		$this->assertSame(
			array( 'language', 'object_id', 'object_type', 'status', 'is_source', 'url' ),
			CliProjection::ITEM_FIELDS
		);

		foreach ( array( 'source_hash', 'translated_source_hash', 'source_modified', 'translation_modified', 'object_key', 'group_id' ) as $internal ) {
			$this->assertNotContains( $internal, CliProjection::ITEM_FIELDS );
			$this->assertNotContains( $internal, CliProjection::LANGUAGE_FIELDS );
		}
	}

	/**
	 * Asserts a language row carries exactly the published fields.
	 *
	 * @return void
	 */
	public function test_a_language_row_is_the_published_projection() {
		$languages = $this->api->languages( array( 'status' => 'all' ) );
		$row       = $this->projection->language_row( $languages[0] );

		$this->assertSame( CliProjection::LANGUAGE_FIELDS, array_keys( $row ) );
		$this->assertSame( 'en', $row['code'] );
		$this->assertSame( 'yes', $row['is_default'] );
		$this->assertSame( 'yes', $row['is_active'] );
		$this->assertIsString( $row['home_url'] );

		foreach ( $row as $value ) {
			$this->assertTrue( is_scalar( $value ), 'Every CLI cell must be a scalar.' );
		}
	}

	/**
	 * Asserts the language reader honours the status filter.
	 *
	 * The CLI default is `all`, unlike REST: running `wp` is already more
	 * privileged than any role, and hiding configured-but-disabled languages
	 * from the operator administering them would be the wrong default.
	 *
	 * @return void
	 */
	public function test_the_reader_distinguishes_all_from_active() {
		$all    = wp_list_pluck( $this->api->languages( array( 'status' => 'all' ) ), 'code' );
		$active = wp_list_pluck( $this->api->languages( array( 'status' => 'active' ) ), 'code' );

		$this->assertSame( array( 'en', 'tr', 'de' ), $all );
		$this->assertSame( array( 'en', 'tr' ), $active );
	}

	/**
	 * Asserts a relation row carries exactly the published fields.
	 *
	 * @return void
	 */
	public function test_a_relation_row_is_the_published_projection() {
		$pair  = $this->translated_post();
		$group = $this->api->translation_group( $pair['source'], ContentType::POST );

		$row = $this->projection->item_row( $group['translations']['tr'], '' );

		$this->assertSame( CliProjection::ITEM_FIELDS, array_keys( $row ) );
		$this->assertSame( 'tr', $row['language'] );
		$this->assertSame( $pair['target'], $row['object_id'] );
		$this->assertSame( TranslationStatus::DRAFT, $row['status'] );
		$this->assertSame( 'no', $row['is_source'] );
		$this->assertStringContainsString( '/tr/', $row['url'] );

		$source_row = $this->projection->item_row( $group['source'], '' );

		$this->assertSame( 'yes', $source_row['is_source'] );
		$this->assertSame( TranslationStatus::ORIGINAL, $source_row['status'] );
	}

	/**
	 * Asserts a term row without a taxonomy reports no URL rather than failing.
	 *
	 * @return void
	 */
	public function test_a_term_row_without_a_taxonomy_reports_no_url() {
		$row = $this->projection->item_row(
			array(
				'object_id'   => 7,
				'object_type' => ContentType::TERM,
				'language'    => 'tr',
				'status'      => TranslationStatus::DRAFT,
				'is_source'   => false,
			),
			''
		);

		$this->assertSame( '', $row['url'] );
	}

	/* --------------------------------------------------------------------
	 * Argument parsing
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts a known object type is accepted and normalised.
	 *
	 * @return void
	 */
	public function test_known_object_types_are_accepted() {
		foreach ( ContentType::all() as $type ) {
			$this->assertSame( $type, $this->projection->object_type( $type ) );
		}
	}

	/**
	 * Asserts positive identifiers are accepted.
	 *
	 * @return void
	 */
	public function test_positive_identifiers_are_accepted() {
		$this->assertSame( 1, $this->projection->object_id( '1' ) );
		$this->assertSame( 42, $this->projection->object_id( 42 ) );
	}

	/**
	 * Asserts configured languages are accepted, including inactive ones.
	 *
	 * @return void
	 */
	public function test_configured_languages_are_accepted() {
		foreach ( array( 'en', 'tr', 'de' ) as $code ) {
			$this->assertSame( $code, $this->projection->language( $code ) );
		}
	}

	/**
	 * Asserts the field filter restricts to the published set.
	 *
	 * @return void
	 */
	public function test_the_field_filter_restricts_to_published_fields() {
		$this->assertSame(
			CliProjection::LANGUAGE_FIELDS,
			$this->projection->fields( array(), CliProjection::LANGUAGE_FIELDS ),
			'No --fields means every published field.'
		);

		$this->assertSame(
			array( 'code', 'is_default' ),
			$this->projection->fields( array( 'fields' => 'code, is_default' ), CliProjection::LANGUAGE_FIELDS )
		);

		$this->assertSame(
			array( 'code' ),
			$this->projection->fields( array( 'fields' => 'code,source_hash' ), CliProjection::LANGUAGE_FIELDS ),
			'An internal field name is dropped rather than printed.'
		);
	}

	/* --------------------------------------------------------------------
	 * Read-only
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts building every projection mutates nothing.
	 *
	 * @return void
	 */
	public function test_building_the_projections_mutates_nothing() {
		$pair   = $this->translated_post();
		$tables = $this->container->get( TableNames::class );

		$before = array(
			'rows'   => $this->row_counts( $tables ),
			'source' => get_post_field( 'post_modified_gmt', $pair['source'] ),
			'target' => get_post_field( 'post_modified_gmt', $pair['target'] ),
			'posts'  => $this->post_count(),
		);

		foreach ( $this->api->languages( array( 'status' => 'all' ) ) as $language ) {
			$this->projection->language_row( $language );
		}

		$group = $this->api->translation_group( $pair['source'], ContentType::POST );

		foreach ( $group['translations'] as $item ) {
			$this->projection->item_row( $item, '' );
		}

		$this->assertSame( $before['rows'], $this->row_counts( $tables ) );
		$this->assertSame( $before['posts'], $this->post_count() );
		$this->assertSame( $before['source'], get_post_field( 'post_modified_gmt', $pair['source'] ) );
		$this->assertSame( $before['target'], get_post_field( 'post_modified_gmt', $pair['target'] ) );
	}

	/**
	 * Asserts building the projections contacts nothing.
	 *
	 * @return void
	 */
	public function test_building_the_projections_makes_no_outbound_request() {
		$pair     = $this->translated_post();
		$requests = 0;

		$counter = static function ( $preempt, $args, $url ) use ( &$requests ) {
			unset( $args, $url );
			++$requests;

			return $preempt;
		};

		add_filter( 'pre_http_request', $counter, 10, 3 );

		foreach ( $this->api->languages( array( 'status' => 'all' ) ) as $language ) {
			$this->projection->language_row( $language );
		}

		$group = $this->api->translation_group( $pair['source'], ContentType::POST );

		foreach ( $group['translations'] as $item ) {
			$this->projection->item_row( $item, '' );
		}

		remove_filter( 'pre_http_request', $counter, 10 );

		$this->assertSame( 0, $requests );
	}

	/* --------------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Creates a published post with a Turkish translation.
	 *
	 * @return array{source:int,target:int}
	 */
	private function translated_post() {
		$source = self::factory()->post->create(
			array(
				'post_title'  => 'About us',
				'post_name'   => 'about-us-' . wp_rand( 1000, 999999 ),
				'post_status' => 'publish',
			)
		);

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $source, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		return array(
			'source' => (int) $source,
			'target' => (int) $created['post_id'],
		);
	}

	/**
	 * Returns the number of posts of every status.
	 *
	 * @return int
	 */
	private function post_count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counting rows is the assertion.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
	}

	/**
	 * Returns the row count of every mcLogiora table.
	 *
	 * @param TableNames $tables Table names.
	 * @return array<string,int>
	 */
	private function row_counts( TableNames $tables ) {
		global $wpdb;

		$counts = array();

		foreach ( $tables->all() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table names come from TableNames, and counting rows is the assertion.
			$counts[ $table ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $table ) . '`' );
		}

		return $counts;
	}
}
