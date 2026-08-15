<?php
/**
 * OpenAI translation suggestion provider.
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
 * Suggests translations through the OpenAI Responses API.
 *
 * ## Why Responses, and why `store` is not optional
 *
 * OpenAI directs new integrations to the Responses API, and it can meet
 * mcLogiora's data-minimisation policy -- but only deliberately. Its `store`
 * parameter defaults to `true`, meaning the generated response is retained by
 * OpenAI for later retrieval. Chat Completions defaults the same parameter to
 * `false`.
 *
 * That difference is the whole reason {@see self::REQUIRED_STORE} exists as a
 * named constant and is asserted by a test rather than being one boolean among
 * many in a request array. Every other safeguard in this plugin fails closed;
 * this one fails open, so it is pinned. A refactor that drops the key would
 * begin retaining site content at a third party silently, with no error, no
 * warning and no visible behaviour change.
 *
 * Nothing stateful is used. No `previous_response_id`, no `conversation`, no
 * background mode, no file inputs and no tools: each suggestion is one
 * self-contained request whose only trace is the owner's usage record.
 */
final class OpenAiProvider extends AbstractProvider {
	/**
	 * Provider identifier.
	 */
	const ID = 'openai';

	/**
	 * Responses endpoint.
	 */
	const ENDPOINT = 'https://api.openai.com/v1/responses';

	/**
	 * Models endpoint, used only by an explicit refresh.
	 */
	const MODELS_ENDPOINT = 'https://api.openai.com/v1/models';

