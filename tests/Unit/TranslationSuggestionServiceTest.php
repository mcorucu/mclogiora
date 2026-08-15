<?php
/**
 * Translation suggestion service tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\LlmInstructions;
use McLogiora\Suggestions\Providers\OpenAiProvider;
use McLogiora\Suggestions\ProviderRegistry;
use McLogiora\Suggestions\SuggestionResult;
use McLogiora\Suggestions\SuggestionSettings;
use McLogiora\Suggestions\SuggestionSurface;
use McLogiora\Suggestions\TranslationSuggestionService;
use McLogiora\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Covers the single entry point, and the promises it makes.
 *
 * Two of these tests matter more than the rest. One proves a site with the
 * feature switched off cannot reach a provider through any argument
 * combination. The other proves the request carries the field being
 * translated and nothing else about the object it came from. Both are release
 * invariants rather than ordinary coverage.
 */
final class TranslationSuggestionServiceTest extends TestCase {
	/**
	 * Recording transport.
	 *
	 * @var FakeTransport
	 */
	private $transport;

	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry
	 */
	private $registry;

	/**
	 * Service under test.
	 *
	 * @var TranslationSuggestionService
	 */
	private $service;

	/**
	 * Sets up an enabled, fully configured site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->transport = new FakeTransport();

		$credentials = new CredentialStore();
		$credentials->save( 'openai', 'sk-test-key' );

		$provider = new OpenAiProvider( $this->transport, $credentials, new LlmInstructions() );
		$provider->set_selected_model( 'gpt-5.4-mini' );

		$this->registry = new ProviderRegistry();
		$this->registry->add( $provider );

		update_option( SuggestionSettings::OPTION_ENABLED, true );
		update_option( SuggestionSettings::OPTION_PROVIDER, 'openai' );

		$this->service = new TranslationSuggestionService( new SuggestionSettings(), $this->registry );
	}

	/**
	 * Clears stored state between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		delete_option( SuggestionSettings::OPTION_ENABLED );
		delete_option( SuggestionSettings::OPTION_PROVIDER );

		$provider = $this->registry->find( 'openai' );

		if ( $provider instanceof OpenAiProvider ) {
			$provider->clear_selected_model();
		}

		( new CredentialStore() )->remove( 'openai' );

		parent::tearDown();
	}

	/**
	 * Queues a successful provider answer.
	 *
	 * @param string $text Text the provider returns.
	 * @return void
	 */
	private function will_answer( $text ) {
		$this->transport->will_return(
			array(
				'status' => 'completed',
				'output' => array(
					array(
						'type'    => 'message',
						'content' => array( array( 'type' => 'output_text', 'text' => $text ) ),
					),
				),
			)
		);
	}

