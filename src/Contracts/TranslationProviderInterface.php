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
 * Everything here is per-provider on purpose. The four launch providers differ
 * in every way an HTTP API can: four different authentication schemes, two
 * different notions of "model", and one of them is not a language model at all.
 * A provider therefore owns its own request shaping, its own credential check,
 * and its own way of protecting text it must not translate, rather than having
 * a shared abstraction guess on its behalf.
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
	 * A provider with a stored credential but no chosen model is deliberately
	 * not configured: mcLogiora never picks a model on the owner's behalf, so
	 * until they choose one there is nothing to spend against.
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Returns whether the provider needs an explicit model choice.
	 *
	 * True for the language models, false for a dedicated translation service
	 * that exposes no model menu.
	 *
	 * @return bool
	 */
	public function requires_model_selection();

	/**
	 * Returns the models the provider currently offers.
	 *
	 * Called only when the owner explicitly asks to refresh the list, never
	 * while merely rendering a settings screen, because it is an outbound call
	 * to a third party.
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
	 * Verifies the stored credential without spending translation quota.
	 *
	 * Every launch provider exposes a metadata endpoint that proves a key
	 * works without translating anything, so a failed key costs the owner
	 * nothing to diagnose.
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
