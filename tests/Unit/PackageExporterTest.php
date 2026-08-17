<?php
/**
 * Package exporter tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\ImportExport\PackageEncoder;
use McLogiora\ImportExport\PackageExporter;
use McLogiora\ImportExport\PackageFormat;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Tests\Support\FakeLanguageService;
use McLogiora\Tests\Support\FakeObjectLocatorGateway;
use McLogiora\Tests\Support\FakeRelationRepository;
use PHPUnit\Framework\TestCase;

/**
 * Pins what an export projects, and what it refuses to project.
 *
 * The projection assertions compare whole arrays. An export is the widest
 * surface the plugin has -- everything in it leaves the site -- so a test that
 * checked "the code is there" would not notice the day an internal hash or a
 * row identifier joined it.
 */
final class PackageExporterTest extends TestCase {
	/**
	 * Language service.
	 *
	 * @var FakeLanguageService
	 */
	private $languages;

	/**
	 * Relation repository.
	 *
	 * @var FakeRelationRepository
	 */
	private $relations;

	/**
	 * Object gateway.
	 *
	 * @var FakeObjectLocatorGateway
	 */
	private $objects;

	/**
	 * Exporter under test.
	 *
	 * @var PackageExporter
	 */
	private $exporter;

	/**
	 * Builds a small multilingual site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->languages = new FakeLanguageService(
			array(
				new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ),
				new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, true ),
				new Language( 'ar', 'ar', 'Arabic', 'Arabic', 'rtl', LanguageStatus::INACTIVE, 2, false ),
			)
		);

		$this->objects = new FakeObjectLocatorGateway();
		$this->objects->add_post( 11, 'page', 'about', array( 'company' ) );
		$this->objects->add_post( 12, 'page', 'hakkimizda', array( 'sirket' ) );
		$this->objects->add_post( 21, 'post', 'hello-world', null );
		$this->objects->add_term( 31, 'category', 'news' );
		$this->objects->add_term( 32, 'category', 'haberler' );

		$this->relations = new FakeRelationRepository();
		$this->relations->seed_group(
			'ffff2222-3333-4444-5555-666677778888',
			array(
				new TranslationItem( ContentType::POST, '11', 'en', TranslationStatus::ORIGINAL, true, 'hash-a', 'hash-b', 1700000000, 1700000001 ),
				new TranslationItem( ContentType::POST, '12', 'tr', TranslationStatus::TRANSLATED, false, 'hash-c', 'hash-d', 1700000002, 1700000003 ),
			)
		);
		$this->relations->seed_group(
			'aaaa1111-2222-3333-4444-555566667777',
			array(
				new TranslationItem( ContentType::TERM, '31', 'en', TranslationStatus::ORIGINAL, true ),
				new TranslationItem( ContentType::TERM, '32', 'tr', TranslationStatus::NEEDS_REVIEW, false ),
			)
		);

		$this->exporter = new PackageExporter( $this->languages, $this->relations, $this->objects, '0.15.0' );
	}

	/**
	 * Languages are projected onto the published vocabulary, ordered by code.
	 *
	 * @return void
	 */
	public function test_languages_are_projected_and_ordered_by_code() {
		$languages = $this->exporter->export()->to_array()['payload']['languages'];

		$this->assertSame( array( 'ar', 'en', 'tr' ), array_column( $languages, 'code' ) );
		$this->assertSame(
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
			$languages[1]
		);
		$this->assertFalse( $languages[0]['is_active'] );
		$this->assertSame( 'rtl', $languages[0]['direction'] );
	}

	/**
	 * The raw status constant never reaches a package.
	 *
	 * @return void
	 */
	public function test_language_export_omits_the_internal_status_vocabulary() {
		foreach ( $this->exporter->export()->to_array()['payload']['languages'] as $language ) {
			$this->assertArrayNotHasKey( 'status', $language );
			$this->assertArrayNotHasKey( 'id', $language );
		}
	}

	/**
	 * Groups are ordered by key and their items by language.
	 *
	 * @return void
	 */
	public function test_relations_are_ordered_deterministically() {
		$relations = $this->exporter->export()->to_array()['payload']['relations'];

		$this->assertSame(
			array( 'aaaa1111-2222-3333-4444-555566667777', 'ffff2222-3333-4444-5555-666677778888' ),
			array_column( $relations, 'group_key' )
		);
		$this->assertSame( array( 'en', 'tr' ), array_column( $relations[0]['items'], 'language' ) );
	}

	/**
	 * A relation item carries a locator and no object identifier.
	 *
	 * @return void
	 */
	public function test_relation_items_carry_a_locator_and_no_object_id() {
		$relations = $this->exporter->export()->to_array()['payload']['relations'];
		$items     = $relations[1]['items'];

		$this->assertSame(
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
			$items[0]
		);

		foreach ( $relations as $group ) {
			foreach ( $group['items'] as $item ) {
				$this->assertArrayNotHasKey( 'object_id', $item );
				$this->assertArrayNotHasKey( 'object_key', $item );
				$this->assertArrayNotHasKey( 'source_hash', $item );
				$this->assertArrayNotHasKey( 'translated_source_hash', $item );
				$this->assertArrayNotHasKey( 'source_modified', $item );
				$this->assertArrayNotHasKey( 'translation_modified', $item );
			}
		}
	}

	/**
	 * A term locator names its taxonomy and carries no ancestors.
	 *
	 * @return void
	 */
	public function test_term_items_carry_a_taxonomy_locator() {
		$relations = $this->exporter->export()->to_array()['payload']['relations'];

		$this->assertSame(
			array(
				'kind'     => 'term',
				'taxonomy' => 'category',
				'slug'     => 'news',
			),
			$relations[0]['items'][0]['locator']
		);
	}

