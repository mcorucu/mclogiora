<?php
/**
 * REST relation membership lifecycle tests.
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
use McLogiora\Relations\TranslationStatus;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Qualifies linking and unlinking translation relations over HTTP.
 *
 * The claim these routes make is narrow and easy to get wrong: they change
 * *membership* and nothing else. So the assertions are built around what must
 * not happen. A link creates no post and no term and edits no field of either
 * object. An unlink deletes nothing — the post and the term survive with every
 * field byte-identical, which is the hard gate for this slice, because a REST
 * DELETE that quietly trashed content would be the worst possible reading of
 * the verb.
 *
 * Everything dispatches through WP_REST_Server against the routes
 * Application::boot() registers.
 */
final class RestRelationLifecycleTest extends WP_UnitTestCase {
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
	 * Asserts the relations route serves POST and DELETE with validated args.
	 *
	 * @return void
	 */
	public function test_the_relations_route_registers_validated_membership_verbs() {
		$handlers = $this->server->get_routes()[ self::NS . '/relations' ];
		$seen     = array();

		foreach ( $handlers as $handler ) {
			if ( ! isset( $handler['methods'] ) ) {
				continue;
			}

			$this->assertTrue( is_callable( $handler['permission_callback'] ) );

			foreach ( array_keys( $handler['methods'] ) as $verb ) {
				$seen[ $verb ] = true;
			}

			$required = array();

			if ( ! empty( $handler['methods']['POST'] ) ) {
				$required = array( 'object_type', 'source_id', 'target_id', 'language' );
			} elseif ( ! empty( $handler['methods']['DELETE'] ) ) {
				$required = array( 'object_type', 'object_id', 'language' );
			}

			foreach ( $required as $arg ) {
				$this->assertArrayHasKey( $arg, $handler['args'], $arg . ' must be declared.' );
				$this->assertTrue( $handler['args'][ $arg ]['required'], $arg . ' must be required.' );
				$this->assertTrue(
					is_callable( $handler['args'][ $arg ]['validate_callback'] ),
					$arg . ' must declare a real validate_callback.'
				);
			}

			if ( ! empty( $required ) ) {
				$this->assertSame(
					array( ContentType::POST, ContentType::TERM ),
					$handler['args']['object_type']['enum'],
					'Only posts and terms have link and unlink workflows.'
				);
			}
		}

		$this->assertArrayHasKey( 'GET', $seen );
		$this->assertArrayHasKey( 'POST', $seen );
		$this->assertArrayHasKey( 'DELETE', $seen );
	}

	/**
	 * Asserts the namespace write surface is exactly the two intended routes.
	 *
	 * @return void
	 */
	public function test_the_namespace_write_surface_is_bounded() {
		$writes = array();

		foreach ( $this->server->get_routes() as $path => $handlers ) {
			if ( 0 !== strpos( $path, self::NS . '/' ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				if ( ! isset( $handler['methods'] ) ) {
					continue;
				}

				foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE' ) as $verb ) {
					if ( ! empty( $handler['methods'][ $verb ] ) ) {
						$writes[ $path ][ $verb ] = true;
					}
				}
			}
		}

		ksort( $writes );

		$this->assertSame( array( self::NS . '/relations', self::NS . '/translations' ), array_keys( $writes ) );
		$this->assertSame( array( 'POST', 'DELETE' ), array_keys( $writes[ self::NS . '/relations' ] ) );
		$this->assertArrayNotHasKey( 'DELETE', $writes[ self::NS . '/translations' ] );
		$this->assertArrayNotHasKey( self::NS . '/languages', $writes );
	}

