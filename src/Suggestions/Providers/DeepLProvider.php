<?php
/**
 * DeepL translation suggestion provider.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions\Providers;

use McLogiora\Suggestions\PlaceholderShield;
use McLogiora\Suggestions\SuggestionRequest;
use McLogiora\Suggestions\SuggestionResult;

defined( 'ABSPATH' ) || exit;

/**
 * Suggests translations through the DeepL translation API.
 *
 * ## Not a language model, and treated accordingly
 *
 * The other three providers are general-purpose models that are asked, in
 * words, to behave like translators. DeepL is a translation service. It takes
 * no instructions, has no system prompt, cannot be told what a title is, and
 * cannot refuse on policy grounds. Pushing it through the same instruction
 * pipeline would mean sending prose it would faithfully translate into the
 * target language and hand back as the answer.
 *
 * It also exposes no model menu, so it reports `false` from
 * {@see self::requires_model_selection()} and is usable the moment a key is
 * saved -- there is no model choice for an owner to make, and inventing one
 * would be a settings field that controls nothing.
 *
 * ## Placeholder protection is native here, and stronger for it
 *
 * The shared shield masks protected fragments to opaque tokens and checks them
 * on the way back. For the language models that check is the only real
 * guarantee, because an instruction to preserve a token is a request a model
 * may decline.
 *
 * DeepL supports the guarantee directly: `tag_handling=xml` plus `ignore_tags`
 * tells the engine that the contents of a given element are not translatable.
 * So the adapter rewrites each shield token into an ignored element on the way
 * out and back into a token on the way in. The engine is instructed rather
 * than asked, and the shield's verification still runs afterwards -- belt and
 * braces, because the cost of a broken `%s` is a fatal error on a live page.
 *
 * ## The account tier is read from the key, not configured
 *
 * DeepL documents that Free keys carry a `:fx` suffix, and Free and Pro live
 * on different hosts. Deriving the host from the key removes a settings field
 * that an owner could only ever get wrong, and whose wrong value produces an
 * authentication error that looks like a bad key.
 */
final class DeepLProvider extends AbstractProvider {
	/**
	 * Provider identifier.
	 */
	const ID = 'deepl';

	/**
	 * Host used by paid keys.
	 */
	const HOST_PRO = 'https://api.deepl.com';

	/**
	 * Host used by free keys.
	 */
	const HOST_FREE = 'https://api-free.deepl.com';

	/**
	 * Suffix DeepL documents on free keys.
	 */
	const FREE_KEY_SUFFIX = ':fx';

	/**
	 * Element name whose contents DeepL is told never to translate.
	 */
	const IGNORE_TAG = 'mcq';

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'DeepL', 'mclogiora' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * A dedicated translation service exposes no model menu, so there is
	 * nothing for an owner to choose and nothing to spend differently.
	 */
	public function requires_model_selection() {
		return false;
	}

