<?php
/**
 * Post translation workflow tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Tests\Support\WorkflowTestFactory;
use PHPUnit\Framework\TestCase;

/**
 * Covers creating, linking, and unlinking post translations.
 */
final class ContentTranslationWorkflowTest extends TestCase {
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
		$this->factory->gateway->add_post(
			10,
			array(
				'post_type'    => 'post',
				'post_title'   => 'Hello world',
				'post_content' => 'Body text',
				'post_excerpt' => 'Summary',
				'menu_order'   => 3,
				'post_author'  => 7,
			)
		);
	}

	/**
	 * Asserts a successful creation produces a linked draft.
	 *
	 * @return void
	 */
	public function test_create_translation_creates_draft_and_relation() {
		$result = $this->factory->content->create_translation( 10, 'tr' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'post_id', $result );

		$created = $this->factory->gateway->get_post( $result['post_id'] );

		$this->assertNotNull( $created );
		$this->assertSame( 'draft', $created['post_status'], 'A new translation must always start as a draft.' );
		$this->assertSame( 'post', $created['post_type'] );
		$this->assertSame( 'Hello world', $created['post_title'] );
		$this->assertSame( 'Body text', $created['post_content'] );
		$this->assertSame( 'Summary', $created['post_excerpt'] );
		$this->assertSame( 3, $created['menu_order'] );

		$item = $this->factory->repository->find_item( ContentType::POST, (string) $result['post_id'], 'tr' );

		$this->assertNotNull( $item );
		$this->assertSame( TranslationStatus::DRAFT, $item->status() );
		$this->assertFalse( $item->is_original() );

		$source = $this->factory->repository->find_item( ContentType::POST, '10', 'en' );

		$this->assertNotNull( $source, 'The source must be attached to the group as the original.' );
		$this->assertTrue( $source->is_original() );
	}

	/**
	 * Asserts no post meta is copied to the translation.
	 *
	 * @return void
	 */
	public function test_create_translation_does_not_copy_arbitrary_fields() {
		$result  = $this->factory->content->create_translation( 10, 'tr' );
		$created = $this->factory->gateway->get_post( $result['post_id'] );

		$this->assertArrayNotHasKey( 'post_name', $created, 'Slugs are a later phase and must not be invented here.' );
		$this->assertNotSame( 10, $created['ID'] );
	}

	/**
	 * Asserts an unknown source is rejected.
	 *
	 * @return void
	 */
	public function test_create_translation_rejects_missing_source() {
		$result = $this->factory->content->create_translation( 999, 'tr' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_source_not_found', $result->get_error_code() );
	}

	/**
	 * Asserts an inactive language is rejected.
	 *
	 * @return void
	 */
	public function test_create_translation_rejects_inactive_language() {
		$result = $this->factory->content->create_translation( 10, 'de' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_inactive_target_language', $result->get_error_code() );
	}

	/**
	 * Asserts translating into the source language is rejected.
	 *
	 * @return void
	 */
	public function test_create_translation_rejects_same_language() {
		$result = $this->factory->content->create_translation( 10, 'en' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_same_language', $result->get_error_code() );
	}

	/**
	 * Asserts a second translation for the same language is rejected.
	 *
	 * @return void
	 */
	public function test_create_translation_rejects_existing_translation() {
		$first = $this->factory->content->create_translation( 10, 'tr' );

		$this->assertIsArray( $first );

		$second = $this->factory->content->create_translation( 10, 'tr' );

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'mclogiora_translation_exists', $second->get_error_code() );
	}

	/**
	 * Asserts excluded content types cannot be translated.
	 *
	 * @return void
	 */
	public function test_create_translation_rejects_excluded_content_type() {
		$this->factory->gateway->post_types[] = 'product';
		$this->factory->gateway->add_post( 20, array( 'post_type' => 'product' ) );

		$result = $this->factory->content->create_translation( 20, 'tr' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_content_type_not_translatable', $result->get_error_code() );
	}

	/**
	 * Asserts a user without edit rights on the source is rejected.
	 *
	 * @return void
	 */
	public function test_create_translation_requires_edit_capability_on_source() {
		$this->factory->gateway->denied_capabilities[] = 'edit_post';

		$result = $this->factory->content->create_translation( 10, 'tr' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_cannot_edit_source', $result->get_error_code() );
	}

	/**
	 * Asserts a failed relation write rolls back only the created draft.
	 *
	 * @return void
	 */
	public function test_failed_relation_rolls_back_created_draft() {
		// Establish the group first, so the forced failure lands on the
		// translation item write rather than on group creation.
		$first = $this->factory->content->create_translation( 10, 'tr' );

		$this->assertIsArray( $first );

		$this->factory->repository->add_item_error = new \WP_Error( 'mclogiora_forced_failure', 'Relation write failed.' );

		$result = $this->factory->content->create_translation( 10, 'fr' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $this->factory->gateway->deleted_posts, 'Exactly one post should be compensated.' );

		$deleted_id = $this->factory->gateway->deleted_posts[0];

		$this->assertNotSame( 10, $deleted_id, 'The pre-existing source must never be deleted.' );
		$this->assertNotSame( $first['post_id'], $deleted_id, 'An earlier translation must never be deleted.' );
		$this->assertNotNull( $this->factory->gateway->get_post( 10 ), 'The source post must still exist.' );
		$this->assertNotNull( $this->factory->gateway->get_post( $first['post_id'] ), 'The earlier translation must still exist.' );
	}

	/**
	 * Asserts a failed link never deletes the pre-existing target.
	 *
	 * @return void
	 */
	public function test_failed_link_does_not_delete_existing_content() {
		$this->assertIsArray( $this->factory->content->create_translation( 10, 'tr' ) );
		$this->factory->gateway->add_post( 14, array( 'post_type' => 'post' ) );

		$this->factory->repository->add_item_error = new \WP_Error( 'mclogiora_forced_failure', 'Relation write failed.' );

		$result = $this->factory->content->link_existing( 10, 14, 'fr' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->factory->gateway->deleted_posts, 'Linking must never compensate by deleting user content.' );
		$this->assertNotNull( $this->factory->gateway->get_post( 14 ) );
	}

	/**
	 * Asserts linking existing content records a relation without copying.
	 *
	 * @return void
	 */
	public function test_link_existing_records_relation_without_touching_content() {
		$this->factory->gateway->add_post(
			11,
			array(
				'post_type'  => 'post',
				'post_title' => 'Merhaba dunya',
			)
		);

		$result = $this->factory->content->link_existing( 10, 11, 'tr' );

		$this->assertIsArray( $result );
		$this->assertSame( 11, $result['post_id'] );

		$target = $this->factory->gateway->get_post( 11 );

		$this->assertSame( 'Merhaba dunya', $target['post_title'], 'Linking must not modify the target.' );
		$this->assertSame( 'publish', $target['post_status'], 'Linking must not change the target status.' );

		$item = $this->factory->repository->find_item( ContentType::POST, '11', 'tr' );

		$this->assertNotNull( $item );
		$this->assertSame( TranslationStatus::NEEDS_REVIEW, $item->status() );
	}

	/**
	 * Asserts linking across post types is rejected.
	 *
	 * @return void
	 */
	public function test_link_existing_rejects_post_type_mismatch() {
		$this->factory->gateway->add_post( 12, array( 'post_type' => 'page' ) );

		$result = $this->factory->content->link_existing( 10, 12, 'tr' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_post_type_mismatch', $result->get_error_code() );
	}

	/**
	 * Asserts content already in a group cannot be linked again.
	 *
	 * @return void
	 */
	public function test_link_existing_rejects_already_related_target() {
		$this->factory->gateway->add_post( 11, array( 'post_type' => 'post' ) );
		$this->factory->gateway->add_post( 13, array( 'post_type' => 'post' ) );

		$this->assertIsArray( $this->factory->content->link_existing( 10, 11, 'tr' ) );

		$result = $this->factory->content->link_existing( 13, 11, 'tr' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_object_already_related', $result->get_error_code() );
	}

	/**
	 * Asserts content cannot be linked to itself.
	 *
	 * @return void
	 */
	public function test_link_existing_rejects_self_link() {
		$result = $this->factory->content->link_existing( 10, 10, 'tr' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_cannot_link_to_self', $result->get_error_code() );
	}

	/**
	 * Asserts unlinking removes the relation but never the content.
	 *
	 * @return void
	 */
	public function test_unlink_removes_relation_but_keeps_content() {
		$created = $this->factory->content->create_translation( 10, 'tr' );
		$post_id = $created['post_id'];

		$this->assertNotNull( $this->factory->repository->find_item( ContentType::POST, (string) $post_id, 'tr' ) );

		$result = $this->factory->content->unlink( $post_id, 'tr' );

		$this->assertTrue( $result );
		$this->assertNull(
			$this->factory->repository->find_item( ContentType::POST, (string) $post_id, 'tr' ),
			'The relation should be gone.'
		);
		$this->assertNotNull(
			$this->factory->gateway->get_post( $post_id ),
			'Unlinking must never delete the WordPress post.'
		);
		$this->assertSame(
			array(),
			$this->factory->gateway->deleted_posts,
			'Unlinking must not call any delete operation.'
		);
	}

	/**
	 * Asserts the source cannot be unlinked while translations remain.
	 *
	 * @return void
	 */
	public function test_unlink_protects_source_while_translations_remain() {
		$this->assertIsArray( $this->factory->content->create_translation( 10, 'tr' ) );

		$result = $this->factory->content->unlink( 10, 'en' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotNull( $this->factory->repository->find_item( ContentType::POST, '10', 'en' ) );
	}
}
