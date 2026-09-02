<?php
/**
 * Safe suggestion state handed to the editors.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors;

use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Suggestions\ProviderReadiness;
use McLogiora\Suggestions\ProviderRegistry;
use McLogiora\Suggestions\SuggestionSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the only suggestion information an editor is allowed to see.
 *
 * Everything here ends up in a `<script>` tag on an admin page, which is the
 * strictest audience in the plugin: whatever this returns is readable by
 * anyone who can open the editor and view source. So the method is written as
 * an allow-list of harmless facts rather than a filtered dump of settings.
 *
 * Two things are deliberately absent.
 *
 * **No credential, in any form.** Not the key, not a hash of it, not its
 * length, not whether a particular provider's key looks valid. The editor
 * needs to know whether it may offer a button; it never needs to know anything
 * about the secret behind it.
 *
 * **No source text.** The editor already displays the source post's title and
 * excerpt in its own UI, but it does not receive them from here, because the
 * server resolves the text to translate at request time. Shipping the source
 * text into the page would invite a future change to post it back, which is
 * exactly the design that turns the endpoint into a translation proxy.
 */
final class SuggestionEditorState {
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
	 * Returns the suggestion state for one translation.
	 *
	 * Reaches no provider. Every value is read from local configuration, so
	 * this is safe to call while rendering an editor screen.
	 *
	 * @param int $post_id Post being edited.
	 * @return array<string,mixed>
	 */
	public function for_post( $post_id ) {
		$state = array(
			'available'     => false,
			'reason'        => '',
			'fields'        => array( 'title', 'excerpt' ),
			'providerLabel' => '',
			'modelLabel'    => '',
			'settingsUrl'   => '',
			'nonce'         => '',
			'actions'       => array(
				'generate' => 'mclogiora_generate_suggestion',
				'apply'    => 'mclogiora_apply_suggestion',
				'discard'  => 'mclogiora_discard_suggestion',
			),
		);

		if ( current_user_can( $this->capabilities->resolve( CapabilityRegistry::MANAGE_SETTINGS ) ) ) {
			$state['settingsUrl'] = admin_url( 'admin.php?page=mclogiora-suggestions' );
		}

		if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
			$state['reason'] = __( 'You cannot edit this translation.', 'mclogiora' );

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
				: ( $provider->manages_credentials()
					? __( 'The chosen provider still needs an API key.', 'mclogiora' )
					: __( 'Connect an AI provider in Settings → Connectors.', 'mclogiora' ) );

			return $state;
		}

		$state['available']  = true;
		$state['modelLabel'] = $provider->selected_model();

		/*
		 * The nonce is issued only once every other gate has passed. An
		 * unavailable feature hands the browser no token at all, so a disabled
		 * panel cannot be re-enabled from the console into a working request.
		 */
		$state['nonce'] = wp_create_nonce( SuggestionEditorController::NONCE_ACTION );

		return $state;
	}
}
