<?php
/**
 * REST content creation integration tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Api\Rest\RestErrors;
use McLogiora\Core\Application;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Database\Installer;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Routing\RoutingSettings;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Qualifies the one REST route that brings a WordPress object into existence.
 *
 * Every earlier slice could be described by what it does not touch. This one
 * cannot, so the assertions are about exactness rather than absence: precisely
 * one post, of precisely the source's type, in precisely draft status, with
 * precisely the fields the workflow copies.
 *
 * Two properties get the most attention because they are the ones that hurt
 * when wrong. Nothing is ever published — a translation nobody has read must
 * not become live because a REST client asked for one. And nothing duplicates:
 * a client that retries, or a double-submitted form, must not litter the site
 * with half-finished drafts.
 */
final class RestContentCreationTest extends WP_UnitTestCase {
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
	 * Editor user identifier.
	 *
	 * @var int
	 */
	private $editor;

	/**
	 * Sets up an installed, three-language site with routes registered.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->editor        = self::factory()->user->create( array( 'role' => 'editor' ) );

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
			$languages->create( new Language( 'de', 'de_DE', 'Deutsch', 'German', 'ltr', LanguageStatus::ACTIVE, 2, false ) );
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
	 * Asserts creation is POST and the status change is PUT/PATCH.
	 *
	 * POST on a collection creates. Registering both meanings on one verb
	 * would leave the handler deciding which the caller meant by inspecting
	 * which parameters arrived.
	 *
	 * @return void
	 */
	public function test_creation_is_post_and_the_status_change_is_not() {
		$verbs = array();

		foreach ( $this->server->get_routes()[ self::NS . '/translations' ] as $handler ) {
			if ( ! isset( $handler['methods'] ) ) {
				continue;
			}

			$this->assertTrue( is_callable( $handler['permission_callback'] ) );

			foreach ( array_keys( $handler['methods'] ) as $verb ) {
				$verbs[ $verb ] = $handler;
			}
		}

		$this->assertSame( array( 'GET', 'POST', 'PUT', 'PATCH' ), array_keys( $verbs ) );
		$this->assertArrayNotHasKey( 'DELETE', $verbs );

		$this->assertArrayHasKey( 'source_id', $verbs['POST']['args'] );
		$this->assertArrayNotHasKey( 'status', $verbs['POST']['args'], 'Creation does not take a status.' );

		$this->assertArrayHasKey( 'status', $verbs['PATCH']['args'] );
		$this->assertArrayNotHasKey( 'source_id', $verbs['PATCH']['args'] );
	}

	/**
	 * Asserts every creation argument really validates, and no post field leaks in.
	 *
	 * @return void
	 */
	public function test_creation_arguments_are_validated_and_minimal() {
		$args = $this->create_handler_args();

		$this->assertSame( array( 'object_type', 'source_id', 'language' ), array_keys( $args ) );

		foreach ( $args as $name => $arg ) {
			$this->assertTrue( $arg['required'], $name . ' must be required.' );
			$this->assertTrue( is_callable( $arg['validate_callback'] ), $name . ' must really validate.' );
		}

		$this->assertSame( array( ContentType::POST ), $args['object_type']['enum'] );

		foreach (
			array(
				'post_title',
				'post_content',
				'post_excerpt',
				'post_status',
				'post_author',
				'post_parent',
				'post_name',
				'meta_input',
				'tax_input',
				'featured_media',
			) as $forbidden
		) {
			$this->assertArrayNotHasKey( $forbidden, $args, 'This route is not a wp_insert_post proxy.' );
		}
	}

	/* --------------------------------------------------------------------
	 * The happy path
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts a creation makes exactly one post of the source's type.
	 *
	 * @return void
	 */
	public function test_creation_makes_exactly_one_post_of_the_source_type() {
		$source = $this->source_page();
		$before = $this->post_count();

		$response = $this->create( $source, 'tr' );

		$this->assertSame( 200, $response->get_status(), $this->message( $response ) );

		$created = $response->get_data()['translations']['tr']['object_id'];

		$this->assertSame( $before + 1, $this->post_count(), 'Exactly one post must be created.' );
		$this->assertSame( 'page', get_post_type( $created ), 'The translation keeps the source post type.' );
		$this->assertNotSame( $source, $created );
	}

