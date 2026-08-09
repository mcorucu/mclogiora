<?php
/**
 * Language switcher and URL generation tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\MetadataNeedsUpdateDetector;
use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationRelationService;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Routing\MissingTranslationPolicy;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Routing\TranslatedUrlGenerator;
use McLogiora\Switcher\LanguageSwitcher;
use McLogiora\Switcher\SwitcherStyle;
use McLogiora\Tests\Support\FakeLanguageContext;
use McLogiora\Tests\Support\FakeLanguageService;
use McLogiora\Tests\Support\FakeRelationRepository;
use PHPUnit\Framework\TestCase;

/**
 * Covers URL prefixing, the switcher view model, and the missing-translation
 * policy.
 */
final class LanguageSwitcherTest extends TestCase {
	/**
	 * Language context.
	 *
	 * @var FakeLanguageContext
	 */
	private $context;

	/**
	 * URL generator.
	 *
	 * @var TranslatedUrlGenerator
	 */
	private $urls;

	/**
	 * Relation repository.
	 *
	 * @var FakeRelationRepository
	 */
	private $repository;

	/**
	 * Switcher.
	 *
	 * @var LanguageSwitcher
	 */
	private $switcher;

	/**
	 * Sets up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['mclogiora_test_options']        = array();
		$GLOBALS['mclogiora_test_queried_object'] = new \WP_Post( 10 );

		$languages = array(
			new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, true ),
			new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ),
			new Language( 'ar', 'ar', 'Arabic', 'Arabic', 'rtl', LanguageStatus::ACTIVE, 2, false ),
		);

		$this->context    = new FakeLanguageContext( $languages, 'en', 'en' );
		$this->repository = new FakeRelationRepository();

		$relations = new TranslationRelationService(
			$this->repository,
			new MetadataNeedsUpdateDetector(),
			new FakeLanguageService( $languages )
		);

		$settings = new RoutingSettings();

		$this->urls     = new TranslatedUrlGenerator( $relations, $settings, $this->context );
		$this->switcher = new LanguageSwitcher( $this->context, $this->urls, $settings );
	}

	/**
	 * Asserts the default language carries no prefix by default.
	 *
	 * @return void
	 */
	public function test_default_language_has_no_prefix() {
		$this->assertFalse( $this->urls->language_has_prefix( 'en' ) );
		$this->assertSame( '', $this->urls->prefix_for( 'en' ) );
		$this->assertSame( 'https://example.test/about/', $this->urls->apply_prefix( 'https://example.test/about/', 'en' ) );
	}

	/**
	 * Asserts secondary languages are prefixed.
	 *
	 * @return void
	 */
	public function test_secondary_language_is_prefixed() {
		$this->assertTrue( $this->urls->language_has_prefix( 'tr' ) );
		$this->assertSame(
			'https://example.test/tr/hakkimizda/',
			$this->urls->apply_prefix( 'https://example.test/hakkimizda/', 'tr' )
		);
	}

	/**
	 * Asserts enabling the default prefix changes only the default language.
	 *
	 * @return void
	 */
	public function test_default_prefix_option_is_respected() {
		update_option( RoutingSettings::OPTION_NAME, array( 'default_language_prefix' => true ) );

		$this->assertTrue( $this->urls->language_has_prefix( 'en' ) );
		$this->assertSame(
			'https://example.test/en/about/',
			$this->urls->apply_prefix( 'https://example.test/about/', 'en' )
		);
	}

	/**
	 * Asserts external URLs are never rewritten.
	 *
	 * @return void
	 */
	public function test_external_urls_are_left_alone() {
		$this->assertSame(
			'https://elsewhere.test/about/',
			$this->urls->apply_prefix( 'https://elsewhere.test/about/', 'tr' )
		);
	}

	/**
	 * Asserts home URLs are language-aware.
	 *
	 * @return void
	 */
	public function test_home_urls_are_language_aware() {
		$this->assertSame( 'https://example.test/', $this->urls->home_url_for( 'en' ) );
		$this->assertSame( 'https://example.test/tr/', $this->urls->home_url_for( 'tr' ) );
	}

	/**
	 * Asserts an untranslated object yields no URL rather than a fake one.
	 *
	 * @return void
	 */
	public function test_untranslated_object_has_no_url() {
		$this->assertNull(
			$this->urls->translated_object_id( ContentType::POST, 10, 'tr' ),
			'A missing translation must never be given a plausible-looking URL.'
		);
	}

	/**
	 * Asserts a translated object resolves to its own identifier.
	 *
	 * @return void
	 */
	public function test_translated_object_resolves() {
		$this->seed_group();

		$this->assertSame( 11, $this->urls->translated_object_id( ContentType::POST, 10, 'tr' ) );
		$this->assertNull( $this->urls->translated_object_id( ContentType::POST, 10, 'ar' ) );
	}

