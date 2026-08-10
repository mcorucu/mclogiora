<?php
/**
 * BCP 47 language tag tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Languages\LanguageTag;
use PHPUnit\Framework\TestCase;

/**
 * Pins the conversion from mcLogiora's language metadata to a standards tag.
 *
 * Emitting `hreflang="tr_TR"` is the classic multilingual mistake: the value
 * looks right to a developer reading WordPress code and is silently ignored by
 * search engines, so the annotation appears to work and does nothing.
 */
final class LanguageTagTest extends TestCase {
	/**
	 * Returns a language with a given code and locale.
	 *
	 * @param string $code Language code.
	 * @param string $locale Locale.
	 * @param string $direction Text direction.
	 * @return Language
	 */
	private function language( $code, $locale, $direction = 'ltr' ) {
		return new Language( $code, $locale, 'Native', 'English', $direction, LanguageStatus::ACTIVE, 0, false );
	}

	/**
	 * Asserts locales convert to their BCP 47 form.
	 *
	 * @dataProvider locale_provider
	 * @param string $locale Input locale.
	 * @param string $expected Expected tag.
	 * @return void
	 */
	public function test_locales_convert_to_language_tags( $locale, $expected ) {
		$this->assertSame( $expected, LanguageTag::from_locale( $locale ) );
	}

	/**
	 * Supplies locale conversions.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function locale_provider() {
		return array(
			'region locale'        => array( 'tr_TR', 'tr-TR' ),
			'english locale'       => array( 'en_US', 'en-US' ),
			'bare language'        => array( 'tr', 'tr' ),
			'three letter'         => array( 'ckb', 'ckb' ),
			'script subtag'        => array( 'zh_Hans', 'zh-Hans' ),
			'already hyphenated'   => array( 'pt-BR', 'pt-BR' ),
			'lowercase region'     => array( 'de_de', 'de-DE' ),
			'numeric region'       => array( 'es_419', 'es-419' ),
			'formal variant'       => array( 'de_DE_formal', 'de-DE' ),
			'orthography variant'  => array( 'pt_PT_ao90', 'pt-PT' ),
			'empty'                => array( '', '' ),
			'not a language'       => array( '12', '' ),
			'injection attempt'    => array( '"><script>', '' ),
		);
	}

	/**
	 * Asserts the locale is preferred over the bare code.
	 *
	 * @return void
	 */
	public function test_locale_is_preferred_over_the_language_code() {
		$this->assertSame( 'tr-TR', LanguageTag::for_language( $this->language( 'tr', 'tr_TR' ) ) );
	}

	/**
	 * Asserts a language without a locale falls back to its code.
	 *
	 * @return void
	 */
	public function test_language_without_a_locale_falls_back_to_its_code() {
		$this->assertSame( 'tr', LanguageTag::for_language( $this->language( 'tr', '' ) ) );
	}

	/**
	 * Asserts an unusable locale still yields a tag from the code.
	 *
	 * @return void
	 */
	public function test_unusable_locale_falls_back_to_the_code() {
		$this->assertSame( 'tr', LanguageTag::for_language( $this->language( 'tr', '99' ) ) );
	}

	/**
	 * Asserts a tag never contains an underscore.
	 *
	 * @return void
	 */
	public function test_language_tags_never_contain_underscores() {
		foreach ( array( 'tr_TR', 'pt_BR', 'de_DE_formal', 'zh_Hans' ) as $locale ) {
			$this->assertStringNotContainsString( '_', LanguageTag::from_locale( $locale ) );
		}
	}

	/**
	 * Asserts validation accepts and rejects the right shapes.
	 *
	 * @return void
	 */
	public function test_validation_matches_the_documented_shape() {
		$this->assertTrue( LanguageTag::is_valid( 'en' ) );
		$this->assertTrue( LanguageTag::is_valid( 'en-US' ) );
		$this->assertTrue( LanguageTag::is_valid( 'zh-Hans-CN' ) );
		$this->assertTrue( LanguageTag::is_valid( 'es-419' ) );

		$this->assertFalse( LanguageTag::is_valid( 'en_US' ) );
		$this->assertFalse( LanguageTag::is_valid( 'EN-us' ) );
		$this->assertFalse( LanguageTag::is_valid( '' ) );
		$this->assertFalse( LanguageTag::is_valid( 'toolongcode' ) );
	}

	/**
	 * Asserts OpenGraph locales are produced only when a territory exists.
	 *
	 * @return void
	 */
	public function test_open_graph_locale_requires_a_territory() {
		$this->assertSame( 'tr_TR', LanguageTag::to_open_graph( 'tr-TR' ) );
		$this->assertSame( 'zh_CN', LanguageTag::to_open_graph( 'zh-Hans-CN' ) );

		$this->assertSame( '', LanguageTag::to_open_graph( 'en' ), 'A territory must never be invented.' );
		$this->assertSame( '', LanguageTag::to_open_graph( 'es-419' ), 'A numeric area is not an OpenGraph territory.' );
		$this->assertSame( '', LanguageTag::to_open_graph( 'en_US' ) );
	}
}
