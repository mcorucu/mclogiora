<?php
/**
 * Switcher language attribute tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\MetadataNeedsUpdateDetector;
use McLogiora\Relations\TranslationRelationService;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Routing\TranslatedUrlGenerator;
use McLogiora\Switcher\LanguageSwitcher;
use McLogiora\Switcher\SwitcherRenderer;
use McLogiora\Switcher\SwitcherStyle;
use McLogiora\Tests\Support\FakeLanguageContext;
use McLogiora\Tests\Support\FakeLanguageService;
use McLogiora\Tests\Support\FakeRelationRepository;
use PHPUnit\Framework\TestCase;

/**
 * Pins that switcher links describe languages the way the document head does.
 *
 * The switcher emitted `hreflang="tr"` while the head emitted `hreflang="tr-TR"`
 * for the same page. Both are well formed, so nothing complains, but they are
 * two different claims about one page and a reader -- human or machine -- has
 * to decide which to believe.
 */
final class SwitcherLanguageTagTest extends TestCase {
	/**
	 * Switcher.
	 *
	 * @var LanguageSwitcher
	 */
	private $switcher;

	/**
	 * Builds a switcher over three languages.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['mclogiora_test_options']        = array();
		$GLOBALS['mclogiora_test_queried_object'] = null;

		$languages = array(
			new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, true ),
			new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ),
			new Language( 'pt', 'pt_BR', 'Portugues', 'Portuguese', 'ltr', LanguageStatus::ACTIVE, 2, false ),
			new Language( 'ar', '', 'Arabic', 'Arabic', 'rtl', LanguageStatus::ACTIVE, 3, false ),
		);

		$context   = new FakeLanguageContext( $languages, 'en', 'en' );
		$relations = new TranslationRelationService(
			new FakeRelationRepository(),
			new MetadataNeedsUpdateDetector(),
			new FakeLanguageService( $languages )
		);
		$settings  = new RoutingSettings();

		$this->switcher = new LanguageSwitcher( $context, new TranslatedUrlGenerator( $relations, $settings, $context ), $settings );
	}

	/**
	 * Renders the switcher in one style.
	 *
	 * @param string $style Switcher style.
	 * @return string
	 */
	private function render( $style ) {
		$renderer = new SwitcherRenderer( $this->switcher );

		return $renderer->render(
			array(
				'style'   => $style,
				'missing' => \McLogiora\Routing\MissingTranslationPolicy::HOME,
			)
		);
	}

	/**
	 * Asserts links carry the BCP 47 tag rather than the internal code.
	 *
	 * @return void
	 */
	public function test_links_use_the_language_tag() {
		$html = $this->render( SwitcherStyle::INLINE );

		$this->assertStringContainsString( 'hreflang="tr-TR"', $html );
		$this->assertStringContainsString( 'lang="tr-TR"', $html );
		$this->assertStringContainsString( 'hreflang="pt-BR"', $html );
		$this->assertStringNotContainsString( 'hreflang="tr"', $html );
	}

	/**
	 * Asserts a language with no locale falls back to its code.
	 *
	 * @return void
	 */
	public function test_language_without_a_locale_falls_back_to_its_code() {
		$html = $this->render( SwitcherStyle::INLINE );

		$this->assertStringContainsString( 'hreflang="ar"', $html );
	}

	/**
	 * Asserts no language attribute in any style contains an underscore.
	 *
	 * @return void
	 */
	public function test_no_style_emits_an_underscored_language_attribute() {
		foreach ( array( SwitcherStyle::INLINE, SwitcherStyle::DROPDOWN, SwitcherStyle::COMPACT, SwitcherStyle::PILLS ) as $style ) {
			$html = $this->render( $style );

			preg_match_all( '/(?:hreflang|lang)="([^"]*)"/', $html, $matches );

			foreach ( $matches[1] as $value ) {
				$this->assertStringNotContainsString( '_', $value, "Style {$style} emitted {$value}." );
			}
		}
	}

	/**
	 * Asserts dropdown navigation has no inline event handler and retains a
	 * real-link fallback when JavaScript is unavailable.
	 *
	 * @return void
	 */
	public function test_dropdown_uses_external_enhancement_and_no_script_links() {
		$html = $this->render( SwitcherStyle::DROPDOWN );

		$this->assertStringNotContainsString( 'onchange=', $html );
		$this->assertStringContainsString( 'data-mclogiora-switcher="1"', $html );
		$this->assertStringContainsString( 'mclogiora-switcher__fallback', $html );
		$this->assertStringContainsString( 'href="https://example.test/tr/', $html );
	}
}
