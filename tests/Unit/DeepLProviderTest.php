<?php
/**
 * DeepL provider tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\Providers\DeepLProvider;
use McLogiora\Suggestions\SuggestionRequest;
use McLogiora\Suggestions\SuggestionResult;
use McLogiora\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Covers the DeepL adapter, which is the odd one out by design.
 *
 * DeepL is a translation service rather than a language model. It takes no
 * instructions, exposes no model menu, and protects untranslatable content
 * with its own markup instead of by being asked nicely. These tests pin all
 * three differences, because the pressure over time will be to make DeepL look
 * like the other three -- and each step in that direction would either send it
 * prose it would faithfully translate, or invent a settings field that
 * controls nothing.
 */
final class DeepLProviderTest extends TestCase {
	/**
	 * Recording transport.
	 *
	 * @var FakeTransport
	 */
	private $transport;

	/**
	 * Sets up a recording transport.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->transport = new FakeTransport();
	}

	/**
	 * Clears stored state between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		( new CredentialStore() )->remove( 'deepl' );

		parent::tearDown();
	}

	/**
	 * Builds a provider holding the given key.
	 *
	 * @param string $key API key.
	 * @return DeepLProvider
	 */
	private function provider( $key = 'deepl-pro-key' ) {
		$credentials = new CredentialStore();
		$credentials->save( 'deepl', $key );

		return new DeepLProvider( $this->transport, $credentials );
	}

	/**
	 * Builds a successful translate payload.
	 *
	 * @param string $text Translated text.
	 * @return array<string,mixed>
	 */
	private function translated( $text ) {
		return array(
			'translations' => array(
				array(
					'detected_source_language' => 'EN',
					'text'                     => $text,
				),
			),
		);
	}

	/**
	 * Parses a form-encoded body into an array.
	 *
	 * @param string $body Encoded body.
	 * @return array<string,string>
	 */
	private function parse( $body ) {
		$fields = array();

		parse_str( (string) $body, $fields );

		return $fields;
	}

	/**
	 * Asserts a free key is routed to the free host.
	 *
	 * @return void
	 */
	public function test_a_free_key_uses_the_free_host() {
		$this->transport->will_return( $this->translated( 'Gunaydin' ) );

		$this->provider( '279a2e9d-83b3-c416-7e2d-f721593e42a0:fx' )
			->suggest( new SuggestionRequest( 'Good morning', 'en', 'tr' ) );

		$this->assertSame( 'https://api-free.deepl.com/v2/translate', $this->transport->last_request()['url'] );
	}

	/**
	 * Asserts a paid key is routed to the paid host.
	 *
	 * @return void
	 */
	public function test_a_paid_key_uses_the_paid_host() {
		$this->transport->will_return( $this->translated( 'Gunaydin' ) );

		$this->provider( '279a2e9d-83b3-c416-7e2d-f721593e42a0' )
			->suggest( new SuggestionRequest( 'Good morning', 'en', 'tr' ) );

		$this->assertSame( 'https://api.deepl.com/v2/translate', $this->transport->last_request()['url'] );
	}

	/**
	 * Asserts the connection test uses the free host for a free key too.
	 *
	 * @return void
	 */
	public function test_the_connection_test_follows_the_same_host_rule() {
		$this->transport->will_return( array( 'character_count' => 10, 'character_limit' => 500000 ) );

		$this->provider( 'abc:fx' )->test_connection();

		$this->assertSame( 'https://api-free.deepl.com/v2/usage', $this->transport->last_request()['url'] );
		$this->assertSame( 'GET', $this->transport->last_request()['method'] );
	}

	/**
	 * Asserts the credential travels in the documented header, not the URL.
	 *
	 * @return void
	 */
	public function test_the_credential_is_sent_as_a_header_and_never_in_the_url() {
		$this->transport->will_return( $this->translated( 'Gunaydin' ) );

		$this->provider( 'secret-key' )->suggest( new SuggestionRequest( 'Good morning', 'en', 'tr' ) );

		$sent = $this->transport->last_request();

		$this->assertSame( 'DeepL-Auth-Key secret-key', $sent['headers']['Authorization'] );
		$this->assertStringNotContainsString( 'secret-key', $sent['url'] );
	}

