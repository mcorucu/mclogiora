<?php
/**
 * String Manager suggestion surface tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Admin\SuggestionAdminController;
use McLogiora\Admin\SuggestionAdminState;
use McLogiora\Core\Application;
use McLogiora\Database\Installer;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Strings\StringRepositoryInterface;
use McLogiora\Strings\StringSource;
use McLogiora\Strings\StringSourceType;
use McLogiora\Strings\StringTranslation;
use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\HttpTransport;
use McLogiora\Suggestions\LlmInstructions;
use McLogiora\Suggestions\ProviderRegistry;
use McLogiora\Suggestions\Providers\DeepLProvider;
use McLogiora\Suggestions\Providers\OpenAiProvider;
use McLogiora\Suggestions\Providers\GeminiProvider;
use McLogiora\Suggestions\Providers\AnthropicProvider;
use McLogiora\Suggestions\SuggestionSettings;
use McLogiora\Suggestions\SuggestionSurface;
use McLogiora\Suggestions\TranslationSuggestionService;
use McLogiora\Tests\Support\EchoTransport;
use WP_Ajax_UnitTestCase;

/**
 * Proves the interface-string suggestion surface.
 *
 * A string is not a post. It has no relation row, it carries its own status
 * column, and its identity is the triple of text, text domain and context -- so
 * two identical source strings registered by different plugins are different
 * things and must translate independently. Those are the properties worth
 * asserting here; the provider contract and preview lifecycle are already
 * qualified elsewhere and are not restated.
 *
 * The secret used is distinctive so the state can be searched for the actual
 * value rather than for a field somebody remembered to name.
 */
final class StringSuggestionIntegrationTest extends WP_Ajax_UnitTestCase {
	/**
	 * A credential distinctive enough to find anywhere.
	 */
	const SECRET = 'deepl-live-STRINGSTATE-MUST-NOT-LEAK-6640';

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
	 * String registered under the first text domain.
	 *
	 * @var int
	 */
	private $string_a = 0;

	/**
	 * String registered under the second text domain.
	 *
	 * @var int
	 */
	private $string_b = 0;

