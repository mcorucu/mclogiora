<?php
/**
 * Multilingual routing integration tests.
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
use McLogiora\Routing\RoutingModule;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Routing\TranslatedUrlGenerator;
use McLogiora\Switcher\LanguageSwitcher;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_UnitTestCase;

/**
 * Exercises routing against real WordPress rewrite behaviour.
 *
 * Rewrite rules, permalink generation, and query parsing cannot be proven with
 * doubles: they depend on WP_Rewrite, the permalink structure, and WordPress's
 * own slug uniqueness rules.
 */
final class RoutingIntegrationTest extends WP_UnitTestCase {
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

		if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
			$this->set_permalink_structure( '/%postname%/' );
		}

		$this->container->get( LanguageContextInterface::class )->reset();
	}

	/**
	 * Asserts a rewrite rule exists for each secondary language.
	 *
	 * @return void
	 */
	public function test_rewrite_rules_are_registered_for_secondary_languages() {
		$module = new RoutingModule();
		$module->register( $this->container );
		$module->register_rewrite_rules();

		$this->assertSame( array( 'tr' ), $module->prefixes(), 'The default language carries no prefix by default.' );

		$rules = get_option( 'rewrite_rules' );

		$this->assertIsArray( $rules );

		$matched = false;

		foreach ( array_keys( $rules ) as $pattern ) {
			if ( 0 === strpos( (string) $pattern, '^tr/' ) ) {
				$matched = true;
				break;
			}
		}

		$this->assertTrue( $matched, 'A language prefix rule should be registered.' );
	}

	/**
	 * Asserts the default language keeps unprefixed URLs.
	 *
	 * @return void
	 */
	public function test_default_language_urls_are_unprefixed() {
		$urls = $this->container->get( TranslatedUrlGenerator::class );

		$this->assertFalse( $urls->language_has_prefix( 'en' ) );
		$this->assertSame( home_url( '/' ), $urls->home_url_for( 'en' ) );
		$this->assertSame( home_url( '/tr/' ), $urls->home_url_for( 'tr' ) );
	}

	/**
	 * Asserts an invalid prefix never becomes the active language.
	 *
	 * @return void
	 */
	public function test_invalid_prefix_does_not_become_a_language() {
		$context = $this->container->get( LanguageContextInterface::class );

		foreach ( array( 'zz', 'de', '../etc/passwd', '<script>' ) as $candidate ) {
			$context->set_requested_code( $candidate );

			$this->assertSame( 'en', $context->current_code(), "Rejected prefix: {$candidate}" );
			$this->assertTrue( $context->is_default() );
		}
	}

	/**
	 * Asserts a translated post resolves to its own slug and prefix.
	 *
	 * @return void
	 */
	public function test_translated_post_url_uses_its_own_slug() {
		$source_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'About us',
				'post_name'   => 'about-us',
				'post_status' => 'publish',
			)
		);

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $source_id, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		wp_update_post(
			array(
				'ID'          => $created['post_id'],
				'post_name'   => 'hakkimizda',
				'post_status' => 'publish',
			)
		);

		$url = $this->container->get( TranslatedUrlGenerator::class )->post_url( $source_id, 'tr' );

		$this->assertIsString( $url );
		$this->assertStringContainsString( '/tr/', $url );
		$this->assertStringContainsString( 'hakkimizda', $url );
		$this->assertStringNotContainsString( 'about-us', $url, 'The translated URL must use the translated slug.' );
	}

	/**
	 * Asserts an untranslated post yields no URL at all.
	 *
	 * @return void
	 */
	public function test_untranslated_post_has_no_translated_url() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertNull(
			$this->container->get( TranslatedUrlGenerator::class )->post_url( $post_id, 'tr' ),
			'A missing translation must never produce a URL.'
		);
	}

	/**
	 * Asserts a hierarchical page uses the translated hierarchy.
	 *
	 * @return void
	 */
	public function test_hierarchical_page_uses_translated_ancestors() {
		$parent = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'company',
				'post_status' => 'publish',
			)
		);

		$child = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'team',
				'post_parent' => $parent,
				'post_status' => 'publish',
			)
		);

		$workflow = $this->container->get( TranslationWorkflowService::class )->content();

		$parent_tr = $workflow->create_translation( $parent, 'tr' );
		$child_tr  = $workflow->create_translation( $child, 'tr' );

		$this->assertIsArray( $parent_tr, is_wp_error( $parent_tr ) ? $parent_tr->get_error_message() : '' );
		$this->assertIsArray( $child_tr, is_wp_error( $child_tr ) ? $child_tr->get_error_message() : '' );

		wp_update_post(
			array(
				'ID'          => $parent_tr['post_id'],
				'post_name'   => 'sirket',
				'post_status' => 'publish',
			)
		);

		wp_update_post(
			array(
				'ID'          => $child_tr['post_id'],
				'post_name'   => 'ekip',
				'post_parent' => $parent_tr['post_id'],
				'post_status' => 'publish',
			)
		);

		$url = $this->container->get( TranslatedUrlGenerator::class )->post_url( $child, 'tr' );

		$this->assertIsString( $url );
		$this->assertStringContainsString( 'sirket/ekip', $url, 'A translated child must sit under its translated parent.' );
		$this->assertStringNotContainsString( 'company', $url );
	}

	/**
	 * Asserts a translated term uses its own slug.
	 *
	 * @return void
	 */
	public function test_translated_term_url_uses_its_own_slug() {
		$source = self::factory()->term->create_and_get( array( 'taxonomy' => 'category', 'name' => 'News' ) );

		$created = $this->container->get( TranslationWorkflowService::class )
			->taxonomy()
			->create_translation( $source->term_id, 'category', 'tr', 'Haberler' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		wp_update_term( $created['term_id'], 'category', array( 'slug' => 'haberler' ) );

		$url = $this->container->get( TranslatedUrlGenerator::class )->term_url( $source->term_id, 'category', 'tr' );

		$this->assertIsString( $url );
		$this->assertStringContainsString( '/tr/', $url );
		$this->assertStringContainsString( 'haberler', $url, 'The provisional slug should be replaceable by a real one.' );
	}

	/**
	 * Asserts WordPress term slug uniqueness is respected, not bypassed.
	 *
	 * @return void
	 */
	public function test_term_slug_collisions_follow_wordpress_rules() {
		self::factory()->term->create( array( 'taxonomy' => 'category', 'slug' => 'haberler' ) );

		$source = self::factory()->term->create_and_get( array( 'taxonomy' => 'category', 'name' => 'Updates' ) );

		$created = $this->container->get( TranslationWorkflowService::class )
			->taxonomy()
			->create_translation( $source->term_id, 'category', 'tr', 'Haberler' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		$term = get_term( $created['term_id'], 'category' );

		$this->assertInstanceOf( \WP_Term::class, $term );
		$this->assertNotSame( 'haberler', $term->slug, 'WordPress uniqueness must not be bypassed.' );
	}

	/**
	 * Asserts a custom post type permalink is prefixed.
	 *
	 * @return void
	 */
	public function test_custom_post_type_permalink_is_prefixed() {
		register_post_type(
			'guide',
			array(
				'public'      => true,
				'rewrite'     => array( 'slug' => 'guides' ),
				'has_archive' => true,
			)
		);

		$source_id = self::factory()->post->create(
			array(
				'post_type'   => 'guide',
				'post_name'   => 'setup',
				'post_status' => 'publish',
			)
		);

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $source_id, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		wp_update_post( array( 'ID' => $created['post_id'], 'post_status' => 'publish' ) );

		$url = $this->container->get( TranslatedUrlGenerator::class )->post_url( $source_id, 'tr' );

		$this->assertIsString( $url );
		$this->assertStringContainsString( '/tr/', $url );
		$this->assertStringContainsString( 'guides', $url, 'The custom post type rewrite base must survive.' );

		unregister_post_type( 'guide' );
	}

	/**
	 * Asserts ordinary requests never flush rewrite rules.
	 *
	 * @return void
	 */
	public function test_ordinary_requests_do_not_flush_rewrite_rules() {
		$module = new RoutingModule();
		$module->register( $this->container );

		// First call establishes the fingerprint.
		$module->maybe_flush_rewrite_rules();

		$stored = get_option( RoutingModule::RULES_HASH );

		$this->assertNotEmpty( $stored );

		$flushes = 0;
		$counter = static function () use ( &$flushes ) {
			++$flushes;
		};

		add_action( 'generate_rewrite_rules', $counter );

		for ( $i = 0; $i < 5; $i++ ) {
			$module->maybe_flush_rewrite_rules();
		}

		remove_action( 'generate_rewrite_rules', $counter );

		$this->assertSame( 0, $flushes, 'Rewrite rules must not be rebuilt on ordinary requests.' );
		$this->assertSame( $stored, get_option( RoutingModule::RULES_HASH ) );
	}

	/**
	 * Asserts a language configuration change triggers exactly one rebuild.
	 *
	 * @return void
	 */
	public function test_language_change_triggers_a_single_rebuild() {
		$module = new RoutingModule();
		$module->register( $this->container );
		$module->maybe_flush_rewrite_rules();

		RoutingModule::invalidate_rules();

		$this->assertSame( '', (string) get_option( RoutingModule::RULES_HASH, '' ) );

		$module->maybe_flush_rewrite_rules();

		$this->assertNotEmpty( get_option( RoutingModule::RULES_HASH ) );
	}

	/**
	 * Asserts display-only settings changes never invalidate rewrite rules.
	 *
	 * @return void
	 */
	public function test_display_settings_do_not_invalidate_rewrite_rules() {
		$settings = $this->container->get( RoutingSettings::class );

		$result = $settings->save(
			array(
				'url_strategy'       => 'directory',
				'switcher_style'     => 'dropdown',
				'switcher_show_name' => true,
			)
		);

		$this->assertFalse( $result['routing_changed'], 'Changing a switcher style must not rebuild rewrite rules.' );

		$changed = $settings->save(
			array(
				'url_strategy'            => 'directory',
				'default_language_prefix' => true,
				'switcher_style'          => 'dropdown',
				'switcher_show_name'      => true,
			)
		);

		$this->assertTrue( $changed['routing_changed'], 'Changing the URL shape must rebuild rewrite rules.' );
	}

	/**
	 * Asserts the switcher offers a real URL only when a translation exists.
	 *
	 * @return void
	 */
	public function test_switcher_offers_only_real_translations() {
		$source_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->go_to( get_permalink( $source_id ) );

		$switcher = $this->container->get( LanguageSwitcher::class );
		$codes    = array_column( $switcher->items(), 'code' );

		$this->assertNotContains( 'tr', $codes, 'An untranslated post must not offer a Turkish link.' );

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $source_id, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		wp_update_post( array( 'ID' => $created['post_id'], 'post_status' => 'publish' ) );

		$this->container->get( TranslatedUrlGenerator::class )->reset();
		$this->go_to( get_permalink( $source_id ) );

		$items = $this->container->get( LanguageSwitcher::class )->items();
		$by    = array_column( $items, 'url', 'code' );

		$this->assertArrayHasKey( 'tr', $by );
		$this->assertStringContainsString( '/tr/', (string) $by['tr'] );
	}

	/**
	 * Asserts the front page resolves through translation relations.
	 *
	 * @return void
	 */
	public function test_front_page_translation_is_resolved() {
		$front = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Home',
				'post_status' => 'publish',
			)
		);

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $front, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		wp_update_post( array( 'ID' => $created['post_id'], 'post_status' => 'publish' ) );

		$url = $this->container->get( TranslatedUrlGenerator::class )->post_url( $front, 'tr' );

		$this->assertIsString( $url, 'A translated front page must be reachable.' );
		$this->assertStringContainsString( '/tr/', $url );

		update_option( 'show_on_front', 'posts' );
		delete_option( 'page_on_front' );
	}
}
