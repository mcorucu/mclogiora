<?php
/**
 * String translation lookup caching tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Relations\TranslationStatus;
use McLogiora\Strings\StringSource;
use McLogiora\Strings\StringSourceType;
use McLogiora\Strings\StringTranslationService;
use McLogiora\Tests\Support\ArrayCache;
use McLogiora\Tests\Support\FakeStringRepository;
use PHPUnit\Framework\TestCase;

/**
 * Pins how often a page view is allowed to ask the database about a string.
 *
 * Phase 12 wired this service to `gettext`, which changed its usage profile
 * completely: it went from being called deliberately by an admin screen to
 * being called for every string a theme renders, most of which have no
 * translation. The cost that matters is therefore the *miss*, and a miss must
 * be paid once per request, not once per call.
 */
final class StringTranslationCacheTest extends TestCase {
	/**
	 * Repository spy.
	 *
	 * @var FakeStringRepository
	 */
	private $repository;

	/**
	 * Service under test.
	 *
	 * @var StringTranslationService
	 */
	private $service;

	/**
	 * Builds the service over a counting repository.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->repository = new FakeStringRepository();
		$this->service    = new StringTranslationService( $this->repository, new ArrayCache() );
	}

	/**
	 * Stores a translation and returns the source identifier.
	 *
	 * @param string $text Source text.
	 * @param string $language Language code.
	 * @param string $translated Translated text.
	 * @return int
	 */
	private function store( $text, $language, $translated ) {
		$source = $this->repository->register(
			new StringSource( 0, $text, 'mclogiora', '', StringSourceType::MANUAL, 'tests', 0, false )
		);

		$this->service->save_translation( $source->id(), $language, $translated, TranslationStatus::TRANSLATED );

		return $source->id();
	}

	/**
	 * Asserts a first miss reaches the repository exactly once.
	 *
	 * @return void
	 */
	public function test_first_miss_reaches_the_repository_once() {
		$this->assertSame( 'Unknown', $this->service->translate( 'Unknown', 'tr', 'mclogiora' ) );
		$this->assertSame( 1, $this->repository->lookup_count() );
	}

	/**
	 * Asserts a repeated miss costs nothing.
	 *
	 * @return void
	 */
	public function test_repeated_miss_costs_no_further_lookups() {
		$this->service->translate( 'Unknown', 'tr', 'mclogiora' );

		$before = $this->repository->lookup_count();

		for ( $i = 0; $i < 25; $i++ ) {
			$this->assertSame( 'Unknown', $this->service->translate( 'Unknown', 'tr', 'mclogiora' ) );
		}

		$this->assertSame( $before, $this->repository->lookup_count(), 'A known-missing string must not be looked up again.' );
	}

	/**
	 * Asserts a repeated hit costs nothing either.
	 *
	 * @return void
	 */
	public function test_repeated_hit_costs_no_further_lookups() {
		$this->store( 'Read more', 'tr', 'Devamini oku' );

		$this->assertSame( 'Devamini oku', $this->service->translate( 'Read more', 'tr', 'mclogiora' ) );

		$before = $this->repository->lookup_count();

		for ( $i = 0; $i < 25; $i++ ) {
			$this->assertSame( 'Devamini oku', $this->service->translate( 'Read more', 'tr', 'mclogiora' ) );
		}

		$this->assertSame( $before, $this->repository->lookup_count() );
	}

	/**
	 * Asserts each language is cached separately.
	 *
	 * @return void
	 */
	public function test_languages_are_cached_independently() {
		$this->store( 'Read more', 'tr', 'Devamini oku' );

		$this->assertSame( 'Devamini oku', $this->service->translate( 'Read more', 'tr', 'mclogiora' ) );
		$this->assertSame( 'Read more', $this->service->translate( 'Read more', 'de', 'mclogiora' ) );
		$this->assertSame( 'Devamini oku', $this->service->translate( 'Read more', 'tr', 'mclogiora' ) );
	}

	/**
	 * Asserts the gettext context is part of the cache identity.
	 *
	 * @return void
	 */
	public function test_context_is_part_of_the_cache_identity() {
		$noun = $this->repository->register(
			new StringSource( 0, 'Post', 'mclogiora', 'noun', StringSourceType::MANUAL, 'tests', 0, false )
		);
		$this->service->save_translation( $noun->id(), 'tr', 'Yazi', TranslationStatus::TRANSLATED );

		$this->assertSame( 'Yazi', $this->service->translate( 'Post', 'tr', 'mclogiora', 'noun' ) );
		$this->assertSame( 'Post', $this->service->translate( 'Post', 'tr', 'mclogiora', 'verb' ) );
	}

	/**
	 * Asserts saving a translation invalidates a cached miss.
	 *
	 * Caching a negative answer for the whole request is only safe if writing
	 * a translation clears it; otherwise an editor saves a translation and the
	 * same request keeps insisting there isn't one.
	 *
	 * @return void
	 */
	public function test_saving_a_translation_clears_a_cached_miss() {
		$source = $this->repository->register(
			new StringSource( 0, 'Later', 'mclogiora', '', StringSourceType::MANUAL, 'tests', 0, false )
		);

		$this->assertSame( 'Later', $this->service->translate( 'Later', 'tr', 'mclogiora' ) );

		$this->service->save_translation( $source->id(), 'tr', 'Sonra', TranslationStatus::TRANSLATED );

		$this->assertSame( 'Sonra', $this->service->translate( 'Later', 'tr', 'mclogiora' ) );
	}

	/**
	 * Asserts the memo can be cleared deliberately.
	 *
	 * @return void
	 */
	public function test_reset_clears_the_request_memo() {
		$this->service->translate( 'Unknown', 'tr', 'mclogiora' );

		$before = $this->repository->lookup_count();

		$this->service->reset();
		$this->service->translate( 'Unknown', 'tr', 'mclogiora' );

		$this->assertSame( $before, $this->repository->lookup_count(), 'The object cache still answers after a memo reset.' );
	}
}
