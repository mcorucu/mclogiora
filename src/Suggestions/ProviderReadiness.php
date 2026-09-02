<?php
/**
 * Provider configuration readiness.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

use McLogiora\Contracts\TranslationProviderInterface;
use McLogiora\Suggestions\Providers\WordPressAiProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "can this provider be used yet, and if not, what is missing?"
 *
 * One place computes this so no screen has to. A settings page that worked out
 * readiness from raw options would drift from what the service actually
 * enforces, and the drift would show up as a provider the UI calls Ready that
 * refuses to generate.
 *
 * ## Readiness is local configuration, not a live provider call
 *
 * There is deliberately no `Connected` state. A successful connection test
 * proves a credential worked at one moment against one endpoint; it says
 * nothing about the next request, and storing it would produce a settings
 * screen that cheerfully reports Connected while every suggestion fails. What
 * is reported here is only what the site itself can know without asking a
 * provider: whether a managed credential exists, or whether a
 * WordPress-managed connection can currently serve the request.
 */
final class ProviderReadiness {
	/**
	 * No credential at all.
	 */
	const NOT_CONFIGURED = 'not_configured';

	/**
	 * A credential exists but the provider still needs a model.
	 */
	const MODEL_REQUIRED = 'model_required';

	/**
	 * Everything the site can check is in place.
	 */
	const READY = 'ready';

	/**
	 * No WordPress AI provider is registered.
	 */
	const NO_PROVIDER_AVAILABLE = 'no_provider_available';

	/**
	 * A WordPress AI provider exists but is not configured.
	 */
	const UNCONFIGURED_CONNECTOR = 'unconfigured_connector';

	/**
	 * WordPress AI support is disabled.
	 */
	const AI_DISABLED = 'ai_disabled';

	/**
	 * Credential storage.
	 *
	 * @var CredentialStore
	 */
	private $credentials;

	/**
	 * Builds the resolver.
	 *
	 * @param CredentialStore $credentials Credential storage.
	 */
	public function __construct( CredentialStore $credentials ) {
		$this->credentials = $credentials;
	}

	/**
	 * Returns the readiness state of a provider.
	 *
	 * @param TranslationProviderInterface $provider Provider to inspect.
	 * @return string One of the state constants.
	 */
	public function state( TranslationProviderInterface $provider ) {
		if ( $provider->is_configured() ) {
			return self::READY;
		}

		if ( $provider instanceof WordPressAiProvider ) {
			return $provider->availability_state();
		}

		if (
			$provider->manages_credentials()
			&& $this->credentials->has( $provider->get_id() )
			&& $provider->requires_model_selection()
		) {
			/*
			 * A credential without a chosen model. The provider itself is the
			 * authority on this -- asking it rather than re-deriving the rule
			 * here is what keeps the screen and the service agreeing.
			 */
			return self::MODEL_REQUIRED;
		}

		return self::NOT_CONFIGURED;
	}

	/**
	 * Returns a human-readable label for a provider's state.
	 *
	 * @param TranslationProviderInterface $provider Provider to describe.
	 * @return string
	 */
	public function label( TranslationProviderInterface $provider ) {
		switch ( $this->state( $provider ) ) {
			case self::READY:
				return __( 'Ready', 'mclogiora' );

			case self::MODEL_REQUIRED:
				return __( 'Model required', 'mclogiora' );

			case self::NO_PROVIDER_AVAILABLE:
				return __( 'No AI provider available', 'mclogiora' );

			case self::AI_DISABLED:
				return __( 'AI support disabled', 'mclogiora' );

			case self::UNCONFIGURED_CONNECTOR:
				return __( 'AI connector not configured', 'mclogiora' );

			default:
				return __( 'Not configured', 'mclogiora' );
		}
	}

	/**
	 * Returns what the owner still has to do, or an empty string.
	 *
	 * @param TranslationProviderInterface $provider Provider to describe.
	 * @return string
	 */
	public function next_step( TranslationProviderInterface $provider ) {
		switch ( $this->state( $provider ) ) {
			case self::READY:
				return '';

			case self::MODEL_REQUIRED:
				return __( 'Refresh the model list, then choose a model and save.', 'mclogiora' );

			case self::NO_PROVIDER_AVAILABLE:
				return __( 'Install or activate a compatible AI provider, then configure it in Settings → Connectors.', 'mclogiora' );

			case self::AI_DISABLED:
				return __( 'Enable AI support in WordPress, then configure a provider in Settings → Connectors.', 'mclogiora' );

			case self::UNCONFIGURED_CONNECTOR:
				return __( 'Configure a connected AI provider in Settings → Connectors.', 'mclogiora' );

			default:
				return $provider->manages_credentials()
					? __( 'Add an API key for this provider.', 'mclogiora' )
					: __( 'Connect an AI provider in Settings → Connectors.', 'mclogiora' );
		}
	}

	/**
	 * Returns where a provider's credential comes from.
	 *
	 * @param TranslationProviderInterface $provider Provider to inspect.
	 * @return string Empty when no credential is stored.
	 */
	public function credential_source( TranslationProviderInterface $provider ) {
		if ( ! $provider->manages_credentials() ) {
			return '';
		}

		if ( $this->credentials->is_defined_by_constant( $provider->get_id() ) ) {
			return __( 'Configured via wp-config.php', 'mclogiora' );
		}

		if ( $this->credentials->has( $provider->get_id() ) ) {
			return __( 'Saved in the database', 'mclogiora' );
		}

		return '';
	}
}
