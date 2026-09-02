<?php
/**
 * Editor suggestion endpoint security tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Database\Installer;
use McLogiora\Editors\SuggestionEditorController;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationRelationRepositoryInterface;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\HttpTransport;
use McLogiora\Suggestions\LlmInstructions;
use McLogiora\Suggestions\ProviderRegistry;
use McLogiora\Suggestions\Providers\DeepLProvider;
use McLogiora\Suggestions\Providers\WordPressAiProvider;
use McLogiora\Suggestions\TranslationSuggestionService;
use McLogiora\Suggestions\SuggestionSettings;
use McLogiora\Tests\Support\FakeTransport;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_Ajax_UnitTestCase;

/**
 * Proves the editor endpoint cannot be turned into a translation proxy.
 *
 * This endpoint spends the site owner's money. Anything able to reach
 * `admin-ajax.php` as a logged-in user can call it, so the question these
 * tests answer is not "does it work" but "what is the worst a caller can make
 * it do".
 *
 * The central claim is that a caller cannot choose the text that gets
 * translated. The request names an object and a field; the server resolves the
 * translation group, finds the source object and reads the field itself. If
 * that ever stopped being true, this would become a general-purpose
 * "translate anything on the owner's account" service, and the owner would
 * find out from their invoice.
 *
 * Every rejection case asserts the recorded request count, not just the error
 * response. A refusal that still contacted the provider has already cost the
 * owner money and leaked their content.
 */
