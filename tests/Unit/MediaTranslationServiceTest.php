<?php
/**
 * Media translation tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Media\MediaTranslation;
use McLogiora\Media\MediaTranslationService;
use McLogiora\Tests\Support\ArrayCache;
use McLogiora\Tests\Support\FakeContentGateway;
use McLogiora\Tests\Support\FakeLanguageService;
use McLogiora\Tests\Support\FakeMediaRepository;
use PHPUnit\Framework\TestCase;

/**
 * Covers language-specific attachment metadata.
 */
final class MediaTranslationServiceTest extends TestCase {
	/**
	 * Repository.
	 *
	 * @var FakeMediaRepository
	 */
	private $repository;

	/**
	 * Content gateway.
	 *
	 * @var FakeContentGateway
	 */
	private $gateway;

	/**
	 * Cache.
	 *
	 * @var ArrayCache
	 */
	private $cache;

	/**
	 * Service under test.
	 *
	 * @var MediaTranslationService
	 */
	private $service;

	/**
	 * Sets up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->repository = new FakeMediaRepository();
		$this->gateway    = new FakeContentGateway();
		$this->cache      = new ArrayCache();

		$this->gateway->add_post(
			50,
			array(
				'post_type'    => 'attachment',
				'post_title'   => 'Sunset',
				'post_excerpt' => 'A caption',
				'post_content' => 'A description',
			)
		);

		$this->service = new MediaTranslationService(
			$this->repository,
			new FakeLanguageService(
				array(
					new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, true ),
					new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ),
					new Language( 'de', 'de_DE', 'Deutsch', 'German', 'ltr', LanguageStatus::INACTIVE, 2, false ),
				)
			),
			$this->gateway,
			new CapabilityRegistry(),
			$this->cache
		);
	}

	/**
	 * Asserts saving stores only textual metadata.
	 *
	 * @return void
	 */
	public function test_saves_translated_metadata_without_touching_the_file() {
		$result = $this->service->save(
			50,
			'tr',
			array(
				'title'       => 'Gun batimi',
				'alt_text'    => 'Deniz uzerinde gun batimi',
				'caption'     => 'Bir aciklama',
				'description' => 'Uzun aciklama',
			)
		);

		$this->assertInstanceOf( MediaTranslation::class, $result );

		$stored = $this->repository->find( 50, 'tr' );

		$this->assertSame( 'Gun batimi', $stored->title() );
		$this->assertSame( 'Deniz uzerinde gun batimi', $stored->alt_text() );

		$attachment = $this->gateway->get_post( 50 );

		$this->assertSame( 'Sunset', $attachment['post_title'], 'The attachment itself must not be rewritten.' );
		$this->assertSame( array(), $this->gateway->deleted_posts, 'No attachment may be duplicated or deleted.' );
	}

	/**
	 * Asserts one attachment serves every language.
	 *
	 * @return void
	 */
	public function test_single_attachment_serves_every_language() {
		$this->service->save( 50, 'tr', array( 'title' => 'Gun batimi' ) );

		$this->assertCount( 1, $this->repository->all_for_attachment( 50 ) );
		$this->assertNotNull( $this->gateway->get_post( 50 ) );
		$this->assertCount( 1, array_filter( $this->gateway->posts, static function ( $post ) {
			return 'attachment' === $post['post_type'];
		} ), 'Translating metadata must never create a second attachment.' );
	}

	/**
	 * Asserts untranslated fields fall back to the attachment values.
	 *
	 * @return void
	 */
	public function test_untranslated_fields_fall_back_to_the_source() {
		$this->service->save( 50, 'tr', array( 'title' => 'Gun batimi' ) );

		$metadata = $this->service->metadata_for_language( 50, 'tr' );

		$this->assertSame( 'Gun batimi', $metadata['title'] );
		$this->assertSame( 'A caption', $metadata['caption'], 'A partially translated attachment still renders completely.' );
		$this->assertSame( 'A description', $metadata['description'] );
	}

	/**
	 * Asserts a language with no translation returns source metadata.
	 *
	 * @return void
	 */
	public function test_untranslated_language_returns_source_metadata() {
		$metadata = $this->service->metadata_for_language( 50, 'tr' );

		$this->assertSame( 'Sunset', $metadata['title'] );
	}

	/**
	 * Asserts an inactive language is refused.
	 *
	 * @return void
	 */
	public function test_inactive_language_is_refused() {
		$result = $this->service->save( 50, 'de', array( 'title' => 'Sonnenuntergang' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_inactive_target_language', $result->get_error_code() );
	}

	/**
	 * Asserts a non-attachment is refused.
	 *
	 * @return void
	 */
	public function test_non_attachment_is_refused() {
		$this->gateway->add_post( 60, array( 'post_type' => 'post' ) );

		$result = $this->service->save( 60, 'tr', array( 'title' => 'Nope' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_not_an_attachment', $result->get_error_code() );
	}

	/**
	 * Asserts clearing every field removes the stored translation.
	 *
	 * @return void
	 */
	public function test_clearing_all_fields_removes_the_translation() {
		$this->service->save( 50, 'tr', array( 'title' => 'Gun batimi' ) );
		$this->service->save( 50, 'tr', array( 'title' => '', 'alt_text' => '', 'caption' => '', 'description' => '' ) );

		$this->assertNull( $this->repository->find( 50, 'tr' ) );
	}

	/**
	 * Asserts saving invalidates the affected cache entry.
	 *
	 * @return void
	 */
	public function test_saving_invalidates_cache() {
		$this->service->save( 50, 'tr', array( 'title' => 'Gun batimi' ) );

		$this->assertNotEmpty( $this->cache->deleted );
		$this->assertStringContainsString( '50', $this->cache->deleted[0] );
	}

	/**
	 * Asserts a translation inherits the source featured image by default.
	 *
	 * @return void
	 */
	public function test_featured_image_is_inherited_not_duplicated() {
		$this->assertSame(
			50,
			$this->service->resolve_featured_attachment( 0, 50 ),
			'A translation with no thumbnail of its own references the source attachment.'
		);
		$this->assertSame( array(), $this->gateway->deleted_posts );
	}

	/**
	 * Asserts an explicitly chosen featured image wins.
	 *
	 * @return void
	 */
	public function test_explicit_featured_image_overrides_inheritance() {
		$this->assertSame(
			77,
			$this->service->resolve_featured_attachment( 77, 50 ),
			'An editor choosing a different image for one language must be respected.'
		);
	}
}
