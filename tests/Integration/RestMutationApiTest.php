<?php
/**
 * REST mutation contract integration tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Api\Rest\RestErrors;
use McLogiora\Core\Application;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Database\Installer;
use McLogiora\Database\TableNames;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationRelationRepositoryInterface;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Qualifies the one mutation family this slice exposes: status transitions.
 *
 * Every request goes through WP_REST_Server::dispatch against the routes
 * Application::boot() registers, for the same reason the read suite does: what
 * matters is whether the server refuses a caller before the handler runs, not
 * whether the handler works when called directly.
 *
 * The assertions fall into three groups. The contract group pins what a valid
 * request does. The refusal group pins what the domain rejects and with which
 * HTTP status, including the difference between "your request is wrong" and
 * "your request conflicts with the state". The boundary group proves what a
 * relation mutation must never touch: post content, other languages, other
 * groups, and any provider.
 */
final class RestMutationApiTest extends WP_UnitTestCase {
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
	 * Registration and namespace write audit
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts the write verbs land only on the translations route.
	 *
	 * This is the slice boundary made mechanical. Languages and relations stay
	 * read-only, and nothing in the namespace accepts DELETE.
	 *
	 * @return void
	 */
	public function test_write_methods_exist_only_on_the_translations_route() {
		$writable = array();

		foreach ( $this->server->get_routes() as $path => $handlers ) {
			if ( 0 !== strpos( $path, self::NS . '/' ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				if ( ! isset( $handler['methods'] ) ) {
					continue;
				}

				$this->assertArrayNotHasKey( 'DELETE', $handler['methods'], $path . ' must not accept DELETE.' );

				$this->assertTrue(
					isset( $handler['permission_callback'] ) && is_callable( $handler['permission_callback'] ),
					$path . ' must declare a callable permission_callback.'
				);

				foreach ( array( 'POST', 'PUT', 'PATCH' ) as $verb ) {
					if ( isset( $handler['methods'][ $verb ] ) ) {
						$writable[ $path ] = true;
					}
				}
			}
		}

		$this->assertSame( array( self::NS . '/translations' => true ), $writable );
	}

	/**
	 * Asserts every write verb is served and declares validated arguments.
	 *
	 * @return void
	 */
	public function test_the_write_handler_declares_validated_arguments() {
		$handlers = $this->server->get_routes()[ self::NS . '/translations' ];
		$found    = false;

		foreach ( $handlers as $handler ) {
			if ( empty( $handler['methods']['PATCH'] ) ) {
				continue;
			}

			$found = true;

			foreach ( array( 'POST', 'PUT', 'PATCH' ) as $verb ) {
				$this->assertArrayHasKey( $verb, $handler['methods'] );
			}

			foreach ( array( 'object_type', 'object_id', 'language', 'status' ) as $arg ) {
				$this->assertArrayHasKey( $arg, $handler['args'] );
				$this->assertTrue( $handler['args'][ $arg ]['required'], $arg . ' must be required.' );
				$this->assertTrue(
					is_callable( $handler['args'][ $arg ]['validate_callback'] ),
					$arg . ' must declare a real validate_callback, not decorative schema.'
				);
			}

			$this->assertSame( TranslationStatus::all(), $handler['args']['status']['enum'] );
			$this->assertSame( ContentType::all(), $handler['args']['object_type']['enum'] );
		}

		$this->assertTrue( $found, 'The translations route must serve PATCH.' );
	}

	/* --------------------------------------------------------------------
	 * The happy path
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts a legal transition succeeds and returns the Slice 1 shape.
	 *
	 * @return void
	 */
	public function test_a_legal_transition_succeeds_and_returns_the_read_projection() {
		$pair = $this->translated_post();

		$response = $this->write( $pair['target'], 'tr', TranslationStatus::NEEDS_REVIEW );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( array( 'object_type', 'object_id', 'language', 'source', 'translation' ), array_keys( $data ) );
		$this->assertSame(
			array( 'object_id', 'object_type', 'language', 'status', 'is_source', 'url' ),
			array_keys( $data['translation'] )
		);
		$this->assertSame( TranslationStatus::NEEDS_REVIEW, $data['translation']['status'] );
		$this->assertSame( $pair['target'], $data['translation']['object_id'] );
		$this->assertFalse( $data['translation']['is_source'] );
		$this->assertSame( TranslationStatus::ORIGINAL, $data['source']['status'] );
	}

	/**
	 * Asserts the change is persisted, not merely reported.
	 *
	 * @return void
	 */
	public function test_the_new_status_is_persisted() {
		$pair = $this->translated_post();

		$this->write( $pair['target'], 'tr', TranslationStatus::NEEDS_REVIEW );

		$item = $this->container->get( TranslationRelationRepositoryInterface::class )
			->find_item( ContentType::POST, (string) $pair['target'], 'tr' );

		$this->assertSame( TranslationStatus::NEEDS_REVIEW, $item->status() );

		$read = $this->read( $pair['target'], 'tr' );

		$this->assertSame( TranslationStatus::NEEDS_REVIEW, $read->get_data()['translation']['status'] );
	}

	/**
	 * Asserts all three write verbs perform the same update.
	 *
	 * @return void
	 */
	public function test_post_put_and_patch_all_perform_the_update() {
		foreach ( array( 'POST', 'PUT', 'PATCH' ) as $verb ) {
			$pair     = $this->translated_post();
			$response = $this->write( $pair['target'], 'tr', TranslationStatus::NEEDS_REVIEW, array(), $verb );

			$this->assertSame( 200, $response->get_status(), $verb . ' must be served.' );
			$this->assertSame( TranslationStatus::NEEDS_REVIEW, $response->get_data()['translation']['status'] );
		}
	}

	/**
	 * Asserts mcLogiora imposes no extra nonce on an authenticated request.
	 *
	 * WordPress already governs REST authentication, including the cookie
	 * nonce. Adding an admin-form nonce on top would leave Application Password
	 * and other WordPress-native clients unable to call this route while adding
	 * nothing, so no such field exists and none is required.
	 *
	 * @return void
	 */
	public function test_no_additional_mclogiora_nonce_is_required() {
		$pair    = $this->translated_post();
		$request = new WP_REST_Request( 'PATCH', self::NS . '/translations' );

		$request->set_param( 'object_type', ContentType::POST );
		$request->set_param( 'object_id', $pair['target'] );
		$request->set_param( 'language', 'tr' );
		$request->set_param( 'status', TranslationStatus::NEEDS_REVIEW );

		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
	}

	/* --------------------------------------------------------------------
	 * Repeat semantics
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts a repeated identical write is a stable conflict, not a no-op.
	 *
	 * This operation is deliberately not idempotent. Repeating it does not
	 * change anything a second time, but it does not silently succeed either:
	 * the domain refuses with a specific code, and the row count and stored
	 * status are untouched.
	 *
	 * @return void
	 */
	public function test_a_repeated_write_is_a_stable_conflict() {
		$pair   = $this->translated_post();
		$tables = $this->container->get( TableNames::class );

		$this->assertSame( 200, $this->write( $pair['target'], 'tr', TranslationStatus::NEEDS_REVIEW )->get_status() );

		$after_first = $this->row_counts( $tables );

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$response = $this->write( $pair['target'], 'tr', TranslationStatus::NEEDS_REVIEW );

			$this->assertSame( 409, $response->get_status() );
			$this->assertSame( 'mclogiora_status_unchanged', $response->get_data()['code'] );
		}

		$this->assertSame( $after_first, $this->row_counts( $tables ), 'A refused repeat must add no row.' );

		$item = $this->container->get( TranslationRelationRepositoryInterface::class )
			->find_item( ContentType::POST, (string) $pair['target'], 'tr' );

		$this->assertSame( TranslationStatus::NEEDS_REVIEW, $item->status() );
	}

