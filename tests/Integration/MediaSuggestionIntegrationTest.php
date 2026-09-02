<?php
/**
 * Media suggestion surface tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Admin\MediaTranslationFields;
use McLogiora\Admin\SuggestionAdminController;
use McLogiora\Admin\SuggestionAdminState;
use McLogiora\Core\Application;
use McLogiora\Database\Installer;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Media\MediaTranslationService;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\HttpTransport;
use McLogiora\Suggestions\LlmInstructions;
use McLogiora\Suggestions\ProviderRegistry;
use McLogiora\Suggestions\Providers\DeepLProvider;
use McLogiora\Suggestions\Providers\WordPressAiProvider;
use McLogiora\Suggestions\SuggestionSettings;
use McLogiora\Suggestions\SuggestionSurface;
use McLogiora\Suggestions\TranslationSuggestionService;
use McLogiora\Tests\Support\EchoTransport;
use WP_Ajax_UnitTestCase;

/**
 * Proves the attachment metadata suggestion surface.
 *
 * Media has one hazard the other surfaces do not. `MediaTranslationService::save()`
 * replaces the whole per-language row, so a caller that supplies only the field it
 * means to change silently blanks the other three. The apply service reads all four
 * and writes all four for exactly that reason, and the point of this file is to
 * prove it rather than trust it: every Apply asserts the complete row, not just the
 * field that was meant to move.
 *
 * The second thing worth asserting is that nothing here can touch the file. Media
 * is the one surface where a translation shares a binary with every other language,
 * so the attachment id, its path, its URL, its MIME type and its dimensions are
 * checked across every Apply.
 */
final class MediaSuggestionIntegrationTest extends WP_Ajax_UnitTestCase {
	/**
	 * A credential distinctive enough to find anywhere.
	 */
	const SECRET = 'deepl-live-MEDIASTATE-MUST-NOT-LEAK-9926';

	const NATIVE_TITLE       = 'NATIVE MEDIA TITLE';
	const NATIVE_ALT         = 'NATIVE MEDIA ALT';
	const NATIVE_CAPTION     = 'NATIVE MEDIA CAPTION';
	const NATIVE_DESCRIPTION = 'NATIVE MEDIA DESCRIPTION';

	const STORED_TITLE       = 'EXISTING TRANSLATED TITLE';
	const STORED_ALT         = 'EXISTING TRANSLATED ALT';
	const STORED_CAPTION     = 'EXISTING TRANSLATED CAPTION';
	const STORED_DESCRIPTION = 'EXISTING TRANSLATED DESCRIPTION';

	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Recording transport that echoes the submitted text.
	 *
	 * @var EchoTransport
	 */
	private $transport;

	/**
	 * Attachment identifier.
	 *
	 * @var int
	 */
	private $attachment_id = 0;

	/**
	 * Sets up a ready site with a fully translated attachment row.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		set_current_screen( 'post' );

		$this->container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();

		delete_option( 'mclogiora_db_version' );
		$this->container->get( Installer::class )->install();

		$languages = $this->container->get( LanguageRepositoryInterface::class );

		if ( ! $languages->find_by_code( 'en' ) instanceof Language ) {
			$languages->create( new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, false ) );
		}

		$languages->set_default( 'en' );

		if ( ! $languages->find_by_code( 'tr' ) instanceof Language ) {
			$languages->create( new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ) );
		}

		$this->transport = new EchoTransport( 'TR::' );

		$this->container->set( HttpTransport::class, $this->transport );
		$this->container->set(
			ProviderRegistry::class,
			function () {
				/*
				 * Every provider is registered even though these tests only use
				 * DeepL. The container is a process-wide singleton, so a
				 * one-provider registry left behind here would follow the suite
				 * into other tests and hide the screens that iterate providers.
				 */
				$registry    = new ProviderRegistry();
				$credentials = new CredentialStore();
				$prompts     = new LlmInstructions();

				$registry->add( new WordPressAiProvider( $prompts ) );
				$registry->add( new DeepLProvider( $this->transport, $credentials ) );

