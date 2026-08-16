<?php
/**
 * Safe suggestion state handed to the admin screens.
 *
 * @package McLogiora
 */

namespace McLogiora\Admin;

use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Suggestions\ProviderReadiness;
use McLogiora\Suggestions\ProviderRegistry;
use McLogiora\Suggestions\SuggestionSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the only suggestion information an admin screen is allowed to see.
 *
 * The editor equivalent of this class explains the reasoning at length and it
 * applies unchanged here: whatever this returns is inlined into a `<script>` tag
 * and is readable by anyone who can open the screen. So it is an allow-list of
 * harmless facts, not a filtered dump of settings.
 *
 * No credential in any form, and no source text. The screen needs to know
 * whether it may offer a button; the server resolves what gets translated when
 * that button is pressed.
 *
 * This is separate from the editor's state because the gate is different. A post
 * field is gated on `edit_post` for one post id. These screens each manage many
 * objects at once, so the gate is the translation-management capability the
 * screens themselves already require.
 */
final class SuggestionAdminState {
	/**
	 * Settings reader.
	 *
	 * @var SuggestionSettings
	 */
	private $settings;

	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry
	 */
	private $providers;

	/**
	 * Readiness resolver.
	 *
	 * @var ProviderReadiness
	 */
	private $readiness;

	/**
	 * Capability registry.
	 *
	 * @var CapabilityRegistry
	 */
	private $capabilities;

	/**
	 * Builds the state provider.
	 *
	 * @param SuggestionSettings $settings Settings reader.
	 * @param ProviderRegistry   $providers Provider registry.
	 * @param ProviderReadiness  $readiness Readiness resolver.
	 * @param CapabilityRegistry $capabilities Capability registry.
	 */
	public function __construct(
		SuggestionSettings $settings,
		ProviderRegistry $providers,
		ProviderReadiness $readiness,
		CapabilityRegistry $capabilities
	) {
		$this->settings     = $settings;
		$this->providers    = $providers;
		$this->readiness    = $readiness;
		$this->capabilities = $capabilities;
	}

	/**
	 * Returns the suggestion state for an admin screen.
	 *
	 * Reaches no provider. Every value is read from local configuration, so this
	 * is safe to call while rendering a screen.
	 *
	 * @return array<string,mixed>
	 */
	public function current() {
		$state = array(
			'available'     => false,
			'reason'        => '',
			'providerLabel' => '',
			'modelLabel'    => '',
			'settingsUrl'   => '',
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => '',
			'actions'       => array(
				'generate' => 'mclogiora_admin_generate_suggestion',
				'apply'    => 'mclogiora_admin_apply_suggestion',
				'discard'  => 'mclogiora_admin_discard_suggestion',
			),
		);

		if ( current_user_can( $this->capabilities->resolve( CapabilityRegistry::MANAGE_SETTINGS ) ) ) {
			$state['settingsUrl'] = admin_url( 'admin.php?page=mclogiora-suggestions' );
		}

		if ( ! current_user_can( $this->capabilities->resolve( CapabilityRegistry::MANAGE_TRANSLATIONS ) ) ) {
			$state['reason'] = __( 'You are not allowed to manage translations.', 'mclogiora' );

			return $state;
		}

		if ( ! $this->settings->is_enabled() ) {
			$state['reason'] = __( 'Translation suggestions are switched off for this site.', 'mclogiora' );

			return $state;
		}

		$provider = $this->providers->find( $this->settings->provider_id() );

		if ( null === $provider ) {
			$state['reason'] = __( 'No translation provider has been chosen yet.', 'mclogiora' );

			return $state;
		}

		$state['providerLabel'] = $provider->get_label();

		if ( ! $provider->is_configured() ) {
			$state['reason'] = ProviderReadiness::MODEL_REQUIRED === $this->readiness->state( $provider )
				? __( 'The chosen provider still needs a model.', 'mclogiora' )
				: __( 'The chosen provider still needs an API key.', 'mclogiora' );

			return $state;
		}

		$state['available']  = true;
		$state['modelLabel'] = $provider->selected_model();

		/*
		 * The nonce is issued only once every other gate has passed, so a screen
		 * that may not suggest hands the browser no token at all and cannot be
		 * re-enabled from the console into a working request.
		 */
		$state['nonce'] = wp_create_nonce( SuggestionAdminController::NONCE_ACTION );

		return $state;
	}
}
