<?php
/**
 * Term and media suggestion apply integration tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Database\Installer;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Media\MediaTranslationService;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationRelationRepositoryInterface;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Strings\StringTranslationService;
use McLogiora\Suggestions\SuggestionPreview;
use McLogiora\Suggestions\SuggestionPreviewStore;
use McLogiora\Suggestions\SuggestionResult;
use McLogiora\Suggestions\SuggestionSurface;
use McLogiora\Suggestions\TranslationSuggestionApplyService;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_UnitTestCase;

/**
 * Proves the term and media apply paths against real WordPress persistence.
 *
 * The media tests carry the sharpest hazard in this phase. Phase 11's media
 * service replaces the whole per-language record on save, so an applier that
 * wrote only the suggested field would silently erase the translator's other
 * three -- and a test that asked only "did the alt text change?" would pass
 * while the data was being destroyed. Every media case here asserts the
 * siblings as well as the target.
 *
 * The term tests do the same job for the slug. A machine-translated slug would
 * change every URL a term owns, so its immutability is asserted rather than
 * assumed.
 */
final class SuggestionSurfaceApplyIntegrationTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Preview storage.
	 *
	 * @var SuggestionPreviewStore
	 */
	private $previews;

	/**
	 * Apply service under test.
	 *
	 * @var TranslationSuggestionApplyService
	 */
	private $apply;

	/**
	 * Administrator identifier.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Sets up schema, languages, a user and the apply service.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->editor_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->editor_id );

		$this->container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();

		delete_option( 'mclogiora_db_version' );
		$this->container->get( Installer::class )->install();

		$languages = $this->container->get( LanguageRepositoryInterface::class );

		if ( ! $languages->find_by_code( 'en' ) instanceof Language ) {
			$languages->create( new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, false ) );
			$languages->set_default( 'en' );
		}

		if ( ! $languages->find_by_code( 'tr' ) instanceof Language ) {
			$languages->create( new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ) );
		}

		delete_option( RoutingSettings::OPTION_NAME );

		$context = $this->container->get( LanguageContextInterface::class );
		$context->reset();
		$context->set_requested_code( '' );

		$this->previews = new SuggestionPreviewStore();
		$this->apply    = new TranslationSuggestionApplyService(
			$this->previews,
			$this->container->get( TranslationWorkflowService::class ),
			$this->container->get( MediaTranslationService::class ),
			$this->container->get( StringTranslationService::class )
		);
	}

	/**
	 * Stores a preview bound to the given target.
	 *
	 * @param string $surface Surface identifier.
	 * @param string $object_type Object kind.
	 * @param int    $target_id Target identifier.
	 * @param string $text Suggested text.
	 * @return SuggestionPreview
	 */
	private function preview( $surface, $object_type, $target_id, $text ) {
		$preview = $this->previews->create(
			new SuggestionResult( $text, 'deepl' ),
			array(
				'user_id'         => $this->editor_id,
				'object_type'     => $object_type,
				'source_id'       => '0',
				'target_id'       => (string) $target_id,
				'surface'         => $surface,
				'source_language' => 'en',
				'target_language' => 'tr',
			)
		);

		$this->assertInstanceOf( SuggestionPreview::class, $preview );

		return $preview;
	}

	/**
	 * Returns the binding context for a target.
	 *
	 * @param string $surface Surface identifier.
	 * @param string $object_type Object kind.
	 * @param int    $target_id Target identifier.
	 * @return array<string,mixed>
	 */
	private function context( $surface, $object_type, $target_id ) {
		return array(
			'user_id'         => $this->editor_id,
			'object_type'     => $object_type,
			'target_id'       => (string) $target_id,
			'surface'         => $surface,
			'target_language' => 'tr',
		);
	}

	/**
	 * Creates a source term and its Turkish translation.
	 *
	 * @return array{0:int,1:int}
	 */
	private function translated_term() {
		$source = self::factory()->term->create(
			array(
				'taxonomy'    => 'category',
				'name'        => 'English category',
				'description' => 'English description',
			)
		);

		$created = $this->container->get( TranslationWorkflowService::class )
			->taxonomy()
			->create_translation( $source, 'category', 'tr', 'Turkce kategori', 'Turkce aciklama' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		return array( (int) $source, (int) $created['term_id'] );
	}

	/**
	 * Asserts a term name suggestion changes only the name.
	 *
	 * @return void
	 */
	public function test_applying_a_term_name_leaves_slug_and_description_alone() {
		list( $source_id, $target_id ) = $this->translated_term();

		$before = get_term( $target_id );

		$preview = $this->preview( SuggestionSurface::TERM_NAME, 'term', $target_id, 'Onerilen kategori' );

		$result = $this->apply->apply( $preview->token(), $this->context( SuggestionSurface::TERM_NAME, 'term', $target_id ) );

		$this->assertInstanceOf( SuggestionPreview::class, $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$after = get_term( $target_id );

		$this->assertSame( 'Onerilen kategori', $after->name );
		$this->assertSame( $before->slug, $after->slug, 'A machine-translated slug would change every URL the term owns.' );
		$this->assertSame( $before->description, $after->description );
		$this->assertSame( $before->parent, $after->parent );

		$source = get_term( $source_id );

		$this->assertSame( 'English category', $source->name, 'The source term must never change.' );
	}

	/**
	 * Asserts a term description suggestion changes only the description.
	 *
	 * @return void
	 */
	public function test_applying_a_term_description_leaves_name_and_slug_alone() {
		list( $source_id, $target_id ) = $this->translated_term();

		$before = get_term( $target_id );

		$preview = $this->preview( SuggestionSurface::TERM_DESCRIPTION, 'term', $target_id, 'Onerilen aciklama' );

		$result = $this->apply->apply( $preview->token(), $this->context( SuggestionSurface::TERM_DESCRIPTION, 'term', $target_id ) );

		$this->assertInstanceOf( SuggestionPreview::class, $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$after = get_term( $target_id );

		$this->assertSame( 'Onerilen aciklama', $after->description );
		$this->assertSame( $before->name, $after->name );
		$this->assertSame( $before->slug, $after->slug );
		$this->assertSame( 'English description', get_term( $source_id )->description );
	}

	/**
	 * Asserts a term apply records the machine-suggested review state.
	 *
	 * @return void
	 */
	public function test_applying_to_a_term_records_the_review_state() {
		list( , $target_id ) = $this->translated_term();

		$preview = $this->preview( SuggestionSurface::TERM_NAME, 'term', $target_id, 'Onerilen kategori' );

		$this->apply->apply( $preview->token(), $this->context( SuggestionSurface::TERM_NAME, 'term', $target_id ) );

		$item = $this->container->get( TranslationRelationRepositoryInterface::class )
			->find_item( ContentType::TERM, (string) $target_id, 'tr' );

		$this->assertNotNull( $item );
		$this->assertSame( TranslationStatus::MACHINE_SUGGESTED, $item->status() );
	}

	/**
	 * Creates an attachment carrying a full set of Turkish metadata.
	 *
	 * @return int
	 */
	private function attachment_with_translation() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'image.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'English media title',
				'post_excerpt'   => 'English caption',
				'post_content'   => 'English description',
			)
		);

		$saved = $this->container->get( MediaTranslationService::class )->save(
			$attachment_id,
			'tr',
			array(
				'title'       => 'Turkce baslik',
				'alt_text'    => 'Turkce alternatif metin',
				'caption'     => 'Turkce resim yazisi',
				'description' => 'Turkce aciklama',
			)
		);

		$this->assertNotWPError( $saved );

		return (int) $attachment_id;
	}

	/**
	 * Asserts applying one media field leaves the other three intact.
	 *
	 * @param string $surface Surface identifier.
	 * @param string $key Metadata key that should change.
	 * @return void
	 *
	 * @dataProvider provide_media_surfaces
	 */
	public function test_applying_one_media_field_preserves_the_others( $surface, $key ) {
		$attachment_id = $this->attachment_with_translation();
		$service       = $this->container->get( MediaTranslationService::class );

		$before = $service->metadata_for_language( $attachment_id, 'tr' );

		$preview = $this->preview( $surface, 'media', $attachment_id, 'Onerilen deger' );

		$result = $this->apply->apply( $preview->token(), $this->context( $surface, 'media', $attachment_id ) );

		$this->assertInstanceOf( SuggestionPreview::class, $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$after = $service->metadata_for_language( $attachment_id, 'tr' );

		$this->assertSame( 'Onerilen deger', $after[ $key ] );

		foreach ( array( 'title', 'alt_text', 'caption', 'description' ) as $other ) {
			if ( $other === $key ) {
				continue;
			}

			$this->assertSame(
				$before[ $other ],
				$after[ $other ],
				"Applying {$key} must not disturb {$other}."
			);
		}

		$attachment = get_post( $attachment_id );

		$this->assertSame( 'English media title', $attachment->post_title, 'The untranslated source values must not change.' );
		$this->assertSame( 'English caption', $attachment->post_excerpt );
		$this->assertSame( 'image/jpeg', $attachment->post_mime_type, 'The file itself must never be touched.' );
	}

	/**
	 * Supplies each translatable media field.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provide_media_surfaces() {
		return array(
			'title'       => array( SuggestionSurface::MEDIA_TITLE, 'title' ),
			'alt text'    => array( SuggestionSurface::MEDIA_ALT, 'alt_text' ),
			'caption'     => array( SuggestionSurface::MEDIA_CAPTION, 'caption' ),
			'description' => array( SuggestionSurface::MEDIA_DESCRIPTION, 'description' ),
		);
	}

	/**
	 * Asserts a mis-bound media apply writes nothing.
	 *
	 * @return void
	 */
	public function test_a_mis_bound_media_apply_changes_nothing() {
		$attachment_id = $this->attachment_with_translation();
		$service       = $this->container->get( MediaTranslationService::class );

		$before = $service->metadata_for_language( $attachment_id, 'tr' );

		$preview = $this->preview( SuggestionSurface::MEDIA_ALT, 'media', $attachment_id, 'Onerilen deger' );

		$result = $this->apply->apply(
			$preview->token(),
			$this->context( SuggestionSurface::MEDIA_CAPTION, 'media', $attachment_id )
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( $before, $service->metadata_for_language( $attachment_id, 'tr' ) );
	}
}