	/**
	 * Returns an empty list, and never reaches the network to do it.
	 *
	 * Narrower than the contract's `array|WP_Error` on purpose: there is no
	 * model endpoint to call, so there is no request that could fail. A
	 * "Refresh models" action against this provider costs nothing and asks
	 * nobody anything.
	 *
	 * @return array<int,array{id:string,label:string,recommended:bool}>
	 */
	public function available_models() {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $source_language Source language code.
	 * @param string $target_language Target language code.
	 */
	public function supports_language_pair( $source_language, $target_language ) {
		return '' !== (string) $source_language
			&& '' !== (string) $target_language
			&& $source_language !== $target_language;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Uses the usage endpoint rather than a translation. It proves the key
	 * works and reports remaining quota without spending a character of it,
	 * which matters when every character is billed to the site owner.
	 */
	public function test_connection() {
		if ( ! $this->credentials->has( self::ID ) ) {
			return new \WP_Error(
				'mclogiora_suggestion_not_configured',
				__( 'Add a DeepL API key before testing the connection.', 'mclogiora' )
			);
		}

		$response = $this->transport->get_json( $this->host() . '/v2/usage', $this->headers() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Returns the account's character usage, when the provider reports it.
	 *
	 * Surfaced on the settings screen so an owner can see how much of their
	 * allowance suggestions have consumed before they run out mid-edit.
	 *
	 * @return array{used:int,limit:int}|\WP_Error
	 */
	public function usage() {
		if ( ! $this->credentials->has( self::ID ) ) {
			return new \WP_Error(
				'mclogiora_suggestion_not_configured',
				__( 'Add a DeepL API key before checking usage.', 'mclogiora' )
			);
		}

		$response = $this->transport->get_json( $this->host() . '/v2/usage', $this->headers() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'used'  => isset( $response['character_count'] ) ? (int) $response['character_count'] : 0,
			'limit' => isset( $response['character_limit'] ) ? (int) $response['character_limit'] : 0,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SuggestionRequest $request Text and languages to translate.
	 */
	public function suggest( SuggestionRequest $request ) {
		if ( ! $this->is_configured() ) {
			return $this->not_configured_error();
		}

		$target = $this->map_target_language( $request->target_language(), $request->target_locale() );

		if ( '' === $target ) {
			return new \WP_Error(
				'mclogiora_suggestion_unsupported_language',
				__( 'DeepL does not accept the target language for this translation.', 'mclogiora' )
			);
		}

		$shield = new PlaceholderShield();
		$masked = $shield->protect( $request->source_text() );

		if ( '' === $masked && '' !== $request->source_text() ) {
			return new \WP_Error(
				'mclogiora_suggestion_unprotectable',
				__( 'The source text could not be prepared safely for translation.', 'mclogiora' )
			);
		}

		$tagged = $this->tokens_to_tags( $masked, $shield );

		$response = $this->transport->post_form(
			$this->host() . '/v2/translate',
			$this->headers(),
			$this->build_request_body( $tagged, $request, $target, $shield->has_placeholders() )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = $this->read_text( $response );

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$text = $this->tags_to_tokens( $text, $shield );

		$problems = $shield->verify( $text );

		if ( array() !== $problems ) {
			return new \WP_Error( 'mclogiora_suggestion_placeholder_damage', implode( ' ', $problems ) );
		}

		return new SuggestionResult( $shield->restore( $text ), self::ID );
	}

	/**
	 * Builds the form-encoded request body.
	 *
	 * Encoded by hand rather than with `http_build_query()` because that
	 * function indexes array parameters as `text%5B0%5D`, and DeepL expects a
	 * repeated bare `text` parameter.
	 *
	 * @param string            $text Text with protected fragments already tagged.
	 * @param SuggestionRequest $request Request being sent.
	 * @param string            $target DeepL target language code.
	 * @param bool              $has_placeholders Whether protected fragments are present.
	 * @return string
	 */
	public function build_request_body( $text, SuggestionRequest $request, $target, $has_placeholders ) {
		$fields = array(
			'text'        => (string) $text,
			'target_lang' => (string) $target,
		);

		$source = $this->map_source_language( $request->source_language() );

		if ( '' !== $source ) {
			$fields['source_lang'] = $source;
		}

		if ( $has_placeholders || $request->is_html() ) {
			/*
			 * XML handling is used even for HTML text, because the protected
			 * fragments are wrapped in an element of mcLogiora's own making
			 * and `ignore_tags` is what makes DeepL leave their contents
			 * alone. HTML mode would strip the wrapper as unknown markup.
			 */
			$fields['tag_handling'] = 'xml';
			$fields['ignore_tags']  = self::IGNORE_TAG;
		}

		if ( '' !== $request->context() ) {
			/*
			 * DeepL translates the text and reads the context. It is never
			 * returned, which is exactly the behaviour a disambiguation hint
			 * should have.
			 */
			$fields['context'] = $request->context();
		}

		$pairs = array();

		foreach ( $fields as $key => $value ) {
			$pairs[] = rawurlencode( $key ) . '=' . rawurlencode( $value );
		}

		return implode( '&', $pairs );
	}

	/**
	 * Wraps each shield token in the element DeepL is told to ignore.
	 *
	 * @param string            $masked Masked text.
	 * @param PlaceholderShield $shield Shield holding the tokens.
	 * @return string
	 */
	private function tokens_to_tags( $masked, PlaceholderShield $shield ) {
		$tokens = $shield->tokens();

		if ( array() === $tokens ) {
			return $masked;
		}

		$replacements = array();

		foreach ( $tokens as $token ) {
			$replacements[ $token ] = '<' . self::IGNORE_TAG . '>' . $token . '</' . self::IGNORE_TAG . '>';
		}

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $masked );
	}

	/**
	 * Unwraps the ignored elements, leaving the bare tokens for verification.
	 *
	 * @param string            $text Translated text.
	 * @param PlaceholderShield $shield Shield holding the tokens.
	 * @return string
	 */
	private function tags_to_tokens( $text, PlaceholderShield $shield ) {
		if ( ! $shield->has_placeholders() ) {
			return $text;
		}

		return (string) preg_replace(
			'#<' . self::IGNORE_TAG . '\s*>(.*?)</' . self::IGNORE_TAG . '\s*>#s',
			'$1',
			$text
		);
	}

	/**
	 * Reads the answer out of a translate payload.
	 *
	 * @param array<string,mixed> $response Decoded response body.
	 * @return string|\WP_Error
	 */
	private function read_text( array $response ) {
		if ( ! isset( $response['translations'][0]['text'] ) || ! is_string( $response['translations'][0]['text'] ) ) {
			return $this->invalid_response_error();
		}

		$text = $response['translations'][0]['text'];

		if ( '' === trim( $text ) ) {
			return $this->invalid_response_error();
		}

		return $text;
	}

	/**
	 * Maps a source language onto a DeepL source code.
	 *
	 * @param string $code Language code.
	 * @return string Empty when the language should be auto-detected.
	 */
	private function map_source_language( $code ) {
		$code = strtoupper( substr( (string) $code, 0, 2 ) );

		return '' === $code ? '' : $code;
	}

	/**
	 * Maps a target language onto a DeepL target code.
	 *
	 * English and Portuguese must name a regional variant as a target; the
	 * bare codes are deprecated. The site's own locale answers which variant
	 * is meant, so the region is taken from there rather than guessed.
	 *
	 * @param string $code Language code.
	 * @param string $locale Locale, when known.
	 * @return string Empty when no valid target can be derived.
	 */
	private function map_target_language( $code, $locale ) {
		$code   = strtoupper( substr( (string) $code, 0, 2 ) );
		$region = '';

		if ( '' !== (string) $locale && preg_match( '/[_-]([A-Za-z]{2})$/', (string) $locale, $matches ) ) {
			$region = strtoupper( $matches[1] );
		}

		if ( '' === $code ) {
			return '';
		}

		if ( 'EN' === $code ) {
			return 'GB' === $region ? 'EN-GB' : 'EN-US';
		}

		if ( 'PT' === $code ) {
			return 'BR' === $region ? 'PT-BR' : 'PT-PT';
		}

		return $code;
	}

	/**
	 * Returns the API host implied by the stored key.
	 *
	 * @return string
	 */
	private function host() {
		$key = $this->api_key();

		return $this->is_free_key( $key ) ? self::HOST_FREE : self::HOST_PRO;
	}

	/**
	 * Returns whether a key is a free-tier key.
	 *
	 * @param string $key Stored key.
	 * @return bool
	 */
	private function is_free_key( $key ) {
		$suffix = self::FREE_KEY_SUFFIX;

		return substr( (string) $key, -strlen( $suffix ) ) === $suffix;
	}

	/**
	 * Returns the request headers.
	 *
	 * @return array<string,string>
	 */
	private function headers() {
		return array( 'Authorization' => 'DeepL-Auth-Key ' . $this->api_key() );
	}
}