	/**
	 * Asserts resolving one language memoises the whole group.
	 *
	 * @return void
	 */
	public function test_group_resolution_is_memoised_for_every_language() {
		$this->seed_group();

		$this->urls->translated_object_id( ContentType::POST, 10, 'tr' );

		$this->assertSame(
			10,
			$this->urls->translated_object_id( ContentType::POST, 10, 'en' ),
			'The whole group is cached in one pass, so a switcher does not re-query per language.'
		);
	}

	/**
	 * Asserts missing translations are hidden by default.
	 *
	 * @return void
	 */
	public function test_missing_translations_are_hidden_by_default() {
		$items = $this->switcher->items();
		$codes = array_column( $items, 'code' );

		$this->assertContains( 'en', $codes, 'The current language is always offered.' );
		$this->assertNotContains( 'tr', $codes );
		$this->assertNotContains( 'ar', $codes );
	}

	/**
	 * Asserts every language is offered when no single object is queried.
	 *
	 * On a home page or archive there is no object to translate, so each
	 * language's own home page is the honest destination.
	 *
	 * @return void
	 */
	public function test_archive_context_offers_every_language_home() {
		$GLOBALS['mclogiora_test_queried_object'] = null;

		$by = array_column( $this->switcher->items(), 'url', 'code' );

		$this->assertSame( 'https://example.test/tr/', $by['tr'] );
		$this->assertSame( 'https://example.test/', $by['en'] );
	}

	/**
	 * Asserts the home policy offers a language home link instead.
	 *
	 * @return void
	 */
	public function test_home_policy_links_to_the_language_home() {
		$items = $this->switcher->items( array( 'missing' => MissingTranslationPolicy::HOME ) );
		$by    = array_column( $items, 'url', 'code' );

		$this->assertSame( 'https://example.test/tr/', $by['tr'] );
	}

	/**
	 * Asserts the disable policy marks a language unavailable without a link.
	 *
	 * @return void
	 */
	public function test_disable_policy_marks_language_unavailable() {
		$items = $this->switcher->items( array( 'missing' => MissingTranslationPolicy::DISABLE ) );

		foreach ( $items as $item ) {
			if ( 'tr' !== $item['code'] ) {
				continue;
			}

			$this->assertFalse( $item['available'] );
			$this->assertNull( $item['url'], 'An unavailable language must never carry a URL.' );
		}
	}

	/**
	 * Asserts the current language can be excluded.
	 *
	 * @return void
	 */
	public function test_current_language_can_be_hidden() {
		$items = $this->switcher->items( array( 'show_current' => false ) );

		$this->assertNotContains( 'en', array_column( $items, 'code' ) );
	}

	/**
	 * Asserts direction metadata reaches the view model.
	 *
	 * @return void
	 */
	public function test_direction_is_exposed_for_rtl_languages() {
		$items = $this->switcher->items( array( 'missing' => MissingTranslationPolicy::HOME ) );
		$by    = array_column( $items, 'direction', 'code' );

		$this->assertSame( 'rtl', $by['ar'] );
		$this->assertSame( 'ltr', $by['en'] );
	}

	/**
	 * Asserts a switcher configured to show nothing still shows a label.
	 *
	 * @return void
	 */
	public function test_options_never_resolve_to_an_empty_label() {
		$options = $this->switcher->options(
			array(
				'show_name' => false,
				'show_code' => false,
				'show_flag' => false,
			)
		);

		$this->assertTrue( $options['show_name'], 'An unlabelled switcher would be unusable.' );
	}

	/**
	 * Asserts unknown options and invalid values are rejected.
	 *
	 * @return void
	 */
	public function test_invalid_options_fall_back_to_safe_values() {
		$options = $this->switcher->options(
			array(
				'style'    => 'carousel',
				'missing'  => 'show_source_content',
				'class'    => 'ok<script>',
				'evil_key' => 'ignored',
			)
		);

		$this->assertSame( SwitcherStyle::INLINE, $options['style'] );
		$this->assertSame( MissingTranslationPolicy::HIDE, $options['missing'] );
		$this->assertSame( 'okscript', $options['class'] );
		$this->assertArrayNotHasKey( 'evil_key', $options, 'Unknown attributes must not reach the view model.' );
	}

	/**
	 * Seeds a translation group for post 10 with a Turkish translation.
	 *
	 * @return void
	 */
	private function seed_group() {
		$this->repository->seed_group(
			'group-1',
			array(
				new TranslationItem( ContentType::POST, '10', 'en', TranslationStatus::ORIGINAL, true ),
				new TranslationItem( ContentType::POST, '11', 'tr', TranslationStatus::TRANSLATED, false ),
			)
		);
	}
}
