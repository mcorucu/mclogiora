<?php
/**
 * Workflow service status change tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Tests\Support\WorkflowTestFactory;
use PHPUnit\Framework\TestCase;

/**
 * Covers status changes applied through the workflow service.
 */
final class TranslationWorkflowServiceTest extends TestCase {
	/**
	 * Test factory.
	 *
	 * @var WorkflowTestFactory
	 */
	private $factory;

	/**
	 * Translation post ID.
	 *
	 * @var int
	 */
	private $translation_id;

	/**
	 * Sets up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->factory = new WorkflowTestFactory();
		$this->factory->gateway->add_post( 10, array( 'post_type' => 'post' ) );

		$created              = $this->factory->content->create_translation( 10, 'tr' );
		$this->translation_id = $created['post_id'];
	}

	/**
	 * Asserts a permitted status change is applied.
	 *
	 * @return void
	 */
	public function test_permitted_status_change_is_applied() {
		$result = $this->factory->workflows->change_status(
			ContentType::POST,
			$this->translation_id,
			'tr',
			TranslationStatus::NEEDS_REVIEW
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			TranslationStatus::NEEDS_REVIEW,
			$this->factory->repository->find_item( ContentType::POST, (string) $this->translation_id, 'tr' )->status()
		);
	}

	/**
	 * Asserts a forbidden status change is rejected and nothing is written.
	 *
	 * @return void
	 */
	public function test_forbidden_status_change_is_rejected() {
		$result = $this->factory->workflows->change_status(
			ContentType::POST,
			$this->translation_id,
			'tr',
			TranslationStatus::MACHINE_SUGGESTED
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_machine_status_reserved', $result->get_error_code() );
		$this->assertSame(
			TranslationStatus::DRAFT,
			$this->factory->repository->find_item( ContentType::POST, (string) $this->translation_id, 'tr' )->status(),
			'A rejected transition must not change stored state.'
		);
	}

	/**
	 * Asserts the source item cannot have its status changed.
	 *
	 * @return void
	 */
	public function test_source_status_cannot_be_changed() {
		$result = $this->factory->workflows->change_status(
			ContentType::POST,
			10,
			'en',
			TranslationStatus::TRANSLATED
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_original_status_immutable', $result->get_error_code() );
	}

	/**
	 * Asserts a missing item is reported rather than created.
	 *
	 * @return void
	 */
	public function test_missing_item_is_reported() {
		$result = $this->factory->workflows->change_status(
			ContentType::POST,
			4321,
			'tr',
			TranslationStatus::TRANSLATED
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_translation_item_not_found', $result->get_error_code() );
	}

	/**
	 * Asserts a full review cycle works end to end.
	 *
	 * @return void
	 */
	public function test_draft_to_review_to_translated_cycle() {
		$this->factory->workflows->change_status( ContentType::POST, $this->translation_id, 'tr', TranslationStatus::NEEDS_REVIEW );
		$this->factory->workflows->change_status( ContentType::POST, $this->translation_id, 'tr', TranslationStatus::TRANSLATED );

		$this->assertSame(
			TranslationStatus::TRANSLATED,
			$this->factory->repository->find_item( ContentType::POST, (string) $this->translation_id, 'tr' )->status()
		);

		$this->factory->workflows->change_status( ContentType::POST, $this->translation_id, 'tr', TranslationStatus::NEEDS_UPDATE );

		$this->assertSame(
			TranslationStatus::NEEDS_UPDATE,
			$this->factory->repository->find_item( ContentType::POST, (string) $this->translation_id, 'tr' )->status()
		);
	}

	/**
	 * Asserts relation integrity rejects a duplicate object assignment.
	 *
	 * @return void
	 */
	public function test_duplicate_object_assignment_is_rejected() {
		$this->factory->gateway->add_post( 30, array( 'post_type' => 'post' ) );

		$result = $this->factory->content->link_existing( 30, $this->translation_id, 'tr' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_object_already_related', $result->get_error_code() );
	}
}
