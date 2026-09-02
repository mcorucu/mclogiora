<?php
/**
 * Translation provider contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Contracts;

use McLogiora\Suggestions\SuggestionRequest;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for opt-in translation suggestion providers.
 *
 * Declared in an earlier phase as a forward marker and given its behaviour in
 * Phase 16. The signatures changed when it was implemented -- `suggest()` now
 * speaks in value objects rather than loose strings -- which was safe to do
 * because nothing implemented it yet. Evolving the existing contract rather
 * than adding a second one keeps a single name for a single idea.
 *
 * The contract supports both providers managed by mcLogiora and providers
 * backed by a WordPress-managed connection. The latter deliberately expose no
 * credential or model controls to this plugin.
 */
interface TranslationProviderInterface {
	/**
	 * Returns the provider identifier.
	 *
	 * Stable and machine-readable. It is stored in settings and used as an
	 * option key prefix, so it must not change once shipped.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Returns the human-readable provider label.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Returns whether the provider has everything it needs to run.
	 *
	 * A provider backed by WordPress may determine readiness from the site's
	 * configured AI connection rather than from mcLogiora options.
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Returns whether mcLogiora owns credential storage for this provider.
	 *
	 * Providers backed by a WordPress-managed connection must return false.
	 * Their credentials are intentionally handled by WordPress rather than by
	 * this plugin's options table or settings screen.
	 *
	 * @return bool
	 */
	public function manages_credentials();

	/**
	 * Returns whether the provider needs an explicit model choice.
	 *
	 * True when the provider exposes a model menu in mcLogiora, false for a
	 * WordPress-managed provider or a dedicated service with no model menu.
	 *
	 * @return bool
	 */
	public function requires_model_selection();

	/**
	 * Returns the models the provider currently offers.
	 *
	 * Called only when the owner explicitly asks to refresh the list for a
	 * provider that owns its model catalogue.
	 *
	 * @return array<int,array{id:string,label:string,recommended:bool}>|\WP_Error
	 */
	public function available_models();

	/**
	 * Returns the model the owner explicitly chose.
	 *
	 * Empty when none has been chosen, and empty for providers that expose no
	 * model menu. Never a default: choosing a model is choosing a price and a
	 * capability, and neither is mcLogiora's to choose.
	 *
	 * @return string
	 */
	public function selected_model();

	/**
	 * Records the owner's model choice.
	 *
	 * @param string $model Model identifier.
	 * @return bool
	 */
	public function set_selected_model( $model );

	/**
	 * Forgets the owner's model choice.
	 *
	 * @return bool
	 */
	public function clear_selected_model();

	/**
	 * Drops a stored model the provider no longer offers.
	 *
	 * @param array<int,array{id:string,label:string,recommended:bool}> $models Freshly fetched models.
	 * @return bool Whether a stored selection was invalidated.
	 */
	public function reconcile_selected_model( array $models );

	/**
	 * Returns whether the language pair is supported.
	 *
	 * @param string $source_language Source language code.
	 * @param string $target_language Target language code.
	 * @return bool
	 */
	public function supports_language_pair( $source_language, $target_language );

	/**
	 * Verifies provider readiness without translating content.
	 *
	 * @return true|\WP_Error
	 */
	public function test_connection();

	/**
	 * Returns a suggested translation for explicit user review.
	 *
	 * Never applies, saves or publishes anything. The caller decides what to
	 * do with the answer.
	 *
	 * @param SuggestionRequest $request Text and languages to translate.
	 * @return \McLogiora\Suggestions\SuggestionResult|\WP_Error
	 */
	public function suggest( SuggestionRequest $request );
}
