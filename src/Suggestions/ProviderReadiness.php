<?php
/**
 * Provider configuration readiness.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

use McLogiora\Contracts\TranslationProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "can this provider be used yet, and if not, what is missing?"
 *
 * One place computes this so no screen has to. A settings page that worked out
 * readiness from raw options would drift from what the service actually
 * enforces, and the drift would show up as a provider the UI calls Ready that
 * refuses to generate.
 *
 * ## Readiness is configuration, not reachability
 *
 * There is deliberately no `Connected` state. A successful connection test
 * proves a credential worked at one moment against one endpoint; it says
 * nothing about the next request, and storing it would produce a settings
 * screen that cheerfully reports Connected while every suggestion fails. What
 * is reported here is only what the site itself can know without asking
 * anyone: whether a credential exists, where it came from, and whether a model
 * has been chosen.
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
		if ( ! $this->credentials->has( $provider->get_id() ) ) {
			return self::NOT_CONFIGURED;
		}

		if ( $provider->requires_model_selection() && ! $provider->is_configured() ) {
			/*
			 * A credential without a chosen model. The provider itself is the
			 * authority on this -- asking it rather than re-deriving the rule
			 * here is what keeps the screen and the service agreeing.
			 */
			return self::MODEL_REQUIRED;
		}

		return $provider->is_configured() ? self::READY : self::NOT_CONFIGURED;
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

			default:
				return __( 'Add an API key for this provider.', 'mclogiora' );
		}
	}

	/**
	 * Returns where a provider's credential comes from.
	 *
	 * @param TranslationProviderInterface $provider Provider to inspect.
	 * @return string Empty when no credential is stored.
	 */
	public function credential_source( TranslationProviderInterface $provider ) {
		if ( $this->credentials->is_defined_by_constant( $provider->get_id() ) ) {
			return __( 'Configured via wp-config.php', 'mclogiora' );
		}

		if ( $this->credentials->has( $provider->get_id() ) ) {
			return __( 'Saved in the database', 'mclogiora' );
		}

		return '';
	}
}
