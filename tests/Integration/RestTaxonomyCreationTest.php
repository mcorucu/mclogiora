<?php
/**
 * REST taxonomy creation integration tests.
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
 * Qualifies term creation, which shares a route with post creation and almost
 * nothing else.
 *
 * WordPress terms behave differently enough from posts that reusing the content
 * assertions would prove very little. A term has no status to leave unpublished
 * and no revisions, but it does have a slug the workflow derives itself, a
 * parent that must never cross languages, and collision rules that resolve by
 * suffixing rather than refusing. Those are where this suite spends its
 * attention.
 *
 * The sharpest boundary is adoption. A term with the same name, or holding the
 * slug the workflow wanted, must never be handed back as the translation:
 * creation creates. Linking an existing term is a different operation with its
 * own route, and a caller has to choose it deliberately.
 */
final class RestTaxonomyCreationTest extends WP_UnitTestCase {
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
	 * Asserts the creation contract now covers terms, with real validation.
	 *
	 * @return void
	 */
	public function test_the_creation_contract_covers_terms() {
		$args = $this->create_handler_args();

		$this->assertSame(
			array( 'object_type', 'source_id', 'taxonomy', 'translated_name', 'translated_description', 'language' ),
			array_keys( $args )
		);

		$this->assertSame( array( ContentType::POST, ContentType::TERM ), $args['object_type']['enum'] );

		foreach ( array( 'object_type', 'source_id', 'language' ) as $required ) {
			$this->assertTrue( $args[ $required ]['required'], $required . ' must be required.' );
		}

		foreach ( array( 'taxonomy', 'translated_name', 'translated_description' ) as $optional ) {
			$this->assertFalse( $args[ $optional ]['required'], $optional . ' is required only for terms.' );
		}

		foreach ( $args as $name => $arg ) {
			$this->assertTrue( is_callable( $arg['validate_callback'] ), $name . ' must really validate.' );
		}

		$this->assertSame( 'sanitize_text_field', $args['translated_name']['sanitize_callback'] );
		$this->assertSame( 'sanitize_textarea_field', $args['translated_description']['sanitize_callback'] );

		foreach ( array( 'slug', 'parent', 'term_group', 'term_id', 'term_taxonomy_id', 'meta_input' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $args, 'This route is not a wp_insert_term proxy.' );
		}
	}

	/**
	 * Asserts the namespace write surface has not grown.
	 *
	 * @return void
	 */
	public function test_the_namespace_write_surface_is_unchanged() {
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
		$this->assertSame( array( 'POST', 'PUT', 'PATCH' ), array_keys( $writes[ self::NS . '/translations' ] ) );
	}

	/* --------------------------------------------------------------------
	 * The happy path
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts a creation makes exactly one term in the source's taxonomy.
	 *
	 * @return void
	 */
	public function test_creation_makes_exactly_one_term_in_the_right_taxonomy() {
		$source = $this->category( 'News' );
		$before = $this->term_count();

		$response = $this->create( $source, 'tr', 'Haberler' );

		$this->assertSame( 200, $response->get_status(), $this->message( $response ) );

		$created = $response->get_data()['translations']['tr']['object_id'];

		$this->assertSame( $before + 1, $this->term_count(), 'Exactly one term must be created.' );
		$this->assertSame( 'category', get_term( $created )->taxonomy );
		$this->assertNotSame( $source, $created );
	}

	/**
	 * Asserts the stored name and description are the caller's, read back.
	 *
	 * @return void
	 */
	public function test_the_stored_name_and_description_are_the_callers() {
		$source = $this->category( 'News' );

		$created = get_term(
			$this->create( $source, 'tr', 'Haberler', array( 'translated_description' => 'Turkce aciklama' ) )
				->get_data()['translations']['tr']['object_id']
		);

		$this->assertSame( 'Haberler', $created->name );
		$this->assertSame( 'Turkce aciklama', $created->description );
	}

	/**
	 * Asserts an omitted description stores an empty string, not the source's.
	 *
	 * @return void
	 */
	public function test_an_omitted_description_is_empty_rather_than_copied() {
		$source = self::factory()->term->create(
			array(
				'taxonomy'    => 'category',
				'name'        => 'News ' . wp_rand( 1000, 999999 ),
				'description' => 'English description',
			)
		);

		$created = get_term( $this->create( $source, 'tr', 'Haberler' )->get_data()['translations']['tr']['object_id'] );

		$this->assertSame( '', $created->description, 'Nothing is copied or invented for the description.' );
		$this->assertSame( 'English description', get_term( $source )->description );
	}

