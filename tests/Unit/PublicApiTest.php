<?php
/**
 * Public developer API contract tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Api\PublicApi;
use McLogiora\Core\Container;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\MetadataNeedsUpdateDetector;
use McLogiora\Relations\TranslationRelationService;
use McLogiora\Relations\TranslationRelationServiceInterface;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Tests\Support\FakeLanguageContext;
use McLogiora\Tests\Support\WorkflowTestFactory;
use PHPUnit\Framework\TestCase;

/**
 * Pins the published array shapes and the unconfigured-site behaviour.
 *
 * These assertions are deliberately exact rather than "contains". The point of
 * the public API is that its shape is a promise, and a test that only checks
 * the keys it cares about would let an internal field leak into it unnoticed.
 */
final class PublicApiTest extends TestCase {
	/**
	 * Workflow factory.
	 *
	 * @var WorkflowTestFactory
	 */
	private $factory;

	/**
	 * Relation service over the factory's repository.
	 *
	 * @var TranslationRelationServiceInterface
	 */
	private $relations;

	/**
	 * Sets up a wired factory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->factory   = new WorkflowTestFactory();
		$this->relations = new TranslationRelationService(
			$this->factory->repository,
			new MetadataNeedsUpdateDetector(),
			$this->factory->languages
		);
	}

	/**
	 * Builds an API over a fully populated container.
	 *
	 * @return PublicApi
	 */
	private function configured_api() {
		$container = new Container();

		$container->set( LanguageServiceInterface::class, $this->factory->languages );
		$container->set( TranslationRelationServiceInterface::class, $this->relations );
		$container->set(
			LanguageContextInterface::class,
			new FakeLanguageContext( $this->factory->languages->get_active_languages(), 'tr', 'en' )
		);

		return new PublicApi( $container );
	}

	/**
	 * Asserts active languages are projected with exactly the published keys.
	 *
	 * @return void
	 */
	public function test_languages_returns_the_published_shape() {
		$languages = $this->configured_api()->languages();

		$this->assertCount( 3, $languages, 'Only active languages are returned by default.' );

		$this->assertSame(
			array( 'code', 'locale', 'tag', 'native_name', 'english_name', 'direction', 'is_active', 'is_default', 'order' ),
			array_keys( $languages[0] )
		);

		$this->assertSame(
			array(
				'code'         => 'en',
				'locale'       => 'en_US',
				'tag'          => 'en-US',
				'native_name'  => 'English',
				'english_name' => 'English',
				'direction'    => 'ltr',
				'is_active'    => true,
				'is_default'   => true,
				'order'        => 0,
			),
			$languages[0]
		);
	}

	/**
	 * Asserts the raw status vocabulary is not published.
	 *
	 * @return void
	 */
	public function test_language_projection_hides_the_internal_status_constant() {
		foreach ( $this->configured_api()->languages( array( 'status' => 'all' ) ) as $language ) {
			$this->assertArrayNotHasKey( 'status', $language );
		}
	}

	/**
	 * Asserts inactive languages are available on request.
	 *
	 * @return void
	 */
	public function test_languages_can_include_inactive_languages() {
		$all = $this->configured_api()->languages( array( 'status' => 'all' ) );

		$this->assertCount( 4, $all );
		$this->assertSame( 'de', $all[3]['code'] );
		$this->assertFalse( $all[3]['is_active'] );
	}

	/**
	 * Asserts the default language is projected.
	 *
	 * @return void
	 */
	public function test_default_language_is_the_configured_default() {
		$default = $this->configured_api()->default_language();

		$this->assertIsArray( $default );
		$this->assertSame( 'en', $default['code'] );
		$this->assertTrue( $default['is_default'] );
	}

	/**
	 * Asserts the current language comes from the language context.
	 *
	 * @return void
	 */
	public function test_current_language_comes_from_the_language_context() {
		$current = $this->configured_api()->current_language();

		$this->assertIsArray( $current );
		$this->assertSame( 'tr', $current['code'] );
	}

