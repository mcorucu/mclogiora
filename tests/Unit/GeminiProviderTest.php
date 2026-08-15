<?php
/**
 * Google Gemini provider tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\LlmInstructions;
use McLogiora\Suggestions\Providers\GeminiProvider;
use McLogiora\Suggestions\SuggestionRequest;
use McLogiora\Suggestions\SuggestionResult;
use McLogiora\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Covers the Gemini adapter, with particular attention to key placement.
 *
 * Google documents two ways to authenticate: an `x-goog-api-key` header and a
 * `?key=` query parameter. Both work, and only one of them keeps the
 * credential out of web server access logs, proxy logs, browser history and
 * `Referer` headers. The tests below make the safe one non-negotiable, because
 * the unsafe one is a one-character change away and would never fail a
 * functional test.
 */
final class GeminiProviderTest extends TestCase {
	/**
	 * Recording transport.
	 *
	 * @var FakeTransport
	 */
	private $transport;

	/**
	 * Provider under test.
	 *
	 * @var GeminiProvider
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
		$credentials->save( 'gemini', 'AIza-test-key' );

		$this->provider = new GeminiProvider( $this->transport, $credentials, new LlmInstructions() );
		$this->provider->set_selected_model( 'gemini-3.6-flash' );
	}

	/**
	 * Clears stored state between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->provider->clear_selected_model();

		( new CredentialStore() )->remove( 'gemini' );

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
	 * Builds a successful generateContent payload.
	 *
	 * @param string $text Text the model returned.
	 * @param string $finish Finish reason.
	 * @return array<string,mixed>
	 */
	private function candidate( $text, $finish = 'STOP' ) {
		return array(
			'candidates' => array(
				array(
					'finishReason' => $finish,
					'content'      => array(
						'role'  => 'model',
						'parts' => array( array( 'text' => $text ) ),
					),
				),
			),
		);
	}

	/**
	 * Asserts the key is sent as a header and never as a query parameter.
	 *
	 * @return void
	 */
	public function test_the_key_is_sent_only_in_the_header() {
		$this->transport->will_return( $this->candidate( 'Gunaydin' ) );

		$this->provider->suggest( new SuggestionRequest( 'Good morning', 'en', 'tr' ) );

		$sent = $this->transport->last_request();

		$this->assertSame( 'AIza-test-key', $sent['headers']['x-goog-api-key'] );
		$this->assertStringNotContainsString( 'AIza-test-key', $sent['url'], 'The key must never reach the URL.' );
		$this->assertStringNotContainsString( 'key=', $sent['url'], 'The query-parameter form must never be used.' );
		$this->assertStringNotContainsString( '?', $sent['url'], 'The generation URL carries no query string at all.' );
	}

	/**
	 * Asserts model discovery also keeps the key out of the URL.
	 *
	 * The listing call is a different code path and would leak just as badly.
	 *
	 * @return void
	 */
	public function test_model_discovery_also_keeps_the_key_out_of_the_url() {
		$this->transport->will_return( array( 'models' => array() ) );

		$this->provider->available_models();

		$sent = $this->transport->last_request();

		$this->assertSame( 'AIza-test-key', $sent['headers']['x-goog-api-key'] );
		$this->assertStringNotContainsString( 'key=', $sent['url'] );
		$this->assertSame( 'https://generativelanguage.googleapis.com/v1beta/models', $sent['url'] );
	}

	/**
	 * Asserts the documented endpoint shape is used.
	 *
	 * @return void
	 */
	public function test_requests_go_to_the_generate_content_endpoint() {
		$this->transport->will_return( $this->candidate( 'Gunaydin' ) );

		$this->provider->suggest( new SuggestionRequest( 'Good morning', 'en', 'tr' ) );

		$this->assertSame(
			'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent',
			$this->transport->last_request()['url']
		);
	}

	/**
	 * Asserts the request body uses the documented content shape.
	 *
	 * @return void
	 */
	public function test_the_request_body_uses_the_documented_shape() {
		$this->transport->will_return( $this->candidate( 'Gunaydin' ) );

		$this->provider->suggest( new SuggestionRequest( 'Good morning', 'en', 'tr' ) );

		$body = $this->transport->last_request()['body'];

		$this->assertSame( 'Good morning', $body['contents'][0]['parts'][0]['text'] );
		$this->assertSame( 'user', $body['contents'][0]['role'] );
		$this->assertNotSame( '', $body['systemInstruction']['parts'][0]['text'] );
	}

