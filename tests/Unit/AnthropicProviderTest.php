<?php
/**
 * Anthropic provider tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\LlmInstructions;
use McLogiora\Suggestions\Providers\AnthropicProvider;
use McLogiora\Suggestions\SuggestionRequest;
use McLogiora\Suggestions\SuggestionResult;
use McLogiora\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Covers the Anthropic adapter's contract, request shape and failure modes.
 *
 * Matches the rigour applied to OpenAI. A provider without its own contract
 * tests is not implemented, only written: the interesting failures here --
 * a refusal and a truncation, both arriving as HTTP 200 -- are exactly the
 * ones that look like success until something reads them carefully.
 */
final class AnthropicProviderTest extends TestCase {
	/**
	 * Recording transport.
	 *
	 * @var FakeTransport
	 */
	private $transport;

	/**
	 * Provider under test.
	 *
	 * @var AnthropicProvider
	 */
	private $provider;

	/**
	 * Sets up a configured provider.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->transport = new FakeTransport();

		$credentials = new CredentialStore();
		$credentials->save( 'anthropic', 'sk-ant-test-key' );

		$this->provider = new AnthropicProvider( $this->transport, $credentials, new LlmInstructions() );
		$this->provider->set_selected_model( 'claude-haiku-4-5' );
	}

	/**
	 * Clears stored state between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->provider->clear_selected_model();

		( new CredentialStore() )->remove( 'anthropic' );

		parent::tearDown();
	}

	/**
	 * Returns a representative request.
	 *
	 * @return SuggestionRequest
	 */
	private function request() {
		return new SuggestionRequest( 'Showing %s results', 'en', 'tr', array( 'surface' => 'string' ) );
	}

	/**
	 * Builds a successful Messages payload.
	 *
	 * @param string $text Text the model returned.
	 * @param string $stop Stop reason.
	 * @return array<string,mixed>
	 */
	private function message( $text, $stop = 'end_turn' ) {
		return array(
			'stop_reason' => $stop,
			'content'     => array(
				array(
					'type' => 'text',
					'text' => $text,
				),
			),
		);
	}

	/**
	 * Asserts the documented endpoint is used.
	 *
	 * @return void
	 */
	public function test_requests_go_to_the_messages_endpoint() {
		$this->transport->will_return( $this->message( 'Gunaydin' ) );

		$this->provider->suggest( new SuggestionRequest( 'Good morning', 'en', 'tr' ) );

		$sent = $this->transport->last_request();

		$this->assertSame( 'https://api.anthropic.com/v1/messages', $sent['url'] );
		$this->assertSame( 'POST', $sent['method'] );
	}

	/**
	 * Asserts the credential travels in a header and never in the URL.
	 *
	 * @return void
	 */
	public function test_the_credential_is_sent_as_a_header_and_never_in_the_url() {
		$this->transport->will_return( $this->message( 'Gunaydin' ) );

		$this->provider->suggest( new SuggestionRequest( 'Good morning', 'en', 'tr' ) );

		$sent = $this->transport->last_request();

		$this->assertSame( 'sk-ant-test-key', $sent['headers']['x-api-key'] );
		$this->assertSame( '2023-06-01', $sent['headers']['anthropic-version'] );
		$this->assertStringNotContainsString( 'sk-ant-test-key', $sent['url'], 'A credential must never reach a URL.' );
	}

	/**
	 * Asserts the request body carries the chosen model and the source text.
	 *
	 * @return void
	 */
	public function test_the_request_body_carries_the_selected_model() {
		$this->transport->will_return( $this->message( 'Gunaydin' ) );

		$this->provider->suggest( new SuggestionRequest( 'Good morning', 'en', 'tr' ) );

		$body = $this->transport->last_request()['body'];

		$this->assertSame( 'claude-haiku-4-5', $body['model'] );
		$this->assertIsInt( $body['max_tokens'] );
		$this->assertSame( 'Good morning', $body['messages'][0]['content'] );
		$this->assertSame( 'user', $body['messages'][0]['role'] );
		$this->assertNotSame( '', $body['system'] );
	}

	/**
	 * Asserts a good answer becomes a result.
	 *
	 * @return void
	 */
	public function test_a_sound_answer_is_returned() {
		$this->transport->will_return( $this->message( 'Gunaydin' ) );

		$result = $this->provider->suggest( new SuggestionRequest( 'Good morning', 'en', 'tr' ) );

		$this->assertInstanceOf( SuggestionResult::class, $result );
		$this->assertSame( 'Gunaydin', $result->text() );
		$this->assertSame( 'anthropic', $result->provider_id() );
		$this->assertSame( 'claude-haiku-4-5', $result->model() );
	}

	/**
	 * Asserts a refusal is categorised as a decline.
	 *
	 * @return void
	 */
	public function test_a_refusal_is_categorised_as_declined() {
		$this->transport->will_return(
			array(
				'stop_reason'  => 'refusal',
				'stop_details' => array( 'category' => 'cyber' ),
				'content'      => array(),
			)
		);

		$result = $this->provider->suggest( $this->request() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'mclogiora_suggestion_declined', $result->get_error_code() );
	}

	/**
	 * Asserts a truncated generation is categorised as incomplete.
	 *
	 * @return void
	 */
	public function test_a_truncated_generation_is_categorised_as_incomplete() {
		$this->transport->will_return( $this->message( 'Gosterilen', 'max_tokens' ) );

		$result = $this->provider->suggest( $this->request() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'mclogiora_suggestion_incomplete', $result->get_error_code() );
	}

