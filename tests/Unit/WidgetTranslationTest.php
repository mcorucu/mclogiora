<?php
/**
 * Widget adapter and translation tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Tests\Support\FakeContentGateway;
use McLogiora\Tests\Support\FakeLanguageService;
use McLogiora\Tests\Support\FakeWidgetRepository;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Widgets\BlockWidgetAdapter;
use McLogiora\Widgets\CustomHtmlWidgetAdapter;
use McLogiora\Widgets\TextWidgetAdapter;
use McLogiora\Widgets\WidgetAdapterRegistry;
use McLogiora\Widgets\WidgetTranslationService;
use PHPUnit\Framework\TestCase;

/**
 * Covers adapter boundaries and widget translation storage.
 */
final class WidgetTranslationTest extends TestCase {
	/**
	 * Repository.
	 *
	 * @var FakeWidgetRepository
	 */
	private $repository;

	/**
	 * Service under test.
	 *
	 * @var WidgetTranslationService
	 */
	private $service;

	/**
	 * Sets up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->repository = new FakeWidgetRepository();
		$languages        = new FakeLanguageService(
			array(
				new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, true ),
				new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ),
				new Language( 'de', 'de_DE', 'Deutsch', 'German', 'ltr', LanguageStatus::INACTIVE, 2, false ),
			)
		);

		$this->service = new WidgetTranslationService(
			$this->repository,
			new WidgetAdapterRegistry( array( new TextWidgetAdapter(), new CustomHtmlWidgetAdapter(), new BlockWidgetAdapter() ) ),
			$languages,
			new FakeContentGateway(),
			new CapabilityRegistry()
		);
	}

	/**
	 * Asserts the core adapters declare their fields.
	 *
	 * @return void
	 */
	public function test_core_adapters_declare_translatable_fields() {
		$this->assertArrayHasKey( 'title', ( new TextWidgetAdapter() )->translatable_fields() );
		$this->assertArrayHasKey( 'text', ( new TextWidgetAdapter() )->translatable_fields() );
		$this->assertArrayHasKey( 'content', ( new CustomHtmlWidgetAdapter() )->translatable_fields() );
		$this->assertArrayHasKey( 'content', ( new BlockWidgetAdapter() )->translatable_fields() );
	}

	/**
	 * Asserts extraction reads only declared fields.
	 *
	 * @return void
	 */
	public function test_extract_reads_only_declared_fields() {
		$adapter = new TextWidgetAdapter();

		$extracted = $adapter->extract(
			array(
				'title'  => 'Hello',
				'text'   => 'Body',
				'filter' => true,
				'secret' => 'do-not-touch',
			)
		);

		$this->assertSame( array( 'title' => 'Hello', 'text' => 'Body' ), $extracted );
	}

	/**
	 * Asserts applying a translation leaves undeclared options untouched.
	 *
	 * @return void
	 */
	public function test_apply_preserves_undeclared_options() {
		$adapter  = new TextWidgetAdapter();
		$instance = array( 'title' => 'Hello', 'text' => 'Body', 'filter' => true );

		$applied = $adapter->apply( $instance, array( 'title' => 'Merhaba' ) );

		$this->assertSame( 'Merhaba', $applied['title'] );
		$this->assertSame( 'Body', $applied['text'], 'An untranslated field keeps its source value.' );
		$this->assertTrue( $applied['filter'], 'Undeclared options must be preserved exactly.' );
		$this->assertSame( 'Hello', $instance['title'], 'The source instance must not be modified in place.' );
	}

	/**
	 * Asserts an unknown widget type has no adapter.
	 *
	 * @return void
	 */
	public function test_unknown_widget_type_is_unsupported() {
		$registry = new WidgetAdapterRegistry( array( new TextWidgetAdapter() ) );

		$this->assertFalse( $registry->supports( 'third_party_slider' ) );
		$this->assertNull( $registry->for_type( 'third_party_slider' ) );
	}

	/**
	 * Asserts saving an unsupported widget type is refused.
	 *
	 * @return void
	 */
	public function test_saving_unsupported_widget_is_refused() {
		$result = $this->service->save( 'third_party_slider', 'abc', 'tr', array( 'headline' => 'Merhaba' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_widget_not_supported', $result->get_error_code() );
	}

	/**
	 * Asserts an unsupported widget instance is returned unmodified.
	 *
	 * @return void
	 */
	public function test_unsupported_widget_instance_is_never_mutated() {
		$instance = array( 'headline' => 'Original', 'colour' => '#fff' );

		$applied = $this->service->apply_for_language( 'third_party_slider', 'abc', 'tr', $instance );

		$this->assertSame( $instance, $applied );
	}

	/**
	 * Asserts only declared fields are stored.
	 *
	 * @return void
	 */
	public function test_only_declared_fields_are_stored() {
		$result = $this->service->save(
			'text',
			'abc',
			'tr',
			array( 'title' => 'Merhaba', 'text' => 'Govde', 'secret' => 'nope' )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array( 'title', 'text' ), array_keys( $result->fields() ) );
		$this->assertArrayNotHasKey( 'secret', $result->fields() );
	}

	/**
	 * Asserts an inactive language is refused.
	 *
	 * @return void
	 */
	public function test_inactive_language_is_refused() {
		$result = $this->service->save( 'text', 'abc', 'de', array( 'title' => 'Hallo' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_inactive_target_language', $result->get_error_code() );
	}

	/**
	 * Asserts a stored translation is applied for the named language only.
	 *
	 * @return void
	 */
	public function test_translation_applies_for_the_named_language_only() {
		$this->service->save( 'text', 'abc', 'tr', array( 'title' => 'Merhaba', 'text' => 'Govde' ) );

		$instance = array( 'title' => 'Hello', 'text' => 'Body' );

		$this->assertSame( 'Merhaba', $this->service->apply_for_language( 'text', 'abc', 'tr', $instance )['title'] );
		$this->assertSame( 'Hello', $this->service->apply_for_language( 'text', 'abc', 'en', $instance )['title'] );
	}

	/**
	 * Asserts widget keys are stable and distinct.
	 *
	 * @return void
	 */
	public function test_widget_keys_are_stable_and_distinct() {
		$this->assertSame(
			$this->service->widget_key( 'text', 'abc' ),
			$this->service->widget_key( 'text', 'abc' )
		);
		$this->assertNotSame(
			$this->service->widget_key( 'text', 'abc' ),
			$this->service->widget_key( 'text', 'def' )
		);
	}

	/**
	 * Asserts clearing every field removes the stored translation.
	 *
	 * @return void
	 */
	public function test_clearing_all_fields_removes_the_translation() {
		$this->service->save( 'text', 'abc', 'tr', array( 'title' => 'Merhaba' ) );
		$this->service->save( 'text', 'abc', 'tr', array( 'title' => '', 'text' => '' ) );

		$this->assertNull( $this->repository->find( $this->service->widget_key( 'text', 'abc' ), 'tr' ) );
	}
}