	/* --------------------------------------------------------------------
	 * Content link
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts linking a post records membership and returns the group.
	 *
	 * @return void
	 */
	public function test_linking_a_post_records_membership() {
		$source = $this->post( 'Source' );
		$target = $this->post( 'Hedef' );

		$response = $this->send_link( ContentType::POST, $source, $target, 'tr' );

		$this->assertSame( 200, $response->get_status(), $this->message( $response ) );

		$data = $response->get_data();

		$this->assertSame( array( 'group_key', 'object_type', 'source', 'translations' ), array_keys( $data ) );
		$this->assertSame( ContentType::POST, $data['object_type'] );
		$this->assertSame( $source, $data['source']['object_id'] );
		$this->assertSame( array( 'en', 'tr' ), array_keys( $data['translations'] ) );
		$this->assertSame( $target, $data['translations']['tr']['object_id'] );
		$this->assertSame( TranslationStatus::NEEDS_REVIEW, $data['translations']['tr']['status'] );
	}

	/**
	 * Asserts linking creates no post and edits neither object.
	 *
	 * @return void
	 */
	public function test_linking_a_post_creates_nothing_and_edits_nothing() {
		$source = $this->post( 'Source' );
		$target = $this->post( 'Hedef' );

		$before = array(
			'posts'  => $this->post_count(),
			'source' => $this->post_fingerprint( $source ),
			'target' => $this->post_fingerprint( $target ),
			'revs'   => count( wp_get_post_revisions( $target ) ),
		);

		$this->assertSame( 200, $this->send_link( ContentType::POST, $source, $target, 'tr' )->get_status() );

		$this->assertSame( $before['posts'], $this->post_count(), 'Linking must create no post.' );
		$this->assertSame( $before['source'], $this->post_fingerprint( $source ), 'The source post must be untouched.' );
		$this->assertSame( $before['target'], $this->post_fingerprint( $target ), 'The target post must be untouched.' );
		$this->assertSame( $before['revs'], count( wp_get_post_revisions( $target ) ), 'Linking must create no revision.' );
	}

	/**
	 * Asserts a repeated link is a stable conflict with no duplicate rows.
	 *
	 * @return void
	 */
	public function test_a_repeated_link_is_a_stable_conflict() {
		$source = $this->post( 'Source' );
		$target = $this->post( 'Hedef' );
		$tables = $this->container->get( TableNames::class );

		$this->assertSame( 200, $this->send_link( ContentType::POST, $source, $target, 'tr' )->get_status() );

		$after_first = $this->row_counts( $tables );

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$response = $this->send_link( ContentType::POST, $source, $target, 'tr' );

			$this->assertSame( 409, $response->get_status() );
			$this->assertSame( 'mclogiora_object_already_related', $response->get_data()['code'] );
		}