	/* --------------------------------------------------------------------
	 * Domain refusals, and the 400/409 split
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts an illegal transition is a conflict with the current state.
	 *
	 * REST does not decide this. `translated` cannot move to
	 * `machine_suggested` because overwriting approved text with a machine's
	 * proposal would undo somebody's work, and that rule lives in the domain.
	 *
	 * @return void
	 */
	public function test_an_illegal_transition_is_a_conflict() {
		$pair = $this->translated_post();

		$this->write( $pair['target'], 'tr', TranslationStatus::TRANSLATED );

		$response = $this->write( $pair['target'], 'tr', TranslationStatus::MACHINE_SUGGESTED );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'mclogiora_invalid_status_transition', $response->get_data()['code'] );
	}

	/**
	 * Asserts the source item cannot be given a translation status.
	 *
	 * @return void
	 */
	public function test_the_source_item_status_is_immutable() {
		$pair = $this->translated_post();

		$response = $this->write( $pair['source'], 'en', TranslationStatus::NEEDS_REVIEW );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'mclogiora_original_status_immutable', $response->get_data()['code'] );

		$item = $this->container->get( TranslationRelationRepositoryInterface::class )
			->find_item( ContentType::POST, (string) $pair['source'], 'en' );

		$this->assertSame( TranslationStatus::ORIGINAL, $item->status() );
	}

	/**
	 * Asserts unassignable statuses are rejected as bad input, not conflict.
	 *
	 * `original` is a structural role and `missing` is a computed absence.
	 * Neither can be assigned to anything in any state, which makes them a
	 * wrong request rather than a state conflict.
	 *
	 * @return void
	 */
	public function test_unassignable_statuses_are_rejected_as_bad_input() {
		$pair = $this->translated_post();

		$original = $this->write( $pair['target'], 'tr', TranslationStatus::ORIGINAL );

		$this->assertSame( 400, $original->get_status() );
		$this->assertSame( 'mclogiora_original_not_assignable', $original->get_data()['code'] );

		$missing = $this->write( $pair['target'], 'tr', TranslationStatus::MISSING );

		$this->assertSame( 400, $missing->get_status() );
		$this->assertSame( 'mclogiora_missing_not_assignable', $missing->get_data()['code'] );
	}

	/**
	 * Asserts an unknown status string never reaches the domain.
	 *
	 * @return void
	 */
	public function test_an_unknown_status_is_rejected_by_argument_validation() {
		$pair = $this->translated_post();

		$response = $this->write( $pair['target'], 'tr', 'brilliant' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/**
	 * Asserts a language with no item on this object is a 404.
	 *
	 * @return void
	 */
	public function test_a_language_without_an_item_is_a_404() {
		$pair = $this->translated_post();

		$response = $this->write( $pair['target'], 'de', TranslationStatus::NEEDS_REVIEW );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'mclogiora_translation_item_not_found', $response->get_data()['code'] );
	}

	/**
	 * Asserts malformed identifiers and types never reach the workflow.
	 *
	 * @return void
	 */
	public function test_malformed_arguments_are_rejected_before_the_workflow() {
		$pair = $this->translated_post();

		foreach ( array( '0', '-3', 'nonsense', '2.5', '' ) as $id ) {
			$response = $this->write( $id, 'tr', TranslationStatus::NEEDS_REVIEW );

			$this->assertSame( 400, $response->get_status(), var_export( $id, true ) . ' must be rejected.' );
		}

		foreach ( array( 'wp_options', 'users', '' ) as $type ) {
			$response = $this->write( $pair['target'], 'tr', TranslationStatus::NEEDS_REVIEW, array( 'object_type' => $type ) );

			$this->assertSame( 400, $response->get_status(), $type . ' must be rejected.' );
		}

		$unknown = $this->write( $pair['target'], 'zz', TranslationStatus::NEEDS_REVIEW );

		$this->assertSame( 400, $unknown->get_status() );
		$this->assertSame( RestErrors::UNKNOWN_LANGUAGE, $unknown->get_data()['code'] );
	}

	/**
	 * Asserts a missing required argument is refused.
	 *
	 * @return void
	 */
	public function test_missing_required_arguments_are_refused() {
		$pair    = $this->translated_post();
		$request = new WP_REST_Request( 'PATCH', self::NS . '/translations' );

		$request->set_param( 'object_type', ContentType::POST );
		$request->set_param( 'object_id', $pair['target'] );

		$this->assertSame( 400, $this->server->dispatch( $request )->get_status() );
	}

	/**
	 * Asserts no error body carries internal implementation detail.
	 *
	 * @return void
	 */
	public function test_error_bodies_carry_no_internal_detail() {
		$pair = $this->translated_post();

		$bodies = array(
			(string) wp_json_encode( $this->write( $pair['source'], 'en', TranslationStatus::NEEDS_REVIEW )->get_data() ),
			(string) wp_json_encode( $this->write( $pair['target'], 'de', TranslationStatus::NEEDS_REVIEW )->get_data() ),
			(string) wp_json_encode( $this->write( $pair['target'], 'tr', TranslationStatus::ORIGINAL )->get_data() ),
		);

		foreach ( $bodies as $body ) {
			foreach ( array( 'SELECT', 'wpdb', 'wptests_', 'McLogiora\\', '/Volumes', 'Stack trace', 'api_key', 'credential' ) as $needle ) {
				$this->assertStringNotContainsString( $needle, $body );
			}
		}
	}

	/* --------------------------------------------------------------------
	 * Permission matrix and probing
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts the write is closed to anonymous, subscriber and editor callers.
	 *
	 * The editor case is the interesting one: the capability mcLogiora resolves
	 * to today is `manage_options`, so a user who may edit the underlying post
	 * still may not manage its translation relations.
	 *
	 * @return void
	 */
	public function test_the_write_requires_the_manage_capability() {
		$pair = $this->translated_post();

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->write( $pair['target'], 'tr', TranslationStatus::NEEDS_REVIEW )->get_status() );

		wp_set_current_user( $this->subscriber );
		$this->assertSame( 403, $this->write( $pair['target'], 'tr', TranslationStatus::NEEDS_REVIEW )->get_status() );

		wp_set_current_user( $this->editor );
		$this->assertSame( 403, $this->write( $pair['target'], 'tr', TranslationStatus::NEEDS_REVIEW )->get_status() );

		$item = $this->container->get( TranslationRelationRepositoryInterface::class )
			->find_item( ContentType::POST, (string) $pair['target'], 'tr' );

		$this->assertSame( TranslationStatus::DRAFT, $item->status(), 'A refused write must change nothing.' );

		wp_set_current_user( $this->administrator );
		$this->assertSame( 200, $this->write( $pair['target'], 'tr', TranslationStatus::NEEDS_REVIEW )->get_status() );
	}

	/**
	 * Asserts refused callers cannot tell existing objects from absent ones.
	 *
	 * Permission is resolved before any lookup, so every unauthorised request
	 * gets the same answer whether the target exists, is private, or was never
	 * created at all.
	 *
	 * @return void
	 */
	public function test_permission_is_resolved_before_any_lookup() {
		$pair    = $this->translated_post();
		$private = $this->translated_post( 'private' );

		wp_set_current_user( $this->subscriber );

		$statuses = array(
			$this->write( $pair['target'], 'tr', TranslationStatus::NEEDS_REVIEW )->get_status(),
			$this->write( $private['target'], 'tr', TranslationStatus::NEEDS_REVIEW )->get_status(),
			$this->write( 99999999, 'tr', TranslationStatus::NEEDS_REVIEW )->get_status(),
		);

		$this->assertSame( array( 403, 403, 403 ), $statuses );

		$bodies = array(
			(string) wp_json_encode( $this->write( $private['target'], 'tr', TranslationStatus::NEEDS_REVIEW )->get_data() ),
			(string) wp_json_encode( $this->write( 99999999, 'tr', TranslationStatus::NEEDS_REVIEW )->get_data() ),
		);

		$this->assertSame( $bodies[0], $bodies[1], 'A refusal must not vary with whether the object exists.' );
		$this->assertStringNotContainsString( (string) $private['target'], $bodies[0] );
	}

	/**
	 * Asserts a private translation cannot be mutated by an unauthorised user.
	 *
	 * @return void
	 */
	public function test_a_private_translation_cannot_be_mutated_by_an_unauthorised_caller() {
		$private = $this->translated_post( 'private' );

		foreach ( array( 0, $this->subscriber, $this->editor ) as $user ) {
			wp_set_current_user( $user );

			$this->write( $private['target'], 'tr', TranslationStatus::TRANSLATED );
		}

		wp_set_current_user( $this->administrator );

		$item = $this->container->get( TranslationRelationRepositoryInterface::class )
			->find_item( ContentType::POST, (string) $private['target'], 'tr' );

		$this->assertSame( TranslationStatus::DRAFT, $item->status() );
		$this->assertSame( 'private', get_post_status( $private['target'] ) );
	}

	/* --------------------------------------------------------------------
	 * Tampering and blast radius
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts a language belonging to another group cannot be reached.
	 *
	 * Naming one group's object with another group's language must not walk
	 * across groups: the item is looked up by object and language together.
	 *
	 * @return void
	 */
	public function test_a_language_from_another_group_is_not_reachable() {
		$first  = $this->translated_post();
		$second = $this->translated_post();

		$this->write( $second['target'], 'tr', TranslationStatus::TRANSLATED );

		$response = $this->write( $first['target'], 'de', TranslationStatus::TRANSLATED );

		$this->assertSame( 404, $response->get_status() );

		$repository = $this->container->get( TranslationRelationRepositoryInterface::class );

		$this->assertSame(
			TranslationStatus::DRAFT,
			$repository->find_item( ContentType::POST, (string) $first['target'], 'tr' )->status(),
			'A refused write must not touch the group it named.'
		);

		$this->assertSame(
			TranslationStatus::TRANSLATED,
			$repository->find_item( ContentType::POST, (string) $second['target'], 'tr' )->status(),
			'A refused write must not touch any other group.'
		);
	}

	/**
	 * Asserts a term object type cannot reach a post relation.
	 *
	 * @return void
	 */
	public function test_the_object_type_is_part_of_the_identity() {
		$pair = $this->translated_post();

		$response = $this->write(
			$pair['target'],
			'tr',
			TranslationStatus::TRANSLATED,
			array( 'object_type' => ContentType::TERM )
		);

		$this->assertSame( 404, $response->get_status() );

		$item = $this->container->get( TranslationRelationRepositoryInterface::class )
			->find_item( ContentType::POST, (string) $pair['target'], 'tr' );

		$this->assertSame( TranslationStatus::DRAFT, $item->status() );
	}

	/**
	 * Asserts a status change edits no WordPress content at all.
	 *
	 * Relation management is not content editing. A status move must not touch
	 * the post's content, title, slug, status, modified time or revisions, on
	 * either side of the relation.
	 *
	 * @return void
	 */
	public function test_a_status_change_edits_no_wordpress_content() {
		$pair   = $this->translated_post();
		$tables = $this->container->get( TableNames::class );

		$before = array(
			'rows'      => $this->row_counts( $tables ),
			'source'    => $this->post_fingerprint( $pair['source'] ),
			'target'    => $this->post_fingerprint( $pair['target'] ),
			'revisions' => count( wp_get_post_revisions( $pair['target'] ) ),
		);

		$this->assertSame( 200, $this->write( $pair['target'], 'tr', TranslationStatus::NEEDS_REVIEW )->get_status() );

		$this->assertSame( $before['rows'], $this->row_counts( $tables ), 'No relation row may be added or removed.' );
		$this->assertSame( $before['source'], $this->post_fingerprint( $pair['source'] ), 'The source post must be untouched.' );
		$this->assertSame( $before['target'], $this->post_fingerprint( $pair['target'] ), 'The translated post must be untouched.' );
		$this->assertSame( $before['revisions'], count( wp_get_post_revisions( $pair['target'] ) ), 'No revision may be created.' );
	}

	/**
	 * Asserts a relation mutation contacts no translation provider.
	 *
	 * Relation management is not Translation Suggestions. Moving a status to
	 * `machine_suggested` is a bookkeeping change and must not, by itself,
	 * reach OpenAI, Anthropic, Gemini or DeepL.
	 *
	 * @return void
	 */
	public function test_mutations_make_no_outbound_http_request() {
		$pair     = $this->translated_post();
		$requests = 0;

		$counter = static function ( $preempt, $args, $url ) use ( &$requests ) {
			unset( $args, $url );
			++$requests;

			return $preempt;
		};

		add_filter( 'pre_http_request', $counter, 10, 3 );

		$this->write( $pair['target'], 'tr', TranslationStatus::MACHINE_SUGGESTED );
		$this->write( $pair['target'], 'tr', TranslationStatus::TRANSLATED );
		$this->write( $pair['target'], 'tr', TranslationStatus::NEEDS_UPDATE );
		$this->write( $pair['source'], 'en', TranslationStatus::TRANSLATED );

		remove_filter( 'pre_http_request', $counter, 10 );

		$this->assertSame( 0, $requests );
	}

	/**
	 * Asserts the read routes are unchanged by the write route existing.
	 *
	 * @return void
	 */
	public function test_the_read_projection_is_unchanged() {
		$pair = $this->translated_post();

		$before = $this->read( $pair['target'], 'tr' )->get_data();

		$this->write( $pair['target'], 'tr', TranslationStatus::NEEDS_REVIEW );

		$after = $this->read( $pair['target'], 'tr' )->get_data();

		$this->assertSame( array_keys( $before ), array_keys( $after ), 'The GET response structure must not change.' );
		$this->assertSame( array_keys( $before['translation'] ), array_keys( $after['translation'] ) );
		$this->assertSame( TranslationStatus::DRAFT, $before['translation']['status'] );
		$this->assertSame( TranslationStatus::NEEDS_REVIEW, $after['translation']['status'] );
	}

	/* --------------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Dispatches a status write.
	 *
	 * @param int|string          $object_id Object identifier.
	 * @param string              $language Language code.
	 * @param string              $status Requested status.
	 * @param array<string,mixed> $overrides Argument overrides.
	 * @param string              $method HTTP method.
	 * @return \WP_REST_Response
	 */
	private function write( $object_id, $language, $status, array $overrides = array(), $method = 'PATCH' ) {
		$request = new WP_REST_Request( $method, self::NS . '/translations' );

		$params = array_merge(
			array(
				'object_type' => ContentType::POST,
				'object_id'   => $object_id,
				'language'    => $language,
				'status'      => $status,
			),
			$overrides
		);

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatches a translation read.
	 *
	 * @param int    $object_id Object identifier.
	 * @param string $language Language code.
	 * @return \WP_REST_Response
	 */
	private function read( $object_id, $language ) {
		$request = new WP_REST_Request( 'GET', self::NS . '/translations' );

		$request->set_param( 'object_type', ContentType::POST );
		$request->set_param( 'object_id', $object_id );
		$request->set_param( 'language', $language );

		return $this->server->dispatch( $request );
	}

	/**
	 * Returns the fields a relation change must never alter.
	 *
	 * @param int $post_id Post identifier.
	 * @return array<string,mixed>
	 */
	private function post_fingerprint( $post_id ) {
		$post = get_post( $post_id );

		return array(
			'title'    => $post->post_title,
			'content'  => $post->post_content,
			'name'     => $post->post_name,
			'status'   => $post->post_status,
			'parent'   => $post->post_parent,
			'modified' => $post->post_modified_gmt,
		);
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
	 * Creates an English post with a Turkish translation in draft status.
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
				'post_name'   => 'about-us-' . wp_rand( 1000, 999999 ),
				'post_status' => 'publish',
			)
		);

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $source, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		if ( 'publish' !== $target_status ) {
			wp_update_post(
				array(
					'ID'          => $created['post_id'],
					'post_status' => $target_status,
				)
			);
		}

		wp_set_current_user( $current );

		return array(
			'source' => (int) $source,
			'target' => (int) $created['post_id'],
		);
	}
}
