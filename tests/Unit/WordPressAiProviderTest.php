<?php
/**
 * WordPress AI Client provider tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Suggestions\LlmInstructions;
use McLogiora\Suggestions\Providers\WordPressAiProvider;
use McLogiora\Suggestions\SuggestionRequest;
use McLogiora\Suggestions\SuggestionResult;
use PHPUnit\Framework\TestCase;

/**
 * Small in-process stand-in for the Core prompt builder.
 *
 * @package McLogiora
 */
final class WordPressAiPromptBuilderDouble {
	/**
	 * Whether Core reports text generation support.
	 *
	 * @var bool
	 */
	public $supported = true;

	/**
	 * Text returned by generation.
	 *
	 * @var string|\WP_Error
	 */
	public $response = 'Merhaba';

	/**
	 * Last system instruction.
	 *
	 * @var string
	 */
	public $instruction = '';

	/**
	 * Last text passed to Core.
	 *
	 * @var string
	 */
	public $input = '';

	/**
	 * Returns the configured capability.
	 *
	 * @return bool
	 */
	public function is_supported_for_text_generation() {
		return $this->supported;
	}

	/**
	 * Records the instruction.
	 *
	 * @param string $instruction Instruction text.
	 * @return self
	 */
	public function using_system_instruction( $instruction ) {
		$this->instruction = $instruction;

		return $this;
	}

	/**
	 * Records the input.
	 *
	 * @param string $input Text to translate.
	 * @return self
	 */
	public function with_text( $input ) {
		$this->input = $input;

		return $this;
	}

	/**
	 * Returns the canned generation result.
	 *
	 * @return string|\WP_Error
	 */
	public function generate_text() {
		return $this->response;
	}
}

/**
 * Covers the provider-neutral AI adapter without making a network request.
 */
final class WordPressAiProviderTest extends TestCase {
	/**
	 * Provider under test.
	 *
	 * @var WordPressAiProvider
	 */
	private $provider;

	/**
	 * Builds the provider.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->provider = new WordPressAiProvider( new LlmInstructions() );
	}

	/**
	 * Core-managed providers do not expose credentials or model catalogues.
	 *
	 * @return void
	 */
	public function test_core_owns_credentials_and_model_selection() {
		$this->assertFalse( $this->provider->manages_credentials() );
		$this->assertFalse( $this->provider->requires_model_selection() );
		$this->assertSame( array(), $this->provider->available_models() );
		$this->assertSame( '', $this->provider->selected_model() );
	}

	/**
	 * The unit bootstrap has no WordPress AI Client, so no call is possible.
	 *
	 * @return void
	 */
	public function test_missing_core_client_is_reported_without_a_request() {
		$this->assertFalse( $this->provider->is_configured() );
		$this->assertSame( WordPressAiProvider::AVAILABILITY_NO_PROVIDER, $this->provider->availability_state() );
		$this->assertTrue( is_wp_error( $this->provider->test_connection() ) );
		$this->assertTrue( is_wp_error( $this->provider->suggest( new SuggestionRequest( 'Hello', 'en', 'tr' ) ) ) );
	}

	/**
	 * The adapter passes only the request text and translation instructions to Core.
	 *
	 * @return void
	 */
	public function test_supported_core_client_returns_a_reviewable_result() {
		$builder = new WordPressAiPromptBuilderDouble();
		$provider = new WordPressAiProvider( new LlmInstructions(), static function () use ( $builder ) {
			return $builder;
		} );

		$this->assertTrue( $provider->is_configured() );
		$result = $provider->suggest( new SuggestionRequest( 'Hello', 'en', 'tr' ) );

		$this->assertInstanceOf( SuggestionResult::class, $result );
		$this->assertSame( 'Merhaba', $result->text() );
		$this->assertSame( 'Hello', $builder->input );
		$this->assertStringContainsString( 'en', $builder->instruction );
		$this->assertStringContainsString( 'tr', $builder->instruction );
	}

	/**
	 * An unavailable Core capability is reported without generation.
	 *
	 * @return void
	 */
	public function test_unsupported_core_client_is_not_ready() {
		$builder = new WordPressAiPromptBuilderDouble();
		$builder->supported = false;
		$provider = new WordPressAiProvider( new LlmInstructions(), static function () use ( $builder ) {
			return $builder;
		} );

		$this->assertFalse( $provider->is_configured() );
		$this->assertTrue( is_wp_error( $provider->suggest( new SuggestionRequest( 'Hello', 'en', 'tr' ) ) ) );
		$this->assertSame( '', $builder->input );
	}

	/**
	 * Core generation errors remain errors at the adapter boundary.
	 *
	 * @return void
	 */
	public function test_core_generation_error_is_not_converted_to_success() {
		$builder = new WordPressAiPromptBuilderDouble();
		$builder->response = new \WP_Error( 'core_failure', 'Provider failed.' );
		$provider = new WordPressAiProvider( new LlmInstructions(), static function () use ( $builder ) {
			return $builder;
		} );

		$result = $provider->suggest( new SuggestionRequest( 'Hello', 'en', 'tr' ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'core_failure', $result->get_error_code() );
	}

	/**
	 * Empty Core output cannot become an empty suggestion.
	 *
	 * @return void
	 */
	public function test_empty_core_output_is_rejected() {
		$builder = new WordPressAiPromptBuilderDouble();
		$builder->response = " \n ";
		$provider = new WordPressAiProvider( new LlmInstructions(), static function () use ( $builder ) {
			return $builder;
		} );

		$result = $provider->suggest( new SuggestionRequest( 'Hello', 'en', 'tr' ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'mclogiora_suggestion_invalid_response', $result->get_error_code() );
	}

	/**
	 * Exceptions at the Core boundary are normalised instead of escaping as fatals.
	 *
	 * @return void
	 */
	public function test_core_boundary_exception_is_normalised() {
		$provider = new WordPressAiProvider( new LlmInstructions(), static function () {
			throw new \RuntimeException( 'raw provider detail must not escape' );
		} );

		$result = $provider->suggest( new SuggestionRequest( 'Hello', 'en', 'tr' ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'mclogiora_suggestion_not_configured', $result->get_error_code() );
		$this->assertStringNotContainsString( 'raw provider detail', $result->get_error_message() );
	}
}
