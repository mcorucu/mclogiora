<?php
/**
 * Front-end translation application tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Database\Installer;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Media\MediaTranslationService;
use McLogiora\Menus\MenuTranslationWorkflow;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Strings\StringRepositoryInterface;
use McLogiora\Strings\StringSource;
use McLogiora\Strings\StringSourceType;
use McLogiora\Strings\StringTranslationService;
use McLogiora\Widgets\WidgetTranslationService;
use WP_UnitTestCase;

/**
 * Proves Phase 11's stores actually reach the rendered page.
 *
 * Phase 11 could store a translated string, caption, widget field, and menu.
 * Nothing displayed any of them, because no phase had yet decided what
 * language a request was in. These tests exercise the join: the current
 * language from Phase 12 driving the lookups from Phase 11, through the real
 * WordPress filters a theme actually triggers.
 */
final class FrontendTranslationIntegrationTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Sets up an installed, two-language site in Turkish.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();

		delete_option( 'mclogiora_db_version' );
		$this->container->get( Installer::class )->install();
		$this->container->get( RuntimeReadiness::class )->reset();

		$languages = $this->container->get( LanguageRepositoryInterface::class );

		if ( ! $languages->find_by_code( 'en' ) instanceof Language ) {
			$languages->create( new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, false ) );
			$languages->set_default( 'en' );
		}

		if ( ! $languages->find_by_code( 'tr' ) instanceof Language ) {
			$languages->create( new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ) );
		}

		$context = $this->container->get( LanguageContextInterface::class );
		$context->reset();
		$context->set_requested_code( 'tr' );
	}

	/**
	 * Returns the language context to the default.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->container->get( LanguageContextInterface::class )->set_requested_code( '' );

		parent::tear_down();
	}

	/**
	 * Asserts a stored string translation reaches gettext output.
	 *
	 * @return void
	 */
	public function test_frontend_string_translation_is_applied() {
		$this->store_string( 'Read more', 'mclogiora', '', 'Devamini oku' );

		$this->assertSame( 'Devamini oku', __( 'Read more', 'mclogiora' ) );
	}

	/**
	 * Asserts the default language keeps the original string.
	 *
	 * @return void
	 */
	public function test_default_language_keeps_the_original_string() {
		$this->store_string( 'Read less', 'mclogiora', '', 'Daha az oku' );

		$this->container->get( LanguageContextInterface::class )->set_requested_code( '' );

		$this->assertSame( 'Read less', __( 'Read less', 'mclogiora' ) );
	}

	/**
	 * Asserts a string with no translation returns unchanged.
	 *
	 * @return void
	 */
	public function test_untranslated_string_returns_the_original() {
		$this->assertSame( 'Never translated', __( 'Never translated', 'mclogiora' ) );
	}

	/**
	 * Asserts a gettext context selects the right translation.
	 *
	 * @return void
	 */
	public function test_context_aware_string_translation_is_applied() {
		$this->store_string( 'Post', 'mclogiora', 'noun', 'Yazi' );
		$this->store_string( 'Post', 'mclogiora', 'verb', 'Gonder' );

		$this->assertSame( 'Yazi', _x( 'Post', 'noun', 'mclogiora' ) );
		$this->assertSame( 'Gonder', _x( 'Post', 'verb', 'mclogiora' ) );
	}

	/**
	 * Asserts a text domain is part of a string's identity.
	 *
	 * @return void
	 */
	public function test_translation_is_scoped_to_its_text_domain() {
		$this->store_string( 'Settings', 'mclogiora', '', 'Ayarlar' );

		$this->assertSame( 'Ayarlar', __( 'Settings', 'mclogiora' ) );
		$this->assertSame( 'Settings', __( 'Settings', 'default' ), 'Another domain must not borrow the translation.' );
	}

	/**
	 * Asserts translated alternative text and caption reach the media APIs.
	 *
	 * @return void
	 */
	public function test_frontend_media_translation_is_applied() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'sunset.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Sunset',
				'post_excerpt'   => 'An English caption',
			)
		);

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Sunset over the sea' );

		$saved = $this->container->get( MediaTranslationService::class )->save(
			$attachment_id,
			'tr',
			array(
				'alt_text' => 'Deniz uzerinde gun batimi',
				'caption'  => 'Turkce altyazi',
			)
		);

		$this->assertNotWPError( $saved );

		$this->assertSame( 'Deniz uzerinde gun batimi', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Turkce altyazi', wp_get_attachment_caption( $attachment_id ) );
	}

	/**
	 * Asserts untranslated media keeps its original metadata.
	 *
	 * @return void
	 */
	public function test_untranslated_media_keeps_its_original_metadata() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'plain.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Plain',
				'post_excerpt'   => 'Original caption',
			)
		);

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Original alt' );

		$this->assertSame( 'Original alt', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Original caption', wp_get_attachment_caption( $attachment_id ) );
	}

	/**
	 * Asserts a translated widget body is rendered without rewriting storage.
	 *
	 * @return void
	 */
	public function test_frontend_widget_translation_is_applied() {
		$instance = array(
			'title' => 'Hello',
			'text'  => 'English body',
		);

		update_option( 'widget_text', array( 2 => $instance ) );

		$saved = $this->container->get( WidgetTranslationService::class )->save(
			'text',
			'2',
			'tr',
			array(
				'title' => 'Merhaba',
				'text'  => 'Turkce govde',
			)
		);

		$this->assertNotWPError( $saved );

		$widget = (object) array( 'number' => 2 );

		$this->assertSame(
			'Turkce govde',
			apply_filters( 'widget_text', 'English body', $instance, $widget )
		);

		$stored = get_option( 'widget_text' );

		$this->assertSame( 'English body', $stored[2]['text'], 'Rendering must never rewrite the stored widget.' );
	}

	/**
	 * Asserts an untranslated widget renders its source text.
	 *
	 * @return void
	 */
	public function test_untranslated_widget_renders_its_source_text() {
		$instance = array( 'text' => 'Only English' );

		$this->assertSame(
			'Only English',
			apply_filters( 'widget_text', 'Only English', $instance, (object) array( 'number' => 9 ) )
		);
	}

	/**
	 * Asserts the translated menu is chosen for the current language.
	 *
	 * @return void
	 */
	public function test_translated_menu_is_selected() {
		$menu_id = wp_create_nav_menu( 'Primary ' . uniqid( '', false ) );

		wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => 'Home',
				'menu-item-url'    => home_url( '/' ),
				'menu-item-status' => 'publish',
			)
		);

		$created = $this->container->get( MenuTranslationWorkflow::class )->create_translation( $menu_id, 'tr', 'Ana Menu' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		$args = apply_filters( 'wp_nav_menu_args', array( 'menu' => $menu_id ) );

		$this->assertSame( (int) $created['menu_id'], (int) $args['menu'], 'The Turkish menu must be selected.' );
	}

	/**
	 * Asserts an untranslated menu falls back to the source menu.
	 *
	 * Menus are the deliberate exception to the 404 policy. Navigation is
	 * wayfinding: an untranslated menu still works, whereas a vanished one
	 * strands the visitor on a page with no way out.
	 *
	 * @return void
	 */
	public function test_untranslated_menu_falls_back_to_the_source() {
		$menu_id = wp_create_nav_menu( 'Lonely ' . uniqid( '', false ) );

		$args = apply_filters( 'wp_nav_menu_args', array( 'menu' => $menu_id ) );

		$this->assertSame( $menu_id, $args['menu'], 'A missing menu translation must fall back, not disappear.' );
	}

	/**
	 * Stores a source string and its Turkish translation.
	 *
	 * @param string $text Source text.
	 * @param string $domain Text domain.
	 * @param string $context Gettext context.
	 * @param string $translated Translated text.
	 * @return void
	 */
	private function store_string( $text, $domain, $context, $translated ) {
		$repository = $this->container->get( StringRepositoryInterface::class );

		$source = $repository->register(
			new StringSource( 0, $text, $domain, $context, StringSourceType::MANUAL, 'tests', 0, false )
		);

		$this->assertNotWPError( $source );

		$saved = $this->container->get( StringTranslationService::class )
			->save_translation( $source->id(), 'tr', $translated );

		$this->assertNotWPError( $saved );
	}
}
