<?php
/**
 * Compact language switcher tests.
 *
 * @package McLogiora
 */

namespace McLogioraTests\Unit;

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

final class SwitcherCompactTest extends TestCase {
	/**
	 * Compact mode keeps the control semantic and uses plugin-owned assets.
	 *
	 * @return void
	 */
	public function test_compact_mode_is_accessible_and_uses_bundled_flags() {
		$GLOBALS['mclogiora_test_is_front_page'] = true;
		$languages = array(
			new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, true ),
			new Language( 'tr', 'tr_TR', 'Türkçe', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ),
		);
		$context   = new FakeLanguageContext( $languages, 'en', 'en' );
		$relations = new TranslationRelationService(
			new FakeRelationRepository(),
			new MetadataNeedsUpdateDetector(),
			new FakeLanguageService( $languages )
		);
		$renderer = new SwitcherRenderer(
			new LanguageSwitcher( $context, new TranslatedUrlGenerator( $relations, new RoutingSettings(), $context ), new RoutingSettings() )
		);

		$html = $renderer->render(
			array(
				'style'        => SwitcherStyle::COMPACT,
				'show_name'    => false,
				'show_code'    => true,
				'show_flag'    => true,
				'show_current' => true,
				'missing'      => 'home',
			)
		);

		$this->assertStringContainsString( 'data-mclogiora-compact="1"', $html );
		$this->assertStringContainsString( 'aria-haspopup="menu"', $html );
		$this->assertStringContainsString( 'aria-expanded="false"', $html );
		$this->assertStringContainsString( 'aria-controls="mclogiora-switcher-', $html );
		$this->assertStringContainsString( 'role="menu"', $html );
		$this->assertStringContainsString( 'role="menuitem"', $html );
		$this->assertStringContainsString( '/assets/flags/us.svg', $html );
		$this->assertStringContainsString( '/assets/flags/tr.svg', $html );
		$this->assertStringNotContainsString( 'epiktetos', $html );
		$GLOBALS['mclogiora_test_is_front_page'] = false;
	}
}
