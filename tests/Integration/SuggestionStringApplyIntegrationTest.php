<?php
/**
 * String Translation suggestion apply integration tests.
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
use McLogiora\Strings\StringRepositoryInterface;
use McLogiora\Strings\StringSource;
use McLogiora\Strings\StringSourceType;
use McLogiora\Strings\StringTranslationService;
use McLogiora\Suggestions\SuggestionPreview;
use McLogiora\Suggestions\SuggestionPreviewStore;
use McLogiora\Suggestions\SuggestionResult;
use McLogiora\Suggestions\SuggestionSurface;
use McLogiora\Suggestions\TranslationSuggestionApplyService;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_UnitTestCase;

/**
 * Proves the String Translation apply path against real persistence.
 *
 * Strings are the surface that does not fit the relation model, so this file
 * exists partly to prove the absence of something: applying a suggested string
 * must store a translation and must NOT invent a translation relation row to
 * make the four surfaces look symmetrical in a UI.
 *
 * It also pins the string identity. A string is identified by its text, its
 * text domain and its context together -- two identical English strings in
 * different domains are different strings -- so an applied suggestion must
 * land against exactly one of them and leave the source triple untouched.
 */
final class SuggestionStringApplyIntegrationTest extends WP_UnitTestCase {
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
	 * String repository.
	 *
	 * @var StringRepositoryInterface
	 */
	private $strings;

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

		$this->strings  = $this->container->get( StringRepositoryInterface::class );
		$this->previews = new SuggestionPreviewStore();

