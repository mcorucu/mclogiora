<?php
/**
 * Shared behaviour for translation suggestion providers.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions\Providers;

use McLogiora\Contracts\TranslationProviderInterface;
use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\TransportInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the parts of a provider that are genuinely the same everywhere.
 *
 * Deliberately thin. Credential lookup and model-selection bookkeeping are
 * identical across providers and duplicating them four times would be four
 * places to get a security rule wrong. Everything that actually differs --
 * request shaping, response reading, and how text is protected from
 * translation -- stays in the concrete provider, because pretending those are
 * the same is how an abstraction starts lying.
 */
abstract class AbstractProvider implements TranslationProviderInterface {
	/**
	 * Option name prefix for the chosen model.
	 */
	const MODEL_OPTION_PREFIX = 'mclogiora_suggestion_model_';

	/**
	 * HTTP transport.
	 *
	 * @var TransportInterface
	 */
	protected $transport;

	/**
	 * Credential storage.
	 *
	 * @var CredentialStore
	 */
	protected $credentials;

	/**
	 * Builds a provider.
	 *
	 * @param TransportInterface $transport HTTP transport.
	 * @param CredentialStore    $credentials Credential storage.
	 */
	public function __construct( TransportInterface $transport, CredentialStore $credentials ) {
		$this->transport   = $transport;
		$this->credentials = $credentials;
	}

	/**
	 * {@inheritDoc}
	 */
	public function requires_model_selection() {
		return true;
	}

	/**
	 * Returns the model the owner explicitly chose.
	 *
	 * Never falls back to a default. A provider with no chosen model is not
	 * usable, which is the intended outcome: choosing a model is choosing a
	 * price and a capability, and neither is mcLogiora's to choose.
	 *
	 * @return string Empty when nothing has been chosen.
	 */
	public function selected_model() {
		if ( ! $this->requires_model_selection() ) {
			return '';
		}

		return (string) get_option( $this->model_option_name(), '' );
	}

	/**
	 * Records the owner's model choice.
	 *
	 * @param string $model Model identifier.
	 * @return bool
	 */
	public function set_selected_model( $model ) {
		$model = trim( (string) $model );

		if ( '' === $model ) {
			return false;
		}

		return update_option( $this->model_option_name(), $model, false );
	}

	/**
	 * Forgets the owner's model choice.
	 *
	 * @return bool
	 */
	public function clear_selected_model() {
		return delete_option( $this->model_option_name() );
	}

	/**
	 * Drops a stored model that the provider no longer offers.
	 *
	 * Called after an explicit refresh. A model can be retired at any time,
	 * and the tempting response -- quietly move the site to whatever looks
	 * closest -- would change what the owner is billed and what quality they
	 * get without anyone deciding to. So the selection is cleared instead and
	 * the provider reports itself incomplete until a human chooses again.
	 *
	 * @param array<int,array{id:string,label:string,recommended:bool}> $models Freshly fetched models.
	 * @return bool Whether a stored selection was invalidated.
	 */
	public function reconcile_selected_model( array $models ) {
		$selected = $this->selected_model();

		if ( '' === $selected ) {
			return false;
		}

		foreach ( $models as $model ) {
			if ( isset( $model['id'] ) && (string) $model['id'] === $selected ) {
				return false;
			}
		}

		$this->clear_selected_model();

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		if ( ! $this->credentials->has( $this->get_id() ) ) {
			return false;
		}

		if ( ! $this->requires_model_selection() ) {
			return true;
		}

		return '' !== $this->selected_model();
	}

	/**
	 * Returns the stored credential.
	 *
	 * @return string
	 */
	protected function api_key() {
		return $this->credentials->get( $this->get_id() );
	}

	/**
	 * Returns the error used when a provider is asked to work unconfigured.
	 *
	 * @return \WP_Error
	 */
	protected function not_configured_error() {
		return new \WP_Error(
			'mclogiora_suggestion_not_configured',
			sprintf(
				/* translators: %s: provider label. */
				__( '%s needs an API key and a selected model before it can suggest translations.', 'mclogiora' ),
				$this->get_label()
			)
		);
	}

	/**
	 * Returns the error used when a provider declines to answer.
	 *
	 * A refusal, a truncation or an empty body all arrive as a successful HTTP
	 * response. None of them is a suggestion, and treating them as one would
	 * put empty or partial text in front of a reviewer as though a model had
	 * produced it deliberately.
	 *
	 * @param string $detail Provider-supplied reason, already safe to display.
	 * @return \WP_Error
	 */
	protected function declined_error( $detail = '' ) {
		$message = sprintf(
			/* translators: %s: provider label. */
			__( '%s did not return a usable translation.', 'mclogiora' ),
			$this->get_label()
		);

		if ( '' !== $detail ) {
			$message .= ' ' . sprintf(
				/* translators: %s: provider-supplied reason. */
				__( 'Reason: %s', 'mclogiora' ),
				$detail
			);
		}

		return new \WP_Error( 'mclogiora_suggestion_declined', $message );
	}

	/**
	 * Returns the option name holding the chosen model.
	 *
	 * @return string
	 */
	private function model_option_name() {
		return self::MODEL_OPTION_PREFIX . sanitize_key( $this->get_id() );
	}
}
