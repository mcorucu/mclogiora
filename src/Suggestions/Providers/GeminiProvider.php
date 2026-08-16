<?php
/**
 * Google Gemini translation suggestion provider.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions\Providers;

use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\LlmInstructions;
use McLogiora\Suggestions\PlaceholderShield;
use McLogiora\Suggestions\SuggestionRequest;
use McLogiora\Suggestions\SuggestionResult;
use McLogiora\Suggestions\TransportInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Suggests translations through the Gemini `generateContent` endpoint.
 *
 * ## A superseded endpoint, contained on purpose
 *
 * Google's reference now carries the banner "The Interactions API is now
 * generally available. We recommend using this API for access to all the
 * latest features and models", and describes Interactions as optimised for
 * "agentic workflows, server-side state management" while `generateContent`
 * is "best for non-interactive tasks". `generateContent` is not marked
 * deprecated.
 *
 * mcLogiora uses `generateContent` because a translation suggestion is one
 * stateless request for one string, and server-side state at a third party is
 * the specific thing this feature must not create. But Google's direction is
 * clear, so the endpoint is treated as an implementation detail with a
 * limited life.
 *
 * **The migration seam is a constraint on future work, not a note.** Every
 * shape peculiar to this endpoint -- `contents[]`, `parts[]`, `candidates`,
 * `finishReason`, `promptFeedback` -- appears in this file and nowhere else in
 * the plugin. Moving to Interactions must therefore be a change to one class,
 * with the provider contract, the suggestion service, the placeholder shield
 * and every editor surface untouched. A future phase that finds itself editing
 * a second file to migrate Gemini has found a leak, and should fix the leak
 * rather than widen it.
 *
 * The key travels in the `x-goog-api-key` header rather than the `?key=` query
 * parameter Google also documents. Both authenticate; only one keeps the
 * credential out of access logs, proxy logs and `Referer` headers.
 */
final class GeminiProvider extends AbstractProvider {
	/**
	 * Provider identifier.
	 */
	const ID = 'gemini';

	/**
	 * API base.
	 */
	const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

	/**
	 * Instruction builder.
	 *
	 * @var LlmInstructions
	 */
	private $instructions;

