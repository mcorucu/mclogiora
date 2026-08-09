<?php
/**
 * Language context tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Routing\LanguageContext;
use McLogiora\Tests\Support\FakeLanguageService;
use PHPUnit\Framework\TestCase;

/**
 * Covers the single authoritative answer to "what language is this request?".
 */
final class LanguageContextTest extends TestCase {
	/**
	 * Context under test.
	 *
	 * @var LanguageContext
	 */
	private $context;

	/**
	 * Sets up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->context = new LanguageContext(
			new FakeLanguageService(
				array(
					new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, true ),
					new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ),
					new Language( 'ar', 'ar', 'Arabic', 'Arabic', 'rtl', LanguageStatus::ACTIVE, 2, false ),
					new Language( 'de', 'de_DE', 'Deutsch', 'German', 'ltr', LanguageStatus::INACTIVE, 3, false ),
				)
			)
		);
	}

	/**
	 * Asserts an unprefixed request resolves to the default language.
	 *
	 * @return void
	 */
	public function test_unprefixed_request_uses_the_default_language() {
		$this->assertSame( 'en', $this->context->current_code() );
		$this->assertTrue( $this->context->is_default() );
		$this->assertSame( '', $this->context->requested_code() );
	}

	/**
	 * Asserts an explicit active language is honoured.
	 *
	 * @return void
	 */
	public function test_explicit_active_language_is_used() {
		$this->context->set_requested_code( 'tr' );

		$this->assertSame( 'tr', $this->context->current_code() );
		$this->assertSame( 'tr', $this->context->requested_code() );
		$this->assertFalse( $this->context->is_default() );
	}

	/**
	 * Provides codes that must never become the current language.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function rejected_codes() {
		return array(
			'inactive language' => array( 'de' ),
			'unknown language'  => array( 'zz' ),
			'path traversal'    => array( '../etc' ),
			'script injection'  => array( '<script>' ),
			'sql fragment'      => array( "en' OR 1=1" ),
			'too long'          => array( 'abcdefghijklmnop' ),
			'empty'             => array( '' ),
			'slash'             => array( 'en/tr' ),
		);
	}

	/**
	 * Asserts untrusted request text never becomes a language.
	 *
	 * @dataProvider rejected_codes
	 * @param string $code Candidate code.
	 * @return void
	 */
	public function test_untrusted_input_never_becomes_a_language( $code ) {
		$this->context->set_requested_code( $code );

		$this->assertSame( '', $this->context->requested_code() );
		$this->assertSame( 'en', $this->context->current_code(), 'An unusable prefix must fall back to the default.' );
		$this->assertTrue( $this->context->is_default() );
	}

	/**
	 * Asserts routability reflects active configuration only.
	 *
	 * @return void
	 */
	public function test_only_active_languages_are_routable() {
		$this->assertTrue( $this->context->is_routable( 'tr' ) );
		$this->assertTrue( $this->context->is_routable( 'ar' ) );
		$this->assertFalse( $this->context->is_routable( 'de' ), 'An inactive language must not be routable.' );
		$this->assertFalse( $this->context->is_routable( 'zz' ) );
	}

	/**
	 * Asserts the code is normalised before comparison.
	 *
	 * @return void
	 */
	public function test_codes_are_normalised() {
		$this->context->set_requested_code( '  TR  ' );

		$this->assertSame( 'tr', $this->context->current_code() );
	}

	/**
	 * Asserts only active languages are offered.
	 *
	 * @return void
	 */
	public function test_available_lists_active_languages_only() {
		$codes = array();

		foreach ( $this->context->available() as $language ) {
			$codes[] = $language->code();
		}

		$this->assertSame( array( 'en', 'tr', 'ar' ), $codes );
	}

	/**
	 * Asserts the resolved language is stable within a request.
	 *
	 * @return void
	 */
	public function test_resolution_is_stable_within_a_request() {
		$this->context->set_requested_code( 'tr' );

		$first  = $this->context->current();
		$second = $this->context->current();

		$this->assertSame( $first, $second, 'The language must not change mid-request.' );
	}

	/**
	 * Asserts direction metadata survives resolution.
	 *
	 * @return void
	 */
	public function test_direction_is_available_for_rtl_languages() {
		$this->context->set_requested_code( 'ar' );

		$this->assertSame( 'rtl', $this->context->current()->direction() );
	}
}
