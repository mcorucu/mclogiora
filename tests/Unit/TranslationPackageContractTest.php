<?php
/**
 * Portable package format contract tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\ImportExport\ObjectLocator;
use McLogiora\ImportExport\PackageEncoder;
use McLogiora\ImportExport\PackageFormat;
use McLogiora\ImportExport\PackageParser;
use McLogiora\ImportExport\TranslationPackage;
use PHPUnit\Framework\TestCase;

/**
 * Pins the wire format and everything the parser must refuse.
 *
 * These assertions are exact rather than partial. The package is a file that
 * travels between sites and outlives the release that wrote it, so its shape is
 * a promise; a test that checked only the keys it cared about would let a field
 * appear in it, or disappear from it, unnoticed.
 *
 * The rejection cases are the larger half deliberately. A parser is judged by
 * what it refuses, and every input below is one an operator could plausibly
 * feed it: a truncated download, a hand-edited file, a package from a future
 * release, or something that is simply not a package at all.
 */
final class TranslationPackageContractTest extends TestCase {
	/**
	 * Parser under test.
	 *
	 * @var PackageParser
	 */
	private $parser;

	/**
	 * Sets up the parser.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->parser = new PackageParser();
	}

	/**
	 * The format version is its own number and not the plugin version.
	 *
	 * @return void
	 */
	public function test_format_version_is_independent_of_the_plugin_version() {
		$this->assertIsInt( PackageFormat::VERSION );
		$this->assertSame( 1, PackageFormat::VERSION );
		$this->assertSame( 'mclogiora/translation-package', PackageFormat::FORMAT );
		$this->assertFalse( PackageFormat::supports_version( '1' ) );
		$this->assertFalse( PackageFormat::supports_version( 2 ) );
		$this->assertFalse( PackageFormat::supports_version( 1.0 ) );
	}

	/**
	 * The manifest carries exactly the declared fields, in order.
	 *
	 * @return void
	 */
	public function test_manifest_shape_is_fixed() {
		$package = $this->parse( $this->valid_package() );

		$this->assertInstanceOf( TranslationPackage::class, $package );
		$this->assertSame(
			array( 'format', 'format_version', 'generator', 'generator_version', 'created_at', 'sections', 'counts' ),
			array_keys( $package->manifest()->to_array() )
		);
		$this->assertSame(
			array( 'manifest', 'payload' ),
			array_keys( $package->to_array() )
		);
		$this->assertSame(
			array( 'languages', 'relations' ),
			array_keys( $package->to_array()['payload'] )
		);
	}

	/**
	 * The manifest records no fact about the site that produced it.
	 *
	 * @return void
	 */
	public function test_manifest_records_nothing_about_the_source_site() {
		$manifest = $this->parse( $this->valid_package() )->manifest()->to_array();

		foreach ( array( 'site_url', 'home_url', 'site_name', 'admin_email', 'user', 'user_id', 'environment' ) as $field ) {
			$this->assertArrayNotHasKey( $field, $manifest );
		}
	}

	/**
	 * Encoding and re-reading a package changes nothing about it.
	 *
	 * @return void
	 */
	public function test_json_round_trip_is_lossless() {
		$original = $this->parse( $this->valid_package() );
		$encoder  = new PackageEncoder();
		$reparsed = $this->parse( json_decode( $encoder->encode( $original ), true ) );

		$this->assertSame( $original->to_array(), $reparsed->to_array() );
	}

	/**
	 * Pretty printing changes whitespace and nothing else.
	 *
	 * @return void
	 */
	public function test_pretty_printing_only_changes_whitespace() {
		$package = $this->parse( $this->valid_package() );
		$encoder = new PackageEncoder();

		$this->assertSame(
			json_decode( $encoder->encode( $package, false ), true ),
			json_decode( $encoder->encode( $package, true ), true )
		);
		$this->assertStringContainsString( "\n", $encoder->encode( $package, true ) );
		$this->assertStringNotContainsString( "\n", $encoder->encode( $package, false ) );
	}