	/**
	 * Asserts the slug is the workflow's provisional language-scoped one.
	 *
	 * It is deliberately not a translated slug: it exists so WordPress does not
	 * collide with the source when both names reduce to the same slug, and so
	 * the slug manager can recognise and replace it later.
	 *
	 * @return void
	 */
	public function test_the_slug_is_the_provisional_language_scoped_one() {
		$source = $this->category( 'News' );

		$created = get_term( $this->create( $source, 'tr', 'Haberler' )->get_data()['translations']['tr']['object_id'] );

		$this->assertSame( sanitize_title( 'Haberler-tr' ), $created->slug );
		$this->assertStringEndsWith( '-tr', $created->slug );
	}

	/**
	 * Asserts an identical name in the same taxonomy still gets its own slug.
	 *
	 * @return void
	 */
	public function test_a_name_matching_the_source_still_gets_a_distinct_slug() {
		$source = $this->category( 'News' );

		$created = get_term( $this->create( $source, 'tr', get_term( $source )->name )->get_data()['translations']['tr']['object_id'] );

		$this->assertNotSame( get_term( $source )->slug, $created->slug, 'The translation must not collide with its source.' );
		$this->assertStringEndsWith( '-tr', $created->slug );
	}

	/**
	 * Asserts the relation records the new term at draft status.
	 *
	 * @return void
	 */
	public function test_the_relation_records_the_new_term() {
		$source = $this->category( 'News' );

		$data = $this->create( $source, 'tr', 'Haberler' )->get_data();

		$this->assertSame( array( 'group_key', 'object_type', 'source', 'translations' ), array_keys( $data ) );
		$this->assertSame( ContentType::TERM, $data['object_type'] );
		$this->assertSame( $source, $data['source']['object_id'] );
		$this->assertSame( TranslationStatus::ORIGINAL, $data['source']['status'] );
		$this->assertSame( TranslationStatus::DRAFT, $data['translations']['tr']['status'] );
		$this->assertFalse( $data['translations']['tr']['is_source'] );
	}

	/**
	 * Asserts the source term is untouched.
	 *
	 * @return void
	 */
	public function test_the_source_term_is_untouched() {
		$source = $this->category( 'News' );
		$before = $this->term_fingerprint( $source );

		$this->create( $source, 'tr', 'Haberler' );

		$this->assertSame( $before, $this->term_fingerprint( $source ) );
	}

	/**
	 * Asserts creation contacts no translation provider.
	 *
	 * @return void
	 */
	public function test_creation_makes_no_outbound_http_request() {
		$source   = $this->category( 'News' );
		$requests = 0;

		$counter = static function ( $preempt, $args, $url ) use ( &$requests ) {
			unset( $args, $url );
			++$requests;

			return $preempt;
		};

		add_filter( 'pre_http_request', $counter, 10, 3 );

		$this->create( $source, 'tr', 'Haberler' );
		$this->create( $source, 'de', 'Nachrichten' );

		remove_filter( 'pre_http_request', $counter, 10 );

		$this->assertSame( 0, $requests );
	}

	/* --------------------------------------------------------------------
	 * Parent semantics
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts a top-level source produces a top-level translation.
	 *
	 * @return void
	 */
	public function test_a_top_level_source_produces_a_top_level_translation() {
		$source = $this->category( 'News' );

		$created = get_term( $this->create( $source, 'tr', 'Haberler' )->get_data()['translations']['tr']['object_id'] );

		$this->assertSame( 0, (int) $created->parent );
	}

	/**
	 * Asserts an untranslated parent yields a top-level translation.
	 *
	 * Attaching the child to a parent in another language would build a
	 * mixed-language hierarchy, which is worse than a flat one.
	 *
	 * @return void
	 */
	public function test_an_untranslated_parent_yields_a_top_level_translation() {
		$parent = $this->category( 'World' );
		$child  = $this->category( 'News', $parent );

		$created = get_term( $this->create( $child, 'tr', 'Haberler' )->get_data()['translations']['tr']['object_id'] );

		$this->assertSame( 0, (int) $created->parent, 'No cross-language parent may be created.' );
		$this->assertSame( $parent, (int) get_term( $child )->parent, 'The source hierarchy is unchanged.' );
	}

