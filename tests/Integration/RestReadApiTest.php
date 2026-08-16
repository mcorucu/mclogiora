<?php
/**
 * Read-only REST API integration tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Api\Rest\RestApiModule;
use McLogiora\Api\Rest\RestErrors;
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
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Exercises the REST routes through a real WP_REST_Server.
 *
 * Calling controller methods directly would prove the handlers work and nothing
 * else. What matters about a REST surface is what the server does around it:
 * whether a route is registered at all, whether the permission callback runs
 * before the handler, whether declared args reject bad input before any lookup,
 * and what status code reaches the client. Every request below goes through
 * dispatch.
 */
final class RestReadApiTest extends WP_UnitTestCase {
	const NS = '/mclogiora/v1';

	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Administrator user identifier.
	 *
	 * @var int
	 */
	private $administrator;

	/**
	 * Subscriber user identifier.
	 *
	 * @var int
	 */
	private $subscriber;

	/**
	 * Sets up an installed, three-language site with routes registered.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber    = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $this->administrator );

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

		global $wp_rest_server;

		/*
		 * No module is constructed here. RestApiModule is registered by
		 * Application::boot() when the plugin loads, so firing rest_api_init
		 * exercises the registration a real request would, rather than a
		 * second copy wired up by the test.
		 */
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		do_action( 'rest_api_init', $this->server );
	}

	/**
	 * Clears the REST server.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;

		parent::tear_down();
	}

	/* --------------------------------------------------------------------
	 * Registration
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts the three planned routes exist under the versioned namespace.
	 *
	 * @return void
	 */
	public function test_the_planned_routes_are_registered() {
		$routes = $this->server->get_routes();

		foreach ( array( '/languages', '/translations', '/relations' ) as $route ) {
			$this->assertArrayHasKey( self::NS . $route, $routes, $route . ' must be registered.' );
		}

		$this->assertSame( 'mclogiora/v1', RestApiModule::NAMESPACE_V1 );
	}

	/**
	 * Asserts every mcLogiora route is GET-only and carries a permission check.
	 *
	 * This is the slice boundary made mechanical: a write handler added by
	 * accident fails here rather than in review.
	 *
	 * @return void
	 */
	public function test_every_route_is_read_only_with_an_explicit_permission_callback() {
		$checked = 0;

		foreach ( $this->server->get_routes() as $path => $handlers ) {
			/*
			 * The bare namespace path is the index route WordPress registers
			 * for every namespace. It is core's, not mcLogiora's.
			 */
			if ( 0 !== strpos( $path, self::NS . '/' ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				if ( ! isset( $handler['methods'] ) ) {
					continue;
				}

				++$checked;

				foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE' ) as $write ) {
					$this->assertArrayNotHasKey(
						$write,
						$handler['methods'],
						$path . ' must not expose ' . $write . ' in this slice.'
					);
				}

				$this->assertArrayHasKey( 'GET', $handler['methods'] );

				$this->assertTrue(
					isset( $handler['permission_callback'] ) && is_callable( $handler['permission_callback'] ),
					$path . ' must declare a callable permission_callback.'
				);
			}
		}

		$this->assertGreaterThanOrEqual( 3, $checked );
	}

	/**
	 * Asserts a write request to a registered route is refused by the server.
	 *
	 * @return void
	 */
	public function test_write_methods_are_not_served() {
		foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE' ) as $method ) {
			$response = $this->server->dispatch( new WP_REST_Request( $method, self::NS . '/languages' ) );

			$this->assertSame( 404, $response->get_status(), $method . ' must not be served.' );
		}
	}

	/* --------------------------------------------------------------------
	 * /languages
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts the active languages are readable anonymously.
	 *
	 * @return void
	 */
	public function test_languages_are_readable_anonymously() {
		wp_set_current_user( 0 );

		$response = $this->get( '/languages' );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertCount( 2, $data, 'Only active languages are returned by default.' );
		$this->assertSame( array( 'en', 'tr' ), wp_list_pluck( $data, 'code' ) );
	}

	/**
	 * Asserts the language resource is scalars only, with no internal fields.
	 *
	 * @return void
	 */
	public function test_language_resource_is_a_stable_scalar_shape() {
		$data = $this->get( '/languages' )->get_data();

		$this->assertSame(
			array( 'code', 'locale', 'tag', 'native_name', 'english_name', 'direction', 'is_active', 'is_default', 'order', 'home_url' ),
			array_keys( $data[0] )
		);

		$this->assertSame( 'en', $data[0]['code'] );
		$this->assertSame( 'en-US', $data[0]['tag'] );
		$this->assertTrue( $data[0]['is_default'] );
		$this->assertIsInt( $data[0]['order'] );
		$this->assertIsString( $data[0]['home_url'] );

		foreach ( $data as $language ) {
			foreach ( $language as $value ) {
				$this->assertTrue(
					is_scalar( $value ) || null === $value,
					'Every language field must be a scalar or null.'
				);
			}
		}
	}

	/**
	 * Asserts inactive languages need permission.
	 *
	 * An inactive language is unpublished configuration: nothing on the front
	 * end reveals it, so neither does an anonymous request.
	 *
	 * @return void
	 */
	public function test_inactive_languages_require_permission() {
		wp_set_current_user( 0 );

		$response = $this->get( '/languages', array( 'status' => 'all' ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( RestErrors::FORBIDDEN, $response->get_data()['code'] );

		wp_set_current_user( $this->subscriber );

		$this->assertSame( 403, $this->get( '/languages', array( 'status' => 'all' ) )->get_status() );

		wp_set_current_user( $this->administrator );

		$response = $this->get( '/languages', array( 'status' => 'all' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'en', 'tr', 'de' ), wp_list_pluck( $response->get_data(), 'code' ) );
	}

	/**
	 * Asserts an unknown status value is rejected by the declared enum.
	 *
	 * @return void
	 */
	public function test_an_unknown_status_argument_is_rejected() {
		$response = $this->get( '/languages', array( 'status' => 'everything' ) );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Asserts an unconfigured site returns an empty collection, not an error.
	 *
	 * The route is re-registered over an empty container rather than by
	 * emptying the database, because the database cannot be emptied: the
	 * repository refuses to delete the default language. An empty container is
	 * the state a real site is in before the plugin boots or when environment
	 * validation fails, and the route must answer it rather than fatal.
	 *
	 * @return void
	 */
	public function test_an_unconfigured_site_returns_no_languages() {
		global $wp_rest_server;

		/*
		 * The booted module is still attached to rest_api_init and would
		 * register the configured controller over the top of the empty one.
		 * WP_UnitTestCase backs up and restores the hook registry per test, so
		 * clearing it here does not leak.
		 */
		remove_all_actions( 'rest_api_init' );

		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		$empty = new RestApiModule();
		$empty->register( new Container() );

		do_action( 'rest_api_init', $this->server );

		$response = $this->get( '/languages' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
	}

	/* --------------------------------------------------------------------
	 * /relations and /translations
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts a relation reads back as the stable group projection.
	 *
	 * @return void
	 */
	public function test_relation_returns_the_group_projection() {
		$pair = $this->translated_post();

		$response = $this->get(
			'/relations',
			array(
				'object_type' => ContentType::POST,
				'object_id'   => $pair['source'],
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( array( 'group_key', 'object_type', 'source', 'translations' ), array_keys( $data ) );
		$this->assertSame( ContentType::POST, $data['object_type'] );
		$this->assertSame( $pair['source'], $data['source']['object_id'] );
		$this->assertTrue( $data['source']['is_source'] );
		$this->assertSame( TranslationStatus::ORIGINAL, $data['source']['status'] );
		$this->assertSame( array( 'en', 'tr' ), array_keys( $data['translations'] ) );
		$this->assertSame( $pair['target'], $data['translations']['tr']['object_id'] );
		$this->assertStringContainsString( '/tr/', (string) $data['translations']['tr']['url'] );
	}

	/**
	 * Asserts the change detector's working state is never serialized.
	 *
	 * @return void
	 */
	public function test_relation_response_excludes_internal_fields() {
		$pair = $this->translated_post();

		$response = $this->get(
			'/relations',
			array(
				'object_type' => ContentType::POST,
				'object_id'   => $pair['source'],
			)
		);

		$items = array_merge( array( $response->get_data()['source'] ), array_values( $response->get_data()['translations'] ) );

		foreach ( $items as $item ) {
			$this->assertSame(
				array( 'object_id', 'object_type', 'language', 'status', 'is_source', 'url' ),
				array_keys( $item )
			);
		}

		$encoded = (string) wp_json_encode( $response->get_data() );

		foreach ( array( 'source_hash', 'translated_source_hash', 'source_modified', 'translation_modified', 'object_key', 'group_id' ) as $internal ) {
			$this->assertStringNotContainsString( $internal, $encoded );
		}
	}

	/**
	 * Asserts a single translation resolves with its source alongside it.
	 *
	 * @return void
	 */
	public function test_translation_resolves_one_language() {
		$pair = $this->translated_post();

		$response = $this->get(
			'/translations',
			array(
				'object_type' => ContentType::POST,
				'object_id'   => $pair['source'],
				'language'    => 'tr',
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( array( 'object_type', 'object_id', 'language', 'source', 'translation' ), array_keys( $data ) );
		$this->assertSame( $pair['source'], $data['object_id'] );
		$this->assertSame( 'tr', $data['language'] );
		$this->assertSame( $pair['target'], $data['translation']['object_id'] );
		$this->assertSame( 'en', $data['source']['language'] );
	}

	/**
	 * Asserts an untranslated language is a 404, not an empty success.
	 *
	 * @return void
	 */
	public function test_a_missing_translation_is_a_404() {
		$pair = $this->translated_post();

		$response = $this->get(
			'/translations',
			array(
				'object_type' => ContentType::POST,
				'object_id'   => $pair['source'],
				'language'    => 'de',
			)
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( RestErrors::TRANSLATION_MISSING, $response->get_data()['code'] );
	}

	/**
	 * Asserts an object outside any group is a 404.
	 *
	 * @return void
	 */
	public function test_an_object_without_a_relation_is_a_404() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$response = $this->get(
			'/relations',
			array(
				'object_type' => ContentType::POST,
				'object_id'   => $post_id,
			)
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( RestErrors::RELATION_NOT_FOUND, $response->get_data()['code'] );
	}

	/**
	 * Asserts an unconfigured language is rejected before any lookup.
	 *
	 * @return void
	 */
	public function test_an_unconfigured_language_is_rejected() {
		$pair = $this->translated_post();

		$response = $this->get(
			'/translations',
			array(
				'object_type' => ContentType::POST,
				'object_id'   => $pair['source'],
				'language'    => 'zz',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( RestErrors::UNKNOWN_LANGUAGE, $response->get_data()['code'] );
	}

	/**
	 * Asserts object types are allow-listed rather than pattern-matched.
	 *
	 * `ContentType::is_valid()` accepts any lowercase token, so a route relying
	 * on it would happily look up an invented type.
	 *
	 * @return void
	 */
	public function test_object_type_is_allow_listed() {
		foreach ( array( 'wp_options', 'users', 'anything', '' ) as $type ) {
			$response = $this->get(
				'/relations',
				array(
					'object_type' => $type,
					'object_id'   => 1,
				)
			);

			$this->assertSame( 400, $response->get_status(), $type . ' must be rejected.' );
		}
	}

	/**
	 * Asserts malformed identifiers are rejected rather than cast.
	 *
	 * @return void
	 */
	public function test_malformed_object_ids_are_rejected() {
		foreach ( array( '0', '-4', 'nonsense', '1.5', '' ) as $id ) {
			$response = $this->get(
				'/relations',
				array(
					'object_type' => ContentType::POST,
					'object_id'   => $id,
				)
			);

			$this->assertSame( 400, $response->get_status(), var_export( $id, true ) . ' must be rejected.' );
		}
	}

	/**
	 * Asserts a missing required argument is rejected.
	 *
	 * @return void
	 */
	public function test_missing_required_arguments_are_rejected() {
		$this->assertSame( 400, $this->get( '/relations' )->get_status() );
		$this->assertSame(
			400,
			$this->get( '/translations', array( 'object_type' => ContentType::POST, 'object_id' => 1 ) )->get_status()
		);
	}

	/* --------------------------------------------------------------------
	 * Permission matrix and leakage
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts relation routes are closed to anonymous and subscriber callers.
	 *
	 * @return void
	 */
	public function test_relation_routes_require_permission() {
		$pair = $this->translated_post();
		$args = array(
			'object_type' => ContentType::POST,
			'object_id'   => $pair['source'],
		);

		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->get( '/relations', $args )->get_status() );
		$this->assertSame( 401, $this->get( '/translations', array_merge( $args, array( 'language' => 'tr' ) ) )->get_status() );

		wp_set_current_user( $this->subscriber );

		$this->assertSame( 403, $this->get( '/relations', $args )->get_status() );
		$this->assertSame( 403, $this->get( '/translations', array_merge( $args, array( 'language' => 'tr' ) ) )->get_status() );

		wp_set_current_user( $this->administrator );

		$this->assertSame( 200, $this->get( '/relations', $args )->get_status() );
	}

	/**
	 * Asserts a draft translation's identifier never reaches an unauthorised caller.
	 *
	 * A relation record names object IDs whatever state those objects are in.
	 * This is the assertion that stops REST announcing that a private page
	 * exists and handing over its translation's ID.
	 *
	 * @return void
	 */
	public function test_a_private_translation_is_not_leaked_to_unauthorised_callers() {
		$pair = $this->translated_post( 'private' );

		$args = array(
			'object_type' => ContentType::POST,
			'object_id'   => $pair['source'],
		);

		foreach ( array( 0, $this->subscriber ) as $user ) {
			wp_set_current_user( $user );

			foreach ( array( '/relations', '/translations' ) as $route ) {
				$request = array_merge( $args, '/translations' === $route ? array( 'language' => 'tr' ) : array() );
				$body    = (string) wp_json_encode( $this->get( $route, $request )->get_data() );

				$this->assertStringNotContainsString( (string) $pair['target'], $body, 'A hidden translation ID must never be serialized.' );
				$this->assertStringNotContainsString( 'hidden-translation', $body );
			}
		}

		wp_set_current_user( $this->administrator );

		$this->assertSame( $pair['target'], $this->get( '/relations', $args )->get_data()['translations']['tr']['object_id'] );
	}

	/**
	 * Asserts the permission callback runs before the handler.
	 *
	 * An unauthorised caller must not be able to probe which objects exist by
	 * comparing a 403 against a 404.
	 *
	 * @return void
	 */
	public function test_permission_is_checked_before_the_lookup() {
		wp_set_current_user( $this->subscriber );

		$response = $this->get(
			'/relations',
			array(
				'object_type' => ContentType::POST,
				'object_id'   => 99999999,
			)
		);

		$this->assertSame( 403, $response->get_status(), 'Permission is refused without revealing whether the object exists.' );
	}

	/* --------------------------------------------------------------------
	 * Read-only in practice
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts no REST read changes stored domain state.
	 *
	 * @return void
	 */
	public function test_reads_do_not_mutate_domain_state() {
		global $wpdb;

		$pair   = $this->translated_post();
		$tables = $this->container->get( TableNames::class );
		$before = $this->row_counts( $tables );

		$modified = get_post_field( 'post_modified_gmt', $pair['target'] );
		$revisions = count( wp_get_post_revisions( $pair['source'] ) );

		$this->get( '/languages' );
		$this->get( '/languages', array( 'status' => 'all' ) );
		$this->get( '/relations', array( 'object_type' => ContentType::POST, 'object_id' => $pair['source'] ) );
		$this->get( '/translations', array( 'object_type' => ContentType::POST, 'object_id' => $pair['source'], 'language' => 'tr' ) );

		$this->assertSame( $before, $this->row_counts( $tables ), 'A GET must not add or remove any mcLogiora row.' );
		$this->assertSame( $modified, get_post_field( 'post_modified_gmt', $pair['target'] ), 'A GET must not touch a post.' );
		$this->assertSame( $revisions, count( wp_get_post_revisions( $pair['source'] ) ), 'A GET must not create a revision.' );

		unset( $wpdb );
	}

	/**
	 * Asserts a REST read contacts no translation provider.
	 *
	 * There is no reason for a developer reading relations to reach OpenAI,
	 * Anthropic, Gemini or DeepL, so the assertion is zero requests, not few.
	 *
	 * @return void
	 */
	public function test_reads_make_no_outbound_http_request() {
		$pair     = $this->translated_post();
		$requests = 0;

		$counter = static function ( $preempt, $args, $url ) use ( &$requests ) {
			unset( $args, $url );
			++$requests;

			return $preempt;
		};

		add_filter( 'pre_http_request', $counter, 10, 3 );

		$this->get( '/languages' );
		$this->get( '/relations', array( 'object_type' => ContentType::POST, 'object_id' => $pair['source'] ) );
		$this->get( '/translations', array( 'object_type' => ContentType::POST, 'object_id' => $pair['source'], 'language' => 'tr' ) );

		remove_filter( 'pre_http_request', $counter, 10 );

		$this->assertSame( 0, $requests );
	}

	/**
	 * Asserts no response body carries anything credential-shaped.
	 *
	 * @return void
	 */
	public function test_no_response_contains_secret_material() {
		$pair = $this->translated_post();

		$bodies = array(
			(string) wp_json_encode( $this->get( '/languages', array( 'status' => 'all' ) )->get_data() ),
			(string) wp_json_encode( $this->get( '/relations', array( 'object_type' => ContentType::POST, 'object_id' => $pair['source'] ) )->get_data() ),
			(string) wp_json_encode( $this->get( '/translations', array( 'object_type' => ContentType::POST, 'object_id' => $pair['source'], 'language' => 'tr' ) )->get_data() ),
		);

		foreach ( $bodies as $body ) {
			foreach ( array( 'api_key', 'credential', 'Authorization', 'DeepL-Auth-Key', 'preview_token', 'secret', 'wpdb', 'McLogiora\\' ) as $needle ) {
				$this->assertStringNotContainsString( $needle, $body );
			}
		}
	}

	/* --------------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Dispatches a GET request and returns the response.
	 *
	 * @param string              $route Route below the namespace.
	 * @param array<string,mixed> $params Query parameters.
	 * @return \WP_REST_Response
	 */
	private function get( $route, array $params = array() ) {
		$request = new WP_REST_Request( 'GET', self::NS . $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $this->server->dispatch( $request );
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

	/**
	 * Creates an English post with a Turkish translation.
	 *
	 * @param string $target_status Post status for the translation.
	 * @return array{source:int,target:int}
	 */
	private function translated_post( $target_status = 'publish' ) {
		$current = get_current_user_id();

		wp_set_current_user( $this->administrator );

		$source = self::factory()->post->create(
			array(
				'post_title'  => 'About us',
				'post_name'   => 'about-us-' . wp_rand( 1000, 99999 ),
				'post_status' => 'publish',
			)
		);

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $source, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		wp_update_post(
			array(
				'ID'          => $created['post_id'],
				'post_name'   => 'publish' === $target_status ? 'hakkimizda-' . wp_rand( 1000, 99999 ) : 'hidden-translation',
				'post_status' => $target_status,
			)
		);

		wp_set_current_user( $current );

		return array(
			'source' => (int) $source,
			'target' => (int) $created['post_id'],
		);
	}
}