	/**
	 * A flat post type gets no ancestors member at all.
	 *
	 * @return void
	 */
	public function test_flat_post_types_carry_no_ancestors_member() {
		$this->relations->seed_group(
			'0000aaaa-1111-2222-3333-444455556666',
			array( new TranslationItem( ContentType::POST, '21', 'en', TranslationStatus::ORIGINAL, true ) )
		);

		$relations = $this->exporter->export()->to_array()['payload']['relations'];

		$this->assertSame(
			array(
				'kind'      => 'post',
				'post_type' => 'post',
				'slug'      => 'hello-world',
			),
			$relations[0]['items'][0]['locator']
		);
	}

	/**
	 * An object the site can no longer read exports as a null locator.
	 *
	 * @return void
	 */
	public function test_a_deleted_object_exports_a_null_locator_rather_than_disappearing() {
		$this->relations->seed_group(
			'0000bbbb-1111-2222-3333-444455556666',
			array( new TranslationItem( ContentType::POST, '999', 'en', TranslationStatus::ORIGINAL, true ) )
		);

		$relations = $this->exporter->export()->to_array()['payload']['relations'];

		$this->assertSame( '0000bbbb-1111-2222-3333-444455556666', $relations[0]['group_key'] );
		$this->assertNull( $relations[0]['items'][0]['locator'] );
	}

	/**
	 * A content type with no locator strategy exports as a null locator.
	 *
	 * @return void
	 */
	public function test_content_types_without_a_locator_export_as_null() {
		$this->relations->seed_group(
			'0000cccc-1111-2222-3333-444455556666',
			array( new TranslationItem( ContentType::MEDIA, '77', 'en', TranslationStatus::ORIGINAL, true ) )
		);

		$relations = $this->exporter->export()->to_array()['payload']['relations'];

		$this->assertSame( 'media', $relations[0]['items'][0]['object_type'] );
		$this->assertNull( $relations[0]['items'][0]['locator'] );
	}

	/**
	 * The manifest describes the payload it was built from.
	 *
	 * @return void
	 */
	public function test_manifest_counts_describe_the_payload() {
		$manifest = $this->exporter->export()->manifest();

		$this->assertSame( PackageFormat::FORMAT, $manifest->format() );
		$this->assertSame( 1, $manifest->format_version() );
		$this->assertSame( 'mclogiora', $manifest->generator() );
		$this->assertSame( '0.15.0', $manifest->generator_version() );
		$this->assertSame( array( 'languages', 'relations' ), $manifest->sections() );
		$this->assertSame(
			array(
				'languages'       => 3,
				'relation_groups' => 2,
				'relation_items'  => 4,
			),
			$manifest->counts()
		);
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $manifest->created_at() );
	}

	/**
	 * Two exports of unchanged state produce the same payload bytes.
	 *
	 * @return void
	 */
	public function test_two_exports_of_unchanged_state_are_identical() {
		$encoder = new PackageEncoder();
		$first   = json_decode( $encoder->encode( $this->exporter->export() ), true );
		$second  = json_decode( $encoder->encode( $this->exporter->export() ), true );

		$this->assertSame( $first['payload'], $second['payload'] );
		$this->assertSame(
			array_diff_key( $first['manifest'], array( 'created_at' => true ) ),
			array_diff_key( $second['manifest'], array( 'created_at' => true ) )
		);
	}

	/**
	 * Export order does not follow the repository's insertion order.
	 *
	 * @return void
	 */
	public function test_export_order_is_independent_of_insertion_order() {
		$expected = $this->exporter->export()->to_array()['payload'];

		$reversed = new FakeRelationRepository();
		$reversed->seed_group(
			'ffff2222-3333-4444-5555-666677778888',
			array(
				new TranslationItem( ContentType::POST, '12', 'tr', TranslationStatus::TRANSLATED, false ),
				new TranslationItem( ContentType::POST, '11', 'en', TranslationStatus::ORIGINAL, true ),
			)
		);
		$reversed->seed_group(
			'aaaa1111-2222-3333-4444-555566667777',
			array(
				new TranslationItem( ContentType::TERM, '32', 'tr', TranslationStatus::NEEDS_REVIEW, false ),
				new TranslationItem( ContentType::TERM, '31', 'en', TranslationStatus::ORIGINAL, true ),
			)
		);

		$exporter = new PackageExporter( $this->languages, $reversed, $this->objects, '0.15.0' );

		$this->assertSame( $expected, $exporter->export()->to_array()['payload'] );
	}

	/**
	 * Exporting reads and never asks a repository to write.
	 *
	 * @return void
	 */
	public function test_export_leaves_the_domain_untouched() {
		$before = $this->fingerprint();

		$this->exporter->export();
		$this->exporter->export();

		$this->assertSame( $before, $this->fingerprint() );
	}

	/**
	 * Returns a deterministic fingerprint of the fixture domain state.
	 *
	 * @return string
	 */
	private function fingerprint() {
		$state = array();

		foreach ( $this->relations->active_group_keys( 100, 0 ) as $key ) {
			$group = $this->relations->find_group( $key );
			$rows  = array();

			foreach ( $group->items() as $item ) {
				$rows[] = implode(
					'|',
					array(
						$item->content_type(),
						$item->object_key(),
						$item->language_code(),
						$item->status(),
						$item->is_original() ? '1' : '0',
						$item->source_hash(),
						$item->translated_source_hash(),
					)
				);
			}

			$state[ $key ] = $rows;
		}

		foreach ( $this->languages->get_languages() as $language ) {
			$state[ 'lang:' . $language->code() ] = array(
				$language->locale(),
				$language->status(),
				$language->order(),
				$language->is_default() ? '1' : '0',
			);
		}

		return md5( (string) wp_json_encode( $state ) );
	}
}
