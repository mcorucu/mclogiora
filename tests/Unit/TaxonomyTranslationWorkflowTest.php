<?php
/**
 * Taxonomy translation workflow tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Tests\Support\WorkflowTestFactory;
use PHPUnit\Framework\TestCase;

/**
 * Covers creating, linking, and unlinking term translations.
 */
final class TaxonomyTranslationWorkflowTest extends TestCase {
	/**
	 * Test factory.
	 *
	 * @var WorkflowTestFactory
	 */
	private $factory;

	/**
	 * Sets up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->factory = new WorkflowTestFactory();
		$this->factory->gateway->add_term(
			5,
			array(
				'taxonomy' => 'category',
				'name'     => 'News',
				'slug'     => 'news',
			)
		);
	}

	/**
	 * Asserts a translated term is created with the supplied name.
	 *
	 * @return void
	 */
	public function test_create_translation_uses_supplied_name() {
		$result = $this->factory->taxonomy->create_translation( 5, 'category', 'tr', 'Haberler', 'Aciklama' );

		$this->assertIsArray( $result );

		$created = $this->factory->gateway->get_term( $result['term_id'], 'category' );

		$this->assertSame( 'Haberler', $created['name'] );
		$this->assertSame( 'Aciklama', $created['description'] );
		$this->assertSame( 'category', $created['taxonomy'] );
		$this->assertNotSame( 'News', $created['name'], 'A term translation must never be a copy of the source name.' );

		$item = $this->factory->repository->find_item( ContentType::TERM, (string) $result['term_id'], 'tr' );

		$this->assertNotNull( $item );
		$this->assertSame( TranslationStatus::DRAFT, $item->status() );
	}

	/**
	 * Asserts a missing translated name is rejected.
	 *
	 * @return void
	 */
	public function test_create_translation_requires_translated_name() {
		$result = $this->factory->taxonomy->create_translation( 5, 'category', 'tr', '   ' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_missing_translated_name', $result->get_error_code() );
	}

	/**
	 * Asserts an excluded taxonomy is rejected.
	 *
	 * @return void
	 */
	public function test_create_translation_rejects_excluded_taxonomy() {
		$this->factory->gateway->taxonomies[] = 'product_cat';
		$this->factory->gateway->add_term( 6, array( 'taxonomy' => 'product_cat' ) );

		$result = $this->factory->taxonomy->create_translation( 6, 'product_cat', 'tr', 'Kategori' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_taxonomy_not_translatable', $result->get_error_code() );
	}

	/**
	 * Asserts an inactive target language is rejected.
	 *
	 * @return void
	 */
	public function test_create_translation_rejects_inactive_language() {
		$result = $this->factory->taxonomy->create_translation( 5, 'category', 'de', 'Nachrichten' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_inactive_target_language', $result->get_error_code() );
	}

	/**
	 * Asserts a duplicate language slot is rejected.
	 *
	 * @return void
	 */
	public function test_create_translation_rejects_duplicate_language() {
		$this->assertIsArray( $this->factory->taxonomy->create_translation( 5, 'category', 'tr', 'Haberler' ) );

		$second = $this->factory->taxonomy->create_translation( 5, 'category', 'tr', 'Baska' );

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'mclogiora_translation_exists', $second->get_error_code() );
	}

	/**
	 * Asserts a failed relation write rolls back the created term.
	 *
	 * @return void
	 */
	public function test_failed_relation_rolls_back_created_term() {
		// Establish the group first, so the forced failure lands on the
		// translation item write rather than on group creation.
		$this->assertIsArray( $this->factory->taxonomy->create_translation( 5, 'category', 'tr', 'Haberler' ) );

		$this->factory->repository->add_item_error = new \WP_Error( 'mclogiora_forced_failure', 'Relation write failed.' );

		$result = $this->factory->taxonomy->create_translation( 5, 'category', 'fr', 'Nouvelles' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $this->factory->gateway->deleted_terms );
		$this->assertNotSame( 5, $this->factory->gateway->deleted_terms[0], 'The source term must never be deleted.' );
		$this->assertNotNull( $this->factory->gateway->get_term( 5, 'category' ) );
	}

	/**
	 * Asserts linking across taxonomies is rejected.
	 *
	 * @return void
	 */
	public function test_link_existing_rejects_taxonomy_mismatch() {
		$this->factory->gateway->add_term( 7, array( 'taxonomy' => 'post_tag' ) );

		$result = $this->factory->taxonomy->link_existing( 5, 'category', 7, 'tr' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_target_not_found', $result->get_error_code() );
	}

	/**
	 * Asserts linking an existing term records only a relation.
	 *
	 * @return void
	 */
	public function test_link_existing_records_relation_only() {
		$this->factory->gateway->add_term(
			8,
			array(
				'taxonomy' => 'category',
				'name'     => 'Haberler',
			)
		);

		$result = $this->factory->taxonomy->link_existing( 5, 'category', 8, 'tr' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Haberler', $this->factory->gateway->get_term( 8, 'category' )['name'] );
		$this->assertSame( array(), $this->factory->gateway->deleted_terms );

		$item = $this->factory->repository->find_item( ContentType::TERM, '8', 'tr' );

		$this->assertNotNull( $item );
		$this->assertSame( TranslationStatus::NEEDS_REVIEW, $item->status() );
	}

	/**
	 * Asserts unlinking a term never deletes it.
	 *
	 * @return void
	 */
	public function test_unlink_does_not_delete_term() {
		$created = $this->factory->taxonomy->create_translation( 5, 'category', 'tr', 'Haberler' );
		$term_id = $created['term_id'];

		$result = $this->factory->taxonomy->unlink( $term_id, 'tr' );

		$this->assertTrue( $result );
		$this->assertNull( $this->factory->repository->find_item( ContentType::TERM, (string) $term_id, 'tr' ) );
		$this->assertNotNull( $this->factory->gateway->get_term( $term_id, 'category' ) );
		$this->assertSame( array(), $this->factory->gateway->deleted_terms );
	}

	/**
	 * Asserts a translated parent is used only when it exists.
	 *
	 * @return void
	 */
	public function test_parent_falls_back_to_top_level_when_untranslated() {
		$this->factory->gateway->add_term(
			9,
			array(
				'taxonomy' => 'category',
				'name'     => 'Child',
				'parent'   => 5,
			)
		);

		$result  = $this->factory->taxonomy->create_translation( 9, 'category', 'tr', 'Cocuk' );
		$created = $this->factory->gateway->get_term( $result['term_id'], 'category' );

		$this->assertSame(
			0,
			(int) $created['parent'],
			'An untranslated parent must not produce a mixed-language hierarchy.'
		);
	}
}