	/**
	 * Asserts the created draft carries the workflow's documented defaults.
	 *
	 * @return void
	 */
	public function test_the_created_draft_carries_the_workflow_defaults() {
		$source = $this->source_page();
		$post   = get_post( $source );

		$created = get_post( $this->create( $source, 'tr' )->get_data()['translations']['tr']['object_id'] );

		$this->assertSame( 'draft', $created->post_status );
		$this->assertSame( $post->post_title, $created->post_title );
		$this->assertSame( $post->post_content, $created->post_content );
		$this->assertSame( $post->post_excerpt, $created->post_excerpt );
		$this->assertSame( (int) $post->post_author, (int) $created->post_author );
		$this->assertSame( (int) $post->menu_order, (int) $created->menu_order );
		$this->assertSame( 0, (int) $created->post_parent, 'No parent is copied.' );
	}

	/**
	 * Asserts a REST creation never publishes.
	 *
	 * A translation nobody has read must not become live because a client
	 * asked for one. This is the hard gate.
	 *
	 * @return void
	 */
	public function test_creation_never_publishes() {
		foreach ( array( 'tr', 'de' ) as $language ) {
			$source  = $this->source_page();
			$created = $this->create( $source, $language )->get_data()['translations'][ $language ]['object_id'];

			$this->assertSame( 'draft', get_post_status( $created ) );
			$this->assertNotSame( 'publish', get_post_status( $created ) );
			$this->assertEmpty( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'include' => array( $created ) ) ) );
		}
	}

	/**
	 * Asserts the relation records the new draft correctly.
	 *
	 * @return void
	 */
	public function test_the_relation_records_the_new_draft() {
		$source = $this->source_page();

		$data = $this->create( $source, 'tr' )->get_data();

		$this->assertSame( array( 'group_key', 'object_type', 'source', 'translations' ), array_keys( $data ) );
		$this->assertSame( ContentType::POST, $data['object_type'] );
		$this->assertSame( $source, $data['source']['object_id'] );
		$this->assertSame( TranslationStatus::ORIGINAL, $data['source']['status'] );
		$this->assertTrue( $data['source']['is_source'] );

		$this->assertSame( array( 'en', 'tr' ), array_keys( $data['translations'] ) );
		$this->assertSame( TranslationStatus::DRAFT, $data['translations']['tr']['status'] );
		$this->assertFalse( $data['translations']['tr']['is_source'] );
	}

	/**
	 * Asserts creating one language leaves the others free.
	 *
	 * @return void
	 */
	public function test_creating_one_language_leaves_the_others_free() {
		$source = $this->source_page();

		$this->create( $source, 'tr' );

		$data = $this->create( $source, 'de' )->get_data();
		$codes = array_keys( $data['translations'] );

		sort( $codes );

		/*
		 * Sorted, because the group is keyed by language and the repository
		 * decides the order. Asserting insertion order would be asserting
		 * something the contract does not promise.
		 */
		$this->assertSame( array( 'de', 'en', 'tr' ), $codes );
		$this->assertNotSame(
			$data['translations']['tr']['object_id'],
			$data['translations']['de']['object_id']
		);
	}

	/**
	 * Asserts the source post is untouched by a creation.
	 *
	 * @return void
	 */
	public function test_the_source_post_is_untouched() {
		$source = $this->source_page();

		$before    = $this->post_fingerprint( $source );
		$revisions = count( wp_get_post_revisions( $source ) );

		$this->create( $source, 'tr' );

		$this->assertSame( $before, $this->post_fingerprint( $source ), 'The source must be byte-identical.' );
		$this->assertSame( $revisions, count( wp_get_post_revisions( $source ) ), 'No revision may be added to the source.' );
	}

	/**
	 * Asserts creation contacts no translation provider.
	 *
	 * The draft starts as a copy of the source's text for a person to work on.
	 * Nothing is machine-translated, so nothing is sent anywhere.
	 *
	 * @return void
	 */
	public function test_creation_makes_no_outbound_http_request() {
		$source   = $this->source_page();
		$requests = 0;

		$counter = static function ( $preempt, $args, $url ) use ( &$requests ) {
			unset( $args, $url );
			++$requests;

			return $preempt;
		};

		add_filter( 'pre_http_request', $counter, 10, 3 );

		$this->create( $source, 'tr' );
		$this->create( $source, 'de' );

		remove_filter( 'pre_http_request', $counter, 10 );

		$this->assertSame( 0, $requests );
	}

	/* --------------------------------------------------------------------
	 * Duplication safety
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts a repeated identical request creates no second draft.
	 *
	 * A retrying client or a double-submitted form must not litter the site.
	 *
	 * @return void
	 */
	public function test_a_repeated_request_creates_no_second_draft() {
		$source = $this->source_page();

		$first   = $this->create( $source, 'tr' );
		$created = $first->get_data()['translations']['tr']['object_id'];
		$after   = $this->post_count();

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$repeat = $this->create( $source, 'tr' );

			$this->assertSame( 409, $repeat->get_status(), 'A repeat is a conflict, not a second creation.' );
			$this->assertSame( 'mclogiora_translation_exists', $repeat->get_data()['code'] );
			$this->assertSame( $after, $this->post_count(), 'No repeat may create a post.' );
		}

		$group = $this->read_group( $source );

		$this->assertSame( array( 'en', 'tr' ), array_keys( $group['translations'] ) );
		$this->assertSame( $created, $group['translations']['tr']['object_id'], 'The slot keeps its first occupant.' );
		$this->assertSame( $this->post_fingerprint( $source ), $this->post_fingerprint( $source ) );
	}

	/**
	 * Asserts an occupied slot is refused before anything is created.
	 *
	 * The slot check runs before the insert, so a refusal costs no post at all
	 * rather than costing one that is then rolled back.
	 *
	 * @return void
	 */
	public function test_an_occupied_slot_creates_no_post() {
		$source   = $this->source_page();
		$existing = $this->source_page( 'Existing translation' );

		$this->assertSame( 200, $this->link( $source, $existing, 'tr' )->get_status() );

		$before   = $this->post_count();
		$response = $this->create( $source, 'tr' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'mclogiora_translation_exists', $response->get_data()['code'] );
		$this->assertSame( $before, $this->post_count(), 'A refused creation must create nothing.' );
		$this->assertSame( $existing, $this->read_group( $source )['translations']['tr']['object_id'] );
	}

	/* --------------------------------------------------------------------
	 * Refusals
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts the source's own language is refused.
	 *
	 * @return void
	 */
	public function test_the_source_language_is_refused() {
		$source = $this->source_page();
		$before = $this->post_count();

		$response = $this->create( $source, 'en' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'mclogiora_same_language', $response->get_data()['code'] );
		$this->assertSame( $before, $this->post_count() );
	}

	/**
	 * Asserts a non-translatable content type is refused.
	 *
	 * @return void
	 */
	public function test_a_non_translatable_content_type_is_refused() {
		$attachment = self::factory()->post->create( array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
		$before     = $this->post_count();

		$response = $this->create( $attachment, 'tr' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'mclogiora_content_type_not_translatable', $response->get_data()['code'] );
		$this->assertSame( $before, $this->post_count() );
	}

	/**
	 * Asserts a missing source is a 404 and creates nothing.
	 *
	 * @return void
	 */
	public function test_a_missing_source_is_a_404() {
		$before   = $this->post_count();
		$response = $this->create( 99999999, 'tr' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'mclogiora_source_not_found', $response->get_data()['code'] );
		$this->assertSame( $before, $this->post_count() );
	}

	/**
	 * Asserts malformed input never reaches the workflow.
	 *
	 * @return void
	 */
	public function test_malformed_input_is_refused() {
		$source = $this->source_page();
		$before = $this->post_count();

		foreach ( array( '0', '-5', 'nonsense', '4.2', '' ) as $bad ) {
			$this->assertSame( 400, $this->create( $bad, 'tr' )->get_status(), var_export( $bad, true ) );
		}

		foreach ( array( ContentType::TERM, 'string', 'media', 'wp_options', '' ) as $type ) {
			$this->assertSame(
				400,
				$this->create( 1, 'tr', array( 'object_type' => $type ) )->get_status(),
				$type . ' must be refused; term creation is a separate slice.'
			);
		}

		$unknown = $this->create( $source, 'zz' );

		$this->assertSame( 400, $unknown->get_status() );
		$this->assertSame( RestErrors::UNKNOWN_LANGUAGE, $unknown->get_data()['code'] );

		$missing = new WP_REST_Request( 'POST', self::NS . '/translations' );
		$missing->set_param( 'object_type', ContentType::POST );

		$this->assertSame( 400, $this->server->dispatch( $missing )->get_status() );
		$this->assertSame( $before, $this->post_count(), 'No refused request may create a post.' );
	}

	/**
	 * Asserts no error body carries internal implementation detail.
	 *
	 * @return void
	 */
	public function test_error_bodies_carry_no_internal_detail() {
		$bodies = array(
			(string) wp_json_encode( $this->create( 99999999, 'tr' )->get_data() ),
			(string) wp_json_encode( $this->create( $this->source_page(), 'en' )->get_data() ),
		);

		foreach ( $bodies as $body ) {
			foreach ( array( 'SELECT', 'wpdb', 'wptests_', 'McLogiora\\', '/Volumes', 'Stack trace', 'api_key' ) as $needle ) {
				$this->assertStringNotContainsString( $needle, $body );
			}
		}
	}

	/* --------------------------------------------------------------------
	 * Permissions
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts creation requires the manage capability.
	 *
	 * @return void
	 */
	public function test_creation_requires_the_manage_capability() {
		$source = $this->source_page();
		$before = $this->post_count();

		foreach ( array( 0 => 401, $this->subscriber => 403, $this->editor => 403 ) as $user => $expected ) {
			wp_set_current_user( $user );

			$this->assertSame( $expected, $this->create( $source, 'tr' )->get_status() );
		}

		$this->assertSame( $before, $this->post_count(), 'A refused caller must create nothing.' );

		wp_set_current_user( $this->administrator );

		$this->assertSame( 200, $this->create( $source, 'tr' )->get_status() );
	}

	/**
	 * Asserts refusals cannot be used to discover which sources exist.
	 *
	 * @return void
	 */
	public function test_refusals_do_not_reveal_whether_a_source_exists() {
		$public  = $this->source_page();
		$private = $this->source_page( 'Gizli', 'private' );

		wp_set_current_user( $this->subscriber );

		$bodies = array(
			(string) wp_json_encode( $this->create( $public, 'tr' )->get_data() ),
			(string) wp_json_encode( $this->create( $private, 'tr' )->get_data() ),
			(string) wp_json_encode( $this->create( 99999999, 'tr' )->get_data() ),
		);

		$this->assertCount( 1, array_unique( $bodies ), 'Every refusal must be identical whatever the source is.' );
		$this->assertStringNotContainsString( (string) $private, $bodies[0] );
	}

	/**
	 * Asserts a private source cannot be translated by an unauthorised caller.
	 *
	 * @return void
	 */
	public function test_a_private_source_is_protected() {
		$private = $this->source_page( 'Gizli', 'private' );
		$before  = $this->post_count();

		foreach ( array( 0, $this->subscriber, $this->editor ) as $user ) {
			wp_set_current_user( $user );

			$this->create( $private, 'tr' );
		}

		wp_set_current_user( $this->administrator );

		$this->assertSame( $before, $this->post_count(), 'No unauthorised caller may create a draft.' );
		$this->assertSame( 'private', get_post_status( $private ) );
	}

	/* --------------------------------------------------------------------
	 * Coherence with the read routes
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts the read routes see the creation through unchanged schemas.
	 *
	 * @return void
	 */
	public function test_the_read_routes_reflect_the_creation() {
		$source  = $this->source_page();
		$created = $this->create( $source, 'tr' )->get_data()['translations']['tr']['object_id'];

		$group = $this->read_group( $source );

		$this->assertSame( array( 'group_key', 'object_type', 'source', 'translations' ), array_keys( $group ) );
		$this->assertSame( $created, $group['translations']['tr']['object_id'] );

		$translation = new WP_REST_Request( 'GET', self::NS . '/translations' );
		$translation->set_param( 'object_type', ContentType::POST );
		$translation->set_param( 'object_id', $source );
		$translation->set_param( 'language', 'tr' );

		$response = $this->server->dispatch( $translation );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array( 'object_type', 'object_id', 'language', 'source', 'translation' ),
			array_keys( $response->get_data() )
		);
		$this->assertSame( $created, $response->get_data()['translation']['object_id'] );
	}

	/**
	 * Asserts the creation response is scalars only, in the shared shape.
	 *
	 * @return void
	 */
	public function test_the_creation_response_is_the_shared_scalar_projection() {
		$data = $this->create( $this->source_page(), 'tr' )->get_data();

		foreach ( array_merge( array( $data['source'] ), array_values( $data['translations'] ) ) as $item ) {
			$this->assertSame(
				array( 'object_id', 'object_type', 'language', 'status', 'is_source', 'url' ),
				array_keys( $item )
			);

			foreach ( $item as $value ) {
				$this->assertTrue( is_scalar( $value ) || null === $value );
			}
		}

		$encoded = (string) wp_json_encode( $data );

		foreach ( array( 'source_hash', 'translated_source_hash', 'edit_link', 'post_content', 'object_key' ) as $internal ) {
			$this->assertStringNotContainsString( $internal, $encoded );
		}
	}

	/* --------------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Returns the declared arguments of the creation handler.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function create_handler_args() {
		foreach ( $this->server->get_routes()[ self::NS . '/translations' ] as $handler ) {
			if ( ! empty( $handler['methods']['POST'] ) ) {
				return $handler['args'];
			}
		}

		$this->fail( 'The translations route must serve POST.' );
	}

	/**
	 * Dispatches a creation request.
	 *
	 * @param int|string          $source_id Source identifier.
	 * @param string              $language Target language.
	 * @param array<string,mixed> $overrides Argument overrides.
	 * @return \WP_REST_Response
	 */
	private function create( $source_id, $language, array $overrides = array() ) {
		$request = new WP_REST_Request( 'POST', self::NS . '/translations' );

		$params = array_merge(
			array(
				'object_type' => ContentType::POST,
				'source_id'   => $source_id,
				'language'    => $language,
			),
			$overrides
		);

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatches a link request, to occupy a slot without creating anything.
	 *
	 * @param int    $source_id Source identifier.
	 * @param int    $target_id Target identifier.
	 * @param string $language Language code.
	 * @return \WP_REST_Response
	 */
	private function link( $source_id, $target_id, $language ) {
		$request = new WP_REST_Request( 'POST', self::NS . '/relations' );

		$request->set_param( 'object_type', ContentType::POST );
		$request->set_param( 'source_id', $source_id );
		$request->set_param( 'target_id', $target_id );
		$request->set_param( 'language', $language );

		return $this->server->dispatch( $request );
	}

	/**
	 * Reads a translation group through the REST read route.
	 *
	 * @param int $object_id Object identifier.
	 * @return array<string,mixed>|null
	 */
	private function read_group( $object_id ) {
		$request = new WP_REST_Request( 'GET', self::NS . '/relations' );

		$request->set_param( 'object_type', ContentType::POST );
		$request->set_param( 'object_id', $object_id );

		$response = $this->server->dispatch( $request );

		return 200 === $response->get_status() ? $response->get_data() : null;
	}

	/**
	 * Returns the error code and message of a response, for assertion output.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @return string
	 */
	private function message( $response ) {
		$data = $response->get_data();

		return isset( $data['code'] ) ? $data['code'] . ': ' . $data['message'] : '';
	}

	/**
	 * Creates a published page with content worth copying.
	 *
	 * A page rather than a post, so the assertion that the translation keeps
	 * the source's type can fail if the workflow ever hard-codes `post`.
	 *
	 * @param string $title Page title.
	 * @param string $status Post status.
	 * @return int
	 */
	private function source_page( $title = 'About us', $status = 'publish' ) {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => $title,
				'post_content' => 'Body of ' . $title . '.',
				'post_excerpt' => 'Excerpt of ' . $title . '.',
				'post_name'    => sanitize_title( $title ) . '-' . wp_rand( 1000, 999999 ),
				'post_status'  => $status,
				'menu_order'   => 3,
			)
		);
	}

	/**
	 * Returns the fields a creation must never alter on the source.
	 *
	 * @param int $post_id Post identifier.
	 * @return array<string,mixed>
	 */
	private function post_fingerprint( $post_id ) {
		$post = get_post( $post_id );

		return array(
			'ID'       => (int) $post->ID,
			'type'     => $post->post_type,
			'title'    => $post->post_title,
			'excerpt'  => $post->post_excerpt,
			'content'  => $post->post_content,
			'name'     => $post->post_name,
			'status'   => $post->post_status,
			'parent'   => (int) $post->post_parent,
			'author'   => (int) $post->post_author,
			'date'     => $post->post_date_gmt,
			'modified' => $post->post_modified_gmt,
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
}