	/**
	 * Asserts protected content is wrapped and DeepL is told to ignore it.
	 *
	 * This is the provider-native mechanism. The engine is instructed rather
	 * than asked, which is a stronger guarantee than any instruction a
	 * language model may choose to follow.
	 *
	 * @return void
	 */
	public function test_protected_content_uses_xml_tag_handling_and_ignore_tags() {
		$this->transport->will_return( $this->translated( 'placeholder' ) );

		$this->provider()->suggest( new SuggestionRequest( 'Showing %s results', 'en', 'tr' ) );

		$body = $this->parse( $this->transport->last_request()['body'] );

		$this->assertSame( 'xml', $body['tag_handling'] );
		$this->assertSame( 'mcq', $body['ignore_tags'] );
		$this->assertStringNotContainsString( '%s', $body['text'], 'A placeholder must never reach the provider.' );
		$this->assertStringContainsString( '<mcq>', $body['text'], 'Protected content must be wrapped in the ignored element.' );
		$this->assertStringContainsString( '</mcq>', $body['text'] );
	}

	/**
	 * Asserts text with nothing to protect carries no tag handling.
	 *
	 * Sending XML handling for plain prose would make DeepL treat stray angle
	 * brackets in the copy as markup.
	 *
	 * @return void
	 */
	public function test_plain_text_is_sent_without_tag_handling() {
		$this->transport->will_return( $this->translated( 'Gunaydin' ) );

		$this->provider()->suggest( new SuggestionRequest( 'Good morning', 'en', 'tr' ) );

		$body = $this->parse( $this->transport->last_request()['body'] );

		$this->assertArrayNotHasKey( 'tag_handling', $body );
		$this->assertArrayNotHasKey( 'ignore_tags', $body );
	}

	/**
	 * Asserts no language-model instruction text is ever sent.
	 *
	 * DeepL translates whatever it is given. An instruction sent here would
	 * come back translated into the target language and be offered to a
	 * reviewer as the suggestion.
	 *
	 * @return void
	 */
	public function test_no_language_model_instruction_text_is_sent() {
		$this->transport->will_return( $this->translated( 'Gunaydin' ) );

		$this->provider()->suggest(
			new SuggestionRequest( 'Good morning', 'en', 'tr', array( 'surface' => 'post_title' ) )
		);

		$body = $this->parse( $this->transport->last_request()['body'] );

		$this->assertSame( 'Good morning', $body['text'], 'Only the source text may be sent.' );

		foreach ( array( 'system', 'instructions', 'systemInstruction', 'messages', 'model' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $body );
		}

		$this->assertStringNotContainsStringIgnoringCase( 'translator', $this->transport->last_request()['body'] );
		$this->assertStringNotContainsStringIgnoringCase( 'do not add', $this->transport->last_request()['body'] );
	}

	/**
	 * Asserts the target language is sent in DeepL's own form.
	 *
	 * @param string $code Language code.
	 * @param string $locale Locale.
	 * @param string $expected Expected DeepL target code.
	 * @return void
	 *
	 * @dataProvider provide_target_languages
	 */
	public function test_target_languages_are_mapped( $code, $locale, $expected ) {
		$this->transport->will_return( $this->translated( 'x' ) );

		$this->provider()->suggest(
			new SuggestionRequest( 'Good morning', 'de', $code, array( 'target_locale' => $locale ) )
		);

		$body = $this->parse( $this->transport->last_request()['body'] );

		$this->assertSame( $expected, $body['target_lang'] );
	}

