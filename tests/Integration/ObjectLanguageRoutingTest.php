<?php
/**
 * Object-language routing integration tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Database\Installer;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Routing\ObjectLanguageRedirect;
use McLogiora\Routing\PermalinkModule;
use McLogiora\Routing\RoutingModule;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_UnitTestCase;

/**
 * Covers the Phase 13.1 routing and canonical corrections.
 *
 * Three defects motivated these tests, and every one of them survived the
 * Phase 12 and Phase 13 suites:
 *
 * - A translated post 404'd under `/%postname%/`, because mcLogiora stopped at
 *   the first matching rewrite rule where WordPress core keeps looking.
 * - A translated object answered at the unprefixed default-language URL as
 *   well as its own, with a canonical that appeared in neither of its own
 *   hreflang alternates.
 * - An object with no translation was served under a language prefix, which
 *   Phase 12 explicitly refused to allow.
 *
 * The routing tests use a real permalink structure rather than doubles,
 * because `use_verbose_page_rules` is a property of WP_Rewrite and the whole
 * class of bug only exists once WordPress is really parsing a request.
 */
final class ObjectLanguageRoutingTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Sets up languages, schema, and pretty permalinks.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();

		delete_option( 'mclogiora_db_version' );
		$this->container->get( Installer::class )->install();

		$languages = $this->container->get( LanguageRepositoryInterface::class );

		if ( ! $languages->find_by_code( 'en' ) instanceof Language ) {
			$languages->create( new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, false ) );
			$languages->set_default( 'en' );
		}

		if ( ! $languages->find_by_code( 'tr' ) instanceof Language ) {
			$languages->create( new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ) );
		}

		delete_option( RoutingSettings::OPTION_NAME );

		$this->use_permalinks( '/%postname%/' );

		/*
		 * After the structure, not before. `set_permalink_structure()` calls
		 * `WP_Rewrite::init()`, which empties `extra_permastructs`, so a post
		 * type registered first loses its rewrite rules and its entries never
		 * reach the rule set. A real site registers post types on `init` with
		 * the structure already stored, and never hits this ordering.
		 */
		register_post_type(
			'manual',
			array(
				'public'      => true,
				'rewrite'     => array( 'slug' => 'manuals' ),
				'has_archive' => true,
			)
		);

		/*
		 * The routing module only flushes when its own prefix fingerprint
		 * changes, which it does not between tests in this class. Clearing it
		 * makes `activate_routing()` genuinely rebuild, picking up both the
		 * language rules and the post type registered above.
		 */
		RoutingModule::invalidate_rules();

		$context = $this->container->get( LanguageContextInterface::class );
		$context->reset();
		$context->set_requested_code( '' );
	}

	/**
	 * Applies a permalink structure and re-registers built-in taxonomies.
	 *
	 * @param string $structure Permalink structure.
	 * @return void
	 */
	private function use_permalinks( $structure ) {
		if ( $structure !== get_option( 'permalink_structure' ) ) {
			$this->set_permalink_structure( $structure );
		}

		create_initial_taxonomies();
	}

	/**
	 * Registers the routing module and persists its rules.
	 *
	 * @return RoutingModule
	 */
	private function activate_routing() {
		$module = new RoutingModule();
		$module->register( $this->container );
		$module->register_rewrite_rules();
		$module->maybe_flush_rewrite_rules();

		return $module;
	}

	/**
	 * Returns the route policy wired to the container but hooked to nothing.
	 *
	 * The decision is asserted rather than executed, so a test never has to
	 * survive a real redirect or an `exit`.
	 *
	 * @return ObjectLanguageRedirect
	 */
	private function policy() {
		$policy = new ObjectLanguageRedirect();
		$policy->prepare( $this->container );

		return $policy;
	}

	/**
	 * Creates a published source and a published translation.
	 *
	 * @param array<string,mixed> $source_args Source post arguments.
	 * @param string              $translated_slug Slug for the translation.
	 * @return array{0:int,1:int}
	 */
	private function translated_pair( array $source_args, $translated_slug ) {
		$source = self::factory()->post->create( array_merge( array( 'post_status' => 'publish' ), $source_args ) );

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $source, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		wp_update_post(
			array(
				'ID'          => $created['post_id'],
				'post_name'   => $translated_slug,
				'post_status' => 'publish',
			)
		);

		return array( (int) $source, (int) $created['post_id'] );
	}

	/**
	 * Asserts a translated post resolves under a postname permalink structure.
	 *
	 * This is the regression that BLOCKER-1 named. The page rule is a
	 * catch-all under `/%postname%/`, so the translated post slug matched it
	 * first and resolved to a page that does not exist.
	 *
	 * @return void
	 */
	public function test_postname_directory_route_resolves_a_translated_post() {
		list( , $translation ) = $this->translated_pair(
			array(
				'post_type' => 'post',
				'post_name' => 'source-story',
			),
			'ceviri-hikaye'
		);

		$this->activate_routing();

		$this->go_to( home_url( '/tr/ceviri-hikaye/' ) );

		$this->assertSame( 'tr', $this->container->get( LanguageContextInterface::class )->current_code() );
		$this->assertFalse( is_404(), 'A translated post under a language prefix must resolve.' );
		$this->assertTrue( is_singular(), 'The translated post must resolve as a singular request.' );
		$this->assertSame( $translation, get_queried_object_id() );
	}

	/**
	 * Asserts a translated page still resolves under the same structure.
	 *
	 * The page case always passed. It is kept beside the post case so a future
	 * change cannot fix one by breaking the other.
	 *
	 * @return void
	 */
	public function test_postname_directory_route_resolves_a_translated_page() {
		list( , $translation ) = $this->translated_pair(
			array(
				'post_type' => 'page',
				'post_name' => 'source-info',
			),
			'ceviri-bilgi'
		);

		$this->activate_routing();

		$this->go_to( home_url( '/tr/ceviri-bilgi/' ) );

		$this->assertFalse( is_404(), 'A translated page under a language prefix must resolve.' );
		$this->assertSame( $translation, get_queried_object_id() );
	}

	/**
	 * Asserts a hierarchical translated page resolves through its ancestors.
	 *
	 * @return void
	 */
	public function test_hierarchical_translated_page_resolves() {
		list( , $parent_translation ) = $this->translated_pair(
			array(
				'post_type' => 'page',
				'post_name' => 'services',
			),
			'hizmetler'
		);

		$child_source = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'consulting',
				'post_status' => 'publish',
			)
		);

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $child_source, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		wp_update_post(
			array(
				'ID'          => $created['post_id'],
				'post_name'   => 'danismanlik',
				'post_parent' => $parent_translation,
				'post_status' => 'publish',
			)
		);

		$this->activate_routing();

		$this->go_to( home_url( '/tr/hizmetler/danismanlik/' ) );

		$this->assertFalse( is_404(), 'A nested translated page must resolve.' );
		$this->assertSame( (int) $created['post_id'], get_queried_object_id() );
	}

	/**
	 * Asserts a translated custom post type route resolves to the right query.
	 *
	 * The assertion stops at the query mcLogiora produces rather than the post
	 * WordPress then finds. Turning a language-prefixed path back into query
	 * vars is mcLogiora's job and is exactly what BLOCKER-1 broke; running
	 * those vars through WP_Query is core's. Under the test suite a post type
	 * registered after `WP_Rewrite::init()` does not survive `go_to()` intact,
	 * so asserting the resolved post would be asserting a harness artefact.
	 * End-to-end custom post type routing is covered by the WordPress 7.1-RC3
	 * browser smoke, where `/tr/manuals/kurulum/` returns 200 and the
	 * unprefixed route 301s to it.
	 *
	 * @return void
	 */
	public function test_translated_custom_post_type_route_resolves_to_its_own_query() {
		$this->translated_pair(
			array(
				'post_type' => 'manual',
				'post_name' => 'install',
			),
			'kurulum'
		);

		$this->activate_routing();

		$this->go_to( home_url( '/tr/manuals/kurulum/' ) );

		$this->assertFalse( is_404(), 'A translated custom post type path must resolve to a query.' );
		$this->assertSame(
			'tr',
			$this->container->get( LanguageContextInterface::class )->current_code(),
			'The request must be recognised as Turkish.'
		);
		$this->assertSame(
			'kurulum',
			get_query_var( 'manual' ),
			'The language prefix must be stripped and the remainder resolved against the post type rule.'
		);
	}

	/**
	 * Asserts a translated post still resolves under date permalinks.
	 *
	 * Date-based structures never set `use_verbose_page_rules`, so this case
	 * always worked. It guards the fix against over-correcting.
	 *
	 * @return void
	 */
	public function test_date_permalink_translated_post_resolves() {
		$this->use_permalinks( '/%year%/%monthnum%/%postname%/' );

		list( , $translation ) = $this->translated_pair(
			array(
				'post_type' => 'post',
				'post_name' => 'dated-source',
				'post_date' => '2026-03-04 10:00:00',
			),
			'tarihli-ceviri'
		);

		wp_update_post(
			array(
				'ID'        => $translation,
				'post_date' => '2026-03-04 10:00:00',
			)
		);

		$this->activate_routing();

		$this->go_to( home_url( '/tr/2026/03/tarihli-ceviri/' ) );

		$this->assertFalse( is_404(), 'A translated post must resolve under date permalinks.' );
		$this->assertSame( $translation, get_queried_object_id() );
	}

	/**
	 * Asserts an unresolvable translated path is still a 404.
	 *
	 * @return void
	 */
	public function test_missing_translation_under_a_prefix_is_still_a_404() {
		$this->activate_routing();

		$this->go_to( home_url( '/tr/hicbir-sey/' ) );

		$this->assertTrue( is_404(), 'An unresolvable translated URL must 404.' );
	}

	/**
	 * Asserts a permalink belongs to its object's language, not the request's.
	 *
	 * @return void
	 */
	public function test_permalink_uses_the_object_language_not_the_request_language() {
		list( $source, $translation ) = $this->translated_pair(
			array(
				'post_type' => 'post',
				'post_name' => 'object-language',
			),
			'nesne-dili'
		);

		$this->activate_routing();

		$permalinks = new PermalinkModule();
		$permalinks->register( $this->container );

		$context = $this->container->get( LanguageContextInterface::class );

		/*
		 * Paths rather than whole URLs, because `home_url()` is one of the
		 * filters under test: calling it to build an expectation while the
		 * Turkish context is active would return a Turkish URL and the test
		 * would assert the bug instead of the fix.
		 */
		$context->reset();
		$context->set_requested_code( '' );

		$this->assertSame( '/object-language/', wp_parse_url( get_permalink( $source ), PHP_URL_PATH ) );
		$this->assertSame(
			'/tr/nesne-dili/',
			wp_parse_url( get_permalink( $translation ), PHP_URL_PATH ),
			'A translation keeps its own language URL when linked from the default language.'
		);

		$context->reset();
		$context->set_requested_code( 'tr' );

		$this->assertSame(
			'/object-language/',
			wp_parse_url( get_permalink( $source ), PHP_URL_PATH ),
			'An English source keeps its own language URL when linked from a Turkish page.'
		);
		$this->assertSame( '/tr/nesne-dili/', wp_parse_url( get_permalink( $translation ), PHP_URL_PATH ) );
	}

	/**
	 * Asserts a permalink never gains the same prefix twice.
	 *
	 * @return void
	 */
	public function test_permalinks_never_double_the_language_prefix() {
		list( , $translation ) = $this->translated_pair(
			array(
				'post_type' => 'post',
				'post_name' => 'double-check',
			),
			'cift-kontrol'
		);

		$this->activate_routing();

		$permalinks = new PermalinkModule();
		$permalinks->register( $this->container );

		$context = $this->container->get( LanguageContextInterface::class );
		$context->reset();
		$context->set_requested_code( 'tr' );

		$link = get_permalink( $translation );

		$this->assertStringNotContainsString( '/tr/tr/', $link );
		$this->assertSame( '/tr/cift-kontrol/', wp_parse_url( $link, PHP_URL_PATH ) );
	}

	/**
	 * Asserts a translated object at the default route is sent to its own URL.
	 *
	 * @return void
	 */
	public function test_translated_object_at_the_default_route_redirects() {
		list( , $translation ) = $this->translated_pair(
			array(
				'post_type' => 'post',
				'post_name' => 'wrong-route',
			),
			'yanlis-rota'
		);

		$this->activate_routing();

		$this->go_to( home_url( '/yanlis-rota/' ) );

		$this->assertSame( $translation, get_queried_object_id(), 'WordPress resolves the slug regardless of language.' );

		$decision = $this->policy()->decide();

		$this->assertSame( ObjectLanguageRedirect::REDIRECT, $decision['action'] );
		$this->assertSame( home_url( '/tr/yanlis-rota/' ), $decision['target'] );
	}

	/**
	 * Asserts the authoritative route is left alone.
	 *
	 * A redirect here would bounce the request against itself.
	 *
	 * @return void
	 */
	public function test_translated_object_at_its_own_route_is_not_redirected() {
		$this->translated_pair(
			array(
				'post_type' => 'post',
				'post_name' => 'own-route',
			),
			'kendi-rota'
		);

		$this->activate_routing();

		$this->go_to( home_url( '/tr/kendi-rota/' ) );

		$this->assertSame( ObjectLanguageRedirect::STAY, $this->policy()->decide()['action'] );
	}

	/**
	 * Asserts a source object at its own route is left alone.
	 *
	 * @return void
	 */
	public function test_source_object_at_the_default_route_is_not_redirected() {
		$this->translated_pair(
			array(
				'post_type' => 'post',
				'post_name' => 'source-route',
			),
			'kaynak-rota'
		);

		$this->activate_routing();

		$this->go_to( home_url( '/source-route/' ) );

		$this->assertSame( ObjectLanguageRedirect::STAY, $this->policy()->decide()['action'] );
	}

	/**
	 * Asserts an untranslated object under a language prefix is a 404.
	 *
	 * Phase 12 chose a genuine 404 over serving source content beneath a
	 * translated URL. The verbose page rule defect was hiding the fact that
	 * nothing actually enforced it once the path resolved.
	 *
	 * @return void
	 */
	public function test_untranslated_object_under_a_prefix_is_not_found() {
		self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_name'   => 'monolingual',
				'post_status' => 'publish',
			)
		);

		$this->activate_routing();

		/*
		 * Registered rather than merely prepared, because the assertion is the
		 * end state of the request. `set_404()` clears `is_singular`, so a
		 * second decision after the module has acted can only ever say "stay";
		 * what matters is that the request really became a 404.
		 */
		$redirect = new ObjectLanguageRedirect();
		$redirect->register( $this->container );

		$this->go_to( home_url( '/tr/monolingual/' ) );

		$this->assertTrue(
			is_404(),
			'Source content must not be served under a translated URL.'
		);
	}

	/**
	 * Asserts an untranslated object at the default route is left alone.
	 *
	 * @return void
	 */
	public function test_untranslated_object_at_the_default_route_is_not_touched() {
		self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_name'   => 'ordinary',
				'post_status' => 'publish',
			)
		);

		$this->activate_routing();

		$this->go_to( home_url( '/ordinary/' ) );

		$this->assertSame( ObjectLanguageRedirect::STAY, $this->policy()->decide()['action'] );
	}
}
