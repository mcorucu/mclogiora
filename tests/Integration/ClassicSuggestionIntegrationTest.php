<?php
/**
 * Classic Editor suggestion surface tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Database\Installer;
use McLogiora\Editors\ClassicEditorMetabox;
use McLogiora\Editors\SuggestionEditorController;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\HttpTransport;
use McLogiora\Suggestions\LlmInstructions;
use McLogiora\Suggestions\ProviderRegistry;
use McLogiora\Suggestions\Providers\AnthropicProvider;
use McLogiora\Suggestions\Providers\DeepLProvider;
use McLogiora\Suggestions\Providers\GeminiProvider;
use McLogiora\Suggestions\Providers\OpenAiProvider;
use McLogiora\Suggestions\SuggestionSettings;
use McLogiora\Tests\Support\FakeTransport;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_UnitTestCase;

/**
 * Proves the Classic Editor surface is safe and correctly scoped.
 *
 * The Block Editor's suggestion behaviour is already qualified, and Classic
 * reuses the same endpoints, service, preview store and apply service. What is
 * genuinely new here is the surface: server-rendered markup living inside
 * WordPress's own `<form id="post">`.
 *
 * That form is the whole risk. HTML does not allow a nested form -- the parser
 * silently discards it and leaves its buttons submitting the post instead -- so
 * a suggestion control that shipped a form, or a button that defaulted to
 * `type="submit"`, would turn "ask for a translation" into "save the post".
 * These tests assert the markup cannot do that.
 */
final class ClassicSuggestionIntegrationTest extends WP_UnitTestCase {
	/**
	 * Credentials distinctive enough to find anywhere.
	 */
	const SECRETS = array(
		'openai'    => 'sk-live-CLASSIC-OPENAI-MUST-NOT-LEAK-4401',
		'anthropic' => 'sk-ant-CLASSIC-ANTHROPIC-MUST-NOT-LEAK-4402',
		'gemini'    => 'AIza-CLASSIC-GEMINI-MUST-NOT-LEAK-4403',
		'deepl'     => 'CLASSIC-DEEPL-MUST-NOT-LEAK-4404',
	);

	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Recording transport.
	 *
	 * @var FakeTransport
	 */
	private $transport;

