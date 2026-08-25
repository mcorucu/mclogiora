<?php
/**
 * Bundled language catalog tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Languages\LanguageCatalog;
use McLogiora\Languages\LanguageDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Verifies catalog integrity and normalized metadata.
 */
final class LanguageCatalogTest extends TestCase {
	/**
	 * Every catalog definition has a unique persistence identity and locale.
	 *
	 * @return void
	 */
	public function test_catalog_entries_are_unique_and_complete() {
		$codes   = array();
		$locales = array();

		foreach ( LanguageCatalog::all() as $definition ) {
			$this->assertInstanceOf( LanguageDefinition::class, $definition );
			$this->assertNotContains( $definition->code(), $codes );
			$this->assertNotContains( $definition->locale(), $locales );
			$this->assertNotSame( '', $definition->native_name() );
			$this->assertNotSame( '', $definition->english_name() );
			$this->assertContains( $definition->direction(), array( 'ltr', 'rtl' ) );
			$this->assertMatchesRegularExpression( '/^[a-z]{2,3}(?:_[A-Z]{2})?$/', $definition->locale() );
			$this->assertMatchesRegularExpression( '/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-(?:[A-Z]{2}|[0-9]{3}))?$/', $definition->hreflang() );
			$codes[]   = $definition->code();
			$locales[] = $definition->locale();
		}

		$this->assertGreaterThanOrEqual( 40, count( $codes ) );
	}

	/**
	 * Representative locales and directions resolve from the same catalog.
	 *
	 * @return void
	 */
	public function test_representative_entries_resolve_without_manual_metadata() {
		$turkish = LanguageCatalog::find( 'tr_TR' );
		$english = LanguageCatalog::find( 'en_US' );
		$british = LanguageCatalog::find( 'en_GB' );
		$german  = LanguageCatalog::find( 'de_DE' );
		$arabic  = LanguageCatalog::find( 'ar' );

		$this->assertSame( 'tr', $turkish->hreflang() );
		$this->assertSame( 'en-US', $english->hreflang() );
		$this->assertSame( 'en-GB', $british->hreflang() );
		$this->assertSame( 'de', $german->hreflang() );
		$this->assertSame( 'rtl', $arabic->direction() );
		$this->assertSame( 'tr_TR', LanguageCatalog::language_data( 'tr' )['locale'] );
	}

	/**
	 * Unknown values never become persistence data.
	 *
	 * @return void
	 */
	public function test_unknown_language_is_safe() {
		$this->assertNull( LanguageCatalog::find( 'xx_XX' ) );
		$this->assertNull( LanguageCatalog::language_data( 'xx_XX' ) );
	}

	/**
	 * WordPress locale detection suggests without persisting.
	 *
	 * @return void
	 */
	public function test_wordpress_locale_is_only_a_suggestion() {
		$suggestion = LanguageCatalog::suggested_for_site();

		$this->assertInstanceOf( LanguageDefinition::class, $suggestion );
		$this->assertSame( 'en_US', $suggestion->locale() );
		$this->assertSame( 'en', $suggestion->code() );
	}
}