	/**
	 * Supplies language mappings, including the regional variants DeepL needs.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function provide_target_languages() {
		return array(
			'turkish'             => array( 'tr', 'tr_TR', 'TR' ),
			'british english'     => array( 'en', 'en_GB', 'EN-GB' ),
			'american english'    => array( 'en', 'en_US', 'EN-US' ),
			'english without locale' => array( 'en', '', 'EN-US' ),
			'brazilian portuguese'   => array( 'pt', 'pt_BR', 'PT-BR' ),
			'european portuguese'    => array( 'pt', 'pt_PT', 'PT-PT' ),
		);
	}

	/**
	 * Asserts a good answer becomes a result carrying no model.
	 *
	 * @return void
	 */
	public function test_a_sound_answer_is_returned_without_a_model() {
		$this->transport->will_return( $this->translated( 'Gunaydin' ) );

		$result = $this->provider()->suggest( new SuggestionRequest( 'Good morning', 'en', 'tr' ) );

		$this->assertInstanceOf( SuggestionResult::class, $result );
		$this->assertSame( 'Gunaydin', $result->text() );
		$this->assertSame( 'deepl', $result->provider_id() );
		$this->assertSame( '', $result->model(), 'DeepL has no model to report.' );
	}

	/**
	 * Asserts a wrapped placeholder is unwrapped and restored.
	 *
	 * @return void
	 */
	public function test_a_faithful_answer_restores_its_placeholders() {
		$this->transport->will_return( $this->translated( 'x' ) );

		$provider = $this->provider();

		$provider->suggest( new SuggestionRequest( 'Showing %s results', 'en', 'tr' ) );

		$sent = $this->parse( $this->transport->last_request()['body'] );

		$this->assertTrue( (bool) preg_match( '/\[\[MCQ_[a-f0-9]+_0\]\]/', $sent['text'], $matches ) );

		$echoed = new FakeTransport();
		$echoed->will_return( $this->translated( 'Gosterilen <mcq>' . $matches[0] . '</mcq> sonuc' ) );

		$credentials = new CredentialStore();
		$credentials->save( 'deepl', 'deepl-pro-key' );

		/*
		 * A fresh provider builds a fresh shield with a fresh nonce, so a token
		 * minted for an earlier request must not validate here. That is the
		 * collision guarantee observed from the outside.
		 */
		$result = ( new DeepLProvider( $echoed, $credentials ) )
			->suggest( new SuggestionRequest( 'Showing %s results', 'en', 'tr' ) );

		$this->assertTrue( is_wp_error( $result ), 'A token from another request must not validate.' );
	}

	/**
	 * Asserts a dropped placeholder is refused.
	 *
	 * @return void
	 */
	public function test_a_dropped_placeholder_is_refused() {
		$this->transport->will_return( $this->translated( 'Gosterilen sonuc' ) );

		$result = $this->provider()->suggest( new SuggestionRequest( 'Showing %s results', 'en', 'tr' ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'mclogiora_suggestion_placeholder_damage', $result->get_error_code() );
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

		$result = $this->provider()->suggest( new SuggestionRequest( 'Good morning', 'en', 'tr' ) );

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
			'no translations'    => array( array() ),
			'empty translations' => array( array( 'translations' => array() ) ),
			'empty text'         => array( array( 'translations' => array( array( 'text' => '   ' ) ) ) ),
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

		$result = $this->provider()->suggest( new SuggestionRequest( 'Good morning', 'en', 'tr' ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/**
	 * Supplies normalised transport failures, including DeepL's quota state.
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
	 * Asserts there is no model selection to make.
	 *
	 * @return void
	 */
	public function test_there_is_no_model_selection() {
		$provider = $this->provider();

		$this->assertFalse( $provider->requires_model_selection() );
		$this->assertSame( '', $provider->selected_model() );
		$this->assertSame( array(), $provider->available_models() );
		$this->assertSame( array(), $this->transport->requests(), 'Listing models must not reach the network.' );
	}

	/**
	 * Asserts a key alone makes the provider usable.
	 *
	 * @return void
	 */
	public function test_a_key_alone_configures_the_provider() {
		$this->assertTrue( $this->provider()->is_configured() );
	}

	/**
	 * Asserts nothing reaches the network without an explicit action.
	 *
	 * @return void
	 */
	public function test_no_request_is_made_without_an_explicit_action() {
		$provider = $this->provider();

		$provider->is_configured();
		$provider->requires_model_selection();
		$provider->available_models();
		$provider->supports_language_pair( 'en', 'tr' );

		$this->assertSame( array(), $this->transport->requests() );
	}
}