	/**
	 * Sets up a ready site with two same-text strings.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		set_current_screen( 'toplevel_page_mclogiora' );

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

				$registry->add( new OpenAiProvider( $this->transport, $credentials, $prompts ) );
				$registry->add( new AnthropicProvider( $this->transport, $credentials, $prompts ) );
				$registry->add( new GeminiProvider( $this->transport, $credentials, $prompts ) );
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

		$repository = $this->container->get( StringRepositoryInterface::class );

		$this->string_a = $this->register_string( $repository, 'mcq-domain-a' );
		$this->string_b = $this->register_string( $repository, 'mcq-domain-b' );
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
	 * Registers one placeholder-bearing string under a text domain.
	 *
	 * @param StringRepositoryInterface $repository String repository.
	 * @param string                    $text_domain Text domain.
	 * @return int
	 */
	private function register_string( StringRepositoryInterface $repository, $text_domain ) {
		$registered = $repository->register(
			new StringSource(
				0,
				'Hello %1$s, you have %2$d items.',
				$text_domain,
				'greeting',
				StringSourceType::MANUAL,
				'tests/fixture.php',
				1,
				false
			)
		);

		return is_object( $registered ) ? (int) $registered->id() : (int) $registered;
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
	 * Builds a request the way the admin script would.
	 *
	 * @param int                 $string_id String identifier.
	 * @param string              $language Target language.
	 * @param array<string,mixed> $extra Extra fields.
	 * @return void
	 */
	private function admin_post( $string_id, $language, array $extra = array() ) {
		$_POST = array_merge(
			array(
				'nonce'    => $this->state()['nonce'],
				'surface'  => SuggestionSurface::STRING,
				'objectId' => $string_id,
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
	 * Generates a suggestion and returns its payload.
	 *
	 * @param int $string_id String identifier.
	 * @return array<string,mixed>
	 */
	private function generate( $string_id ) {
		$this->admin_post( $string_id, 'tr' );

		$response = $this->dispatch( $this->state()['actions']['generate'] );

		$this->assertIsArray( $response );
		$this->assertTrue( (bool) $response['success'], (string) wp_json_encode( $response ) );

		return $response['data'];
	}

	/**
	 * Returns the stored translation for a string.
	 *
	 * @param int    $string_id String identifier.
	 * @param string $language Language code.
	 * @return StringTranslation|null
	 */
	private function translation( $string_id, $language ) {
		$found = $this->container->get( StringRepositoryInterface::class )
			->find_translation( (int) $string_id, (string) $language );

		return $found instanceof StringTranslation ? $found : null;
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
		$this->assertStringNotContainsString( 'deepl-live', $payload, 'Even a key prefix identifies the credential.' );
		$this->assertStringNotContainsString( 'Authorization', $payload );
		$this->assertStringNotContainsString( 'DeepL-Auth-Key', $payload );
		$this->assertStringNotContainsString( 'mclogiora_suggestion_key_', $payload );
	}

	/**
	 * Asserts no source text is shipped to the browser.
	 *
	 * @return void
	 */
	public function test_the_admin_state_ships_no_source_text() {
		$this->assertStringNotContainsString( 'you have', (string) wp_json_encode( $this->state() ) );
	}

	/**
	 * Asserts building the state reaches no provider.
	 *
	 * @return void
	 */
	public function test_building_the_state_makes_no_provider_request() {
		$this->state();
		$this->state();

		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Asserts Generate translates the authoritative source string.
	 *
	 * @return void
	 */
	public function test_generate_sends_the_authoritative_source_string() {
		$data = $this->generate( $this->string_a );

		$this->assertSame( SuggestionSurface::STRING, $data['surface'] );
		$this->assertNotSame( '', (string) $data['token'] );

		$last = $this->transport->last_request();
		$body = is_string( $last['body'] ) ? $last['body'] : (string) wp_json_encode( $last['body'] );

		$this->assertStringContainsString( 'Hello', rawurldecode( $body ) );
		$this->assertNull( $this->translation( $this->string_a, 'tr' ), 'Generate must write nothing.' );
	}

	/**
	 * Asserts the browser cannot choose what gets translated.
	 *
	 * @return void
	 */
	public function test_arbitrary_request_text_never_reaches_the_provider() {
		$this->admin_post(
			$this->string_a,
			'tr',
			array(
				'text'       => 'ATTACKER CONTROLLED TEXT',
				'sourceText' => 'ATTACKER CONTROLLED TEXT',
			)
		);

		$this->dispatch( $this->state()['actions']['generate'] );

		$last = $this->transport->last_request();
		$body = rawurldecode( is_string( $last['body'] ) ? $last['body'] : (string) wp_json_encode( $last['body'] ) );

		$this->assertStringNotContainsString( 'ATTACKER', $body, 'The endpoint must not be a translation proxy.' );
		$this->assertStringContainsString( 'Hello', $body );
	}

	/**
	 * Asserts placeholders are shielded on the way out.
	 *
	 * @return void
	 */
	public function test_placeholders_are_shielded_from_the_provider() {
		$this->generate( $this->string_a );

		$last = $this->transport->last_request();
		$body = rawurldecode( is_string( $last['body'] ) ? $last['body'] : (string) wp_json_encode( $last['body'] ) );

		$this->assertStringContainsString( 'ignore_tags=mcq', $body );
		$this->assertStringNotContainsString( '%1$s', $body, 'A bare placeholder must not be exposed to the provider.' );
	}

	/**
	 * Asserts the preview restores human-readable placeholders.
	 *
	 * @return void
	 */
	public function test_the_preview_restores_readable_placeholders() {
		$data = $this->generate( $this->string_a );

		$this->assertStringContainsString( '%1$s', $data['text'] );
		$this->assertStringContainsString( '%2$d', $data['text'] );
		$this->assertStringNotContainsString( 'MCQ_', $data['text'], 'The shield token must never be shown.' );
	}

	/**
	 * Asserts Apply stores the translation as machine suggested.
	 *
	 * @return void
	 */
	public function test_apply_stores_the_translation_as_machine_suggested() {
		$preview = $this->generate( $this->string_a );

		$this->admin_post( $this->string_a, 'tr', array( 'token' => $preview['token'] ) );

		$response = $this->dispatch( $this->state()['actions']['apply'] );

		$this->assertIsArray( $response );
		$this->assertTrue( (bool) $response['success'], (string) wp_json_encode( $response ) );
		$this->assertSame( TranslationStatus::MACHINE_SUGGESTED, $response['data']['status'] );

		$stored = $this->translation( $this->string_a, 'tr' );

		$this->assertInstanceOf( StringTranslation::class, $stored );
		$this->assertSame( 'TR::Hello %1$s, you have %2$d items.', $stored->text() );
		$this->assertSame( TranslationStatus::MACHINE_SUGGESTED, $stored->status() );
	}

	/**
	 * Asserts the source string is never rewritten.
	 *
	 * @return void
	 */
	public function test_apply_leaves_the_source_string_untouched() {
		$preview = $this->generate( $this->string_a );

		$this->admin_post( $this->string_a, 'tr', array( 'token' => $preview['token'] ) );
		$this->dispatch( $this->state()['actions']['apply'] );

		$source = $this->container->get( StringRepositoryInterface::class )->find( $this->string_a );

		$this->assertInstanceOf( StringSource::class, $source );
		$this->assertSame( 'Hello %1$s, you have %2$d items.', $source->text() );
		$this->assertSame( 'mcq-domain-a', $source->text_domain() );
		$this->assertSame( 'greeting', $source->context() );
	}

	/**
	 * Asserts identical text in another domain is a different string.
	 *
	 * @return void
	 */
	public function test_an_identical_string_in_another_domain_is_untouched() {
		$preview = $this->generate( $this->string_a );

		$this->admin_post( $this->string_a, 'tr', array( 'token' => $preview['token'] ) );
		$this->dispatch( $this->state()['actions']['apply'] );

		$this->assertNotNull( $this->translation( $this->string_a, 'tr' ) );
		$this->assertNull(
			$this->translation( $this->string_b, 'tr' ),
			'The same text in another text domain is a different string.'
		);
	}

	/**
	 * Asserts a suggested string gets no relation row.
	 *
	 * @return void
	 */
	public function test_applying_a_string_creates_no_relation_row() {
		global $wpdb;

		$preview = $this->generate( $this->string_a );

		$this->admin_post( $this->string_a, 'tr', array( 'token' => $preview['token'] ) );
		$this->dispatch( $this->state()['actions']['apply'] );

		$rows = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}mclogiora_translation_items WHERE content_type = 'string'"
		);

		$this->assertSame( 0, $rows, 'Strings are not relation-backed and must invent no relation.' );
	}

	/**
	 * Asserts Regenerate is one more explicit call that writes nothing.
	 *
	 * @return void
	 */
	public function test_regenerate_is_one_more_explicit_call() {
		$first = $this->generate( $this->string_a );

		$this->assertCount( 1, $this->transport->requests() );

		$second = $this->generate( $this->string_a );

		$this->assertCount( 2, $this->transport->requests() );
		$this->assertNotSame( $first['token'], $second['token'] );
		$this->assertNull( $this->translation( $this->string_a, 'tr' ) );
	}

	/**
	 * Asserts Discard forgets the preview and writes nothing.
	 *
	 * @return void
	 */
	public function test_discard_writes_nothing_and_invalidates_the_token() {
		$preview = $this->generate( $this->string_a );
		$before  = count( $this->transport->requests() );

		$this->admin_post( $this->string_a, 'tr', array( 'token' => $preview['token'] ) );

		$discarded = $this->dispatch( $this->state()['actions']['discard'] );

		$this->assertIsArray( $discarded );
		$this->assertTrue( (bool) $discarded['success'] );
		$this->assertCount( $before, $this->transport->requests(), 'Discard must reach no provider.' );
		$this->assertNull( $this->translation( $this->string_a, 'tr' ) );

		$this->admin_post( $this->string_a, 'tr', array( 'token' => $preview['token'] ) );

		$reapplied = $this->dispatch( $this->state()['actions']['apply'] );

		$this->assertIsArray( $reapplied );
		$this->assertFalse( (bool) $reapplied['success'], 'A discarded preview must not be applicable.' );
		$this->assertNull( $this->translation( $this->string_a, 'tr' ) );
	}

	/**
	 * Asserts requests that cannot be honoured reach no provider.
	 *
	 * @return void
	 */
	public function test_refused_requests_never_reach_a_provider() {
		$refusals = array(
			'missing string'        => array( 999999, 'tr' ),
			'unconfigured language' => array( $this->string_a, 'de' ),
			'source language'       => array( $this->string_a, 'en' ),
		);

		foreach ( $refusals as $label => $arguments ) {
			$this->admin_post( $arguments[0], $arguments[1] );

			$response = $this->dispatch( $this->state()['actions']['generate'] );

			$this->assertIsArray( $response, $label );
			$this->assertFalse( (bool) $response['success'], $label );
		}

		$this->assertSame( array(), $this->transport->requests(), 'A refusal must cost the owner nothing.' );
	}

	/**
	 * Asserts a post field cannot be driven through the admin endpoint.
	 *
	 * @return void
	 */
	public function test_a_post_surface_is_refused_by_the_admin_endpoint() {
		$this->admin_post( $this->string_a, 'tr', array( 'surface' => SuggestionSurface::POST_TITLE ) );

		$response = $this->dispatch( $this->state()['actions']['generate'] );

		$this->assertIsArray( $response );
		$this->assertFalse( (bool) $response['success'] );
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
		$this->assertStringContainsString( 'switched off', (string) $state['reason'] );
	}
}