	/**
	 * The only acceptable value for the `store` parameter.
	 *
	 * Retention is off. This is a policy constant, not a default.
	 */
	const REQUIRED_STORE = false;

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
		return __( 'OpenAI', 'mclogiora' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $source_language Source language code.
	 * @param string $target_language Target language code.
	 */
	public function supports_language_pair( $source_language, $target_language ) {
		/*
		 * A general-purpose model publishes no language matrix, so claiming to
		 * know which pairs work would be an invention. Any pair of non-empty,
		 * different codes is allowed and the reviewer judges the result.
		 */
		return '' !== (string) $source_language
			&& '' !== (string) $target_language
			&& $source_language !== $target_language;
	}

	/**
	 * Builds the request body sent for a suggestion.
	 *
	 * Separated from {@see self::suggest()} so the retention policy can be
	 * asserted directly by a test without a transport, a credential or a
	 * network call standing in the way.
	 *
	 * @param SuggestionRequest $request Request being sent.
	 * @param string            $masked_text Text with placeholders already protected.
	 * @param bool              $has_placeholders Whether protected tokens are present.
	 * @param string            $model Model identifier.
	 * @return array<string,mixed>
	 */
	public function build_request_body( SuggestionRequest $request, $masked_text, $has_placeholders, $model ) {
		return array(
			'model'        => (string) $model,
			'instructions' => $this->instructions->build( $request, (bool) $has_placeholders ),
			'input'        => (string) $masked_text,

			/*
			 * Never remove, never make conditional, never read from settings.
			 * See the class docblock.
			 */
			'store'        => self::REQUIRED_STORE,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function test_connection() {
		if ( ! $this->credentials->has( self::ID ) ) {
			return new \WP_Error(
				'mclogiora_suggestion_not_configured',
				__( 'Add an OpenAI API key before testing the connection.', 'mclogiora' )
			);
		}

		$response = $this->transport->get_json( self::MODELS_ENDPOINT, $this->headers() );

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
				__( 'Add an OpenAI API key before refreshing the model list.', 'mclogiora' )
			);
		}

		$response = $this->transport->get_json( self::MODELS_ENDPOINT, $this->headers() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return new \WP_Error(
				'mclogiora_suggestion_bad_response',
				__( 'OpenAI returned a model list mcLogiora could not read.', 'mclogiora' )
			);
		}

		$models = array();

		foreach ( $response['data'] as $entry ) {
			if ( ! isset( $entry['id'] ) || ! is_string( $entry['id'] ) ) {
				continue;
			}

			$id = $entry['id'];

			/*
			 * The account-wide list includes embeddings, audio, image and
			 * moderation models that cannot answer a Responses call at all.
			 * Offering them would let an owner pick a model that fails on
			 * first use, so only text-capable families are surfaced.
			 */
			if ( ! $this->is_text_model( $id ) ) {
				continue;
			}

			$models[] = array(
				'id'          => $id,
				'label'       => $id,
				'recommended' => $this->is_recommended( $id ),
			);
		}

		usort(
			$models,
			static function ( $a, $b ) {
				return strcmp( $a['id'], $b['id'] );
			}
		);

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
			self::ENDPOINT,
			$this->headers(),
			$this->build_request_body( $request, $masked, $shield->has_placeholders(), $model )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = $this->read_output_text( $response );

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$problems = $shield->verify( $text );

		if ( array() !== $problems ) {
			return new \WP_Error(
				'mclogiora_suggestion_placeholder_damage',
				implode( ' ', $problems )
			);
		}

		return new SuggestionResult( $shield->restore( $text ), self::ID, $model );
	}

	/**
	 * Reads the answer out of a Responses payload.
	 *
	 * A refusal, an incomplete generation and an empty output all arrive as
	 * HTTP 200. None of them is a translation, so each becomes an error rather
	 * than an empty suggestion a reviewer might apply without noticing.
	 *
	 * @param array<string,mixed> $response Decoded response body.
	 * @return string|\WP_Error
	 */
	private function read_output_text( array $response ) {
		if ( isset( $response['status'] ) && 'completed' !== $response['status'] ) {
			$reason = '';

			if ( isset( $response['incomplete_details']['reason'] ) && is_string( $response['incomplete_details']['reason'] ) ) {
				$reason = $response['incomplete_details']['reason'];
			}

			return $this->declined_error( $reason );
		}

		if ( ! isset( $response['output'] ) || ! is_array( $response['output'] ) ) {
			return $this->declined_error();
		}

		$text = '';

		foreach ( $response['output'] as $item ) {
			if ( ! isset( $item['content'] ) || ! is_array( $item['content'] ) ) {
				continue;
			}

			foreach ( $item['content'] as $block ) {
				if ( ! isset( $block['type'] ) ) {
					continue;
				}

				if ( 'refusal' === $block['type'] ) {
					$detail = isset( $block['refusal'] ) && is_string( $block['refusal'] ) ? $block['refusal'] : '';

					return $this->declined_error( $detail );
				}

				if ( 'output_text' === $block['type'] && isset( $block['text'] ) && is_string( $block['text'] ) ) {
					$text .= $block['text'];
				}
			}
		}

		if ( '' === trim( $text ) ) {
			return $this->declined_error();
		}

		return $text;
	}

	/**
	 * Returns whether a model identifier looks like a text generation model.
	 *
	 * @param string $id Model identifier.
	 * @return bool
	 */
	private function is_text_model( $id ) {
		foreach ( array( 'embedding', 'whisper', 'tts', 'dall-e', 'moderation', 'audio', 'realtime', 'transcribe', 'image' ) as $excluded ) {
			if ( false !== strpos( $id, $excluded ) ) {
				return false;
			}
		}

		return 0 === strpos( $id, 'gpt-' ) || 0 === strpos( $id, 'o1' ) || 0 === strpos( $id, 'o3' ) || 0 === strpos( $id, 'o4' );
	}

	/**
	 * Returns whether a model is worth highlighting to the owner.
	 *
	 * A label only. Nothing is selected on the owner's behalf.
	 *
	 * @param string $id Model identifier.
	 * @return bool
	 */
	private function is_recommended( $id ) {
		return false !== strpos( $id, 'mini' ) && 0 === strpos( $id, 'gpt-' );
	}

	/**
	 * Returns the request headers.
	 *
	 * @return array<string,string>
	 */
	private function headers() {
		return array( 'Authorization' => 'Bearer ' . $this->api_key() );
	}
}
