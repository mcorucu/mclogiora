<?php
/**
 * Public developer API integration tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Database\Installer;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Routing\TranslatedUrlGenerator;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_UnitTestCase;

/**
 * Qualifies the published functions against real persistence.
 *
 * The unit suite pins the array shapes over doubles. What it cannot prove is
 * that the functions are actually loaded on a real site, that they read the
 * database rather than a fixture, and that the URL they return is the same URL
 * the rest of the plugin emits for the same object. Those are the claims the
 * contract makes to a theme author, so they are proven here.
 */
final class PublicApiIntegrationTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Installs the schema, two languages, and pretty permalinks.
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
	}

	/**
	 * Asserts the plugin bootstrap really defines the published functions.
	 *
	 * @return void
	 */
	public function test_the_published_functions_are_defined_on_a_real_site() {
		foreach (
			array(
				'mclogiora_get_languages',
				'mclogiora_get_default_language',
				'mclogiora_get_current_language',
				'mclogiora_get_translation',
				'mclogiora_get_translation_group',
				'mclogiora_get_language_url',
				'mclogiora_language_switcher',
				'mclogiora_the_language_switcher',
				'mclogiora_current_language',
			) as $function
		) {
			$this->assertTrue( function_exists( $function ), $function . '() must be loaded by the plugin bootstrap.' );
		}
	}

	/**
	 * Asserts languages are read back from the database.
	 *
	 * @return void
	 */
	public function test_languages_are_read_from_real_persistence() {
		$active = mclogiora_get_languages();
		$codes  = wp_list_pluck( $active, 'code' );

		$this->assertSame( array( 'en', 'tr' ), $codes, 'Inactive languages are excluded by default.' );

		$all = wp_list_pluck( mclogiora_get_languages( array( 'status' => 'all' ) ), 'code' );

		$this->assertSame( array( 'en', 'tr', 'de' ), $all );

		$default = mclogiora_get_default_language();

		$this->assertIsArray( $default );
		$this->assertSame( 'en', $default['code'] );
		$this->assertTrue( $default['is_default'] );
		$this->assertSame( 'en-US', $default['tag'] );
	}

	/**
	 * Asserts the current language is the request language.
	 *
	 * @return void
	 */
	public function test_current_language_follows_the_request() {
		$this->assertSame( 'en', mclogiora_get_current_language()['code'] );

		$this->container->get( LanguageContextInterface::class )->set_requested_code( 'tr' );

		$this->assertSame( 'tr', mclogiora_get_current_language()['code'] );
	}

	/**
	 * Asserts the two published current-language readers cannot drift apart.
	 *
	 * `mclogiora_current_language()` shipped in 0.11.0 and returns the code;
	 * `mclogiora_get_current_language()` returns the whole record. Both are
	 * supported, so both must always be answering the same question.
	 *
	 * @return void
	 */
	public function test_the_two_current_language_readers_agree() {
		$this->container->get( LanguageContextInterface::class )->set_requested_code( 'tr' );

		$this->assertSame( mclogiora_current_language(), mclogiora_get_current_language()['code'] );
	}

	/**
	 * Asserts a real workflow-created translation reads back through the API.
	 *
	 * @return void
	 */
	public function test_a_created_post_translation_reads_back_through_the_api() {
		$source_id = self::factory()->post->create(
			array(
				'post_title'  => 'About us',
				'post_name'   => 'about-us',
				'post_status' => 'publish',
			)
		);

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $source_id, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		$this->assertSame( (int) $created['post_id'], mclogiora_get_translation( $source_id, ContentType::POST, 'tr' ) );
		$this->assertSame( $source_id, mclogiora_get_translation( (int) $created['post_id'], ContentType::POST, 'en' ) );
		$this->assertNull( mclogiora_get_translation( $source_id, ContentType::POST, 'de' ) );

		$group = mclogiora_get_translation_group( $source_id, ContentType::POST );

		$this->assertIsArray( $group );
		$this->assertSame( array( 'group_key', 'object_type', 'source', 'translations' ), array_keys( $group ) );
		$this->assertSame( ContentType::POST, $group['object_type'] );
		$this->assertIsArray( $group['source'] );
		$this->assertSame( $source_id, $group['source']['object_id'] );
		$this->assertSame( 'en', $group['source']['language'] );
		$this->assertTrue( $group['source']['is_source'] );
		$this->assertSame( TranslationStatus::ORIGINAL, $group['source']['status'] );

		$this->assertArrayHasKey( 'tr', $group['translations'] );
		$this->assertSame( (int) $created['post_id'], $group['translations']['tr']['object_id'] );
		$this->assertFalse( $group['translations']['tr']['is_source'] );
		$this->assertArrayNotHasKey( 'de', $group['translations'] );
	}

	/**
	 * Asserts an unrelated object has no group and no translation.
	 *
	 * @return void
	 */
	public function test_an_untranslated_post_has_no_group() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertNull( mclogiora_get_translation_group( $post_id, ContentType::POST ) );
		$this->assertNull( mclogiora_get_translation( $post_id, ContentType::POST, 'tr' ) );
	}

	/**
	 * Asserts the API returns the same URL the plugin itself emits.
	 *
	 * A second implementation of what a translated URL looks like would drift
	 * from the generator and start handing themes links that do not resolve.
	 * This is the assertion that keeps the API a delegate.
	 *
	 * @return void
	 */
	public function test_language_url_agrees_with_the_url_generator() {
		$source_id = self::factory()->post->create(
			array(
				'post_title'  => 'Contact',
				'post_name'   => 'contact',
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
				'post_name'   => 'iletisim',
				'post_status' => 'publish',
			)
		);

		$urls = $this->container->get( TranslatedUrlGenerator::class );
		$urls->reset();

		$api_url = mclogiora_get_language_url( 'tr', $source_id );

		$urls->reset();

		$this->assertIsString( $api_url );
		$this->assertStringContainsString( '/tr/', $api_url );
		$this->assertStringContainsString( 'iletisim', $api_url );
		$this->assertSame( $urls->post_url( $source_id, 'tr' ), $api_url );
	}

	/**
	 * Asserts a translated term URL is reachable through the API.
	 *
	 * @return void
	 */
	public function test_language_url_resolves_translated_terms() {
		$source = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'category',
				'name'     => 'News',
			)
		);

		$created = $this->container->get( TranslationWorkflowService::class )
			->taxonomy()
			->create_translation( $source->term_id, 'category', 'tr', 'Haberler' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		wp_update_term( $created['term_id'], 'category', array( 'slug' => 'haberler' ) );

		$this->container->get( TranslatedUrlGenerator::class )->reset();

		$url = mclogiora_get_language_url( 'tr', $source->term_id, ContentType::TERM, 'category' );

		$this->assertIsString( $url );
		$this->assertStringContainsString( '/tr/', $url );
		$this->assertStringContainsString( 'haberler', $url );
	}

	/**
	 * Asserts a missing translation yields null rather than an invented URL.
	 *
	 * @return void
	 */
	public function test_language_url_is_null_for_a_missing_translation() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertNull( mclogiora_get_language_url( 'tr', $post_id ) );
	}

	/**
	 * Asserts the language home URL is returned when no object is given.
	 *
	 * @return void
	 */
	public function test_language_url_returns_the_language_home_without_an_object() {
		$this->assertSame(
			$this->container->get( TranslatedUrlGenerator::class )->home_url_for( 'tr' ),
			mclogiora_get_language_url( 'tr' )
		);

		$this->assertStringContainsString( '/tr/', (string) mclogiora_get_language_url( 'tr' ) );
	}
}
