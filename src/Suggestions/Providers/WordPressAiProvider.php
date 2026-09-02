<?php
/**
 * WordPress AI Client translation suggestion provider.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions\Providers;

use McLogiora\Contracts\TranslationProviderInterface;
use McLogiora\Suggestions\LlmInstructions;
use McLogiora\Suggestions\PlaceholderShield;
use McLogiora\Suggestions\SuggestionRequest;
use McLogiora\Suggestions\SuggestionResult;
use McLogiora\Suggestions\SuggestionError;

defined( 'ABSPATH' ) || exit;

/**
 * Routes language-model suggestions through WordPress Core's AI Client.
 *
 * WordPress owns provider discovery, model selection and credential storage.
 * This adapter therefore contains no vendor endpoint, SDK, API key or model
 * catalogue. It remains inert on unsupported WordPress versions, although
 * the plugin's public minimum is WordPress 7.0 where the client is available.
 */
final class WordPressAiProvider implements TranslationProviderInterface {
	/**
	 * Provider identifier used in mcLogiora settings and previews.
	 */
	const ID = 'wordpress-ai';

	/**
	 * No AI provider is registered with WordPress.
	 */
	const AVAILABILITY_NO_PROVIDER = 'no_provider_available';

	/**
	 * At least one provider is registered but no usable connection is ready.
	 */
	const AVAILABILITY_UNCONFIGURED = 'unconfigured_connector';

	/**
	 * AI support is disabled for the current request or site.
	 */
	const AVAILABILITY_DISABLED = 'ai_disabled';

	/**
	 * Instruction builder.
	 *
	 * @var LlmInstructions
	 */
	private $instructions;

	/**
	 * Optional Core-client factory, used to isolate the WordPress boundary in tests.
	 *
	 * @var callable|null
	 */
	private $client_factory;

	/**
	 * Builds the provider.
	 *
	 * @param LlmInstructions $instructions Instruction builder.
	 * @param callable|null   $client_factory Optional Core-client factory.
	 */
	public function __construct( LlmInstructions $instructions, $client_factory = null ) {
		$this->instructions   = $instructions;
		$this->client_factory = $client_factory;
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
		return __( 'WordPress AI Client', 'mclogiora' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		$builder = $this->client();

		if ( is_wp_error( $builder ) || ! is_object( $builder ) ) {
			return false;
		}

		try {
			return (bool) $builder->is_supported_for_text_generation();
		} catch ( \Throwable $exception ) {
			unset( $exception );

			return false;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function manages_credentials() {
		return false;
	}

	/**
	 * Returns the local reason the Core connection is unavailable.
	 *
	 * This inspects only the connector registry. It never reads credential
	 * values and never contacts an AI provider.
	 *
	 * @return string One of the availability constants.
	 */
	public function availability_state() {
		if ( function_exists( 'wp_supports_ai' ) && ! wp_supports_ai() ) {
			return self::AVAILABILITY_DISABLED;
		}

		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return self::AVAILABILITY_NO_PROVIDER;
		}

		$connectors = wp_get_connectors();

		foreach ( $connectors as $connector ) {
			if ( ! is_array( $connector ) || ! isset( $connector['type'] ) || 'ai_provider' !== $connector['type'] ) {
				continue;
			}

			if ( isset( $connector['plugin']['is_active'] ) && is_callable( $connector['plugin']['is_active'] ) ) {
				try {
					if ( ! call_user_func( $connector['plugin']['is_active'] ) ) {
						continue;
					}
				} catch ( \Throwable $exception ) {
					unset( $exception );

					continue;
				}
			}

			return self::AVAILABILITY_UNCONFIGURED;
		}

		return self::AVAILABILITY_NO_PROVIDER;
	}

	/**
	 * {@inheritDoc}
	 */
	public function requires_model_selection() {
		return false;
	}

	/**
	 * WordPress Core owns the model catalogue.
	 *
	 * @return array<int,array{id:string,label:string,recommended:bool}>
	 */
	public function available_models() {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function selected_model() {
		return '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $model Ignored because WordPress owns model selection.
	 */
	public function set_selected_model( $model ) {
		unset( $model );

		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function clear_selected_model() {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int,array{id:string,label:string,recommended:bool}> $models Ignored because WordPress owns model selection.
	 */
	public function reconcile_selected_model( array $models ) {
		unset( $models );

		return false;
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
	 * Checks whether the Core AI Client can currently serve text generation.
	 *
	 * No prompt or site content is sent by this check.
	 *
	 * @return true|\WP_Error
	 */
	public function test_connection() {
		if ( $this->is_configured() ) {
			return true;
		}

		return new \WP_Error(
			SuggestionError::NOT_CONFIGURED,
			__( 'Connect an AI provider in Settings → Connectors before testing WordPress AI Client.', 'mclogiora' )
		);
	}

	/**
	 * Sends one translation request through WordPress Core.
	 *
	 * @param SuggestionRequest $request Text and languages to translate.
	 * @return SuggestionResult|\WP_Error
	 */
	public function suggest( SuggestionRequest $request ) {
		$builder = $this->client();

		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		if ( ! is_object( $builder ) ) {
			return SuggestionError::not_configured( $this->get_label() );
		}

		try {
			if ( ! $builder->is_supported_for_text_generation() ) {
				return SuggestionError::not_configured( $this->get_label() );
			}

			$shield = new PlaceholderShield();
			$masked = $shield->protect( $request->source_text() );

			if ( '' === $masked && '' !== $request->source_text() ) {
				return new \WP_Error(
					'mclogiora_suggestion_unprotectable',
					__( 'The source text could not be prepared safely for translation.', 'mclogiora' )
				);
			}

			$text = $builder
				->using_system_instruction( $this->instructions->build( $request, $shield->has_placeholders() ) )
				->with_text( $masked )
				->generate_text();
		} catch ( \Throwable $exception ) {
			unset( $exception );

			return SuggestionError::invalid_response( $this->get_label() );
		}

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$text = (string) $text;

		if ( '' === trim( $text ) ) {
			return SuggestionError::invalid_response( $this->get_label() );
		}

		$problems = $shield->verify( $text );

		if ( array() !== $problems ) {
			return new \WP_Error(
				'mclogiora_suggestion_placeholder_damage',
				implode( ' ', $problems )
			);
		}

		return new SuggestionResult( $shield->restore( $text ), self::ID );
	}

	/**
	 * Returns the Core prompt builder without allowing boundary failures to escape.
	 *
	 * @return object|\WP_Error
	 */
	private function client() {
		try {
			if ( null !== $this->client_factory ) {
				return call_user_func( $this->client_factory );
			}

			if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
				return new \WP_Error(
					SuggestionError::NOT_CONFIGURED,
					__( 'No compatible WordPress AI provider is available or connected.', 'mclogiora' )
				);
			}

			if ( function_exists( 'wp_supports_ai' ) && ! wp_supports_ai() ) {
				return new \WP_Error(
					SuggestionError::NOT_CONFIGURED,
					__( 'No compatible WordPress AI provider is available or connected.', 'mclogiora' )
				);
			}

			return wp_ai_client_prompt();
		} catch ( \Throwable $exception ) {
			unset( $exception );

			return new \WP_Error(
				SuggestionError::NOT_CONFIGURED,
				__( 'No compatible WordPress AI provider is available or connected.', 'mclogiora' )
			);
		}
	}
}
