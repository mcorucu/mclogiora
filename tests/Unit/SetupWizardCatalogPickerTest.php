<?php

declare( strict_types=1 );

namespace McLogiora\Tests\Unit;

use McLogiora\Languages\LanguageCatalog;
use PHPUnit\Framework\TestCase;

final class SetupWizardCatalogPickerTest extends TestCase {

	public function test_site_locale_suggestion_is_a_confirmable_catalog_definition(): void {
		$suggestion = LanguageCatalog::suggested_for_site();

		self::assertNotNull( $suggestion );
		self::assertSame( 'en', $suggestion->code() );
		self::assertSame( 'en_US', $suggestion->locale() );
	}

	public function test_primary_catalog_definition_cannot_be_represented_by_a_duplicate_target_code(): void {
		$primary = LanguageCatalog::find( 'tr_TR' );
		$target  = LanguageCatalog::find( 'tr' );

		self::assertNotNull( $primary );
		self::assertNotNull( $target );
		self::assertSame( $primary->code(), $target->code() );
	}
}