		$this->apply = new TranslationSuggestionApplyService(
			$this->previews,
			$this->container->get( TranslationWorkflowService::class ),
			$this->container->get( MediaTranslationService::class ),
			$this->container->get( StringTranslationService::class )
		);
	}

	/**
	 * Registers a source string and returns its stored identifier.
	 *
	 * @param string $text Source text.
	 * @param string $domain Text domain.
	 * @param string $context Gettext context.
	 * @return int
	 */
	private function register_string( $text, $domain = 'mclogiora-demo', $context = 'checkout' ) {
		$registered = $this->strings->register(
			new StringSource( 0, $text, $domain, $context, StringSourceType::MANUAL, 'test', 0, false )
		);

		$this->assertNotWPError( $registered );

		$source = $this->strings->find_by_hash(
			( new StringSource( 0, $text, $domain, $context ) )->hash()
		);

		$this->assertInstanceOf( StringSource::class, $source );

		return (int) $source->id();
	}

	/**
	 * Stores a preview bound to a string.
	 *
	 * @param int    $string_id String identifier.
	 * @param string $text Suggested translation.
	 * @param string $language Target language.
	 * @param int    $user_id Owning user.
	 * @return SuggestionPreview
	 */
	private function preview( $string_id, $text, $language = 'tr', $user_id = 0 ) {
		$preview = $this->previews->create(
			new SuggestionResult( $text, 'deepl' ),
			array(
				'user_id'         => $user_id > 0 ? $user_id : $this->editor_id,
				'object_type'     => 'string',
				'source_id'       => (string) $string_id,
				'target_id'       => (string) $string_id,
				'surface'         => SuggestionSurface::STRING,
				'source_language' => 'en',
				'target_language' => $language,
			)
		);

		$this->assertInstanceOf( SuggestionPreview::class, $preview );

		return $preview;
	}

	/**
	 * Returns the binding context for a string.
	 *
	 * @param int    $string_id String identifier.
	 * @param string $language Target language.
	 * @param int    $user_id Acting user.
	 * @return array<string,mixed>
	 */
	private function context( $string_id, $language = 'tr', $user_id = 0 ) {
		return array(
			'user_id'         => $user_id > 0 ? $user_id : $this->editor_id,
			'object_type'     => 'string',
			'target_id'       => (string) $string_id,
			'surface'         => SuggestionSurface::STRING,
			'target_language' => $language,
		);
	}

	/**
	 * Asserts a suggested string is stored, marked and identity-preserving.
	 *
	 * @return void
	 */
	public function test_applying_a_string_stores_the_translation_and_marks_it_suggested() {
		$string_id = $this->register_string( 'Add to cart' );

		$preview = $this->preview( $string_id, 'Sepete ekle' );

		$result = $this->apply->apply( $preview->token(), $this->context( $string_id ) );

		$this->assertInstanceOf( SuggestionPreview::class, $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$stored = $this->strings->find_translation( $string_id, 'tr' );

		$this->assertNotNull( $stored );
		$this->assertSame( 'Sepete ekle', $stored->text() );
		$this->assertSame(
			TranslationStatus::MACHINE_SUGGESTED,
			$stored->status(),
			'A suggested string must be recorded as suggested, not as a finished translation.'
		);

		$source = $this->strings->find( $string_id );

		$this->assertSame( 'Add to cart', $source->text(), 'The source string must never change.' );
		$this->assertSame( 'mclogiora-demo', $source->text_domain(), 'The text domain is part of the string identity.' );
		$this->assertSame( 'checkout', $source->context(), 'The context is part of the string identity.' );
	}

	/**
	 * Asserts no translation relation row is invented for a string.
	 *
	 * @return void
	 */
	public function test_applying_a_string_creates_no_translation_relation() {
		$string_id = $this->register_string( 'Continue shopping' );

		$preview = $this->preview( $string_id, 'Alisverise devam et' );

		$this->apply->apply( $preview->token(), $this->context( $string_id ) );

		$relations = $this->container->get( TranslationRelationRepositoryInterface::class );

		$this->assertNull(
			$relations->find_item( ContentType::STRING, (string) $string_id, 'tr' ),
			'String translations are not relation-backed and must not gain a relation row.'
		);
	}

	/**
	 * Asserts placeholders survive an applied string exactly.
	 *
	 * @return void
	 */
	public function test_placeholders_survive_an_applied_string_exactly() {
		$string_id = $this->register_string( 'Hello %1$s, you have %2$d items.' );

		$preview = $this->preview( $string_id, 'Merhaba %1$s, %2$d urununuz var.' );

		$result = $this->apply->apply( $preview->token(), $this->context( $string_id ) );

		$this->assertInstanceOf( SuggestionPreview::class, $result );

		$stored = $this->strings->find_translation( $string_id, 'tr' );

		$this->assertSame( 'Merhaba %1$s, %2$d urununuz var.', $stored->text() );
		$this->assertSame( 1, substr_count( $stored->text(), '%1$s' ), 'The first placeholder must appear exactly once.' );
		$this->assertSame( 1, substr_count( $stored->text(), '%2$d' ), 'The second placeholder must appear exactly once.' );
	}

	/**
	 * Asserts a preview cannot be applied twice.
	 *
	 * @return void
	 */
	public function test_a_string_preview_cannot_be_applied_twice() {
		$string_id = $this->register_string( 'Checkout' );

		$preview = $this->preview( $string_id, 'Odeme' );

		$this->assertInstanceOf( SuggestionPreview::class, $this->apply->apply( $preview->token(), $this->context( $string_id ) ) );

		$second = $this->apply->apply( $preview->token(), $this->context( $string_id ) );

		$this->assertTrue( is_wp_error( $second ) );
		$this->assertSame( 'Odeme', $this->strings->find_translation( $string_id, 'tr' )->text(), 'A refused second apply must not rewrite.' );
	}

	/**
	 * Asserts every mis-bound string apply stores nothing.
	 *
	 * @param string $mutate Binding fact to corrupt.
	 * @return void
	 *
	 * @dataProvider provide_string_binding_mutations
	 */
	public function test_a_mis_bound_string_apply_stores_nothing( $mutate ) {
		$string_id = $this->register_string( 'Order summary' );
		$other_id  = $this->register_string( 'Shipping address' );

		$preview = $this->preview( $string_id, 'Siparis ozeti' );

		$context = $this->context( $string_id );

		switch ( $mutate ) {
			case 'identity':
				$context['target_id'] = (string) $other_id;
				break;

			case 'language':
				$context['target_language'] = 'de';
				break;

			case 'user':
				$context['user_id'] = self::factory()->user->create( array( 'role' => 'administrator' ) );
				break;

			case 'surface':
				$context['surface'] = SuggestionSurface::POST_TITLE;
				break;
		}

		$result = $this->apply->apply( $preview->token(), $context );

		$this->assertTrue( is_wp_error( $result ), "A string preview applied with a wrong {$mutate} must be refused." );
		$this->assertNull( $this->strings->find_translation( $string_id, 'tr' ), 'Nothing may be stored for the bound string.' );
		$this->assertNull( $this->strings->find_translation( $other_id, 'tr' ), 'Nothing may be stored for any other string.' );
		$this->assertNull( $this->strings->find_translation( $string_id, 'de' ) );
	}

	/**
	 * Supplies each binding fact that must be revalidated.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_string_binding_mutations() {
		return array(
			'string identity' => array( 'identity' ),
			'target language' => array( 'language' ),
			'owning user'     => array( 'user' ),
			'surface'         => array( 'surface' ),
		);
	}

	/**
	 * Asserts an expired preview stores nothing.
	 *
	 * @return void
	 */
	public function test_an_expired_string_preview_stores_nothing() {
		$string_id = $this->register_string( 'Payment method' );

		$preview = $this->preview( $string_id, 'Odeme yontemi' );

		delete_transient( 'mclogiora_suggestion_preview_' . $preview->token() );

		$result = $this->apply->apply( $preview->token(), $this->context( $string_id ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertNull( $this->strings->find_translation( $string_id, 'tr' ) );
	}

	/**
	 * Asserts two strings differing only by domain stay separate.
	 *
	 * @return void
	 */
	public function test_strings_differing_only_by_domain_are_distinct_targets() {
		$first  = $this->register_string( 'Save', 'plugin-a', '' );
		$second = $this->register_string( 'Save', 'plugin-b', '' );

		$this->assertNotSame( $first, $second, 'The text domain is part of the string identity.' );

		$preview = $this->preview( $first, 'Kaydet' );

		$this->apply->apply( $preview->token(), $this->context( $first ) );

		$this->assertSame( 'Kaydet', $this->strings->find_translation( $first, 'tr' )->text() );
		$this->assertNull(
			$this->strings->find_translation( $second, 'tr' ),
			'Applying to one domain must not translate the identically worded string in another.'
		);
	}
}