	/**
	 * Asserts a linked translation resolves to its object ID.
	 *
	 * @return void
	 */
	public function test_translation_resolves_the_target_object_id() {
		$this->link_pair();

		$api = $this->configured_api();

		$this->assertSame( 77, $api->translation( 42, ContentType::POST, 'tr' ) );
		$this->assertSame( 42, $api->translation( 77, ContentType::POST, 'en' ) );
	}

	/**
	 * Asserts an untranslated language returns null rather than a guess.
	 *
	 * @return void
	 */
	public function test_translation_returns_null_when_the_language_is_missing() {
		$this->link_pair();

		$this->assertNull( $this->configured_api()->translation( 42, ContentType::POST, 'fr' ) );
	}

	/**
	 * Asserts an object outside any group returns null.
	 *
	 * @return void
	 */
	public function test_translation_returns_null_for_an_unrelated_object() {
		$this->assertNull( $this->configured_api()->translation( 999, ContentType::POST, 'tr' ) );
	}

	/**
	 * Asserts the group projection publishes exactly the documented fields.
	 *
	 * @return void
	 */
	public function test_translation_group_returns_the_published_shape() {
		$this->link_pair();

		$group = $this->configured_api()->translation_group( 42, ContentType::POST );

		$this->assertIsArray( $group );
		$this->assertSame( array( 'group_key', 'object_type', 'source', 'translations' ), array_keys( $group ) );
		$this->assertNotSame( '', $group['group_key'] );
		$this->assertSame( ContentType::POST, $group['object_type'] );
		$this->assertSame( array( 'en', 'tr' ), array_keys( $group['translations'] ) );

		$this->assertSame(
			array(
				'object_id'   => 42,
				'object_type' => ContentType::POST,
				'language'    => 'en',
				'status'      => TranslationStatus::ORIGINAL,
				'is_source'   => true,
			),
			$group['source']
		);

		$this->assertSame( 77, $group['translations']['tr']['object_id'] );
		$this->assertFalse( $group['translations']['tr']['is_source'] );
	}

	/**
	 * Asserts the change detector's working state is not published.
	 *
	 * @return void
	 */
	public function test_translation_group_hides_source_change_internals() {
		$this->link_pair();

		$group = $this->configured_api()->translation_group( 42, ContentType::POST );

		foreach ( $group['translations'] as $item ) {
			foreach ( array( 'source_hash', 'translated_source_hash', 'source_modified', 'translation_modified', 'object_key' ) as $internal ) {
				$this->assertArrayNotHasKey( $internal, $item );
			}
		}
	}

	/**
	 * Asserts an object outside any group has no group.
	 *
	 * @return void
	 */
	public function test_translation_group_returns_null_for_an_unrelated_object() {
		$this->assertNull( $this->configured_api()->translation_group( 999, ContentType::POST ) );
	}

	/**
	 * Asserts every reader degrades quietly before the plugin has booted.
	 *
	 * The container is empty until Application::boot() runs, and stays empty
	 * when environment validation fails. A theme calling these helpers then
	 * must get nothing back, not a fatal error.
	 *
	 * @return void
	 */
	public function test_every_reader_is_safe_on_an_unbooted_container() {
		$api = new PublicApi( new Container() );

		$this->assertSame( array(), $api->languages() );
		$this->assertNull( $api->default_language() );
		$this->assertNull( $api->current_language() );
		$this->assertNull( $api->translation( 42, ContentType::POST, 'tr' ) );
		$this->assertNull( $api->translation_group( 42, ContentType::POST ) );
		$this->assertNull( $api->language_url( 'tr' ) );
		$this->assertNull( $api->language_url( 'tr', 42 ) );
	}

	/**
	 * Links post 42 (en, source) to post 77 (tr).
	 *
	 * @return void
	 */
	private function link_pair() {
		$group = $this->relations->create_group_from_source_object( ContentType::POST, '42', 'en' );

		$this->assertNotInstanceOf( 'WP_Error', $group );

		$item = $this->relations->attach_existing_object_as_translation(
			$group->group_key(),
			ContentType::POST,
			'77',
			'tr',
			TranslationStatus::TRANSLATED
		);

		$this->assertNotInstanceOf( 'WP_Error', $item );
	}

}
