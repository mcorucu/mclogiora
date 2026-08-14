<?php
/**
 * WordPress core sitemap integration tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Database\Installer;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Routing\RoutingModule;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_UnitTestCase;

/**
 * Proves the core sitemap lists each object under its own language.
 *
 * This is the failure the integration guards against. Core builds sitemap
 * entries with `get_permalink()`, and Phase 12's permalink filter prefixes URLs
 * with the language of the *current request*. A sitemap request carries no
 * language prefix, so without this integration every Turkish post would be
 * listed at its unprefixed URL and search engines would be handed a list of
 * addresses that resolve to the wrong language or to nothing.
 */
final class SitemapIntegrationTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Sets up an installed, two-language site with pretty permalinks.
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

		delete_option( RoutingSettings::OPTION_NAME );

		if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
			$this->set_permalink_structure( '/%postname%/' );
		}

		create_initial_taxonomies();

		$context = $this->container->get( LanguageContextInterface::class );
		$context->reset();
		$context->set_requested_code( '' );

		$module = new RoutingModule();
		$module->register( $this->container );
		$module->register_rewrite_rules();
		$module->maybe_flush_rewrite_rules();
	}

	/**
	 * Restores the language context.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->container->get( LanguageContextInterface::class )->set_requested_code( '' );

		parent::tear_down();
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
	 * Creates a translated page pair.
	 *
	 * @return array{source:int,target:int}
	 */
	private function translated_page() {
		$source = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'About us',
				'post_name'   => 'about-us',
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
				'post_name'   => 'hakkimizda',
				'post_status' => 'publish',
			)
		);

		return array(
			'source' => (int) $source,
			'target' => (int) $created['post_id'],
		);
	}

	/**
	 * Asserts a translated post is listed under its own language prefix.
	 *
	 * @return void
	 */
	public function test_translated_post_is_listed_under_its_own_language() {
		$pages = $this->translated_page();

		$entry = apply_filters(
			'wp_sitemaps_posts_entry',
			array( 'loc' => get_permalink( $pages['target'] ) ),
			get_post( $pages['target'] ),
			'page'
		);

		$this->assertSame( '/tr/hakkimizda/', wp_parse_url( $entry['loc'], PHP_URL_PATH ) );
	}

	/**
	 * Asserts a default-language post keeps its unprefixed URL.
	 *
	 * @return void
	 */
	public function test_default_language_post_is_listed_unprefixed() {
		$pages = $this->translated_page();

		$entry = apply_filters(
			'wp_sitemaps_posts_entry',
			array( 'loc' => get_permalink( $pages['source'] ) ),
			get_post( $pages['source'] ),
			'page'
		);

		$this->assertSame( '/about-us/', wp_parse_url( $entry['loc'], PHP_URL_PATH ) );
	}

	/**
	 * Asserts an untranslated post is left completely alone.
	 *
	 * @return void
	 */
	public function test_untranslated_post_is_left_alone() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'standalone',
				'post_status' => 'publish',
			)
		);

		$entry = apply_filters(
			'wp_sitemaps_posts_entry',
			array( 'loc' => get_permalink( $post_id ) ),
			get_post( $post_id ),
			'page'
		);

		$this->assertSame( '/standalone/', wp_parse_url( $entry['loc'], PHP_URL_PATH ) );
	}

	/**
	 * Asserts the URL the sitemap advertises actually resolves.
	 *
	 * Producing the right-looking URL is only half of it. Until Phase 13.1 the
	 * sitemap listed `/tr/<slug>/` for every translated post while that exact
	 * URL returned a 404, so the plugin was submitting broken addresses to
	 * search engines. Listing a URL and serving it are asserted together here
	 * so they cannot drift apart again.
	 *
	 * @return void
	 */
	public function test_listed_translated_post_url_resolves() {
		/*
		 * A post rather than a page on purpose. Under `/%postname%/` the page
		 * rule is a catch-all that resolves a page correctly and a post not at
		 * all, so a page fixture would have passed throughout the defect.
		 */
		$source = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Field notes',
				'post_name'   => 'field-notes',
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
				'post_name'   => 'saha-notlari',
				'post_status' => 'publish',
			)
		);

		$target = (int) $created['post_id'];

		$entry = apply_filters(
			'wp_sitemaps_posts_entry',
			array( 'loc' => get_permalink( $target ) ),
			get_post( $target ),
			'post'
		);

		$this->assertSame( '/tr/saha-notlari/', wp_parse_url( $entry['loc'], PHP_URL_PATH ) );

		$this->activate_routing();
		$this->go_to( $entry['loc'] );

		$this->assertFalse( is_404(), "The sitemap advertises {$entry['loc']}, which does not resolve." );
		$this->assertSame( $target, get_queried_object_id() );
	}

	/**
	 * Asserts a translated term is listed under its own language prefix.
	 *
	 * @return void
	 */
	public function test_translated_term_is_listed_under_its_own_language() {
		$source = self::factory()->term->create_and_get( array( 'taxonomy' => 'category', 'name' => 'News' ) );

		$created = $this->container->get( TranslationWorkflowService::class )
			->taxonomy()
			->create_translation( $source->term_id, 'category', 'tr', 'Haberler' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		wp_update_term( $created['term_id'], 'category', array( 'slug' => 'haberler' ) );

		$entry = apply_filters(
			'wp_sitemaps_taxonomies_entry',
			array( 'loc' => get_term_link( (int) $created['term_id'], 'category' ) ),
			get_term( (int) $created['term_id'], 'category' ),
			'category'
		);

		$this->assertStringContainsString( '/tr/', $entry['loc'] );
		$this->assertStringContainsString( 'haberler', $entry['loc'] );
	}

	/**
	 * Asserts entry filtering never removes or renames sitemap keys.
	 *
	 * Core validates the entry shape, so an integration that corrects a URL
	 * must not quietly change the contract while doing it.
	 *
	 * @return void
	 */
	public function test_entry_shape_is_preserved() {
		$pages = $this->translated_page();
		$input = array(
			'loc'     => get_permalink( $pages['target'] ),
			'lastmod' => '2026-01-01T00:00:00+00:00',
		);

		$entry = apply_filters( 'wp_sitemaps_posts_entry', $input, get_post( $pages['target'] ), 'page' );

		$this->assertSame( array( 'loc', 'lastmod' ), array_keys( $entry ) );
		$this->assertSame( '2026-01-01T00:00:00+00:00', $entry['lastmod'] );
	}

	/**
	 * Asserts the rendered sitemap is well-formed XML with translated URLs.
	 *
	 * @return void
	 */
	public function test_rendered_sitemap_is_valid_xml() {
		$pages = $this->translated_page();

		$provider = new \WP_Sitemaps_Posts();
		$entries  = $provider->get_url_list( 1, 'page' );

		$this->assertNotEmpty( $entries );

		$renderer = new \WP_Sitemaps_Renderer();

		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();

		$xml    = $renderer->get_sitemap_xml( $entries );
		$loaded = simplexml_load_string( $xml );
		$errors = libxml_get_errors();

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$this->assertSame( array(), $errors, 'The sitemap must remain valid XML.' );
		$this->assertNotFalse( $loaded );
		$this->assertStringContainsString( '/tr/hakkimizda/', $xml );

		unset( $pages );
	}

	/**
	 * Asserts mcLogiora adds no second sitemap index.
	 *
	 * Translated content is ordinary WordPress content and is already listed
	 * by the core providers.
	 *
	 * @return void
	 */
	public function test_no_additional_sitemap_provider_is_registered() {
		$providers = wp_sitemaps_get_server()->registry->get_providers();

		$this->assertSame(
			array( 'posts', 'taxonomies', 'users' ),
			array_keys( $providers ),
			'mcLogiora must not add a parallel sitemap.'
		);
	}
}