	/**
	 * Asserts a good answer becomes a result.
	 *
	 * @return void
	 */
	public function test_a_sound_answer_is_returned() {
		$this->transport->will_return( $this->candidate( 'Gunaydin' ) );

		$result = $this->provider->suggest( new SuggestionRequest( 'Good morning', 'en', 'tr' ) );

		$this->assertInstanceOf( SuggestionResult::class, $result );
		$this->assertSame( 'Gunaydin', $result->text() );
		$this->assertSame( 'gemini', $result->provider_id() );
		$this->assertSame( 'gemini-3.6-flash', $result->model() );
	}

	/**
	 * Asserts a blocked prompt is categorised as a decline.
	 *
	 * @return void
	 */
	public function test_a_blocked_prompt_is_categorised_as_declined() {
		$this->transport->will_return( array( 'promptFeedback' => array( 'blockReason' => 'SAFETY' ) ) );

		$result = $this->provider->suggest( $this->request() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'mclogiora_suggestion_declined', $result->get_error_code() );
	}

	/**
	 * Asserts a safety-stopped candidate is a decline, not a truncation.
	 *
	 * @return void
	 */
	public function test_a_safety_finish_reason_is_categorised_as_declined() {
		$this->transport->will_return( $this->candidate( '', 'SAFETY' ) );

		$result = $this->provider->suggest( $this->request() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'mclogiora_suggestion_declined', $result->get_error_code() );
	}

	/**
	 * Asserts a length-stopped candidate is a truncation, not a decline.
	 *
	 * Both arrive in the same field. Conflating them would tell an owner to
	 * reword text that only needed retrying.
	 *
	 * @return void
	 */
	public function test_a_length_finish_reason_is_categorised_as_incomplete() {
		$this->transport->will_return( $this->candidate( 'Gosterilen', 'MAX_TOKENS' ) );

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
			'no candidates'    => array( array() ),
			'empty candidates' => array( array( 'candidates' => array() ) ),
			'no parts'         => array( array( 'candidates' => array( array( 'finishReason' => 'STOP', 'content' => array() ) ) ) ),
			'empty text'       => array( array( 'candidates' => array( array( 'finishReason' => 'STOP', 'content' => array( 'parts' => array( array( 'text' => '  ' ) ) ) ) ) ) ),
		);
	}

	/**
	 * Asserts a transport error is surfaced unchanged.
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
			'network'        => array( 'mclogiora_suggestion_network' ),
		);
	}

	/**
	 * Asserts placeholders are hidden from the provider.
	 *
	 * @return void
	 */
	public function test_placeholders_are_protected() {
		$this->transport->will_return( $this->candidate( 'placeholder' ) );

		$this->provider->suggest( $this->request() );

		$text = $this->transport->last_request()['body']['contents'][0]['parts'][0]['text'];

		$this->assertStringNotContainsString( '%s', $text );
		$this->assertTrue( (bool) preg_match( '/\[\[MCQ_[a-f0-9]+_0\]\]/', $text ) );
	}

	/**
	 * Asserts a dropped placeholder is refused.
	 *
	 * @return void
	 */
	public function test_a_dropped_placeholder_is_refused() {
		$this->transport->will_return( $this->candidate( 'Gosterilen sonuc' ) );

		$result = $this->provider->suggest( $this->request() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'mclogiora_suggestion_placeholder_damage', $result->get_error_code() );
	}

	/**
	 * Asserts model discovery filters to models that can actually answer.
	 *
	 * @return void
	 */
	public function test_model_discovery_skips_models_that_cannot_generate() {
		$this->transport->will_return(
			array(
				'models' => array(
					array(
						'name'                       => 'models/gemini-3.6-flash',
						'displayName'                => 'Gemini 3.6 Flash',
						'supportedGenerationMethods' => array( 'generateContent' ),
					),
					array(
						'name'                       => 'models/text-embedding-004',
						'displayName'                => 'Embedding',
						'supportedGenerationMethods' => array( 'embedContent' ),
					),
				),
			)
		);

		$models = $this->provider->available_models();

		$this->assertIsArray( $models );
		$this->assertCount( 1, $models, 'A model that cannot generate must not be offered.' );
		$this->assertSame( 'gemini-3.6-flash', $models[0]['id'] );
		$this->assertTrue( $models[0]['recommended'] );
	}

	/**
	 * Asserts nothing reaches the network without an explicit action.
	 *
	 * @return void
	 */
	public function test_no_request_is_made_without_an_explicit_action() {
		$this->provider->is_configured();
		$this->provider->selected_model();
		$this->provider->supports_language_pair( 'en', 'tr' );

		$this->assertSame( array(), $this->transport->requests() );
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
		$this->assertSame( array(), $this->transport->requests() );
	}
}