				return $registry;
			}
		);
		$this->container->set(
			TranslationSuggestionService::class,
			function () {
				return new TranslationSuggestionService(
					new SuggestionSettings(),
					$this->container->get( ProviderRegistry::class )
				);
			}
		);

		( new CredentialStore() )->save( 'deepl', self::SECRET );

		$settings = $this->container->get( SuggestionSettings::class );

		$settings->set_enabled( true );
		$settings->set_provider( 'deepl' );

		( new SuggestionAdminController() )->register( $this->container );

		$this->attachment_id = (int) self::factory()->attachment->create_object(
			array(
				'file'           => 'mcq-media-fixture.png',
				'post_mime_type' => 'image/png',
				'post_title'     => self::NATIVE_TITLE,
				'post_excerpt'   => self::NATIVE_CAPTION,
				'post_content'   => self::NATIVE_DESCRIPTION,
				'post_status'    => 'inherit',
			)
		);

		update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', self::NATIVE_ALT );
		update_post_meta(
			$this->attachment_id,
			'_wp_attachment_metadata',
			array(
				'width'  => 800,
				'height' => 600,
				'file'   => 'mcq-media-fixture.png',
			)
		);

		$saved = $this->container->get( MediaTranslationService::class )->save(
			$this->attachment_id,
			'tr',
			array(
				'title'       => self::STORED_TITLE,
				'alt_text'    => self::STORED_ALT,
				'caption'     => self::STORED_CAPTION,
				'description' => self::STORED_DESCRIPTION,
			)
		);

		$this->assertFalse( is_wp_error( $saved ), is_wp_error( $saved ) ? $saved->get_error_message() : '' );
	}

	/**
	 * Clears configuration between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		( new CredentialStore() )->remove( 'deepl' );

		delete_option( SuggestionSettings::OPTION_ENABLED );
		delete_option( SuggestionSettings::OPTION_PROVIDER );

		parent::tear_down();
	}

	/**
	 * Returns the state an admin screen would receive.
	 *
	 * @return array<string,mixed>
	 */
	private function state() {
		return $this->container->get( SuggestionAdminState::class )->current();
	}

	/**
	 * Returns the stored translated row as the repository holds it.
	 *
	 * Read from the repository rather than the service, because the service
	 * merges native fallbacks over empty fields and this file needs to see what
	 * was actually written.
	 *
	 * @return array<string,string>
	 */
	private function stored_row() {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT translated_title, translated_alt_text, translated_caption, translated_description, status
				FROM {$wpdb->prefix}mclogiora_media_translations
				WHERE attachment_id = %d AND language_code = %s",
				$this->attachment_id,
				'tr'
			),
			ARRAY_A
		);

		$this->assertIsArray( $row, 'Expected a stored translated row.' );

		return array(
			'title'       => (string) $row['translated_title'],
			'alt_text'    => (string) $row['translated_alt_text'],
			'caption'     => (string) $row['translated_caption'],
			'description' => (string) $row['translated_description'],
			'status'      => (string) $row['status'],
		);
	}

	/**
	 * Asserts the complete translated row, field by field.
	 *
	 * @param array<string,string> $expected Expected four values.
	 * @param string               $context Assertion context.
	 * @return void
	 */
	private function assert_row( array $expected, $context ) {
		$actual = $this->stored_row();

		foreach ( array( 'title', 'alt_text', 'caption', 'description' ) as $field ) {
			$this->assertSame(
				$expected[ $field ],
				$actual[ $field ],
				sprintf( '%s: %s', $context, $field )
			);
		}
	}

	/**
	 * Returns the attachment's file identity.
	 *
	 * @return array<string,mixed>
	 */
	private function file_identity() {
		$meta = (array) get_post_meta( $this->attachment_id, '_wp_attachment_metadata', true );

		return array(
			'id'         => $this->attachment_id,
			'file'       => (string) get_attached_file( $this->attachment_id ),
			'url'        => (string) wp_get_attachment_url( $this->attachment_id ),
			'mime'       => (string) get_post_mime_type( $this->attachment_id ),
			'width'      => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'     => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'meta_file'  => isset( $meta['file'] ) ? (string) $meta['file'] : '',
			'post_type'  => (string) get_post_type( $this->attachment_id ),
		);
	}

	/**
	 * Returns the attachment's own default-language metadata.
	 *
	 * @return array<string,string>
	 */
	private function native_metadata() {
		$attachment = get_post( $this->attachment_id );

		return array(
			'title'       => (string) $attachment->post_title,
			'alt'         => (string) get_post_meta( $this->attachment_id, '_wp_attachment_image_alt', true ),
			'caption'     => (string) $attachment->post_excerpt,
			'description' => (string) $attachment->post_content,
		);
	}

	/**
	 * Builds a request the way the admin script would.
	 *
	 * @param string              $surface Suggestion surface.
	 * @param array<string,mixed> $extra Extra fields.
	 * @param int|null            $attachment_id Attachment override.
	 * @param string              $language Language override.
	 * @return void
	 */
	private function admin_post( $surface, array $extra = array(), $attachment_id = null, $language = 'tr' ) {
		$_POST = array_merge(
			array(
				'nonce'    => $this->state()['nonce'],
				'surface'  => $surface,
				'objectId' => null === $attachment_id ? $this->attachment_id : $attachment_id,
				'language' => $language,
			),
			$extra
		);
	}

	/**
	 * Dispatches an action and returns the decoded response.
	 *
	 * @param string $action AJAX action name.
	 * @return array<string,mixed>|null
	 */
	private function dispatch( $action ) {
		$this->_last_response = '';

		try {
			$this->_handleAjax( $action );
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( \WPAjaxDieStopException $e ) {
			unset( $e );
		}

		$decoded = json_decode( (string) $this->_last_response, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Generates a suggestion for one media field.
	 *
	 * @param string $surface Suggestion surface.
	 * @return array<string,mixed>
	 */
	private function generate( $surface ) {
		$this->admin_post( $surface );

		$response = $this->dispatch( $this->state()['actions']['generate'] );

		$this->assertIsArray( $response );
		$this->assertTrue( (bool) $response['success'], (string) wp_json_encode( $response ) );

		return $response['data'];
	}

	/**
	 * Applies a preview token for one media field.
	 *
	 * @param string $surface Suggestion surface.
	 * @param string $token Preview token.
	 * @return array<string,mixed>|null
	 */
	private function apply( $surface, $token ) {
		$this->admin_post( $surface, array( 'token' => $token ) );

		return $this->dispatch( $this->state()['actions']['apply'] );
	}

	/**
	 * Generates then applies one media field.
	 *
	 * @param string $surface Suggestion surface.
	 * @return array<string,mixed>
	 */
	private function generate_and_apply( $surface ) {
		$preview  = $this->generate( $surface );
		$response = $this->apply( $surface, $preview['token'] );

		$this->assertIsArray( $response );
		$this->assertTrue( (bool) $response['success'], (string) wp_json_encode( $response ) );

		return $response['data'];
	}

	/**
	 * Returns the body the provider was last sent.
	 *
	 * @return string
	 */
	private function sent_body() {
		$last = $this->transport->last_request();

		$this->assertNotNull( $last, 'Expected a provider request.' );

		return rawurldecode( is_string( $last['body'] ) ? $last['body'] : (string) wp_json_encode( $last['body'] ) );
	}

	/**
	 * Asserts the screen state carries no credential.
	 *
	 * @return void
	 */
	public function test_the_admin_state_carries_no_credential() {
		$payload = (string) wp_json_encode( $this->state() );

		$this->assertStringNotContainsString( self::SECRET, $payload );
		$this->assertStringNotContainsString( 'MUST-NOT-LEAK', $payload );
		$this->assertStringNotContainsString( 'deepl-live', $payload );
		$this->assertStringNotContainsString( 'Authorization', $payload );
		$this->assertStringNotContainsString( 'DeepL-Auth-Key', $payload );
	}

	/**
	 * Asserts no media text is shipped to the browser.
	 *
	 * @return void
	 */
	public function test_the_admin_state_ships_no_media_text() {
		$payload = (string) wp_json_encode( $this->state() );

		$this->assertStringNotContainsString( self::NATIVE_TITLE, $payload );
		$this->assertStringNotContainsString( self::NATIVE_DESCRIPTION, $payload );
	}

	/**
	 * Asserts rendering the attachment fields reaches no provider.
	 *
	 * @return void
	 */
	public function test_rendering_the_media_fields_makes_no_provider_request() {
		$fields = new MediaTranslationFields();

		$fields->register( $this->container );

		ob_start();
		$fields->render_fields( get_post( $this->attachment_id ) );
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-mclogiora-suggest', $markup );
		$this->assertSame( array(), $this->transport->requests(), 'Opening the screen must contact no provider.' );
	}

	/**
	 * Asserts the rendered markup offers exactly the four metadata fields.
	 *
	 * @return void
	 */
	public function test_only_the_four_metadata_fields_are_offered() {
		$fields = new MediaTranslationFields();

		$fields->register( $this->container );

		ob_start();
		$fields->render_fields( get_post( $this->attachment_id ) );
		$markup = (string) ob_get_clean();

		foreach ( array( 'media_title', 'media_alt', 'media_caption', 'media_description' ) as $surface ) {
			$this->assertStringContainsString( 'data-surface="' . $surface . '"', $markup );
		}

		$this->assertSame( 4, substr_count( $markup, 'data-mclogiora-generate' ), 'Exactly four fields may be offered.' );

		foreach ( array( 'filename', 'file_path', 'attachment_url', 'mime', 'dimensions', 'media_file' ) as $forbidden ) {
			$this->assertStringNotContainsString( 'data-surface="' . $forbidden . '"', $markup );
		}
	}

	/**
	 * Asserts the corrected form structure survives the new controls.
	 *
	 * @return void
	 */
	public function test_the_markup_opens_no_form_and_no_submit_button() {
		$fields = new MediaTranslationFields();

		$fields->register( $this->container );

		ob_start();
		$fields->render_fields( get_post( $this->attachment_id ) );
		$markup = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<form', $markup, 'The fields must not open a form inside the post form.' );

		$buttons = array();

		preg_match_all( '/<button[^>]*data-mclogiora-generate[^>]*>/', $markup, $buttons );

		$this->assertCount( 4, $buttons[0] );

		foreach ( $buttons[0] as $button ) {
			$this->assertStringContainsString( 'type="button"', $button );
		}
	}

	/**
	 * Asserts each field's Generate sends that field's authoritative source.
	 *
	 * @return void
	 */
	public function test_generate_sends_the_authoritative_source_for_each_field() {
		$expected = array(
			SuggestionSurface::MEDIA_TITLE       => self::NATIVE_TITLE,
			SuggestionSurface::MEDIA_ALT         => self::NATIVE_ALT,
			SuggestionSurface::MEDIA_CAPTION     => self::NATIVE_CAPTION,
			SuggestionSurface::MEDIA_DESCRIPTION => self::NATIVE_DESCRIPTION,
		);

		foreach ( $expected as $surface => $source ) {
			$this->generate( $surface );

			$body = $this->sent_body();

			$this->assertStringContainsString( $source, $body, $surface );
			$this->assertStringNotContainsString( 'EXISTING TRANSLATED', $body, $surface . ': the stored translation is not the source.' );
		}
	}

	/**
	 * Asserts the browser cannot choose what gets translated.
	 *
	 * @return void
	 */
	public function test_arbitrary_request_text_never_reaches_the_provider() {
		$this->admin_post(
			SuggestionSurface::MEDIA_TITLE,
			array(
				'text'       => 'ATTACKER CONTROLLED TEXT',
				'sourceText' => 'ATTACKER CONTROLLED TEXT',
				'source'     => 'ATTACKER CONTROLLED TEXT',
				'content'    => 'ATTACKER CONTROLLED TEXT',
				'value'      => 'ATTACKER CONTROLLED TEXT',
			)
		);

		$this->dispatch( $this->state()['actions']['generate'] );

		$body = $this->sent_body();

		$this->assertStringNotContainsString( 'ATTACKER', $body );
		$this->assertStringContainsString( self::NATIVE_TITLE, $body );
	}

	/**
	 * Asserts Generate writes nothing.
	 *
	 * @return void
	 */
	public function test_generate_changes_nothing() {
		$file = $this->file_identity();

		foreach ( array( SuggestionSurface::MEDIA_TITLE, SuggestionSurface::MEDIA_ALT, SuggestionSurface::MEDIA_CAPTION, SuggestionSurface::MEDIA_DESCRIPTION ) as $surface ) {
			$this->generate( $surface );
		}

		$this->assert_row(
			array(
				'title'       => self::STORED_TITLE,
				'alt_text'    => self::STORED_ALT,
				'caption'     => self::STORED_CAPTION,
				'description' => self::STORED_DESCRIPTION,
			),
			'generate must not write'
		);

		$this->assertSame( $file, $this->file_identity() );
	}

	/**
	 * Asserts applying the title leaves the other three untouched.
	 *
	 * @return void
	 */
	public function test_applying_the_title_preserves_its_siblings() {
		$this->generate_and_apply( SuggestionSurface::MEDIA_TITLE );

		$this->assert_row(
			array(
				'title'       => 'TR::' . self::NATIVE_TITLE,
				'alt_text'    => self::STORED_ALT,
				'caption'     => self::STORED_CAPTION,
				'description' => self::STORED_DESCRIPTION,
			),
			'title apply'
		);
	}

	/**
	 * Asserts applying the alt text leaves the other three untouched.
	 *
	 * @return void
	 */
	public function test_applying_the_alt_preserves_its_siblings() {
		$this->generate_and_apply( SuggestionSurface::MEDIA_ALT );

		$this->assert_row(
			array(
				'title'       => self::STORED_TITLE,
				'alt_text'    => 'TR::' . self::NATIVE_ALT,
				'caption'     => self::STORED_CAPTION,
				'description' => self::STORED_DESCRIPTION,
			),
			'alt apply'
		);
	}

	/**
	 * Asserts applying the caption leaves the other three untouched.
	 *
	 * @return void
	 */
	public function test_applying_the_caption_preserves_its_siblings() {
		$this->generate_and_apply( SuggestionSurface::MEDIA_CAPTION );

		$this->assert_row(
			array(
				'title'       => self::STORED_TITLE,
				'alt_text'    => self::STORED_ALT,
				'caption'     => 'TR::' . self::NATIVE_CAPTION,
				'description' => self::STORED_DESCRIPTION,
			),
			'caption apply'
		);
	}

	/**
	 * Asserts applying the description leaves the other three untouched.
	 *
	 * @return void
	 */
	public function test_applying_the_description_preserves_its_siblings() {
		$this->generate_and_apply( SuggestionSurface::MEDIA_DESCRIPTION );

		$this->assert_row(
			array(
				'title'       => self::STORED_TITLE,
				'alt_text'    => self::STORED_ALT,
				'caption'     => self::STORED_CAPTION,
				'description' => 'TR::' . self::NATIVE_DESCRIPTION,
			),
			'description apply'
		);
	}

	/**
	 * Asserts four successive applies accumulate rather than overwrite.
	 *
	 * Applying every field one at a time is the sequence most likely to expose a
	 * partial write: each Apply must carry the results of the ones before it.
	 *
	 * @return void
	 */
	public function test_applying_every_field_in_turn_accumulates() {
		$this->generate_and_apply( SuggestionSurface::MEDIA_TITLE );
		$this->generate_and_apply( SuggestionSurface::MEDIA_ALT );
		$this->generate_and_apply( SuggestionSurface::MEDIA_CAPTION );
		$this->generate_and_apply( SuggestionSurface::MEDIA_DESCRIPTION );

		$this->assert_row(
			array(
				'title'       => 'TR::' . self::NATIVE_TITLE,
				'alt_text'    => 'TR::' . self::NATIVE_ALT,
				'caption'     => 'TR::' . self::NATIVE_CAPTION,
				'description' => 'TR::' . self::NATIVE_DESCRIPTION,
			),
			'four applies in turn'
		);
	}

	/**
	 * Asserts the attachment's own metadata is never rewritten.
	 *
	 * @return void
	 */
	public function test_apply_leaves_the_native_metadata_untouched() {
		$before = $this->native_metadata();

		$this->generate_and_apply( SuggestionSurface::MEDIA_TITLE );
		$this->generate_and_apply( SuggestionSurface::MEDIA_ALT );

		$this->assertSame( $before, $this->native_metadata() );
		$this->assertSame( self::NATIVE_TITLE, $this->native_metadata()['title'] );
	}

	/**
	 * Asserts the file itself is never touched.
	 *
	 * @return void
	 */
	public function test_apply_leaves_the_file_untouched() {
		$before = $this->file_identity();

		$this->generate_and_apply( SuggestionSurface::MEDIA_TITLE );
		$this->generate_and_apply( SuggestionSurface::MEDIA_CAPTION );

		$after = $this->file_identity();

		$this->assertSame( $before, $after );
		$this->assertSame( 'image/png', $after['mime'] );
		$this->assertSame( 800, $after['width'] );
		$this->assertSame( 600, $after['height'] );
		$this->assertSame( 'attachment', $after['post_type'] );
	}

	/**
	 * Asserts media reports its real status rather than a borrowed one.
	 *
	 * @return void
	 */
	public function test_media_reports_its_real_status() {
		$data = $this->generate_and_apply( SuggestionSurface::MEDIA_TITLE );

		$this->assertSame( TranslationStatus::TRANSLATED, $data['status'] );
		$this->assertNotSame(
			TranslationStatus::MACHINE_SUGGESTED,
			$data['status'],
			'Media storage has no machine-suggested state and must not claim one.'
		);
		$this->assertSame( TranslationStatus::TRANSLATED, $this->stored_row()['status'] );
	}

	/**
	 * Asserts Regenerate is one more explicit call that writes nothing.
	 *
	 * @return void
	 */
	public function test_regenerate_is_one_more_explicit_call() {
		$first = $this->generate( SuggestionSurface::MEDIA_TITLE );

		$this->assertCount( 1, $this->transport->requests() );

		$second = $this->generate( SuggestionSurface::MEDIA_TITLE );

		$this->assertCount( 2, $this->transport->requests() );
		$this->assertNotSame( $first['token'], $second['token'] );

		$this->assert_row(
			array(
				'title'       => self::STORED_TITLE,
				'alt_text'    => self::STORED_ALT,
				'caption'     => self::STORED_CAPTION,
				'description' => self::STORED_DESCRIPTION,
			),
			'regenerate'
		);
	}

	/**
	 * Asserts Discard writes nothing and invalidates the token.
	 *
	 * @return void
	 */
	public function test_discard_writes_nothing_and_invalidates_the_token() {
		$preview = $this->generate( SuggestionSurface::MEDIA_TITLE );
		$before  = count( $this->transport->requests() );

		$this->admin_post( SuggestionSurface::MEDIA_TITLE, array( 'token' => $preview['token'] ) );

		$discarded = $this->dispatch( $this->state()['actions']['discard'] );

		$this->assertIsArray( $discarded );
		$this->assertTrue( (bool) $discarded['success'] );
		$this->assertCount( $before, $this->transport->requests(), 'Discard must reach no provider.' );

		$reapplied = $this->apply( SuggestionSurface::MEDIA_TITLE, $preview['token'] );

		$this->assertIsArray( $reapplied );
		$this->assertFalse( (bool) $reapplied['success'] );

		$this->assert_row(
			array(
				'title'       => self::STORED_TITLE,
				'alt_text'    => self::STORED_ALT,
				'caption'     => self::STORED_CAPTION,
				'description' => self::STORED_DESCRIPTION,
			),
			'discard'
		);
	}

	/**
	 * Asserts a title preview cannot be applied to the alt text.
	 *
	 * @return void
	 */
	public function test_a_title_preview_cannot_be_applied_to_the_alt_text() {
		$preview = $this->generate( SuggestionSurface::MEDIA_TITLE );

		$response = $this->apply( SuggestionSurface::MEDIA_ALT, $preview['token'] );

		$this->assertIsArray( $response );
		$this->assertFalse( (bool) $response['success'] );

		$this->assert_row(
			array(
				'title'       => self::STORED_TITLE,
				'alt_text'    => self::STORED_ALT,
				'caption'     => self::STORED_CAPTION,
				'description' => self::STORED_DESCRIPTION,
			),
			'cross-field apply'
		);
	}

	/**
	 * Asserts a preview cannot be applied to another attachment.
	 *
	 * @return void
	 */
	public function test_a_preview_cannot_be_applied_to_another_attachment() {
		$other = (int) self::factory()->attachment->create_object(
			array(
				'file'           => 'mcq-other-fixture.png',
				'post_mime_type' => 'image/png',
				'post_title'     => 'OTHER NATIVE TITLE',
				'post_status'    => 'inherit',
			)
		);

		$preview = $this->generate( SuggestionSurface::MEDIA_TITLE );

		$this->admin_post( SuggestionSurface::MEDIA_TITLE, array( 'token' => $preview['token'] ), $other );

		$response = $this->dispatch( $this->state()['actions']['apply'] );

		$this->assertIsArray( $response );
		$this->assertFalse( (bool) $response['success'] );
		$this->assertSame( 'OTHER NATIVE TITLE', (string) get_post( $other )->post_title );

		$this->assert_row(
			array(
				'title'       => self::STORED_TITLE,
				'alt_text'    => self::STORED_ALT,
				'caption'     => self::STORED_CAPTION,
				'description' => self::STORED_DESCRIPTION,
			),
			'cross-attachment apply'
		);
	}

	/**
	 * Asserts requests that cannot be honoured reach no provider.
	 *
	 * @return void
	 */
	public function test_refused_requests_never_reach_a_provider() {
		$post = (int) self::factory()->post->create( array( 'post_title' => 'Not An Attachment' ) );

		$refusals = array(
			'missing attachment'    => array( SuggestionSurface::MEDIA_TITLE, 99999999, 'tr' ),
			'not an attachment'     => array( SuggestionSurface::MEDIA_TITLE, $post, 'tr' ),
			'unconfigured language' => array( SuggestionSurface::MEDIA_TITLE, $this->attachment_id, 'de' ),
			'source language'       => array( SuggestionSurface::MEDIA_TITLE, $this->attachment_id, 'en' ),
			'filename surface'      => array( 'media_filename', $this->attachment_id, 'tr' ),
			'url surface'           => array( 'media_url', $this->attachment_id, 'tr' ),
			'mime surface'          => array( 'media_mime', $this->attachment_id, 'tr' ),
			'dimensions surface'    => array( 'media_dimensions', $this->attachment_id, 'tr' ),
			'post surface here'     => array( SuggestionSurface::POST_TITLE, $this->attachment_id, 'tr' ),
			'string surface here'   => array( SuggestionSurface::STRING, $this->attachment_id, 'tr' ),
		);

		foreach ( $refusals as $label => $arguments ) {
			$this->admin_post( $arguments[0], array(), $arguments[1], $arguments[2] );

			$response = $this->dispatch( $this->state()['actions']['generate'] );

			$this->assertIsArray( $response, $label );
			$this->assertFalse( (bool) $response['success'], $label );
		}

		$this->assertSame( array(), $this->transport->requests(), 'A refusal must cost the owner nothing.' );

		$this->assert_row(
			array(
				'title'       => self::STORED_TITLE,
				'alt_text'    => self::STORED_ALT,
				'caption'     => self::STORED_CAPTION,
				'description' => self::STORED_DESCRIPTION,
			),
			'refusals'
		);
	}

	/**
	 * Asserts an invalid nonce is refused before anything happens.
	 *
	 * @return void
	 */
	public function test_an_invalid_nonce_is_refused() {
		$_POST = array(
			'nonce'    => 'not-a-real-nonce',
			'surface'  => SuggestionSurface::MEDIA_TITLE,
			'objectId' => $this->attachment_id,
			'language' => 'tr',
		);

		$this->dispatch( $this->state()['actions']['generate'] );

		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Asserts a user without the capability is refused.
	 *
	 * @return void
	 */
	public function test_a_user_without_the_capability_is_refused() {
		$nonce = $this->state()['nonce'];

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$_POST = array(
			'nonce'    => $nonce,
			'surface'  => SuggestionSurface::MEDIA_TITLE,
			'objectId' => $this->attachment_id,
			'language' => 'tr',
		);

		$this->dispatch( $this->state()['actions']['generate'] );

		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Asserts a disabled site hands the screen no usable token.
	 *
	 * @return void
	 */
	public function test_a_disabled_site_hands_the_screen_no_usable_nonce() {
		$this->container->get( SuggestionSettings::class )->set_enabled( false );

		$state = $this->state();

		$this->assertFalse( (bool) $state['available'] );
		$this->assertSame( '', (string) $state['nonce'] );

		$fields = new MediaTranslationFields();

		$fields->register( $this->container );

		ob_start();
		$fields->render_fields( get_post( $this->attachment_id ) );
		$markup = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'data-mclogiora-generate', $markup );
		$this->assertStringContainsString( 'switched off', $markup );
	}
}