	/**
	 * Encoding is byte-stable for the same package.
	 *
	 * @return void
	 */
	public function test_encoding_is_byte_stable() {
		$package = $this->parse( $this->valid_package() );
		$encoder = new PackageEncoder();

		$this->assertSame( $encoder->encode( $package ), $encoder->encode( $package ) );
	}

	/**
	 * Slashes and non-ASCII text are written literally.
	 *
	 * @return void
	 */
	public function test_encoding_flags_are_fixed_by_the_format() {
		$data = $this->valid_package();

		$data['payload']['languages'][1]['native_name'] = 'Türkçe';

		$encoded = ( new PackageEncoder() )->encode( $this->parse( $data ) );

		$this->assertStringContainsString( 'Türkçe', $encoded );
		$this->assertStringNotContainsString( '\\u00fc', $encoded );
		$this->assertStringNotContainsString( '\/', $encoded );
	}

	/**
	 * A package carries no credential, token or provider material.
	 *
	 * @return void
	 */
	public function test_package_carries_no_secret_material() {
		$encoded = ( new PackageEncoder() )->encode( $this->parse( $this->valid_package() ) );

		$forbidden = array(
			'api_key',
			'apikey',
			'credential',
			'Authorization',
			'DeepL-Auth-Key',
			'MCLOGIORA_OPENAI_API_KEY',
			'MCLOGIORA_ANTHROPIC_API_KEY',
			'MCLOGIORA_GEMINI_API_KEY',
			'MCLOGIORA_DEEPL_API_KEY',
			'mclogiora_suggestion_key_',
			'nonce',
			'token',
			'secret',
			'password',
			'source_hash',
			'translated_source_hash',
			'wp_mclogiora',
			'wpdb',
			'McLogiora\\',
		);

		foreach ( $forbidden as $needle ) {
			$this->assertStringNotContainsString( $needle, $encoded, $needle . ' must never appear in a package' );
		}
	}

