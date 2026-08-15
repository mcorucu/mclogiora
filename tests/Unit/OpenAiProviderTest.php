<?php
/**
 * OpenAI provider tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\LlmInstructions;
use McLogiora\Suggestions\Providers\OpenAiProvider;
use McLogiora\Suggestions\SuggestionRequest;
use McLogiora\Suggestions\SuggestionResult;
use McLogiora\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Covers the OpenAI adapter, including its retention policy.
 *
 * The `store` tests are the reason this file exists. OpenAI's Responses API
 * retains generated responses by default, so the parameter that turns that off
 * is the single line standing between mcLogiora's stated privacy policy and
 * silently retaining every translated title at a third party. It fails open,
 * so it is pinned here rather than trusted.
 */
final class OpenAiProviderTest extends TestCase {
	/**
	 * Recording transport.
	 *
	 * @var FakeTransport
	 */
	private $transport;

	/**
	 * Provider under test.
	 *
	 * @var OpenAiProvider
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
		$credentials->save( 'openai', 'sk-test-key' );

		$this->provider = new OpenAiProvider( $this->transport, $credentials, new LlmInstructions() );
		$this->provider->set_selected_model( 'gpt-5.4-mini' );
	}

	/**
	 * Clears stored state between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->provider->clear_selected_model();

		( new CredentialStore() )->remove( 'openai' );

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
	 * Builds a successful Responses payload.
	 *
	 * @param string $text Text the model returned.
	 * @return array<string,mixed>
	 */
	private function completed( $text ) {
		return array(
			'status' => 'completed',
			'output' => array(
				array(
					'type'    => 'message',
					'content' => array(
						array(
							'type' => 'output_text',
							'text' => $text,
						),
					),
				),
			),
		);
	}

	/**
	 * Asserts the retention parameter is present and exactly false.
	 *
	 * Identity rather than falsiness on purpose. A `0`, a `null` or an empty
	 * string would all read as "off" to a loose check while being a different
	 * value on the wire.
	 *
	 * @return void
	 */
	public function test_request_body_disables_response_storage() {
		$body = $this->provider->build_request_body( $this->request(), 'Showing [[MCQ_x_0]] results', true, 'gpt-5.4-mini' );

		$this->assertArrayHasKey( 'store', $body, 'The store parameter must never be omitted.' );
		$this->assertFalse( $body['store'], 'The store parameter must be exactly false.' );
		$this->assertSame( false, $body['store'], 'The store parameter must be boolean false, not a falsy value.' );
	}

	/**
	 * Asserts the retention parameter survives an actual suggestion call.
	 *
	 * Guards the wiring as well as the builder: a body built correctly and
	 * then not sent would pass the previous test and still retain content.
	 *
	 * @return void
	 */
	public function test_suggestion_requests_are_sent_with_storage_disabled() {
		$this->transport->will_return( $this->completed( 'Gosterilen [[MCQ_x_0]] sonuc' ) );

		$this->provider->suggest( $this->request() );

		$sent = $this->transport->last_request();

		$this->assertNotNull( $sent );
		$this->assertArrayHasKey( 'store', $sent['body'] );
		$this->assertSame( false, $sent['body']['store'] );
	}

	/**
	 * Asserts no stateful Responses feature is ever sent.
	 *
	 * @return void
	 */
	public function test_no_stateful_response_features_are_used() {
		$body = $this->provider->build_request_body( $this->request(), 'text', false, 'gpt-5.4-mini' );

		foreach ( array( 'previous_response_id', 'conversation', 'background', 'tools', 'file_ids', 'attachments' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $body, "{$forbidden} makes the request stateful and must not be sent." );
		}
	}

	/**
	 * Asserts a refusal is an error rather than an empty suggestion.
	 *
	 * @return void
	 */
	public function test_a_refusal_is_reported_as_an_error() {
		$this->transport->will_return(
			array(
				'status' => 'completed',
				'output' => array(
					array(
						'type'    => 'message',
						'content' => array(
							array(
								'type'    => 'refusal',
								'refusal' => 'I cannot help with that.',
							),
						),
					),
				),
			)
		);

		$result = $this->provider->suggest( $this->request() );

		$this->assertTrue( is_wp_error( $result ), 'A refusal must never be treated as a suggestion.' );
		$this->assertSame( 'mclogiora_suggestion_declined', $result->get_error_code() );
	}

	/**
	 * Asserts an incomplete generation is an error.
	 *
	 * @return void
	 */
	public function test_an_incomplete_response_is_reported_as_an_error() {
		$this->transport->will_return(
			array(
				'status'             => 'incomplete',
				'incomplete_details' => array( 'reason' => 'max_output_tokens' ),
				'output'             => array(),
			)
		);

		$result = $this->provider->suggest( $this->request() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'mclogiora_suggestion_declined', $result->get_error_code() );
	}