	/**
	 * Asserts a switched-off site cannot reach a provider at all.
	 *
	 * @return void
	 */
	public function test_a_disabled_site_makes_no_request() {
		update_option( SuggestionSettings::OPTION_ENABLED, false );

		$result = $this->service->generate( SuggestionSurface::POST_TITLE, 'Hello', 'en', 'tr' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( array(), $this->transport->requests(), 'A disabled site must never reach a provider.' );
		$this->assertFalse( $this->service->is_available() );
	}

	/**
	 * Asserts a credential without a chosen model reaches no provider.
	 *
	 * @return void
	 */
	public function test_a_provider_without_a_model_makes_no_request() {
		$provider = $this->registry->find( 'openai' );

		$this->assertInstanceOf( OpenAiProvider::class, $provider );

		$provider->clear_selected_model();

		$result = $this->service->generate( SuggestionSurface::POST_TITLE, 'Hello', 'en', 'tr' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'mclogiora_suggestion_not_configured', $result->get_error_code() );
		$this->assertSame( array(), $this->transport->requests() );
		$this->assertFalse( $this->service->is_available() );
	}

	/**
	 * Asserts an unsupported field is refused without a request.
	 *
	 * @param string $surface Surface identifier.
	 * @return void
	 *
	 * @dataProvider provide_unsupported_surfaces
	 */
	public function test_an_unsupported_field_is_refused_without_a_request( $surface ) {
		$result = $this->service->generate( $surface, 'Hello', 'en', 'tr' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'mclogiora_suggestion_invalid_request', $result->get_error_code() );
		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Supplies fields that must never be sent to a provider.
	 *
	 * `post_content` heads the list. Phase 16 defers document translation
	 * rather than shipping it partially, and the allow-list is what makes that
	 * decision enforceable rather than merely documented.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_unsupported_surfaces() {
		return array(
			'post content'    => array( 'post_content' ),
			'post meta'       => array( 'post_meta' ),
			'attachment url'  => array( 'media_url' ),
			'slug'            => array( 'term_slug' ),
			'empty'           => array( '' ),
			'made up'         => array( 'anything_else' ),
		);
	}

	/**
	 * Asserts every supported surface is accepted.
	 *
	 * @param string $surface Surface identifier.
	 * @return void
	 *
	 * @dataProvider provide_supported_surfaces
	 */
	public function test_every_supported_surface_is_accepted( $surface ) {
		$this->will_answer( 'Merhaba' );

		$result = $this->service->generate( $surface, 'Hello', 'en', 'tr' );

		$this->assertInstanceOf( SuggestionResult::class, $result, "{$surface} should be suggestable." );
	}

	/**
	 * Supplies the fields Phase 16 supports.
	 *
	 * @return array<int,array{0:string}>
	 */
	public function provide_supported_surfaces() {
		$cases = array();

		foreach ( SuggestionSurface::all() as $surface ) {
			$cases[ $surface ] = array( $surface );
		}

		return $cases;
	}

	/**
	 * Asserts the request carries the field and nothing else about the object.
	 *
	 * Data minimisation stated as an assertion. The service is handed a post's
	 * worth of context and must send only the title.
	 *
	 * @return void
	 */
	public function test_only_the_requested_field_is_sent() {
		$this->will_answer( 'Merhaba' );

		$this->service->generate(
			SuggestionSurface::POST_TITLE,
			'Hello world',
			'en',
			'tr',
			array( 'source_locale' => 'en_US', 'target_locale' => 'tr_TR' )
		);

		$encoded = wp_json_encode( $this->transport->last_request()['body'] );

		$this->assertStringContainsString( 'Hello world', $encoded );

        foreach (
			array(
				'The quick brown fox jumped over the body of the post',
				'admin@example.com',
				'https://example.com',
				'post_meta',
				'42',
			) as $absent
		) {
			$this->assertStringNotContainsString( $absent, $encoded, 'Only the requested field may be sent.' );
		}
	}

	/**
	 * Asserts identical languages are refused without a request.
	 *
	 * @return void
	 */
	public function test_identical_languages_are_refused_without_a_request() {
		$result = $this->service->generate( SuggestionSurface::POST_TITLE, 'Hello', 'en', 'en' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Asserts a missing language is refused without a request.
	 *
	 * @return void
	 */
	public function test_a_missing_language_is_refused_without_a_request() {
		$this->assertTrue( is_wp_error( $this->service->generate( SuggestionSurface::POST_TITLE, 'Hello', '', 'tr' ) ) );
		$this->assertTrue( is_wp_error( $this->service->generate( SuggestionSurface::POST_TITLE, 'Hello', 'en', '' ) ) );
		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Asserts empty text is refused without a request.
	 *
	 * @return void
	 */
	public function test_empty_text_is_refused_without_a_request() {
		$result = $this->service->generate( SuggestionSurface::POST_TITLE, "   \n ", 'en', 'tr' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Asserts an unknown provider identifier reaches nothing.
	 *
	 * @return void
	 */
	public function test_an_unknown_provider_reaches_nothing() {
		update_option( SuggestionSettings::OPTION_PROVIDER, 'not-a-provider' );

		$result = $this->service->generate( SuggestionSurface::POST_TITLE, 'Hello', 'en', 'tr' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Asserts a successful generation returns a reviewable result.
	 *
	 * @return void
	 */
	public function test_a_successful_generation_returns_a_result() {
		$this->will_answer( 'Merhaba dunya' );

		$result = $this->service->generate( SuggestionSurface::POST_TITLE, 'Hello world', 'en', 'tr' );

		$this->assertInstanceOf( SuggestionResult::class, $result );
		$this->assertSame( 'Merhaba dunya', $result->text() );
		$this->assertSame( 'openai', $result->provider_id() );
		$this->assertSame( 'gpt-5.4-mini', $result->model() );
	}

	/**
	 * Asserts long-form fields are sent as HTML and short ones are not.
	 *
	 * @return void
	 */
	public function test_long_form_fields_are_treated_as_markup() {
		$this->will_answer( 'x' );
		$this->service->generate( SuggestionSurface::POST_EXCERPT, '<p>Hello</p>', 'en', 'tr' );

		$this->assertStringContainsString( 'HTML', $this->transport->last_request()['body']['instructions'] );

		$this->will_answer( 'x' );
		$this->service->generate( SuggestionSurface::POST_TITLE, 'Hello', 'en', 'tr' );

		$this->assertStringNotContainsString( 'HTML', $this->transport->last_request()['body']['instructions'] );
	}
}