	/**
	 * Sets up a configured site on a Classic editing screen.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		set_current_screen( 'post' );

		add_filter( 'use_block_editor_for_post_type', '__return_false' );

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

		$this->transport = new FakeTransport();

		$this->container->set( HttpTransport::class, $this->transport );
		$this->container->set(
			ProviderRegistry::class,
			function () {
				$registry    = new ProviderRegistry();
				$credentials = new CredentialStore();
				$prompts     = new LlmInstructions();

				$registry->add( new OpenAiProvider( $this->transport, $credentials, $prompts ) );
				$registry->add( new AnthropicProvider( $this->transport, $credentials, $prompts ) );
				$registry->add( new GeminiProvider( $this->transport, $credentials, $prompts ) );
				$registry->add( new DeepLProvider( $this->transport, $credentials ) );

				return $registry;
			}
		);
	}

	/**
	 * Clears configuration between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		$credentials = new CredentialStore();

		foreach ( array_keys( self::SECRETS ) as $id ) {
			$credentials->remove( $id );
			delete_option( 'mclogiora_suggestion_model_' . $id );
		}

		delete_option( SuggestionSettings::OPTION_ENABLED );
		delete_option( SuggestionSettings::OPTION_PROVIDER );

		remove_filter( 'use_block_editor_for_post_type', '__return_false' );

		parent::tear_down();
	}

	/**
	 * Creates a source post and its Turkish translation.
	 *
	 * @return array{source:int,target:int}
	 */
	private function translated_pair() {
		$source = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Classic source title 8842',
				'post_excerpt' => 'Classic source excerpt 9913',
				'post_content' => 'Classic source body 5521',
			)
		);

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $source, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		return array(
			'source' => (int) $source,
			'target' => (int) $created['post_id'],
		);
	}

	/**
	 * Configures a ready DeepL provider.
	 *
	 * @return void
	 */
	private function ready_provider() {
		( new CredentialStore() )->save( 'deepl', self::SECRETS['deepl'] );

		$settings = $this->container->get( SuggestionSettings::class );

		$settings->set_enabled( true );
		$settings->set_provider( 'deepl' );
	}

	/**
	 * Stores every provider credential.
	 *
	 * @return void
	 */
	private function all_credentials() {
		$credentials = new CredentialStore();

		foreach ( self::SECRETS as $id => $secret ) {
			$credentials->save( $id, $secret );
		}

		update_option( 'mclogiora_suggestion_model_openai', 'gpt-4o-mini' );
	}

	/**
	 * Renders the metabox exactly as the Classic screen would.
	 *
	 * @param int $post_id Post being edited.
	 * @return string
	 */
	private function rendered( $post_id ) {
		$metabox = new ClassicEditorMetabox();

		$metabox->register( $this->container );

		ob_start();

		$metabox->render( get_post( $post_id ) );

		return (string) ob_get_clean();
	}

	/**
	 * Returns the state the Classic script would receive.
	 *
	 * @param int $post_id Post being edited.
	 * @return string
	 */
	private function localised( $post_id ) {
		global $post;

		$previous = $post;
		$post     = get_post( $post_id );

		$metabox = new ClassicEditorMetabox();

		$metabox->register( $this->container );

		wp_dequeue_script( ClassicEditorMetabox::SUGGESTIONS_HANDLE );
		wp_deregister_script( ClassicEditorMetabox::SUGGESTIONS_HANDLE );

		$metabox->enqueue( 'post.php' );

		$scripts = wp_scripts();
		$before  = isset( $scripts->registered[ ClassicEditorMetabox::SUGGESTIONS_HANDLE ] )
			? $scripts->get_data( ClassicEditorMetabox::SUGGESTIONS_HANDLE, 'before' )
			: false;

		$data = is_array( $before ) ? implode( "\n", array_filter( $before, 'is_string' ) ) : (string) $before;

		$post = $previous;

		return $data;
	}

	/**
	 * Asserts the suggestions section appears for a translation.
	 *
	 * @return void
	 */
	public function test_a_translation_offers_title_and_excerpt_suggestions() {
		$this->ready_provider();

		$pair = $this->translated_pair();
		$html = $this->rendered( $pair['target'] );

		$this->assertStringContainsString( 'mclogiora-editor__suggestions', $html );
		$this->assertStringContainsString( 'Translation Suggestions', $html );
		$this->assertStringContainsString( 'data-mclogiora-field="title"', $html );
		$this->assertStringContainsString( 'data-mclogiora-field="excerpt"', $html );
		$this->assertSame( 2, substr_count( $html, 'data-mclogiora-generate' ), 'Exactly two fields may be offered.' );
	}

	/**
	 * Asserts no control implies the body can be translated.
	 *
	 * @return void
	 */
	public function test_the_post_body_is_never_offered() {
		$this->ready_provider();

		$pair = $this->translated_pair();
		$html = $this->rendered( $pair['target'] );

		$this->assertStringNotContainsString( 'data-mclogiora-field="content"', $html );
		$this->assertStringNotContainsString( 'data-mclogiora-field="post_content"', $html );
		$this->assertStringNotContainsString( 'data-mclogiora-field="slug"', $html );
	}

	/**
	 * Asserts the source post is offered nothing to translate.
	 *
	 * @return void
	 */
	public function test_the_source_post_gets_no_suggestion_controls() {
		$this->ready_provider();

		$pair = $this->translated_pair();
		$html = $this->rendered( $pair['source'] );

		$this->assertStringNotContainsString( 'mclogiora-editor__suggestions', $html );
		$this->assertStringNotContainsString( 'data-mclogiora-generate', $html );
	}

	/**
	 * Asserts the section introduces no form inside the post form.
	 *
	 * The Classic Editor already wraps this markup in `<form id="post">`. A
	 * nested form is discarded by the parser, leaving its buttons submitting the
	 * post: asking for a suggestion would save the post instead.
	 *
	 * @return void
	 */
	public function test_the_suggestion_section_introduces_no_nested_form() {
		$this->ready_provider();

		$pair = $this->translated_pair();
		$html = $this->rendered( $pair['target'] );

		$this->assertStringNotContainsString( '<form', $html, 'The metabox must not open a form inside the post form.' );
		$this->assertStringNotContainsString( '</form>', $html );
	}

	/**
	 * Asserts no suggestion button can submit the post form.
	 *
	 * A `<button>` with no type attribute defaults to `type="submit"`.
	 *
	 * @return void
	 */
	public function test_every_suggestion_button_is_explicitly_a_plain_button() {
		$this->ready_provider();

		$pair = $this->translated_pair();
		$html = $this->rendered( $pair['target'] );

		$buttons = array();

		preg_match_all( '/<button[^>]*>/', $html, $buttons );

		$this->assertNotEmpty( $buttons[0], 'The ready state must render buttons.' );

		foreach ( $buttons[0] as $button ) {
			$this->assertStringContainsString(
				'type="button"',
				$button,
				'A button without an explicit type submits the Classic post form.'
			);
			$this->assertStringNotContainsString( 'type="submit"', $button );
		}
	}

	/**
	 * Asserts each generate button names its field to assistive technology.
	 *
	 * @return void
	 */
	public function test_generate_buttons_name_their_field() {
		$this->ready_provider();

		$pair = $this->translated_pair();
		$html = $this->rendered( $pair['target'] );

		$this->assertStringContainsString( 'aria-label="Generate Title suggestion"', $html );
		$this->assertStringContainsString( 'aria-label="Generate Excerpt suggestion"', $html );
	}

	/**
	 * Asserts a disabled site explains itself and offers no action.
	 *
	 * @return void
	 */
	public function test_disabled_suggestions_explain_themselves_without_controls() {
		$pair = $this->translated_pair();
		$html = $this->rendered( $pair['target'] );

		$this->assertStringContainsString( 'Translation Suggestions', $html );
		$this->assertStringContainsString( 'switched off for this site', $html );
		$this->assertStringNotContainsString( 'data-mclogiora-generate', $html );
	}

	/**
	 * Asserts a provider still needing a model offers no action.
	 *
	 * @return void
	 */
	public function test_a_provider_needing_a_model_offers_no_action() {
		( new CredentialStore() )->save( 'openai', self::SECRETS['openai'] );

		$settings = $this->container->get( SuggestionSettings::class );

		$settings->set_enabled( true );
		$settings->set_provider( 'openai' );

		$pair = $this->translated_pair();
		$html = $this->rendered( $pair['target'] );

		$this->assertStringContainsString( 'still needs a model', $html );
		$this->assertStringNotContainsString( 'data-mclogiora-generate', $html );
		$this->assertSame( array(), $this->transport->requests(), 'A model-required state must fetch nothing.' );
	}

	/**
	 * Asserts rendering the Classic metabox reaches no provider.
	 *
	 * @return void
	 */
	public function test_rendering_the_metabox_makes_no_provider_request() {
		$this->ready_provider();
		$this->all_credentials();

		$pair = $this->translated_pair();

		$this->rendered( $pair['target'] );
		$this->rendered( $pair['target'] );
		$this->localised( $pair['target'] );

		$this->assertSame(
			array(),
			$this->transport->requests(),
			'Opening the Classic editor must never contact a provider.'
		);
	}

	/**
	 * Asserts no credential reaches the Classic screen.
	 *
	 * @return void
	 */
	public function test_no_credential_reaches_the_classic_screen() {
		$this->ready_provider();
		$this->all_credentials();

		$pair    = $this->translated_pair();
		$payload = $this->rendered( $pair['target'] ) . $this->localised( $pair['target'] );

		foreach ( self::SECRETS as $id => $secret ) {
			$this->assertStringNotContainsString( $secret, $payload, sprintf( 'The %s key must never be rendered.', $id ) );
		}

		$this->assertStringNotContainsString( 'MUST-NOT-LEAK', $payload );
		$this->assertStringNotContainsString( 'sk-live', $payload, 'Even a key prefix identifies the credential type.' );
		$this->assertStringNotContainsString( 'sk-ant', $payload );
		$this->assertStringNotContainsString( 'AIza', $payload );
		$this->assertStringNotContainsString( 'Authorization', $payload );
		$this->assertStringNotContainsString( 'DeepL-Auth-Key', $payload );
		$this->assertStringNotContainsString( 'mclogiora_suggestion_key_', $payload );
	}

	/**
	 * Asserts no source text is shipped to the Classic screen.
	 *
	 * @return void
	 */
	public function test_no_source_text_is_shipped_to_the_browser() {
		$this->ready_provider();

		$pair    = $this->translated_pair();
		$payload = $this->localised( $pair['target'] );

		$this->assertStringNotContainsString( 'Classic source title 8842', $payload );
		$this->assertStringNotContainsString( 'Classic source excerpt 9913', $payload );
		$this->assertStringNotContainsString( 'Classic source body 5521', $payload );
	}

	/**
	 * Asserts the Classic state carries the shared action contract.
	 *
	 * Classic is another presentation surface over the endpoints the Block
	 * Editor already uses, so it must name those actions rather than any of its
	 * own.
	 *
	 * @return void
	 */
	public function test_the_classic_state_uses_the_shared_endpoints() {
		$this->ready_provider();

		$pair    = $this->translated_pair();
		$payload = $this->localised( $pair['target'] );

		$this->assertStringContainsString( 'mclogiora_generate_suggestion', $payload );
		$this->assertStringContainsString( 'mclogiora_apply_suggestion', $payload );
		$this->assertStringContainsString( 'mclogiora_discard_suggestion', $payload );

		$decoded = json_decode( (string) preg_replace( '/^.*?=\s*|;\s*$/s', '', trim( $payload ) ), true );

		$this->assertIsArray( $decoded );
		$this->assertSame( $pair['target'], (int) $decoded['objectId'] );
		$this->assertSame( array( 'title', 'excerpt' ), $decoded['fields'] );
		$this->assertNotSame( '', (string) $decoded['nonce'] );
		$this->assertTrue(
			(bool) wp_verify_nonce( $decoded['nonce'], SuggestionEditorController::NONCE_ACTION ),
			'The Classic nonce must be the one the shared controller checks.'
		);
	}

	/**
	 * Asserts an unavailable feature hands the browser no script or token.
	 *
	 * @return void
	 */
	public function test_an_unavailable_feature_ships_no_state() {
		$pair = $this->translated_pair();

		$this->assertSame( '', $this->localised( $pair['target'] ), 'A disabled feature must ship no state.' );
	}

	/**
	 * Asserts the Classic script is registered for a ready translation.
	 *
	 * @return void
	 */
	public function test_the_classic_script_loads_for_a_ready_translation() {
		$this->ready_provider();

		$pair = $this->translated_pair();

		$this->assertNotSame( '', $this->localised( $pair['target'] ) );
		$this->assertTrue( wp_script_is( ClassicEditorMetabox::SUGGESTIONS_HANDLE, 'enqueued' ) );
	}
}