		$this->assertSame( $after_first, $this->row_counts( $tables ), 'A refused link must add no row.' );
	}

	/**
	 * Asserts the domain's link refusals map to deliberate statuses.
	 *
	 * @return void
	 */
	public function test_link_refusals_carry_the_domain_code() {
		$source = $this->post( 'Source' );
		$target = $this->post( 'Hedef' );
		$page   = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );

		$self = $this->send_link( ContentType::POST, $source, $source, 'tr' );
		$this->assertSame( 400, $self->get_status() );
		$this->assertSame( 'mclogiora_cannot_link_to_self', $self->get_data()['code'] );

		$missing = $this->send_link( ContentType::POST, $source, 99999999, 'tr' );
		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( 'mclogiora_target_not_found', $missing->get_data()['code'] );

		$mismatch = $this->send_link( ContentType::POST, $source, $page, 'tr' );
		$this->assertSame( 409, $mismatch->get_status() );
		$this->assertSame( 'mclogiora_post_type_mismatch', $mismatch->get_data()['code'] );

		$same = $this->send_link( ContentType::POST, $source, $target, 'en' );
		$this->assertSame( 409, $same->get_status() );
		$this->assertSame( 'mclogiora_same_language', $same->get_data()['code'] );
	}

	/**
	 * Asserts an occupied language slot is refused.
	 *
	 * @return void
	 */
	public function test_an_occupied_language_slot_is_refused() {
		$source = $this->post( 'Source' );
		$first  = $this->post( 'Hedef' );
		$second = $this->post( 'Baska' );

		$this->assertSame( 200, $this->send_link( ContentType::POST, $source, $first, 'tr' )->get_status() );

		$response = $this->send_link( ContentType::POST, $source, $second, 'tr' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'mclogiora_translation_exists', $response->get_data()['code'] );

		$group = $this->group( $source, ContentType::POST );

		$this->assertSame( $first, $group['translations']['tr']['object_id'], 'The occupied slot keeps its original occupant.' );
		$this->assertArrayNotHasKey( 'de', $group['translations'] );
	}

	/**
	 * Asserts a target already linked elsewhere cannot jump groups.
	 *
	 * @return void
	 */
	public function test_a_target_linked_elsewhere_cannot_jump_groups() {
		$first_source  = $this->post( 'First source' );
		$shared_target = $this->post( 'Hedef' );
		$other_source  = $this->post( 'Second source' );

		$this->assertSame( 200, $this->send_link( ContentType::POST, $first_source, $shared_target, 'tr' )->get_status() );

		$response = $this->send_link( ContentType::POST, $other_source, $shared_target, 'tr' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'mclogiora_object_already_related', $response->get_data()['code'] );

		$this->assertSame(
			$shared_target,
			$this->group( $first_source, ContentType::POST )['translations']['tr']['object_id'],
			'The original group must keep its member.'
		);

		$this->assertNull( $this->group( $other_source, ContentType::POST ), 'The refused group must not have been created.' );
	}

	/* --------------------------------------------------------------------
	 * Content unlink
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts unlinking detaches membership and leaves the post alive.
	 *
	 * This is the hard gate for the slice.
	 *
	 * @return void
	 */
	public function test_unlinking_a_post_detaches_membership_and_the_post_survives() {
		$source = $this->post( 'Source' );
		$target = $this->post( 'Hedef' );

		$this->assertSame( 200, $this->send_link( ContentType::POST, $source, $target, 'tr' )->get_status() );

		$before = array(
			'posts'       => $this->post_count(),
			'fingerprint' => $this->post_fingerprint( $target ),
			'revs'        => count( wp_get_post_revisions( $target ) ),
		);

		$response = $this->send_unlink( ContentType::POST, $target, 'tr' );

		$this->assertSame( 200, $response->get_status(), $this->message( $response ) );
		$this->assertSame(
			array(
				'object_type' => ContentType::POST,
				'object_id'   => $target,
				'language'    => 'tr',
				'detached'    => true,
			),
			$response->get_data()
		);

		$this->assertInstanceOf( 'WP_Post', get_post( $target ), 'The post must still exist.' );
		$this->assertSame( $before['posts'], $this->post_count(), 'Unlinking must delete no post.' );
		$this->assertSame( $before['fingerprint'], $this->post_fingerprint( $target ), 'The post must be byte-identical.' );
		$this->assertSame( $before['revs'], count( wp_get_post_revisions( $target ) ), 'Unlinking must create no revision.' );
		$this->assertNotSame( 'trash', get_post_status( $target ), 'The post must not be trashed.' );

		$this->assertArrayNotHasKey( 'tr', $this->group( $source, ContentType::POST )['translations'] );
		$this->assertNull( $this->group( $target, ContentType::POST ), 'The detached post belongs to no group.' );
	}

	/**
	 * Asserts the group and its source survive an unlink.
	 *
	 * The domain performs no group cleanup, and this records that rather than
	 * inventing any in REST.
	 *
	 * @return void
	 */
	public function test_the_group_and_its_source_survive_an_unlink() {
		$source = $this->post( 'Source' );
		$target = $this->post( 'Hedef' );

		$this->send_link( ContentType::POST, $source, $target, 'tr' );

		$before = $this->group( $source, ContentType::POST );

		$this->send_unlink( ContentType::POST, $target, 'tr' );

		$after = $this->group( $source, ContentType::POST );

		$this->assertIsArray( $after, 'The group survives its last translation being removed.' );
		$this->assertSame( $before['group_key'], $after['group_key'] );
		$this->assertSame( $source, $after['source']['object_id'] );
		$this->assertSame( array( 'en' ), array_keys( $after['translations'] ) );
	}

	/**
	 * Asserts a repeated unlink reports the membership as already absent.
	 *
	 * @return void
	 */
	public function test_a_repeated_unlink_reports_the_membership_absent() {
		$source = $this->post( 'Source' );
		$target = $this->post( 'Hedef' );

		$this->send_link( ContentType::POST, $source, $target, 'tr' );
		$this->assertSame( 200, $this->send_unlink( ContentType::POST, $target, 'tr' )->get_status() );

		$response = $this->send_unlink( ContentType::POST, $target, 'tr' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'mclogiora_relation_item_not_found', $response->get_data()['code'] );
		$this->assertInstanceOf( 'WP_Post', get_post( $target ) );
	}

	/**
	 * Asserts the source item of a group cannot be detached.
	 *
	 * @return void
	 */
	public function test_the_source_item_cannot_be_detached() {
		$source = $this->post( 'Source' );
		$target = $this->post( 'Hedef' );

		$this->send_link( ContentType::POST, $source, $target, 'tr' );

		$response = $this->send_unlink( ContentType::POST, $source, 'en' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'mclogiora_relation_detach_original', $response->get_data()['code'] );

		$group = $this->group( $source, ContentType::POST );

		$this->assertSame( $source, $group['source']['object_id'] );
		$this->assertSame( $target, $group['translations']['tr']['object_id'] );
	}

	/**
	 * Asserts unlinking one language leaves other slots alone.
	 *
	 * @return void
	 */
	public function test_unlinking_one_language_leaves_other_slots_intact() {
		$source  = $this->post( 'Source' );
		$turkish = $this->post( 'Hedef' );
		$german  = $this->post( 'Ziel' );

		$this->send_link( ContentType::POST, $source, $turkish, 'tr' );
		$this->send_link( ContentType::POST, $source, $german, 'de' );

		$this->assertSame( 200, $this->send_unlink( ContentType::POST, $turkish, 'tr' )->get_status() );

		$group = $this->group( $source, ContentType::POST );

		$this->assertSame( array( 'en', 'de' ), array_keys( $group['translations'] ) );
		$this->assertSame( $german, $group['translations']['de']['object_id'] );
		$this->assertInstanceOf( 'WP_Post', get_post( $turkish ) );
	}

	/* --------------------------------------------------------------------
	 * Terms
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts linking a term records membership and touches no term field.
	 *
	 * @return void
	 */
	public function test_linking_a_term_records_membership_only() {
		$source = $this->term( 'News' );
		$target = $this->term( 'Haberler' );

		$before = array(
			'terms'  => $this->term_count(),
			'source' => $this->term_fingerprint( $source ),
			'target' => $this->term_fingerprint( $target ),
		);

		$response = $this->send_link( ContentType::TERM, $source, $target, 'tr', array( 'taxonomy' => 'category' ) );

		$this->assertSame( 200, $response->get_status(), $this->message( $response ) );

		$data = $response->get_data();

		$this->assertSame( ContentType::TERM, $data['object_type'] );
		$this->assertSame( $target, $data['translations']['tr']['object_id'] );

		$this->assertSame( $before['terms'], $this->term_count(), 'Linking must create no term.' );
		$this->assertSame( $before['source'], $this->term_fingerprint( $source ), 'The source term must be untouched.' );
		$this->assertSame( $before['target'], $this->term_fingerprint( $target ), 'The target term must be untouched.' );
	}

	/**
	 * Asserts a term from another taxonomy is refused.
	 *
	 * @return void
	 */
	public function test_a_term_from_another_taxonomy_is_refused() {
		$source = $this->term( 'News' );
		$tag    = self::factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'Etiket' ) );

		$response = $this->send_link( ContentType::TERM, $source, $tag, 'tr', array( 'taxonomy' => 'category' ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'mclogiora_target_not_found', $response->get_data()['code'] );
		$this->assertNull( $this->group( $source, ContentType::TERM ) );
		$this->assertSame( 'post_tag', get_term( $tag )->taxonomy, 'The term must keep its taxonomy.' );
	}

	/**
	 * Asserts linking terms without a taxonomy is refused before the workflow.
	 *
	 * @return void
	 */
	public function test_linking_terms_requires_a_taxonomy() {
		$source = $this->term( 'News' );
		$target = $this->term( 'Haberler' );

		$response = $this->send_link( ContentType::TERM, $source, $target, 'tr' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( RestErrors::MISSING_TAXONOMY, $response->get_data()['code'] );
		$this->assertNull( $this->group( $source, ContentType::TERM ) );
	}

	/**
	 * Asserts a term cannot be linked to itself.
	 *
	 * @return void
	 */
	public function test_a_term_cannot_be_linked_to_itself() {
		$source = $this->term( 'News' );

		$response = $this->send_link( ContentType::TERM, $source, $source, 'tr', array( 'taxonomy' => 'category' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'mclogiora_cannot_link_to_self', $response->get_data()['code'] );
	}

	/**
	 * Asserts a repeated term link is a stable conflict.
	 *
	 * @return void
	 */
	public function test_a_repeated_term_link_is_a_stable_conflict() {
		$source = $this->term( 'News' );
		$target = $this->term( 'Haberler' );
		$tables = $this->container->get( TableNames::class );

		$this->assertSame( 200, $this->send_link( ContentType::TERM, $source, $target, 'tr', array( 'taxonomy' => 'category' ) )->get_status() );

		$after_first = $this->row_counts( $tables );
		$response    = $this->send_link( ContentType::TERM, $source, $target, 'tr', array( 'taxonomy' => 'category' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'mclogiora_object_already_related', $response->get_data()['code'] );
		$this->assertSame( $after_first, $this->row_counts( $tables ) );
	}

	/**
	 * Asserts unlinking a term detaches membership and the term survives.
	 *
	 * @return void
	 */
	public function test_unlinking_a_term_detaches_membership_and_the_term_survives() {
		$source = $this->term( 'News' );
		$target = $this->term( 'Haberler' );

		$this->send_link( ContentType::TERM, $source, $target, 'tr', array( 'taxonomy' => 'category' ) );

		$before = array(
			'terms'       => $this->term_count(),
			'fingerprint' => $this->term_fingerprint( $target ),
		);

		$response = $this->send_unlink( ContentType::TERM, $target, 'tr' );

		$this->assertSame( 200, $response->get_status(), $this->message( $response ) );
		$this->assertTrue( $response->get_data()['detached'] );

		$this->assertInstanceOf( 'WP_Term', get_term( $target ), 'The term must still exist.' );
		$this->assertSame( $before['terms'], $this->term_count(), 'Unlinking must delete no term.' );
		$this->assertSame( $before['fingerprint'], $this->term_fingerprint( $target ), 'The term must be byte-identical.' );

		$this->assertArrayNotHasKey( 'tr', $this->group( $source, ContentType::TERM )['translations'] );
	}

	/**
	 * Asserts a repeated term unlink reports the membership absent.
	 *
	 * @return void
	 */
	public function test_a_repeated_term_unlink_reports_the_membership_absent() {
		$source = $this->term( 'News' );
		$target = $this->term( 'Haberler' );

		$this->send_link( ContentType::TERM, $source, $target, 'tr', array( 'taxonomy' => 'category' ) );
		$this->assertSame( 200, $this->send_unlink( ContentType::TERM, $target, 'tr' )->get_status() );

		$response = $this->send_unlink( ContentType::TERM, $target, 'tr' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'mclogiora_relation_item_not_found', $response->get_data()['code'] );
		$this->assertInstanceOf( 'WP_Term', get_term( $target ) );
	}

	/* --------------------------------------------------------------------
	 * Input validation
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts malformed identifiers and types never reach a workflow.
	 *
	 * @return void
	 */
	public function test_malformed_arguments_are_refused() {
		$source = $this->post( 'Source' );
		$target = $this->post( 'Hedef' );

		foreach ( array( '0', '-2', 'nonsense', '3.5', '' ) as $bad ) {
			$this->assertSame( 400, $this->send_link( ContentType::POST, $bad, $target, 'tr' )->get_status(), 'source ' . var_export( $bad, true ) );
			$this->assertSame( 400, $this->send_link( ContentType::POST, $source, $bad, 'tr' )->get_status(), 'target ' . var_export( $bad, true ) );
			$this->assertSame( 400, $this->send_unlink( ContentType::POST, $bad, 'tr' )->get_status(), 'unlink ' . var_export( $bad, true ) );
		}

		foreach ( array( 'string', 'media', 'menu', 'widget', 'wp_options', '' ) as $type ) {
			$this->assertSame( 400, $this->send_link( $type, $source, $target, 'tr' )->get_status(), 'link ' . $type );
			$this->assertSame( 400, $this->send_unlink( $type, $target, 'tr' )->get_status(), 'unlink ' . $type );
		}

		$link = $this->send_link( ContentType::POST, $source, $target, 'zz' );
		$this->assertSame( 400, $link->get_status() );
		$this->assertSame( RestErrors::UNKNOWN_LANGUAGE, $link->get_data()['code'] );

		$this->assertSame( 400, $this->send_unlink( ContentType::POST, $target, 'zz' )->get_status() );
		$this->assertNull( $this->group( $source, ContentType::POST ), 'No refused request may create a group.' );
	}

	/**
	 * Asserts a missing required argument is refused.
	 *
	 * @return void
	 */
	public function test_missing_required_arguments_are_refused() {
		$request = new WP_REST_Request( 'POST', self::NS . '/relations' );
		$request->set_param( 'object_type', ContentType::POST );

		$this->assertSame( 400, $this->server->dispatch( $request )->get_status() );

		$delete = new WP_REST_Request( 'DELETE', self::NS . '/relations' );
		$delete->set_param( 'object_type', ContentType::POST );

		$this->assertSame( 400, $this->server->dispatch( $delete )->get_status() );
	}

	/**
	 * Asserts no error body carries internal implementation detail.
	 *
	 * @return void
	 */
	public function test_error_bodies_carry_no_internal_detail() {
		$source = $this->post( 'Source' );

		$bodies = array(
			(string) wp_json_encode( $this->send_link( ContentType::POST, $source, 99999999, 'tr' )->get_data() ),
			(string) wp_json_encode( $this->send_unlink( ContentType::POST, 99999999, 'tr' )->get_data() ),
			(string) wp_json_encode( $this->send_link( ContentType::POST, $source, $source, 'tr' )->get_data() ),
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
	 * Asserts both verbs require the manage capability.
	 *
	 * @return void
	 */
	public function test_membership_writes_require_the_manage_capability() {
		$source = $this->post( 'Source' );
		$target = $this->post( 'Hedef' );

		foreach ( array( 0 => 401, $this->subscriber => 403, $this->editor => 403 ) as $user => $expected ) {
			wp_set_current_user( $user );

			$this->assertSame( $expected, $this->send_link( ContentType::POST, $source, $target, 'tr' )->get_status() );
			$this->assertSame( $expected, $this->send_unlink( ContentType::POST, $target, 'tr' )->get_status() );
		}

		wp_set_current_user( $this->administrator );

		$this->assertNull( $this->group( $source, ContentType::POST ), 'No refused link may have created a group.' );
		$this->assertSame( 200, $this->send_link( ContentType::POST, $source, $target, 'tr' )->get_status() );
	}

	/**
	 * Asserts refused callers cannot use responses as an existence oracle.
	 *
	 * @return void
	 */
	public function test_refusals_do_not_reveal_whether_objects_exist() {
		$source  = $this->post( 'Source' );
		$public  = $this->post( 'Hedef' );
		$private = $this->post( 'Gizli', 'private' );

		wp_set_current_user( $this->subscriber );

		$bodies = array(
			(string) wp_json_encode( $this->send_link( ContentType::POST, $source, $public, 'tr' )->get_data() ),
			(string) wp_json_encode( $this->send_link( ContentType::POST, $source, $private, 'tr' )->get_data() ),
			(string) wp_json_encode( $this->send_link( ContentType::POST, $source, 99999999, 'tr' )->get_data() ),
			(string) wp_json_encode( $this->send_unlink( ContentType::POST, $private, 'tr' )->get_data() ),
			(string) wp_json_encode( $this->send_unlink( ContentType::POST, 99999999, 'tr' )->get_data() ),
		);

		$this->assertCount( 1, array_unique( $bodies ), 'Every refusal must be identical whatever the object is.' );
		$this->assertStringNotContainsString( (string) $private, $bodies[0] );
	}

	/**
	 * Asserts an unauthorised caller cannot touch a private post's relation.
	 *
	 * @return void
	 */
	public function test_a_private_post_relation_is_protected() {
		$source  = $this->post( 'Source' );
		$private = $this->post( 'Gizli', 'private' );

		$this->assertSame( 200, $this->send_link( ContentType::POST, $source, $private, 'tr' )->get_status() );

		foreach ( array( 0, $this->subscriber, $this->editor ) as $user ) {
			wp_set_current_user( $user );

			$this->send_unlink( ContentType::POST, $private, 'tr' );
		}

		wp_set_current_user( $this->administrator );

		$this->assertSame(
			$private,
			$this->group( $source, ContentType::POST )['translations']['tr']['object_id'],
			'The membership must survive every unauthorised unlink.'
		);
		$this->assertSame( 'private', get_post_status( $private ) );
	}

	/* --------------------------------------------------------------------
	 * Boundaries
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts no membership change contacts a translation provider.
	 *
	 * @return void
	 */
	public function test_membership_changes_make_no_outbound_http_request() {
		$source = $this->post( 'Source' );
		$target = $this->post( 'Hedef' );
		$term   = $this->term( 'News' );
		$target_term = $this->term( 'Haberler' );

		$requests = 0;

		$counter = static function ( $preempt, $args, $url ) use ( &$requests ) {
			unset( $args, $url );
			++$requests;

			return $preempt;
		};

		add_filter( 'pre_http_request', $counter, 10, 3 );

		$this->send_link( ContentType::POST, $source, $target, 'tr' );
		$this->send_unlink( ContentType::POST, $target, 'tr' );
		$this->send_link( ContentType::TERM, $term, $target_term, 'tr', array( 'taxonomy' => 'category' ) );
		$this->send_unlink( ContentType::TERM, $target_term, 'tr' );

		remove_filter( 'pre_http_request', $counter, 10 );

		$this->assertSame( 0, $requests );
	}

	/**
	 * Asserts the read projection structure is unchanged by membership writes.
	 *
	 * @return void
	 */
	public function test_the_read_projection_structure_is_unchanged() {
		$source = $this->post( 'Source' );
		$target = $this->post( 'Hedef' );

		$this->send_link( ContentType::POST, $source, $target, 'tr' );

		$linked = $this->group( $source, ContentType::POST );

		$this->assertSame( array( 'group_key', 'object_type', 'source', 'translations' ), array_keys( $linked ) );
		$this->assertSame(
			array( 'object_id', 'object_type', 'language', 'status', 'is_source', 'url' ),
			array_keys( $linked['translations']['tr'] )
		);

		$this->send_unlink( ContentType::POST, $target, 'tr' );

		$unlinked = $this->group( $source, ContentType::POST );

		$this->assertSame( array_keys( $linked ), array_keys( $unlinked ), 'Only membership changes, never the shape.' );
	}

	/* --------------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Dispatches a link request.
	 *
	 * @param string              $object_type Object type.
	 * @param int|string          $source_id Source identifier.
	 * @param int|string          $target_id Target identifier.
	 * @param string              $language Target language.
	 * @param array<string,mixed> $extra Extra parameters.
	 * @return \WP_REST_Response
	 */
	private function send_link( $object_type, $source_id, $target_id, $language, array $extra = array() ) {
		$request = new WP_REST_Request( 'POST', self::NS . '/relations' );

		$params = array_merge(
			array(
				'object_type' => $object_type,
				'source_id'   => $source_id,
				'target_id'   => $target_id,
				'language'    => $language,
			),
			$extra
		);

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatches an unlink request.
	 *
	 * @param string     $object_type Object type.
	 * @param int|string $object_id Object identifier.
	 * @param string     $language Language code.
	 * @return \WP_REST_Response
	 */
	private function send_unlink( $object_type, $object_id, $language ) {
		$request = new WP_REST_Request( 'DELETE', self::NS . '/relations' );

		$request->set_param( 'object_type', $object_type );
		$request->set_param( 'object_id', $object_id );
		$request->set_param( 'language', $language );

		return $this->server->dispatch( $request );
	}

	/**
	 * Reads a translation group through the REST read route.
	 *
	 * @param int    $object_id Object identifier.
	 * @param string $object_type Object type.
	 * @return array<string,mixed>|null
	 */
	private function group( $object_id, $object_type ) {
		$request = new WP_REST_Request( 'GET', self::NS . '/relations' );

		$request->set_param( 'object_type', $object_type );
		$request->set_param( 'object_id', $object_id );
		$request->set_param( 'taxonomy', 'category' );

		$response = $this->server->dispatch( $request );

		return 200 === $response->get_status() ? $response->get_data() : null;
	}

	/**
	 * Returns the error message of a response, for assertion output.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @return string
	 */
	private function message( $response ) {
		$data = $response->get_data();

		return isset( $data['code'] ) ? $data['code'] . ': ' . $data['message'] : '';
	}

	/**
	 * Creates a published post.
	 *
	 * @param string $title Post title.
	 * @param string $status Post status.
	 * @return int
	 */
	private function post( $title, $status = 'publish' ) {
		return (int) self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_name'   => sanitize_title( $title ) . '-' . wp_rand( 1000, 999999 ),
				'post_status' => $status,
			)
		);
	}

	/**
	 * Creates a category term.
	 *
	 * @param string $name Term name.
	 * @return int
	 */
	private function term( $name ) {
		return (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => $name . ' ' . wp_rand( 1000, 999999 ),
			)
		);
	}

	/**
	 * Returns the fields a membership change must never alter on a post.
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
	 * Returns the fields a membership change must never alter on a term.
	 *
	 * @param int $term_id Term identifier.
	 * @return array<string,mixed>
	 */
	private function term_fingerprint( $term_id ) {
		$term = get_term( $term_id );

		return array(
			'term_id'     => (int) $term->term_id,
			'taxonomy'    => $term->taxonomy,
			'name'        => $term->name,
			'description' => $term->description,
			'slug'        => $term->slug,
			'parent'      => (int) $term->parent,
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
	 * Returns the number of terms.
	 *
	 * @return int
	 */
	private function term_count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counting rows is the assertion.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms}" );
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
	 * Asserts no REST controller can reach a repository at all.
	 *
	 * The rule that REST writes only through workflow services is easy to state
	 * and easy to erode: one constructor argument is all it takes. This checks
	 * the shape rather than the intent, so a repository handed to a controller
	 * fails here even if nothing calls it yet.
	 *
	 * @return void
	 */
	public function test_no_rest_controller_receives_a_repository() {
		$controllers = array(
			'McLogiora\\Api\\Rest\\RelationsController',
			'McLogiora\\Api\\Rest\\LanguagesController',
		);

		foreach ( $controllers as $controller ) {
			$constructor = ( new \ReflectionClass( $controller ) )->getConstructor();

			$this->assertNotNull( $constructor );

			foreach ( $constructor->getParameters() as $parameter ) {
				$type = $parameter->getType();
				$name = $type instanceof \ReflectionNamedType ? $type->getName() : '';

				$this->assertStringNotContainsString( 'Repository', $name, $controller . ' must not receive a repository.' );
				$this->assertNotSame( 'wpdb', $name, $controller . ' must not receive wpdb.' );
			}
		}

		$this->assertInstanceOf(
			TranslationWorkflowService::class,
			$this->container->get( TranslationWorkflowService::class ),
			'The workflow service is the mutation path REST wraps.'
		);
	}
}