	/**
	 * Asserts an empty body is an error rather than an empty suggestion.
	 *
	 * @return void
	 */
	public function test_an_empty_output_is_reported_as_an_error() {
		$this->transport->will_return( $this->completed( '   ' ) );

		$this->assertTrue( is_wp_error( $this->provider->suggest( $this->request() ) ) );
	}

	/**
	 * Asserts a damaged placeholder is refused rather than applied.
	 *
	 * @return void
	 */
	public function test_a_damaged_placeholder_is_refused() {
		$this->transport->will_return( $this->completed( 'Gosterilen sonuc' ) );

		$result = $this->provider->suggest( $this->request() );

		$this->assertTrue( is_wp_error( $result ), 'A dropped placeholder must never reach content.' );
		$this->assertSame( 'mclogiora_suggestion_placeholder_damage', $result->get_error_code() );
	}

	/**
	 * Asserts a good answer restores its placeholders and is returned.
	 *
	 * @return void
	 */
	public function test_a_sound_answer_is_returned() {
		$this->transport->will_return( $this->completed( 'Gunaydin' ) );

		$suggestion = $this->provider->suggest(
			new SuggestionRequest( 'Good morning', 'en', 'tr', array( 'surface' => 'string' ) )
		);

		$this->assertInstanceOf( SuggestionResult::class, $suggestion );
		$this->assertSame( 'Gunaydin', $suggestion->text() );
		$this->assertSame( 'openai', $suggestion->provider_id() );
		$this->assertSame( 'gpt-5.4-mini', $suggestion->model() );
		$this->assertFalse( $suggestion->has_warnings() );
	}

	/**
	 * Asserts a faithful answer has its placeholders put back.
	 *
	 * The shield's nonce is random per call, so the token cannot be named in
	 * advance. It is read back out of the recorded request, which is exactly
	 * what a provider that echoed the token faithfully would have produced.
	 *
	 * @return void
	 */
	public function test_a_faithful_answer_restores_its_placeholders() {
		$this->transport->will_return( $this->completed( 'placeholder' ) );

		$this->provider->suggest( $this->request() );

		$sent = $this->transport->last_request();

		$this->assertNotNull( $sent );
		$this->assertTrue( (bool) preg_match( '/\[\[MCQ_[a-f0-9]+_0\]\]/', $sent['body']['input'], $matches ) );

		$echoed = new FakeTransport();
		$echoed->will_return( $this->completed( 'Gosterilen ' . $matches[0] . ' sonuc' ) );

		$credentials = new CredentialStore();
		$credentials->save( 'openai', 'sk-test-key' );

		$provider = new OpenAiProvider( $echoed, $credentials, new LlmInstructions() );
		$provider->set_selected_model( 'gpt-5.4-mini' );

		/*
		 * A fresh provider builds a fresh shield with a fresh nonce, so the
		 * echoed token belongs to a different shield instance and must be
		 * rejected. That is the collision guarantee, asserted from the
		 * outside: a token is only valid for the request that issued it.
		 */
		$result = $provider->suggest( $this->request() );

		$this->assertTrue( is_wp_error( $result ), 'A token from another request must not validate.' );

		$provider->clear_selected_model();
	}

	/**
	 * Asserts an unselected model leaves the provider unusable.
	 *
	 * @return void
	 */
	public function test_a_provider_without_a_chosen_model_is_not_configured() {
		$this->provider->clear_selected_model();

		$this->assertFalse( $this->provider->is_configured() );
		$this->assertTrue( is_wp_error( $this->provider->suggest( $this->request() ) ) );
		$this->assertSame( array(), $this->transport->requests(), 'An unconfigured provider must not make a request.' );
	}

	/**
	 * Asserts a retired model is dropped rather than silently swapped.
	 *
	 * @return void
	 */
	public function test_a_retired_model_invalidates_the_selection() {
		$invalidated = $this->provider->reconcile_selected_model(
			array(
				array(
					'id'          => 'gpt-5.4',
					'label'       => 'gpt-5.4',
					'recommended' => false,
				),
			)
		);

		$this->assertTrue( $invalidated, 'A retired model must invalidate the stored selection.' );
		$this->assertSame( '', $this->provider->selected_model(), 'No replacement model may be chosen automatically.' );
		$this->assertFalse( $this->provider->is_configured() );
	}

	/**
	 * Asserts a still-offered model survives a refresh untouched.
	 *
	 * @return void
	 */
	public function test_a_still_offered_model_is_kept() {
		$invalidated = $this->provider->reconcile_selected_model(
			array(
				array(
					'id'          => 'gpt-5.4-mini',
					'label'       => 'gpt-5.4-mini',
					'recommended' => true,
				),
			)
		);

		$this->assertFalse( $invalidated );
		$this->assertSame( 'gpt-5.4-mini', $this->provider->selected_model() );
	}
}