	/**
	 * Builds the provider.
	 *
	 * @param TransportInterface $transport HTTP transport.
	 * @param CredentialStore    $credentials Credential storage.
	 * @param LlmInstructions    $instructions Instruction builder.
	 */
	public function __construct( TransportInterface $transport, CredentialStore $credentials, LlmInstructions $instructions ) {
		parent::__construct( $transport, $credentials );

		$this->instructions = $instructions;
	}

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
		return __( 'Google Gemini', 'mclogiora' );
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
	 * Builds the request body sent for a suggestion.
	 *
	 * @param SuggestionRequest $request Request being sent.
	 * @param string            $masked_text Text with placeholders already protected.
	 * @param bool              $has_placeholders Whether protected tokens are present.
	 * @return array<string,mixed>
	 */
	public function build_request_body( SuggestionRequest $request, $masked_text, $has_placeholders ) {
		return array(
			'systemInstruction' => array(
				'parts' => array(
					array( 'text' => $this->instructions->build( $request, (bool) $has_placeholders ) ),
				),
			),
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array( 'text' => (string) $masked_text ),
					),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function test_connection() {
		if ( ! $this->credentials->has( self::ID ) ) {
			return new \WP_Error(
				'mclogiora_suggestion_not_configured',
				__( 'Add a Google Gemini API key before testing the connection.', 'mclogiora' )
			);
		}

		$response = $this->transport->get_json( self::API_BASE . '/models', $this->headers() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function available_models() {
		if ( ! $this->credentials->has( self::ID ) ) {
			return new \WP_Error(
				'mclogiora_suggestion_not_configured',
				__( 'Add a Google Gemini API key before refreshing the model list.', 'mclogiora' )
			);
		}

		$response = $this->transport->get_json( self::API_BASE . '/models', $this->headers() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['models'] ) || ! is_array( $response['models'] ) ) {
			return new \WP_Error(
				'mclogiora_suggestion_invalid_response',
				__( 'Google Gemini returned a model list mcLogiora could not read.', 'mclogiora' )
			);
		}

		$models = array();

		foreach ( $response['models'] as $entry ) {
			if ( ! isset( $entry['name'] ) || ! is_string( $entry['name'] ) ) {
				continue;
			}

			/*
			 * Only models that advertise the method this provider calls are
			 * offered. Listing one that cannot answer would let an owner pick
			 * a model that fails on first use.
			 */
			$methods = isset( $entry['supportedGenerationMethods'] ) && is_array( $entry['supportedGenerationMethods'] )
				? $entry['supportedGenerationMethods']
				: array();

			if ( array() !== $methods && ! in_array( 'generateContent', $methods, true ) ) {
				continue;
			}

			$id = preg_replace( '#^models/#', '', $entry['name'] );

			$label = isset( $entry['displayName'] ) && is_string( $entry['displayName'] )
				? $entry['displayName']
				: $id;

			$models[] = array(
				'id'          => $id,
				'label'       => $label,
				'recommended' => false !== strpos( $id, 'flash' ),
			);
		}

		return $models;
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

		$model  = $this->selected_model();
		$shield = new PlaceholderShield();
		$masked = $shield->protect( $request->source_text() );

		if ( '' === $masked && '' !== $request->source_text() ) {
			return new \WP_Error(
				'mclogiora_suggestion_unprotectable',
				__( 'The source text could not be prepared safely for translation.', 'mclogiora' )
			);
		}

		$response = $this->transport->post_json(
			$this->generate_endpoint( $model ),
			$this->headers(),
			$this->build_request_body( $request, $masked, $shield->has_placeholders() )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = $this->read_text( $response );

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$problems = $shield->verify( $text );

		if ( array() !== $problems ) {
			return new \WP_Error( 'mclogiora_suggestion_placeholder_damage', implode( ' ', $problems ) );
		}

		return new SuggestionResult( $shield->restore( $text ), self::ID, $model );
	}

	/**
	 * Reads the answer out of a `generateContent` payload.
	 *
	 * A prompt blocked by a safety filter and a candidate stopped for any
	 * reason other than a natural finish both arrive as HTTP 200 with no
	 * usable text. Each becomes an error so nothing empty reaches a reviewer.
	 *
	 * @param array<string,mixed> $response Decoded response body.
	 * @return string|\WP_Error
	 */
	private function read_text( array $response ) {
		if ( isset( $response['promptFeedback']['blockReason'] ) && is_string( $response['promptFeedback']['blockReason'] ) ) {
			return $this->declined_error( $response['promptFeedback']['blockReason'] );
		}

		if ( ! isset( $response['candidates'][0] ) || ! is_array( $response['candidates'][0] ) ) {
			return $this->invalid_response_error();
		}

		$candidate = $response['candidates'][0];

		if ( isset( $candidate['finishReason'] ) && is_string( $candidate['finishReason'] ) ) {
			$finish = $candidate['finishReason'];

			if ( '' !== $finish && 'STOP' !== $finish ) {
				/*
				 * A candidate stopped by a safety or policy filter is a
				 * refusal; one stopped by the output ceiling is a truncation.
				 * They arrive in the same field and mean different things to
				 * whoever decides what to do next, so they are separated here
				 * rather than flattened into one message.
				 */
				$declined = array( 'SAFETY', 'RECITATION', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'IMAGE_SAFETY' );

				if ( in_array( $finish, $declined, true ) ) {
					return $this->declined_error( $finish );
				}

				return $this->incomplete_error( $finish );
			}
		}

		if ( ! isset( $candidate['content']['parts'] ) || ! is_array( $candidate['content']['parts'] ) ) {
			return $this->invalid_response_error();
		}

		$text = '';

		foreach ( $candidate['content']['parts'] as $part ) {
			if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
				$text .= $part['text'];
			}
		}

		if ( '' === trim( $text ) ) {
			return $this->invalid_response_error();
		}

		return $text;
	}

	/**
	 * Returns the generation endpoint for a model.
	 *
	 * @param string $model Model identifier.
	 * @return string
	 */
	private function generate_endpoint( $model ) {
		return self::API_BASE . '/models/' . rawurlencode( (string) $model ) . ':generateContent';
	}

	/**
	 * Returns the request headers.
	 *
	 * @return array<string,string>
	 */
	private function headers() {
		return array( 'x-goog-api-key' => $this->api_key() );
	}
}