	/**
	 * Asserts a translated parent is used when one exists in the same language.
	 *
	 * @return void
	 */
	public function test_a_translated_parent_is_used_when_available() {
		$parent = $this->category( 'World' );
		$child  = $this->category( 'News', $parent );

		$translated_parent = $this->create( $parent, 'tr', 'Dunya' )->get_data()['translations']['tr']['object_id'];

		$created = get_term( $this->create( $child, 'tr', 'Haberler' )->get_data()['translations']['tr']['object_id'] );

		$this->assertSame( $translated_parent, (int) $created->parent, 'The hierarchy is mirrored within one language.' );
	}

	/**
	 * Asserts a parent translated into another language is not borrowed.
	 *
	 * @return void
	 */
	public function test_a_parent_translated_into_another_language_is_not_borrowed() {
		$parent = $this->category( 'World' );
		$child  = $this->category( 'News', $parent );

		$german_parent = $this->create( $parent, 'de', 'Welt' )->get_data()['translations']['de']['object_id'];

		$created = get_term( $this->create( $child, 'tr', 'Haberler' )->get_data()['translations']['tr']['object_id'] );

		$this->assertSame( 0, (int) $created->parent );
		$this->assertNotSame( $german_parent, (int) $created->parent );
	}

	/**
	 * Asserts a non-hierarchical taxonomy invents no hierarchy.
	 *
	 * @return void
	 */
	public function test_a_non_hierarchical_taxonomy_stays_flat() {
		$source = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Etiket ' . wp_rand( 1000, 999999 ),
			)
		);

		$response = $this->create( $source, 'tr', 'Turkce etiket', array( 'taxonomy' => 'post_tag' ) );

		$this->assertSame( 200, $response->get_status(), $this->message( $response ) );

		$created = get_term( $response->get_data()['translations']['tr']['object_id'] );

		$this->assertSame( 'post_tag', $created->taxonomy );
		$this->assertSame( 0, (int) $created->parent );
	}

	/* --------------------------------------------------------------------
	 * Duplication and collisions
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts a repeated identical request creates no second term.
	 *
	 * @return void
	 */
	public function test_a_repeated_request_creates_no_second_term() {
		$source  = $this->category( 'News' );
		$first   = $this->create( $source, 'tr', 'Haberler' );
		$created = $first->get_data()['translations']['tr']['object_id'];
		$after   = $this->term_count();

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$repeat = $this->create( $source, 'tr', 'Haberler' );

			$this->assertSame( 409, $repeat->get_status() );
			$this->assertSame( 'mclogiora_translation_exists', $repeat->get_data()['code'] );
			$this->assertSame( $after, $this->term_count(), 'No repeat may create a term.' );
		}

		$group = $this->read_group( $source );

		$this->assertSame( array( 'en', 'tr' ), array_keys( $group['translations'] ) );
		$this->assertSame( $created, $group['translations']['tr']['object_id'] );
		$this->assertSame( 'Haberler', get_term( $created )->name, 'The first term is untouched.' );
	}

	/**
	 * Asserts an occupied slot creates nothing.
	 *
	 * @return void
	 */
	public function test_an_occupied_slot_creates_no_term() {
		$source   = $this->category( 'News' );
		$existing = $this->category( 'Haberler' );

		$this->assertSame( 200, $this->link( $source, $existing, 'tr' )->get_status() );

		$before   = $this->term_count();
		$response = $this->create( $source, 'tr', 'Baska' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'mclogiora_translation_exists', $response->get_data()['code'] );
		$this->assertSame( $before, $this->term_count(), 'A refused creation must create nothing.' );
		$this->assertSame( $existing, $this->read_group( $source )['translations']['tr']['object_id'] );
	}

	/**
	 * Asserts a same-named term is never adopted as the translation.
	 *
	 * This is the hard semantic boundary of the slice. The provisional
	 * language-scoped slug means WordPress does not treat a matching *name* as
	 * a duplicate, so creation succeeds -- and what matters is that it creates
	 * a genuinely new term rather than quietly handing back the one that was
	 * already there. Adopting it would be `link_existing` behaviour arriving
	 * through a route that says create.
	 *
	 * @return void
	 */
	public function test_a_same_named_term_is_never_adopted_as_the_translation() {
		$source   = $this->category( 'News' );
		$existing = self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'Haberler' ) );
		$before   = $this->term_count();

		$response = $this->create( $source, 'tr', 'Haberler' );

		$this->assertSame( 200, $response->get_status(), $this->message( $response ) );

		$created = $response->get_data()['translations']['tr']['object_id'];

		$this->assertNotSame( $existing, $created, 'The pre-existing term must never become the translation.' );
		$this->assertSame( $before + 1, $this->term_count(), 'A new term is created rather than one adopted.' );
		$this->assertSame( sanitize_title( 'Haberler-tr' ), get_term( $created )->slug );

		$untouched = get_term( $existing );

		$this->assertInstanceOf( 'WP_Term', $untouched );
		$this->assertSame( 'Haberler', $untouched->name );
		$this->assertNull(
			$this->api_group_language_for( $source, $existing ),
			'The pre-existing term must belong to no language slot of this group.'
		);
	}

	/**
	 * Asserts a taken provisional slug is made unique, never reused.
	 *
	 * WordPress resolves a slug collision by suffixing rather than refusing, so
	 * creation succeeds. What must not happen is the term already holding that
	 * slug being handed back as the translation, and it is not: a new term is
	 * created and the squatter keeps its own name and slug.
	 *
	 * @return void
	 */
	public function test_a_taken_provisional_slug_is_made_unique_not_reused() {
		$source   = $this->category( 'News' );
		$taken    = sanitize_title( 'Haberler-tr' );
		$squatter = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Unrelated',
				'slug'     => $taken,
			)
		);

		$before   = $this->term_count();
		$response = $this->create( $source, 'tr', 'Haberler' );

		$this->assertSame( 200, $response->get_status(), $this->message( $response ) );

		$created = $response->get_data()['translations']['tr']['object_id'];

		$this->assertNotSame( $squatter, $created, 'The term holding the slug must never become the translation.' );
		$this->assertSame( $before + 1, $this->term_count() );
		$this->assertNotSame( $taken, get_term( $created )->slug, 'WordPress must have made the slug unique.' );

		$untouched = get_term( $squatter );

		$this->assertSame( 'Unrelated', $untouched->name, 'The colliding term is untouched.' );
		$this->assertSame( $taken, $untouched->slug );
	}

	/**
	 * Returns the language a term occupies in a source's group, or null.
	 *
	 * @param int $source_id Source term identifier.
	 * @param int $term_id Term identifier to look for.
	 * @return string|null
	 */
	private function api_group_language_for( $source_id, $term_id ) {
		$group = $this->read_group( $source_id );

		if ( null === $group ) {
			return null;
		}

		foreach ( $group['translations'] as $code => $item ) {
			if ( (int) $item['object_id'] === (int) $term_id ) {
				return (string) $code;
			}
		}

		return null;
	}

	/**
	 * Asserts the same name in another taxonomy is not a collision.
	 *
	 * @return void
	 */
	public function test_the_same_name_in_another_taxonomy_is_not_a_collision() {
		$source = $this->category( 'News' );

		self::factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'Haberler' ) );

		$response = $this->create( $source, 'tr', 'Haberler' );

		$this->assertSame( 200, $response->get_status(), $this->message( $response ) );
		$this->assertSame( 'category', get_term( $response->get_data()['translations']['tr']['object_id'] )->taxonomy );
	}

	/* --------------------------------------------------------------------
	 * Refusals
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts an empty translated name is the domain's refusal.
	 *
	 * @return void
	 */
	public function test_an_empty_translated_name_is_refused() {
		$source = $this->category( 'News' );
		$before = $this->term_count();

		foreach ( array( '', '   ', "\t" ) as $name ) {
			$response = $this->create( $source, 'tr', $name );

			$this->assertSame( 409, $response->get_status(), var_export( $name, true ) );
			$this->assertSame( 'mclogiora_missing_translated_name', $response->get_data()['code'] );
		}

		$this->assertSame( $before, $this->term_count() );
	}

	/**
	 * Asserts a missing taxonomy is refused before the workflow.
	 *
	 * @return void
	 */
	public function test_a_missing_taxonomy_is_refused() {
		$source = $this->category( 'News' );

		$response = $this->create( $source, 'tr', 'Haberler', array( 'taxonomy' => '' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( RestErrors::MISSING_TAXONOMY, $response->get_data()['code'] );
	}

	/**
	 * Asserts a taxonomy the source does not belong to is refused.
	 *
	 * @return void
	 */
	public function test_a_mismatched_taxonomy_is_refused() {
		$source = $this->category( 'News' );
		$before = $this->term_count();

		$response = $this->create( $source, 'tr', 'Haberler', array( 'taxonomy' => 'post_tag' ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'mclogiora_source_not_found', $response->get_data()['code'] );
		$this->assertSame( $before, $this->term_count() );
	}

	/**
	 * Asserts a taxonomy that is not translatable is refused.
	 *
	 * @return void
	 */
	public function test_an_untranslatable_taxonomy_is_refused() {
		$source = $this->category( 'News' );

		$response = $this->create( $source, 'tr', 'Haberler', array( 'taxonomy' => 'nav_menu' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'mclogiora_taxonomy_not_translatable', $response->get_data()['code'] );
	}

	/**
	 * Asserts the source's own language is refused.
	 *
	 * @return void
	 */
	public function test_the_source_language_is_refused() {
		$source = $this->category( 'News' );
		$before = $this->term_count();

		$response = $this->create( $source, 'en', 'News again' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'mclogiora_same_language', $response->get_data()['code'] );
		$this->assertSame( $before, $this->term_count() );
	}

	/**
	 * Asserts malformed input never reaches the workflow.
	 *
	 * @return void
	 */
	public function test_malformed_input_is_refused() {
		$source = $this->category( 'News' );
		$before = $this->term_count();

		foreach ( array( '0', '-7', 'nonsense', '2.5', '' ) as $bad ) {
			$this->assertSame( 400, $this->create( $bad, 'tr', 'Haberler' )->get_status(), var_export( $bad, true ) );
		}

		$missing = $this->create( 99999999, 'tr', 'Haberler' );

		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( 'mclogiora_source_not_found', $missing->get_data()['code'] );

		$unknown = $this->create( $source, 'zz', 'Haberler' );

		$this->assertSame( 400, $unknown->get_status() );
		$this->assertSame( RestErrors::UNKNOWN_LANGUAGE, $unknown->get_data()['code'] );

		$this->assertSame( $before, $this->term_count(), 'No refused request may create a term.' );
	}

	/**
	 * Asserts no error body carries internal implementation detail.
	 *
	 * @return void
	 */
	public function test_error_bodies_carry_no_internal_detail() {
		$source = $this->category( 'News' );

		$bodies = array(
			(string) wp_json_encode( $this->create( $source, 'en', 'Nope' )->get_data() ),
			(string) wp_json_encode( $this->create( $source, 'tr', '' )->get_data() ),
			(string) wp_json_encode( $this->create( 99999999, 'tr', 'Haberler' )->get_data() ),
		);

		foreach ( $bodies as $body ) {
			foreach ( array( 'SELECT', 'wpdb', 'wptests_', 'McLogiora\\', '/Volumes', 'Stack trace', 'term_taxonomy_id' ) as $needle ) {
				$this->assertStringNotContainsString( $needle, $body );
			}
		}
	}

	/* --------------------------------------------------------------------
	 * Permissions
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts term creation requires the manage capability.
	 *
	 * An editor has `manage_categories` and so satisfies the workflow's term
	 * check, but not the plugin capability the route resolves first.
	 *
	 * @return void
	 */
	public function test_term_creation_requires_the_manage_capability() {
		$source = $this->category( 'News' );
		$before = $this->term_count();

		foreach ( array( 0 => 401, $this->subscriber => 403, $this->editor => 403 ) as $user => $expected ) {
			wp_set_current_user( $user );

			$this->assertSame( $expected, $this->create( $source, 'tr', 'Haberler' )->get_status() );
		}

		$this->assertSame( $before, $this->term_count(), 'A refused caller must create nothing.' );

		wp_set_current_user( $this->administrator );

		$this->assertSame( 200, $this->create( $source, 'tr', 'Haberler' )->get_status() );
	}

	/**
	 * Asserts refusals cannot be used to discover which terms exist.
	 *
	 * @return void
	 */
	public function test_refusals_do_not_reveal_whether_a_term_exists() {
		$source = $this->category( 'News' );

		wp_set_current_user( $this->subscriber );

		$bodies = array(
			(string) wp_json_encode( $this->create( $source, 'tr', 'Haberler' )->get_data() ),
			(string) wp_json_encode( $this->create( 99999999, 'tr', 'Haberler' )->get_data() ),
			(string) wp_json_encode( $this->create( $source, 'tr', 'Haberler', array( 'taxonomy' => 'nav_menu' ) )->get_data() ),
		);

		$this->assertCount( 1, array_unique( $bodies ), 'Every refusal must be identical whatever the term is.' );
		$this->assertStringNotContainsString( (string) $source, $bodies[0] );
	}

	/* --------------------------------------------------------------------
	 * Coherence
	 * ----------------------------------------------------------------- */

	/**
	 * Asserts the read routes see the creation through unchanged schemas.
	 *
	 * @return void
	 */
	public function test_the_read_routes_reflect_the_creation() {
		$source  = $this->category( 'News' );
		$created = $this->create( $source, 'tr', 'Haberler' )->get_data()['translations']['tr']['object_id'];

		$group = $this->read_group( $source );

		$this->assertSame( array( 'group_key', 'object_type', 'source', 'translations' ), array_keys( $group ) );
		$this->assertSame( $created, $group['translations']['tr']['object_id'] );

		$request = new WP_REST_Request( 'GET', self::NS . '/translations' );
		$request->set_param( 'object_type', ContentType::TERM );
		$request->set_param( 'object_id', $source );
		$request->set_param( 'language', 'tr' );
		$request->set_param( 'taxonomy', 'category' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array( 'object_type', 'object_id', 'language', 'source', 'translation' ),
			array_keys( $response->get_data() )
		);
		$this->assertSame( $created, $response->get_data()['translation']['object_id'] );
		$this->assertIsString( $response->get_data()['translation']['url'] );
	}

	/**
	 * Asserts the response is scalars only, with no term internals.
	 *
	 * @return void
	 */
	public function test_the_response_is_the_shared_scalar_projection() {
		$data = $this->create( $this->category( 'News' ), 'tr', 'Haberler' )->get_data();

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

		foreach ( array( 'edit_link', 'term_taxonomy_id', 'term_group', 'description', 'source_hash' ) as $internal ) {
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
	 * Dispatches a term creation request.
	 *
	 * @param int|string          $source_id Source term identifier.
	 * @param string              $language Target language.
	 * @param string              $name Translated name.
	 * @param array<string,mixed> $overrides Argument overrides.
	 * @return \WP_REST_Response
	 */
	private function create( $source_id, $language, $name, array $overrides = array() ) {
		$request = new WP_REST_Request( 'POST', self::NS . '/translations' );

		$params = array_merge(
			array(
				'object_type'     => ContentType::TERM,
				'source_id'       => $source_id,
				'language'        => $language,
				'taxonomy'        => 'category',
				'translated_name' => $name,
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

		$request->set_param( 'object_type', ContentType::TERM );
		$request->set_param( 'source_id', $source_id );
		$request->set_param( 'target_id', $target_id );
		$request->set_param( 'language', $language );
		$request->set_param( 'taxonomy', 'category' );

		return $this->server->dispatch( $request );
	}

	/**
	 * Reads a translation group through the REST read route.
	 *
	 * @param int $object_id Term identifier.
	 * @return array<string,mixed>|null
	 */
	private function read_group( $object_id ) {
		$request = new WP_REST_Request( 'GET', self::NS . '/relations' );

		$request->set_param( 'object_type', ContentType::TERM );
		$request->set_param( 'object_id', $object_id );
		$request->set_param( 'taxonomy', 'category' );

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
	 * Creates a category term.
	 *
	 * @param string $name Term name.
	 * @param int    $parent Parent term identifier.
	 * @return int
	 */
	private function category( $name, $parent = 0 ) {
		return (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => $name . ' ' . wp_rand( 1000, 999999 ),
				'parent'   => $parent,
			)
		);
	}

	/**
	 * Returns the fields a creation must never alter on the source term.
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
	 * Returns the number of terms.
	 *
	 * @return int
	 */
	private function term_count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counting rows is the assertion.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms}" );
	}
}