final class SuggestionEditorControllerTest extends WP_Ajax_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Recording transport standing in for real HTTP.
	 *
	 * @var FakeTransport
	 */
	private $transport;

	/**
	 * Editor identifier.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Source post identifier.
	 *
	 * @var int
	 */
	private $source_id;

	/**
	 * Translation post identifier.
	 *
	 * @var int
	 */
	private $target_id;

	/**
	 * Sets up a configured site with one translated post.
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

		$this->transport = new FakeTransport();

		/*
		 * Both the transport and the registry are replaced. The container may
		 * already have resolved the registry against the real transport during
		 * plugin boot, and swapping only the transport would leave the cached
		 * registry pointing at the network.
		 */
		$this->container->set( HttpTransport::class, $this->transport );
		$this->container->set(
			ProviderRegistry::class,
			function () {
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

		( new CredentialStore() )->save( 'deepl', 'deepl-test-key' );

		$settings = $this->container->get( SuggestionSettings::class );
		$settings->set_enabled( true );
		$settings->set_provider( 'deepl' );

		$this->source_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Authentic source title',
				'post_excerpt' => 'Authentic source excerpt',
			)
		);

		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $this->source_id, 'tr' );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );

		$this->target_id = (int) $created['post_id'];

		( new SuggestionEditorController() )->register( $this->container );
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
	 * Queues a successful provider translation.
	 *
	 * @param string $text Translated text.
	 * @return void
	 */
	private function will_translate( $text ) {
		$this->transport->will_return(
			array( 'translations' => array( array( 'text' => $text, 'detected_source_language' => 'EN' ) ) )
		);
	}

	/**
	 * Populates a valid POST payload.
	 *
	 * @param array<string,mixed> $overrides Fields to override or add.
	 * @return void
	 */
	private function post( array $overrides = array() ) {
		$_POST = array_merge(
			array(
				'nonce'    => wp_create_nonce( SuggestionEditorController::NONCE_ACTION ),
				'objectId' => $this->target_id,
				'field'    => 'title',
			),
			$overrides
		);
	}

	/**
	 * Dispatches an action and returns the decoded response, or null on die.
	 *
	 * @param string $action AJAX action name.
	 * @return array<string,mixed>|null
	 */
	private function dispatch( $action ) {
		try {
			$this->_handleAjax( $action );
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( \WPAjaxDieStopException $e ) {
			unset( $e );

			return null;
		}

		$decoded = json_decode( $this->_last_response, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Returns the body of the single recorded provider request.
	 *
	 * @return string
	 */
	private function sent_body() {
		$last = $this->transport->last_request();

		$this->assertNotNull( $last, 'Expected a provider request.' );

		return is_string( $last['body'] ) ? $last['body'] : (string) wp_json_encode( $last['body'] );
	}

	/**
	 * Asserts only the authoritative source field ever reaches the provider.
	 *
	 * The request deliberately carries several plausible-looking text fields.
	 * None of them may influence what is translated.
	 *
	 * @return void
	 */
	public function test_the_endpoint_cannot_translate_caller_supplied_text() {
		$this->will_translate( 'Ceviri' );

		$this->post(
			array(
				'text'       => 'ATTACKER SUPPLIED PAYLOAD',
				'sourceText' => 'ATTACKER SUPPLIED PAYLOAD',
				'source'     => 'ATTACKER SUPPLIED PAYLOAD',
				'content'    => 'ATTACKER SUPPLIED PAYLOAD',
			)
		);

		$response = $this->dispatch( 'mclogiora_generate_suggestion' );

		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] );

		$body = $this->sent_body();

		$this->assertStringNotContainsString( 'ATTACKER', $body, 'Caller-supplied text must never reach the provider.' );
		$this->assertStringContainsString( rawurlencode( 'Authentic source title' ), $body, 'Only the real source field may be translated.' );
		$this->assertCount( 1, $this->transport->requests() );
	}

	/**
	 * Asserts the excerpt path resolves its own authoritative value.
	 *
	 * @return void
	 */
	public function test_the_excerpt_field_resolves_its_own_source_value() {
		$this->will_translate( 'Ozet' );

		$this->post( array( 'field' => 'excerpt', 'text' => 'ATTACKER SUPPLIED PAYLOAD' ) );

		$this->dispatch( 'mclogiora_generate_suggestion' );

		$body = $this->sent_body();

		$this->assertStringContainsString( rawurlencode( 'Authentic source excerpt' ), $body );
		$this->assertStringNotContainsString( rawurlencode( 'Authentic source title' ), $body, 'The excerpt request must not carry the title.' );
		$this->assertStringNotContainsString( 'ATTACKER', $body );
	}

	/**
	 * Asserts the source object cannot be used as a translation target.
	 *
	 * @return void
	 */
	public function test_the_source_object_cannot_be_used_as_a_target() {
		$this->post( array( 'objectId' => $this->source_id ) );

		$response = $this->dispatch( 'mclogiora_generate_suggestion' );

		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
		$this->assertSame( array(), $this->transport->requests(), 'A refused request must not reach the provider.' );
	}

	/**
	 * Asserts content outside any translation group is refused.
	 *
	 * @return void
	 */
	public function test_content_outside_a_translation_group_is_refused() {
		$loose = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Unrelated' ) );

		$this->post( array( 'objectId' => $loose ) );

		$response = $this->dispatch( 'mclogiora_generate_suggestion' );

		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Asserts only the two supported fields are accepted.
	 *
	 * @param string $field Requested field.
	 * @return void
	 *
	 * @dataProvider provide_forbidden_fields
	 */
	public function test_unsupported_fields_are_refused( $field ) {
		$this->post( array( 'field' => $field ) );

		$response = $this->dispatch( 'mclogiora_generate_suggestion' );

		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'], "{$field} must not be suggestable." );
		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Supplies fields that must never reach a provider.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_forbidden_fields() {
		return array(
			'post content' => array( 'post_content' ),
			'content'      => array( 'content' ),
			'slug'         => array( 'post_name' ),
			'status'       => array( 'post_status' ),
			'author'       => array( 'post_author' ),
			'meta'         => array( 'meta' ),
			'unknown'      => array( 'anything_at_all' ),
			'empty'        => array( '' ),
		);
	}

	/**
	 * Asserts a missing or wrong nonce is refused before transport.
	 *
	 * @return void
	 */
	public function test_a_bad_nonce_is_refused_before_transport() {
		$this->post( array( 'nonce' => 'not-a-valid-nonce' ) );

		$this->dispatch( 'mclogiora_generate_suggestion' );

		$this->assertSame( array(), $this->transport->requests() );

		$this->post();
		unset( $_POST['nonce'] );

		$this->dispatch( 'mclogiora_generate_suggestion' );

		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Asserts a user without edit rights is refused before transport.
	 *
	 * @return void
	 */
	public function test_a_user_without_edit_rights_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->post();

		$response = $this->dispatch( 'mclogiora_generate_suggestion' );

		$this->assertTrue( null === $response || false === $response['success'] );
		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Asserts a switched-off site refuses before transport.
	 *
	 * @return void
	 */
	public function test_disabled_suggestions_refuse_before_transport() {
		$this->container->get( SuggestionSettings::class )->set_enabled( false );

		$this->post();

		$response = $this->dispatch( 'mclogiora_generate_suggestion' );

		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Asserts an unconfigured provider refuses before transport.
	 *
	 * @return void
	 */
	public function test_an_unconfigured_provider_refuses_before_transport() {
		( new CredentialStore() )->remove( 'deepl' );

		$this->post();

		$response = $this->dispatch( 'mclogiora_generate_suggestion' );

		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Asserts an unavailable WordPress AI connection refuses before transport.
	 *
	 * @return void
	 */
	public function test_an_unavailable_wordpress_ai_connection_refuses_before_transport() {
		$this->container->get( SuggestionSettings::class )->set_provider( 'wordpress-ai' );

		$this->post();

		$response = $this->dispatch( 'mclogiora_generate_suggestion' );

		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
		$this->assertSame( array(), $this->transport->requests() );

	}

	/**
	 * Asserts a successful Generate previews without writing anything.
	 *
	 * @return void
	 */
	public function test_generate_creates_a_preview_and_writes_nothing() {
		$before_title  = get_post_field( 'post_title', $this->target_id );
		$before_source = get_post_field( 'post_title', $this->source_id );

		$this->will_translate( 'Gercek baslik' );

		$this->post();

		$response = $this->dispatch( 'mclogiora_generate_suggestion' );

		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 'Gercek baslik', $response['data']['text'] );
		$this->assertNotSame( '', $response['data']['token'] );
		$this->assertSame( 'title', $response['data']['field'] );

		$this->assertSame( $before_title, get_post_field( 'post_title', $this->target_id ), 'Generate must not write.' );
		$this->assertSame( $before_source, get_post_field( 'post_title', $this->source_id ) );
		$this->assertSame( TranslationStatus::DRAFT, $this->status(), 'Generate must not change status.' );
	}

	/**
	 * Asserts the response carries nothing sensitive.
	 *
	 * @return void
	 */
	public function test_the_generate_response_leaks_nothing() {
		$this->will_translate( 'Gercek baslik' );

		$this->post();
		$this->dispatch( 'mclogiora_generate_suggestion' );

		$raw = (string) $this->_last_response;

		$this->assertStringNotContainsString( 'deepl-test-key', $raw, 'A credential must never reach the browser.' );
		$this->assertStringNotContainsString( 'Authorization', $raw );
		$this->assertStringNotContainsString( 'DeepL-Auth-Key', $raw );
	}

	/**
	 * Asserts Apply persists the field and records the review state.
	 *
	 * @return void
	 */
	public function test_apply_persists_the_field_and_marks_it_machine_suggested() {
		$this->will_translate( 'Uygulanan baslik' );

		$this->post();

		$generated = $this->dispatch( 'mclogiora_generate_suggestion' );

		$this->assertIsArray( $generated );

		$this->_last_response = '';

		$this->post( array( 'token' => $generated['data']['token'] ) );

		$applied = $this->dispatch( 'mclogiora_apply_suggestion' );

		$this->assertIsArray( $applied );
		$this->assertTrue( $applied['success'], wp_json_encode( $applied ) );

		$this->assertSame( 'Uygulanan baslik', get_post_field( 'post_title', $this->target_id ) );
		$this->assertSame( 'Authentic source title', get_post_field( 'post_title', $this->source_id ), 'The source must never change.' );
		$this->assertSame( TranslationStatus::MACHINE_SUGGESTED, $this->status() );
	}

	/**
	 * Asserts a title preview cannot be redirected into the excerpt.
	 *
	 * @return void
	 */
	public function test_a_title_preview_cannot_be_applied_to_the_excerpt() {
		$this->will_translate( 'Baslik onerisi' );

		$this->post();

		$generated = $this->dispatch( 'mclogiora_generate_suggestion' );

		$this->assertIsArray( $generated );

		$before_title   = get_post_field( 'post_title', $this->target_id );
		$before_excerpt = get_post_field( 'post_excerpt', $this->target_id );

		$this->_last_response = '';

		$this->post(
			array(
				'token' => $generated['data']['token'],
				'field' => 'excerpt',
			)
		);

		$applied = $this->dispatch( 'mclogiora_apply_suggestion' );

		$this->assertIsArray( $applied );
		$this->assertFalse( $applied['success'], 'A title preview must not write the excerpt.' );
		$this->assertSame( $before_title, get_post_field( 'post_title', $this->target_id ) );
		$this->assertSame( $before_excerpt, get_post_field( 'post_excerpt', $this->target_id ) );
		$this->assertSame( TranslationStatus::DRAFT, $this->status() );
	}

	/**
	 * Asserts a preview belonging to another user cannot be applied.
	 *
	 * @return void
	 */
	public function test_another_users_preview_cannot_be_applied() {
		$this->will_translate( 'Baslik onerisi' );

		$this->post();

		$generated = $this->dispatch( 'mclogiora_generate_suggestion' );

		$this->assertIsArray( $generated );

		$before = get_post_field( 'post_title', $this->target_id );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->_last_response = '';

		$this->post( array( 'token' => $generated['data']['token'] ) );

		$applied = $this->dispatch( 'mclogiora_apply_suggestion' );

		$this->assertIsArray( $applied );
		$this->assertFalse( $applied['success'], 'A preview is bound to the user who generated it.' );
		$this->assertSame( $before, get_post_field( 'post_title', $this->target_id ) );
	}

	/**
	 * Asserts an unknown token writes nothing.
	 *
	 * @return void
	 */
	public function test_an_unknown_token_writes_nothing() {
		$before = get_post_field( 'post_title', $this->target_id );

		$this->post( array( 'token' => 'not-a-real-token' ) );

		$applied = $this->dispatch( 'mclogiora_apply_suggestion' );

		$this->assertIsArray( $applied );
		$this->assertFalse( $applied['success'] );
		$this->assertSame( $before, get_post_field( 'post_title', $this->target_id ) );
		$this->assertSame( TranslationStatus::DRAFT, $this->status() );
	}

	/**
	 * Asserts Discard removes the preview without touching anything.
	 *
	 * @return void
	 */
	public function test_discard_removes_the_preview_without_writing() {
		$this->will_translate( 'Baslik onerisi' );

		$this->post();

		$generated = $this->dispatch( 'mclogiora_generate_suggestion' );

		$this->assertIsArray( $generated );

		$before   = get_post_field( 'post_title', $this->target_id );
		$requests = count( $this->transport->requests() );

		$this->_last_response = '';

		$this->post( array( 'token' => $generated['data']['token'] ) );

		$discarded = $this->dispatch( 'mclogiora_discard_suggestion' );

		$this->assertIsArray( $discarded );
		$this->assertTrue( $discarded['success'] );
		$this->assertCount( $requests, $this->transport->requests(), 'Discard must not call a provider.' );
		$this->assertSame( $before, get_post_field( 'post_title', $this->target_id ) );

		$this->_last_response = '';

		$this->post( array( 'token' => $generated['data']['token'] ) );

		$applied = $this->dispatch( 'mclogiora_apply_suggestion' );

		$this->assertIsArray( $applied );
		$this->assertFalse( $applied['success'], 'A discarded preview must not apply.' );
	}

	/**
	 * Asserts an explicit regenerate calls the provider exactly once more.
	 *
	 * @return void
	 */
	public function test_regenerate_is_one_more_explicit_call() {
		$this->will_translate( 'Ilk oneri' );

		$this->post();

		$first = $this->dispatch( 'mclogiora_generate_suggestion' );

		$this->assertCount( 1, $this->transport->requests() );

		$this->will_translate( 'Ikinci oneri' );

		$this->_last_response = '';

		$this->post();

		$second = $this->dispatch( 'mclogiora_generate_suggestion' );

		$this->assertCount( 2, $this->transport->requests(), 'Regenerate is one more deliberate request, never a retry loop.' );
		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertNotSame( $first['data']['token'], $second['data']['token'] );
		$this->assertSame( 'Ikinci oneri', $second['data']['text'] );
		$this->assertSame( TranslationStatus::DRAFT, $this->status(), 'Regenerating must not change status.' );
	}

	/**
	 * Returns the stored relation status of the translation.
	 *
	 * @return string
	 */
	private function status() {
		$item = $this->container->get( TranslationRelationRepositoryInterface::class )
			->find_item( ContentType::POST, (string) $this->target_id, 'tr' );

		return null === $item ? '' : $item->status();
	}
}
