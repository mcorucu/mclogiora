<?php
/**
 * String registry and lookup tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Relations\TranslationStatus;
use McLogiora\Strings\StringSource;
use McLogiora\Strings\StringSourceType;
use McLogiora\Strings\StringTranslation;
use McLogiora\Strings\StringTranslationService;
use McLogiora\Tests\Support\ArrayCache;
use McLogiora\Tests\Support\FakeStringRepository;
use PHPUnit\Framework\TestCase;

/**
 * Covers registry identity, deduplication, and explicit-language lookup.
 */
final class StringTranslationServiceTest extends TestCase {
	/**
	 * Repository.
	 *
	 * @var FakeStringRepository
	 */
	private $repository;

	/**
	 * Cache.
	 *
	 * @var ArrayCache
	 */
	private $cache;

	/**
	 * Service under test.
	 *
	 * @var StringTranslationService
	 */
	private $service;

	/**
	 * Sets up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->repository = new FakeStringRepository();
		$this->cache      = new ArrayCache();
		$this->service    = new StringTranslationService( $this->repository, $this->cache );
	}

	/**
	 * Asserts identity depends on text, domain, and context together.
	 *
	 * @return void
	 */
	public function test_identity_covers_text_domain_and_context() {
		$base = StringSource::hash_for( 'Order', 'mclogiora', 'noun' );

		$this->assertNotSame( $base, StringSource::hash_for( 'Order', 'mclogiora', 'verb' ) );
		$this->assertNotSame( $base, StringSource::hash_for( 'Order', 'other', 'noun' ) );
		$this->assertNotSame( $base, StringSource::hash_for( 'Orders', 'mclogiora', 'noun' ) );
		$this->assertSame( $base, StringSource::hash_for( 'Order', 'mclogiora', 'noun' ) );
	}

	/**
	 * Asserts registering the same string twice is idempotent.
	 *
	 * @return void
	 */
	public function test_registering_twice_is_idempotent() {
		$first  = $this->repository->register( new StringSource( 0, 'Hello', 'mclogiora', '', StringSourceType::PLUGIN, 'a.php' ) );
		$second = $this->repository->register( new StringSource( 0, 'Hello', 'mclogiora', '', StringSourceType::PLUGIN, 'a.php' ) );

		$this->assertSame( $first->id(), $second->id(), 'Rescanning must not duplicate a string.' );
		$this->assertSame( 1, $this->repository->count_strings() );
	}

	/**
	 * Asserts differing contexts create separate registry entries.
	 *
	 * @return void
	 */
	public function test_different_contexts_are_separate_entries() {
		$this->repository->register( new StringSource( 0, 'Order', 'mclogiora', 'noun' ) );
		$this->repository->register( new StringSource( 0, 'Order', 'mclogiora', 'verb' ) );

		$this->assertSame( 2, $this->repository->count_strings() );
	}

	/**
	 * Asserts an unknown string returns unchanged.
	 *
	 * @return void
	 */
	public function test_untranslated_string_returns_the_original() {
		$this->assertSame( 'Hello', $this->service->translate( 'Hello', 'tr', 'mclogiora' ) );
	}

	/**
	 * Asserts a stored translation is returned for the named language.
	 *
	 * @return void
	 */
	public function test_returns_translation_for_explicit_language() {
		$source = $this->repository->register( new StringSource( 0, 'Hello', 'mclogiora' ) );
		$this->repository->save_translation( new StringTranslation( $source->id(), 'tr', 'Merhaba', TranslationStatus::TRANSLATED ) );

		$this->assertSame( 'Merhaba', $this->service->translate( 'Hello', 'tr', 'mclogiora' ) );
		$this->assertSame( 'Hello', $this->service->translate( 'Hello', 'de', 'mclogiora' ), 'Another language must not inherit a translation.' );
	}

	/**
	 * Asserts context is respected during lookup.
	 *
	 * @return void
	 */
	public function test_lookup_respects_context() {
		$noun = $this->repository->register( new StringSource( 0, 'Order', 'mclogiora', 'noun' ) );
		$verb = $this->repository->register( new StringSource( 0, 'Order', 'mclogiora', 'verb' ) );

		$this->repository->save_translation( new StringTranslation( $noun->id(), 'tr', 'Siparis' ) );
		$this->repository->save_translation( new StringTranslation( $verb->id(), 'tr', 'Siparis ver' ) );

		$this->assertSame( 'Siparis', $this->service->translate( 'Order', 'tr', 'mclogiora', 'noun' ) );
		$this->assertSame( 'Siparis ver', $this->service->translate( 'Order', 'tr', 'mclogiora', 'verb' ) );
	}

	/**
	 * Asserts an empty language code returns the original.
	 *
	 * @return void
	 */
	public function test_empty_language_returns_the_original() {
		$source = $this->repository->register( new StringSource( 0, 'Hello', 'mclogiora' ) );
		$this->repository->save_translation( new StringTranslation( $source->id(), 'tr', 'Merhaba' ) );

		$this->assertSame( 'Hello', $this->service->translate( 'Hello', '', 'mclogiora' ) );
	}

	/**
	 * Asserts has_translation reflects stored state.
	 *
	 * @return void
	 */
	public function test_has_translation_reports_stored_state() {
		$source = $this->repository->register( new StringSource( 0, 'Hello', 'mclogiora' ) );

		$this->assertFalse( $this->service->has_translation( 'Hello', 'tr', 'mclogiora' ) );

		$this->repository->save_translation( new StringTranslation( $source->id(), 'tr', 'Merhaba' ) );

		$this->assertTrue( $this->service->has_translation( 'Hello', 'tr', 'mclogiora' ) );
	}

	/**
	 * Asserts saving a translation invalidates only that cache entry.
	 *
	 * @return void
	 */
	public function test_saving_invalidates_the_affected_cache_entry() {
		$source = $this->repository->register( new StringSource( 0, 'Hello', 'mclogiora' ) );

		$this->service->translate( 'Hello', 'tr', 'mclogiora' );
		$this->service->save_translation( $source->id(), 'tr', 'Merhaba' );

		$this->assertCount( 1, $this->cache->deleted );
		$this->assertStringContainsString( 'tr', $this->cache->deleted[0] );
		$this->assertSame( 'Merhaba', $this->service->translate( 'Hello', 'tr', 'mclogiora' ), 'The new value must be visible after invalidation.' );
	}

	/**
	 * Asserts saving against an unknown string is rejected.
	 *
	 * @return void
	 */
	public function test_saving_for_unknown_string_is_rejected() {
		$result = $this->service->save_translation( 9999, 'tr', 'Merhaba' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_string_not_found', $result->get_error_code() );
	}

	/**
	 * Asserts a stale string keeps its translation.
	 *
	 * @return void
	 */
	public function test_stale_strings_keep_their_translations() {
		$source = $this->repository->register( new StringSource( 0, 'Hello', 'mclogiora', '', StringSourceType::PLUGIN, 'demo/a.php' ) );
		$this->repository->save_translation( new StringTranslation( $source->id(), 'tr', 'Merhaba' ) );

		$marked = $this->repository->mark_scope_stale( StringSourceType::PLUGIN, 'demo' );

		$this->assertSame( 1, $marked );
		$this->assertTrue( $this->repository->find( $source->id() )->is_stale() );
		$this->assertSame(
			'Merhaba',
			$this->repository->find_translation( $source->id(), 'tr' )->text(),
			'Going stale must never discard translation work.'
		);
	}
}
