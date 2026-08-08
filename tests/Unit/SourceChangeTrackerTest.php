<?php
/**
 * Source change tracking tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Tests\Support\FakeRelationRepository;
use McLogiora\Workflows\SourceChangeTracker;
use McLogiora\Workflows\TranslationStatusTransitions;
use PHPUnit\Framework\TestCase;

/**
 * Covers marking translations as outdated when a source changes.
 */
final class SourceChangeTrackerTest extends TestCase {
	/**
	 * Relation repository.
	 *
	 * @var FakeRelationRepository
	 */
	private $repository;

	/**
	 * Tracker under test.
	 *
	 * @var SourceChangeTracker
	 */
	private $tracker;

	/**
	 * Original source fields.
	 *
	 * @var array<string,string>
	 */
	private $fields = array(
		'post_title'   => 'Hello',
		'post_content' => 'Body',
		'post_excerpt' => '',
	);

	/**
	 * Sets up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->repository = new FakeRelationRepository();
		$this->tracker    = new SourceChangeTracker( $this->repository, new TranslationStatusTransitions() );

		$hash = $this->tracker->hash_source( $this->fields );

		$this->repository->seed_group(
			'group-1',
			array(
				new TranslationItem( ContentType::POST, '10', 'en', TranslationStatus::ORIGINAL, true, $hash, $hash, 100, 100 ),
				new TranslationItem( ContentType::POST, '11', 'tr', TranslationStatus::TRANSLATED, false, $hash, $hash, 100, 100 ),
			)
		);
	}

	/**
	 * Asserts a meaningful source change marks translations outdated.
	 *
	 * @return void
	 */
	public function test_changed_source_marks_translations_needing_update() {
		$marked = $this->tracker->handle_source_saved(
			ContentType::POST,
			10,
			array(
				'post_title'   => 'Hello again',
				'post_content' => 'Body',
				'post_excerpt' => '',
			),
			array( 'post_status' => 'publish' )
		);

		$this->assertSame( array( 11 ), $marked );

		$target = $this->repository->find_item( ContentType::POST, '11', 'tr' );

		$this->assertSame( TranslationStatus::NEEDS_UPDATE, $target->status() );
	}

	/**
	 * Asserts an unchanged source marks nothing.
	 *
	 * @return void
	 */
	public function test_unchanged_source_marks_nothing() {
		$marked = $this->tracker->handle_source_saved(
			ContentType::POST,
			10,
			$this->fields,
			array( 'post_status' => 'publish' )
		);

		$this->assertSame( array(), $marked );
		$this->assertSame(
			TranslationStatus::TRANSLATED,
			$this->repository->find_item( ContentType::POST, '11', 'tr' )->status()
		);
	}

	/**
	 * Asserts saving a translation does not cascade to its siblings.
	 *
	 * @return void
	 */
	public function test_saving_a_translation_does_not_mark_siblings() {
		$marked = $this->tracker->handle_source_saved(
			ContentType::POST,
			11,
			array(
				'post_title'   => 'Completely different',
				'post_content' => 'Different body',
				'post_excerpt' => '',
			),
			array( 'post_status' => 'publish' )
		);

		$this->assertSame( array(), $marked, 'Only the source may invalidate translations.' );
	}

	/**
	 * Provides save contexts that must be ignored.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function ignored_contexts() {
		return array(
			'autosave'   => array( array( 'is_autosave' => true ) ),
			'revision'   => array( array( 'is_revision' => true ) ),
			'bulk edit'  => array( array( 'is_bulk_edit' => true ) ),
			'auto draft' => array( array( 'post_status' => 'auto-draft' ) ),
			'trash'      => array( array( 'post_status' => 'trash' ) ),
			'inherit'    => array( array( 'post_status' => 'inherit' ) ),
		);
	}

	/**
	 * Asserts irrelevant save events are ignored.
	 *
	 * @dataProvider ignored_contexts
	 * @param array<string,mixed> $context Save context.
	 * @return void
	 */
	public function test_irrelevant_save_events_are_ignored( array $context ) {
		$this->assertTrue( $this->tracker->should_ignore( $context ) );

		$marked = $this->tracker->handle_source_saved(
			ContentType::POST,
			10,
			array(
				'post_title'   => 'Changed',
				'post_content' => 'Changed',
				'post_excerpt' => '',
			),
			$context
		);

		$this->assertSame( array(), $marked );
	}

	/**
	 * Asserts an unrelated object is ignored.
	 *
	 * @return void
	 */
	public function test_unrelated_object_is_ignored() {
		$marked = $this->tracker->handle_source_saved(
			ContentType::POST,
			999,
			$this->fields,
			array( 'post_status' => 'publish' )
		);

		$this->assertSame( array(), $marked );
	}

	/**
	 * Asserts the hash ignores fields translators do not act on.
	 *
	 * @return void
	 */
	public function test_hash_ignores_non_translatable_fields() {
		$first  = $this->tracker->hash_source(
			array(
				'post_title'   => 'A',
				'post_content' => 'B',
				'post_excerpt' => 'C',
				'post_status'  => 'draft',
				'post_author'  => 1,
			)
		);
		$second = $this->tracker->hash_source(
			array(
				'post_title'   => 'A',
				'post_content' => 'B',
				'post_excerpt' => 'C',
				'post_status'  => 'publish',
				'post_author'  => 9,
			)
		);

		$this->assertSame( $first, $second, 'Publishing or reassigning a post must not invalidate translations.' );
	}
}