	/**
	 * Every structurally broken input is refused by name.
	 *
	 * @dataProvider malformed_packages
	 *
	 * @param mixed  $input Raw package input.
	 * @param string $code Expected error code.
	 * @return void
	 */
	public function test_malformed_packages_are_refused( $input, $code ) {
		$json   = is_string( $input ) ? $input : (string) wp_json_encode( $input );
		$result = $this->parser->parse( $json );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/**
	 * Supplies the rejection matrix.
	 *
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public function malformed_packages() {
		$cases = array(
			'empty string'            => array( '', 'mclogiora_package_empty' ),
			'whitespace'              => array( "  \n ", 'mclogiora_package_empty' ),
			'not json'                => array( 'not a package', 'mclogiora_package_invalid_json' ),
			'truncated json'          => array( '{"manifest":', 'mclogiora_package_invalid_json' ),
			'json scalar'             => array( '42', 'mclogiora_package_not_an_object' ),
			'json list'               => array( '[1,2,3]', 'mclogiora_package_not_an_object' ),
			'php serialized'          => array( 'a:1:{s:8:"manifest";a:0:{}}', 'mclogiora_package_invalid_json' ),
		);

		$mutations = array(
			'unknown top-level member' => array(
				static function ( array $data ) {
					$data['extra'] = true;

					return $data;
				},
				'mclogiora_package_unknown_member',
			),
			'missing manifest'         => array(
				static function ( array $data ) {
					unset( $data['manifest'] );

					return $data;
				},
				'mclogiora_package_missing_manifest',
			),
			'manifest is a string'     => array(
				static function ( array $data ) {
					$data['manifest'] = 'yes';

					return $data;
				},
				'mclogiora_package_missing_manifest',
			),
			'missing payload'          => array(
				static function ( array $data ) {
					unset( $data['payload'] );

					return $data;
				},
				'mclogiora_package_missing_payload',
			),
			'foreign format'           => array(
				static function ( array $data ) {
					$data['manifest']['format'] = 'wordpress/wxr';

					return $data;
				},
				'mclogiora_package_unknown_format',
			),
			'missing format version'   => array(
				static function ( array $data ) {
					unset( $data['manifest']['format_version'] );

					return $data;
				},
				'mclogiora_package_missing_version',
			),
			'future format version'    => array(
				static function ( array $data ) {
					$data['manifest']['format_version'] = 2;

					return $data;
				},
				'mclogiora_package_unsupported_version',
			),
			'version as a string'      => array(
				static function ( array $data ) {
					$data['manifest']['format_version'] = '1';

					return $data;
				},
				'mclogiora_package_unsupported_version',
			),
			'plugin version as version' => array(
				static function ( array $data ) {
					$data['manifest']['format_version'] = '0.15.0';

					return $data;
				},
				'mclogiora_package_unsupported_version',
			),
			'generator not a string'   => array(
				static function ( array $data ) {
					$data['manifest']['generator'] = 7;

					return $data;
				},
				'mclogiora_package_invalid_manifest_field',
			),
			'counts not whole numbers' => array(
				static function ( array $data ) {
					$data['manifest']['counts']['languages'] = '2';

					return $data;
				},
				'mclogiora_package_invalid_manifest_field',
			),
			'count disagrees with payload' => array(
				static function ( array $data ) {
					$data['manifest']['counts']['relation_items'] = 99;

					return $data;
				},
				'mclogiora_package_count_mismatch',
			),
			'unknown declared section' => array(
				static function ( array $data ) {
					$data['manifest']['sections'][] = 'settings';

					return $data;
				},
				'mclogiora_package_unknown_section',
			),
			'unknown payload section'  => array(
				static function ( array $data ) {
					$data['payload']['settings'] = array();

					return $data;
				},
				'mclogiora_package_unknown_section',
			),
			'undeclared payload section' => array(
				static function ( array $data ) {
					$data['manifest']['sections'] = array( 'languages' );

					return $data;
				},
				'mclogiora_package_undeclared_section',
			),
			'section is an object'     => array(
				static function ( array $data ) {
					$data['payload']['languages'] = array( 'en' => array() );

					return $data;
				},
				'mclogiora_package_invalid_section',
			),
			'language entry is a string' => array(
				static function ( array $data ) {
					$data['payload']['languages'][0] = 'en';

					return $data;
				},
				'mclogiora_package_invalid_language',
			),
			'language code unusable'   => array(
				static function ( array $data ) {
					$data['payload']['languages'][0]['code'] = 'EN GB';

					return $data;
				},
				'mclogiora_package_invalid_language',
			),
			'language listed twice'    => array(
				static function ( array $data ) {
					$data['payload']['languages'][1]['code'] = 'en';

					return $data;
				},
				'mclogiora_package_duplicate_language',
			),
			'language order is text'   => array(
				static function ( array $data ) {
					$data['payload']['languages'][0]['order'] = '0';

					return $data;
				},
				'mclogiora_package_invalid_language',
			),
			'language flag is text'    => array(
				static function ( array $data ) {
					$data['payload']['languages'][0]['is_active'] = 'yes';

					return $data;
				},
				'mclogiora_package_invalid_language',
			),
			'language direction invented' => array(
				static function ( array $data ) {
					$data['payload']['languages'][0]['direction'] = 'sideways';

					return $data;
				},
				'mclogiora_package_invalid_language',
			),
			'two default languages'    => array(
				static function ( array $data ) {
					$data['payload']['languages'][1]['is_default'] = true;

					return $data;
				},
				'mclogiora_package_invalid_language',
			),
			'group key unusable'       => array(
				static function ( array $data ) {
					$data['payload']['relations'][0]['group_key'] = 'Not A Key';

					return $data;
				},
				'mclogiora_package_invalid_relation_group',
			),
			'group listed twice'       => array(
				static function ( array $data ) {
					$data['payload']['relations'][1] = $data['payload']['relations'][0];

					return $data;
				},
				'mclogiora_package_duplicate_relation_group',
			),
			'group with no items'      => array(
				static function ( array $data ) {
					$data['payload']['relations'][0]['items'] = array();

					return $data;
				},
				'mclogiora_package_invalid_relation_group',
			),
			'group with no source'     => array(
				static function ( array $data ) {
					$data['payload']['relations'][0]['items'][0]['is_source'] = false;

					return $data;
				},
				'mclogiora_package_invalid_relation_group',
			),
			'group with two sources'   => array(
				static function ( array $data ) {
					$data['payload']['relations'][0]['items'][1]['is_source'] = true;

					return $data;
				},
				'mclogiora_package_invalid_relation_group',
			),
			'two items in one language' => array(
				static function ( array $data ) {
					$data['payload']['relations'][0]['items'][1]['language'] = 'en';

					return $data;
				},
				'mclogiora_package_duplicate_relation_item',
			),
			'item object type unusable' => array(
				static function ( array $data ) {
					$data['payload']['relations'][0]['items'][0]['object_type'] = '9 lives';

					return $data;
				},
				'mclogiora_package_invalid_relation_item',
			),
			'item without a status'    => array(
				static function ( array $data ) {
					unset( $data['payload']['relations'][0]['items'][0]['status'] );

					return $data;
				},
				'mclogiora_package_invalid_relation_item',
			),
			'item source flag is text' => array(
				static function ( array $data ) {
					$data['payload']['relations'][0]['items'][0]['is_source'] = 'true';

					return $data;
				},
				'mclogiora_package_invalid_relation_item',
			),
			'item without a locator member' => array(
				static function ( array $data ) {
					unset( $data['payload']['relations'][0]['items'][0]['locator'] );

					return $data;
				},
				'mclogiora_package_invalid_relation_item',
			),
			'locator of unknown kind'  => array(
				static function ( array $data ) {
					$data['payload']['relations'][0]['items'][0]['locator']['kind'] = 'widget';

					return $data;
				},
				'mclogiora_package_invalid_relation_item',
			),
			'locator ancestors not strings' => array(
				static function ( array $data ) {
					$data['payload']['relations'][0]['items'][0]['locator']['ancestors'] = array( 5 );

					return $data;
				},
				'mclogiora_package_invalid_relation_item',
			),
		);

		foreach ( $mutations as $name => $mutation ) {
			$cases[ $name ] = array( $mutation[0]( self::package_fixture() ), $mutation[1] );
		}

		return $cases;
	}

	/**
	 * A package cannot be bigger than the reader's bound.
	 *
	 * @return void
	 */
	public function test_oversized_packages_are_refused_before_decoding() {
		$result = $this->parser->parse( str_repeat( 'x', PackageFormat::MAX_BYTES + 1 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_package_too_large', $result->get_error_code() );
	}

	/**
	 * Deep nesting is refused rather than recursed into.
	 *
	 * @return void
	 */
	public function test_excessive_nesting_is_refused() {
		$json = str_repeat( '[', 200 ) . str_repeat( ']', 200 );

		$result = $this->parser->parse( $json );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_package_invalid_json', $result->get_error_code() );
	}

	/**
	 * Unknown optional keys inside objects are ignored, not refused.
	 *
	 * @return void
	 */
	public function test_unknown_member_keys_are_tolerated() {
		$data = self::package_fixture();

		$data['manifest']['produced_by_future_release']          = true;
		$data['payload']['languages'][0]['fallback']             = 'en';
		$data['payload']['relations'][0]['items'][0]['reviewer'] = 'someone';
		$data['payload']['relations'][0]['note']                 = 'ignored';

		$package = $this->parse( $data );

		$this->assertInstanceOf( TranslationPackage::class, $package );
		$this->assertArrayNotHasKey( 'produced_by_future_release', $package->manifest()->to_array() );
		$this->assertArrayNotHasKey( 'fallback', $package->to_array()['payload']['languages'][0] );
		$this->assertArrayNotHasKey( 'reviewer', $package->to_array()['payload']['relations'][0]['items'][0] );
	}

	/**
	 * A locator is rebuilt with the same fields it was written with.
	 *
	 * @return void
	 */
	public function test_locators_survive_the_round_trip() {
		$package = $this->parse( self::package_fixture() );
		$items   = $package->relations()[0]->items();

		$source = null;

		foreach ( $items as $item ) {
			if ( $item->is_source() ) {
				$source = $item;
			}
		}

		$this->assertNotNull( $source );
		$this->assertInstanceOf( ObjectLocator::class, $source->locator() );
		$this->assertSame( ObjectLocator::KIND_POST, $source->locator()->kind() );
		$this->assertSame( 'page', $source->locator()->post_type() );
		$this->assertSame( 'about', $source->locator()->slug() );
		$this->assertSame( array( 'company' ), $source->locator()->ancestors() );
	}

	/**
	 * A null locator is a value the format carries, not a parse failure.
	 *
	 * @return void
	 */
	public function test_null_locators_are_accepted() {
		$data = self::package_fixture();

		$data['payload']['relations'][0]['items'][1]['locator'] = null;

		$package = $this->parse( $data );
		$targets = $package->relations()[0]->targets();

		$this->assertNull( $targets[0]->locator() );
	}

	/**
	 * Parses a package array, failing the test when it is refused.
	 *
	 * @param array<string,mixed> $data Package array.
	 * @return TranslationPackage
	 */
	private function parse( array $data ) {
		$result = $this->parser->parse( (string) wp_json_encode( $data ) );

		if ( $result instanceof \WP_Error ) {
			$this->fail( 'Package unexpectedly refused: ' . $result->get_error_code() . ' -- ' . $result->get_error_message() );
		}

		return $result;
	}

	/**
	 * Returns a valid package array.
	 *
	 * @return array<string,mixed>
	 */
	private function valid_package() {
		return self::package_fixture();
	}

	/**
	 * Builds the shared fixture package.
	 *
	 * Static so the data provider, which runs before the test instance exists,
	 * can build the same package the tests use.
	 *
	 * @return array<string,mixed>
	 */
	private static function package_fixture() {
		return array(
			'manifest' => array(
				'format'            => PackageFormat::FORMAT,
				'format_version'    => PackageFormat::VERSION,
				'generator'         => PackageFormat::GENERATOR,
				'generator_version' => '0.15.0',
				'created_at'        => '2026-08-17T09:00:00Z',
				'sections'          => array( 'languages', 'relations' ),
				'counts'            => array(
					'languages'       => 2,
					'relation_groups' => 1,
					'relation_items'  => 2,
				),
			),
			'payload'  => array(
				'languages' => array(
					array(
						'code'         => 'en',
						'locale'       => 'en_US',
						'native_name'  => 'English',
						'english_name' => 'English',
						'direction'    => 'ltr',
						'is_active'    => true,
						'is_default'   => true,
						'order'        => 0,
					),
					array(
						'code'         => 'tr',
						'locale'       => 'tr_TR',
						'native_name'  => 'Turkce',
						'english_name' => 'Turkish',
						'direction'    => 'ltr',
						'is_active'    => true,
						'is_default'   => false,
						'order'        => 1,
					),
				),
				'relations' => array(
					array(
						'group_key' => 'b3f1c2d4e5a678901234567890abcdef',
						'items'     => array(
							array(
								'object_type' => 'post',
								'language'    => 'en',
								'status'      => 'original',
								'is_source'   => true,
								'locator'     => array(
									'kind'      => 'post',
									'post_type' => 'page',
									'slug'      => 'about',
									'ancestors' => array( 'company' ),
								),
							),
							array(
								'object_type' => 'post',
								'language'    => 'tr',
								'status'      => 'translated',
								'is_source'   => false,
								'locator'     => array(
									'kind'      => 'post',
									'post_type' => 'page',
									'slug'      => 'hakkimizda',
									'ancestors' => array( 'sirket' ),
								),
							),
						),
					),
				),
			),
		);
	}
}