	/**
	 * Asserts a malformed or empty body is categorised as invalid.
	 *
	 * @param array<string,mixed> $payload Response payload.
	 * @return void
	 *
	 * @dataProvider provide_unusable_payloads
	 */
	public function test_an_unusable_body_is_categorised_as_invalid( array $payload ) {
		$this->transport->will_return( $payload );

		$result = $this->provider->suggest( $this->request() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'mclogiora_suggestion_invalid_response', $result->get_error_code() );
	}

	/**
	 * Supplies responses that are not translations.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function provide_unusable_payloads() {
		return array(
			'no content key'   => array( array( 'stop_reason' => 'end_turn' ) ),
			'content not list' => array(
				array(
					'stop_reason' => 'end_turn',
					'content'     => 'oops',
				),
			),
			'empty text'       => array( array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => '   ' ) ) ) ),
			'no text blocks'   => array( array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'thinking' ) ) ) ),
		);
	}

	/**
	 * Asserts a transport error is surfaced unchanged.
	 *
	 * The transport already normalises status codes, so re-wrapping here would
	 * lose the distinction between an auth failure and a rate limit.
	 *
	 * @param string $code Transport error code.
	 * @return void
	 *
	 * @dataProvider provide_transport_errors
	 */
	public function test_transport_errors_are_surfaced_unchanged( $code ) {
		$this->transport->will_return( new \WP_Error( $code, 'boom' ) );

		$result = $this->provider->suggest( $this->request() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/**
	 * Supplies normalised transport failures.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_transport_errors() {
		return array(
			'authentication' => array( 'mclogiora_suggestion_auth_failed' ),
			'rate limit'     => array( 'mclogiora_suggestion_rate_limited' ),
			'quota'          => array( 'mclogiora_suggestion_quota' ),
			'network'        => array( 'mclogiora_suggestion_network' ),
		);
	}

	/**
	 * Asserts placeholders are hidden from the provider and put back after.
	 *
	 * @return void
	 */
	public function test_placeholders_are_protected_and_restored() {
		$this->transport->will_return( $this->message( 'placeholder' ) );

		$this->provider->suggest( $this->request() );

		$sent = $this->transport->last_request();

		$this->assertStringNotContainsString( '%s', $sent['body']['messages'][0]['content'], 'A placeholder must never reach the provider.' );
		$this->assertTrue( (bool) preg_match( '/\[\[MCQ_[a-f0-9]+_0\]\]/', $sent['body']['messages'][0]['content'] ) );
	}

	/**
	 * Asserts a dropped placeholder is refused.
	 *
	 * @return void
	 */
	public function test_a_dropped_placeholder_is_refused() {
		$this->transport->will_return( $this->message( 'Gosterilen sonuc' ) );

		$result = $this->provider->suggest( $this->request() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'mclogiora_suggestion_placeholder_damage', $result->get_error_code() );
	}

	/**
	 * Asserts model discovery reads the documented list shape.
	 *
	 * @return void
	 */
	public function test_model_discovery_reads_the_documented_shape() {
		$this->transport->will_return(
			array(
				'data' => array(
					array( 'id' => 'claude-opus-5', 'display_name' => 'Claude Opus 5' ),
					array( 'id' => 'claude-haiku-4-5', 'display_name' => 'Claude Haiku 4.5' ),
				),
			)
		);

		$models = $this->provider->available_models();

		$this->assertIsArray( $models );
		$this->assertCount( 2, $models );
		$this->assertSame( 'claude-opus-5', $models[0]['id'] );
		$this->assertSame( 'Claude Opus 5', $models[0]['label'] );
		$this->assertSame( 'GET', $this->transport->last_request()['method'] );
		$this->assertSame( 'https://api.anthropic.com/v1/models', $this->transport->last_request()['url'] );
	}

	/**
	 * Asserts nothing reaches the network without an explicit action.
	 *
	 * Constructing a provider, asking whether it is configured and reading its
	 * model are all things that happen while a settings screen renders. None
	 * of them may cost the owner a request.
	 *
	 * @return void
	 */
	public function test_no_request_is_made_without_an_explicit_action() {
		$this->provider->is_configured();
		$this->provider->selected_model();
		$this->provider->get_label();
		$this->provider->supports_language_pair( 'en', 'tr' );

		$this->assertSame( array(), $this->transport->requests(), 'Rendering state must never reach the network.' );
	}

	/**
	 * Asserts an unconfigured provider neither suggests nor calls out.
	 *
	 * @return void
	 */
	public function test_an_unconfigured_provider_makes_no_call() {
		$this->provider->clear_selected_model();

		$result = $this->provider->suggest( $this->request() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'mclogiora_suggestion_not_configured', $result->get_error_code() );
		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Asserts a retired model clears the selection rather than being swapped.
	 *
	 * @return void
	 */
	public function test_a_retired_model_clears_the_selection() {
		$invalidated = $this->provider->reconcile_selected_model(
			array( array( 'id' => 'claude-opus-5', 'label' => 'Claude Opus 5', 'recommended' => false ) )
		);

		$this->assertTrue( $invalidated );
		$this->assertSame( '', $this->provider->selected_model() );
		$this->assertFalse( $this->provider->is_configured() );
	}
}
