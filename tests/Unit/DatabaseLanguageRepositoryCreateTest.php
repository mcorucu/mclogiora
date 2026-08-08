<?php
/**
 * Regression tests for the language repository create() contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Database\SchemaBuilder;
use McLogiora\Database\TableNames;
use McLogiora\Languages\DatabaseLanguageRepository;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Covers the defect found in RE-ENTRY R3.
 *
 * create() promises Language|WP_Error. It previously returned the result of
 * find_by_code() directly, which is Language|null, so a post-insert read
 * failure produced null. A caller testing `instanceof WP_Error` would take the
 * success path and then fatal on a method call against null.
 */
final class DatabaseLanguageRepositoryCreateTest extends TestCase {
	/**
	 * Builds a repository over a fake wpdb.
	 *
	 * @param FakeWpdb $wpdb Fake database.
	 * @return DatabaseLanguageRepository
	 */
	private function repository( FakeWpdb $wpdb ) {
		return new DatabaseLanguageRepository(
			$wpdb,
			new TableNames( $wpdb ),
			new SchemaBuilder( $wpdb )
		);
	}

	/**
	 * Returns a valid language.
	 *
	 * @return Language
	 */
	private function language() {
		return new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false );
	}

	/**
	 * Asserts an unreadable insert returns WP_Error rather than null.
	 *
	 * @return void
	 */
	public function test_create_returns_wp_error_when_row_cannot_be_read_back() {
		$wpdb                = new FakeWpdb();
		$wpdb->insert_result = 1;
		$wpdb->rows          = array();

		$result = $this->repository( $wpdb )->create( $this->language() );

		$this->assertNotNull( $result, 'create() must never return null.' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_language_created_but_unreadable', $result->get_error_code() );

		$data = $result->get_error_data();

		$this->assertIsArray( $data );
		$this->assertSame( 'tr', $data['language_code'] );
	}

	/**
	 * Asserts a failed insert still returns the creation error.
	 *
	 * @return void
	 */
	public function test_create_returns_wp_error_when_insert_fails() {
		$wpdb                = new FakeWpdb();
		$wpdb->insert_result = false;

		$result = $this->repository( $wpdb )->create( $this->language() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_language_create_failed', $result->get_error_code() );
	}

	/**
	 * Asserts a readable insert returns the language.
	 *
	 * @return void
	 */
	public function test_create_returns_language_on_success() {
		$wpdb                = new FakeWpdb();
		$wpdb->insert_result = 1;
		$wpdb->rows_after_insert = array(
			(object) array(
				'language_code'          => 'tr',
				'locale'                 => 'tr_TR',
				'slug'                   => 'tr',
				'native_name'            => 'Turkce',
				'english_name'           => 'Turkish',
				'text_direction'         => 'ltr',
				'status'                 => LanguageStatus::ACTIVE,
				'fallback_language_code' => null,
				'sort_order'             => 1,
				'is_default'             => 0,
			),
		);

		$result = $this->repository( $wpdb )->create( $this->language() );

		$this->assertInstanceOf( Language::class, $result );
		$this->assertSame( 'tr', $result->code() );
	}
}
