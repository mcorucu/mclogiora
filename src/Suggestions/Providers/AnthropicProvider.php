<?php
/**
 * Anthropic translation suggestion provider.
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
 * Suggests translations through the Anthropic Messages API.
 *
 * Talks to the documented HTTP endpoint through the WordPress HTTP API rather
 * than through Anthropic's PHP SDK. That is a deliberate refusal of a
 * convenience: mcLogiora is provider-agnostic by design, and bundling one
 * vendor's SDK would privilege that vendor in a plugin whose whole premise is
 * that no provider is special. It would also add a Composer runtime dependency
 * to a plugin distributed through WordPress.org, where installs are a file
 * copy rather than a build.
 *
 * The Messages API reports a model's decision to decline through
 * `stop_reason: "refusal"` on an otherwise successful HTTP 200. Reading
 * `content[0]` before checking that field is how a refusal becomes an empty
 * suggestion, so the check happens first.
 */
final class AnthropicProvider extends AbstractProvider {
	/**
	 * Provider identifier.
	 */
	const ID = 'anthropic';

	/**
	 * Messages endpoint.
	 */
	const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/**
	 * Models endpoint, used only by an explicit refresh.
	 */
	const MODELS_ENDPOINT = 'https://api.anthropic.com/v1/models';

	/**
	 * Pinned API version.
	 *
	 * Anthropic versions its API by header. Pinning it means a future
	 * server-side change cannot alter the response shape underneath a site
	 * that has not been updated.
	 */
	const API_VERSION = '2023-06-01';

	/**
	 * Output ceiling for a suggestion.
	 *
	 * A translation is about as long as its source. This is a cost guard, not
	 * a quality setting: it bounds what a runaway generation can bill.
	 */
	const MAX_TOKENS = 4096;

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
		return __( 'Anthropic', 'mclogiora' );
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
	 * @param string            $model Model identifier.
	 * @return array<string,mixed>
	 */
	public function build_request_body( SuggestionRequest $request, $masked_text, $has_placeholders, $model ) {
		return array(
			'model'      => (string) $model,
			'max_tokens' => self::MAX_TOKENS,
			'system'     => $this->instructions->build( $request, (bool) $has_placeholders ),
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => (string) $masked_text,
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
				__( 'Add an Anthropic API key before testing the connection.', 'mclogiora' )
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
				__( 'Add an Anthropic API key before refreshing the model list.', 'mclogiora' )
			);
		}

		$response = $this->transport->get_json( self::MODELS_ENDPOINT, $this->headers() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return new \WP_Error(
				'mclogiora_suggestion_bad_response',
				__( 'Anthropic returned a model list mcLogiora could not read.', 'mclogiora' )
			);
		}

		$models = array();

		foreach ( $response['data'] as $entry ) {
			if ( ! isset( $entry['id'] ) || ! is_string( $entry['id'] ) ) {
				continue;
			}

			$label = isset( $entry['display_name'] ) && is_string( $entry['display_name'] )
				? $entry['display_name']
				: $entry['id'];

			$models[] = array(
				'id'          => $entry['id'],
				'label'       => $label,
				'recommended' => false !== strpos( $entry['id'], 'haiku' ),
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
			self::ENDPOINT,
			$this->headers(),
			$this->build_request_body( $request, $masked, $shield->has_placeholders(), $model )
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
	 * Reads the answer out of a Messages payload.
	 *
	 * @param array<string,mixed> $response Decoded response body.
	 * @return string|\WP_Error
	 */
	private function read_text( array $response ) {
		$stop = isset( $response['stop_reason'] ) ? (string) $response['stop_reason'] : '';

		if ( 'refusal' === $stop ) {
			$detail = '';

			if ( isset( $response['stop_details']['category'] ) && is_string( $response['stop_details']['category'] ) ) {
				$detail = $response['stop_details']['category'];
			}

			return $this->declined_error( $detail );
		}

		/*
		 * A generation cut short by the output ceiling is a partial sentence.
		 * Offering it as a suggestion invites a reviewer to apply a truncated
		 * title, so it is refused with a reason they can act on.
		 */
		if ( 'max_tokens' === $stop ) {
			return $this->declined_error( __( 'the translation was longer than the allowed output', 'mclogiora' ) );
		}

		if ( ! isset( $response['content'] ) || ! is_array( $response['content'] ) ) {
			return $this->declined_error();
		}

		$text = '';

		foreach ( $response['content'] as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] && is_string( $block['text'] ) ) {
				$text .= $block['text'];
			}
		}

		if ( '' === trim( $text ) ) {
			return $this->declined_error();
		}

		return $text;
	}

	/**
	 * Returns the request headers.
	 *
	 * @return array<string,string>
	 */
	private function headers() {
		return array(
			'x-api-key'         => $this->api_key(),
			'anthropic-version' => self::API_VERSION,
		);
	}
}
