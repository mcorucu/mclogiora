<?php
/**
 * Suggestion settings, readiness and model cache tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\LlmInstructions;
use McLogiora\Suggestions\ModelCache;
use McLogiora\Suggestions\Providers\AbstractProvider;
use McLogiora\Suggestions\Providers\DeepLProvider;
use McLogiora\Suggestions\ProviderReadiness;
use McLogiora\Suggestions\SuggestionRequest;
use McLogiora\Suggestions\SuggestionResult;
use McLogiora\Suggestions\SuggestionSettings;
use McLogiora\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Configurable provider double for control-plane tests.
 *
 * @package McLogiora
 */
final class ControlPlaneTestProvider extends AbstractProvider {
	/**
	 * Stable synthetic provider identifier.
	 */
	const ID = 'test-provider';

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
		return 'Test provider';
	}

	/**
	 * {@inheritDoc}
	 */
	public function available_models() {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_language_pair( $source_language, $target_language ) {
		return '' !== (string) $source_language && '' !== (string) $target_language && $source_language !== $target_language;
	}

	/**
	 * {@inheritDoc}
	 */
	public function test_connection() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function suggest( SuggestionRequest $request ) {
		return new SuggestionResult( $request->source_text(), self::ID, $this->selected_model() );
	}
}

/**
 * Covers the configuration layer the settings screen draws from.
 *
 * The screen must render without touching the network and must never invent a
 * model on the owner's behalf, so both of those properties are asserted here
 * against the domain rather than against markup -- markup can be rewritten,
 * and these rules must survive that.
 */
final class SuggestionControlPlaneTest extends TestCase {
	/**
	 * Recording transport.
	 *
	 * @var FakeTransport
	 */
	private $transport;

	/**
	 * Credential storage.
	 *
	 * @var CredentialStore
	 */
	private $credentials;

	/**
	 * Settings reader.
	 *
	 * @var SuggestionSettings
	 */
	private $settings;

	/**
	 * Readiness resolver.
	 *
	 * @var ProviderReadiness
	 */
	private $readiness;

	/**
	 * Model cache.
	 *
	 * @var ModelCache
	 */
	private $models;

	/**
	 * Resets stored state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['mclogiora_test_transients']   = array();
		$GLOBALS['mclogiora_test_clock_offset'] = 0;

		$this->transport   = new FakeTransport();
		$this->credentials = new CredentialStore();
		$this->settings    = new SuggestionSettings();
		$this->readiness   = new ProviderReadiness( $this->credentials );
		$this->models      = new ModelCache();
	}

	/**
	 * Clears options between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		delete_option( SuggestionSettings::OPTION_ENABLED );
		delete_option( SuggestionSettings::OPTION_PROVIDER );

		foreach ( array( ControlPlaneTestProvider::ID, 'deepl' ) as $id ) {
			$this->credentials->remove( $id );
			delete_option( 'mclogiora_suggestion_model_' . $id );
		}

		parent::tearDown();
	}

	/**
	 * Returns a configurable provider bound to the recording transport.
	 *
	 * @return ControlPlaneTestProvider
	 */
	private function model_provider() {
		return new ControlPlaneTestProvider( $this->transport, $this->credentials );
	}

	/**
	 * Returns a DeepL provider bound to the recording transport.
	 *
	 * @return DeepLProvider
	 */
	private function deepl() {
		return new DeepLProvider( $this->transport, $this->credentials );
	}

	/**
	 * Asserts a fresh site has suggestions switched off.
	 *
	 * @return void
	 */
	public function test_suggestions_are_disabled_on_a_fresh_site() {
		$this->assertFalse( $this->settings->is_enabled() );
		$this->assertSame( '', $this->settings->provider_id(), 'No provider may be chosen for the owner.' );
	}

	/**
	 * Asserts the master switch persists both ways.
	 *
	 * @return void
	 */
	public function test_the_master_switch_persists() {
		$this->settings->set_enabled( true );
		$this->assertTrue( $this->settings->is_enabled() );

		$this->settings->set_enabled( false );
		$this->assertFalse( $this->settings->is_enabled() );
	}

	/**
	 * Asserts switching provider preserves the other provider's setup.
	 *
	 * @return void
	 */
	public function test_switching_provider_keeps_the_other_configuration() {
		$this->credentials->save( ControlPlaneTestProvider::ID, 'test-provider-key' );
		$this->credentials->save( 'deepl', 'deepl-key' );

		$provider = $this->model_provider();
		$provider->set_selected_model( 'test-model' );

		$this->settings->set_provider( ControlPlaneTestProvider::ID );
		$this->settings->set_provider( 'deepl' );

		$this->assertSame( 'deepl', $this->settings->provider_id() );
		$this->assertTrue( $this->credentials->has( ControlPlaneTestProvider::ID ), 'Switching provider must not delete a credential.' );
		$this->assertSame( 'test-model', $provider->selected_model(), 'Switching provider must not clear a model choice.' );
	}

	/**
	 * Asserts readiness reports each configuration stage honestly.
	 *
	 * @return void
	 */
	public function test_readiness_walks_from_unconfigured_to_ready() {
		$provider = $this->model_provider();

		$this->assertSame( ProviderReadiness::NOT_CONFIGURED, $this->readiness->state( $provider ) );
		$this->assertSame( '', $this->readiness->credential_source( $provider ) );

		$this->credentials->save( ControlPlaneTestProvider::ID, 'test-provider-key' );

		$this->assertSame(
			ProviderReadiness::MODEL_REQUIRED,
			$this->readiness->state( $provider ),
			'A key without a chosen model is not ready.'
		);
		$this->assertNotSame( '', $this->readiness->next_step( $provider ) );

		$provider->set_selected_model( 'test-model' );

		$this->assertSame( ProviderReadiness::READY, $this->readiness->state( $provider ) );
		$this->assertSame( '', $this->readiness->next_step( $provider ) );
	}

	/**
	 * Asserts DeepL is ready on a credential alone.
	 *
	 * @return void
	 */
	public function test_deepl_is_ready_without_a_model() {
		$deepl = $this->deepl();

		$this->assertSame( ProviderReadiness::NOT_CONFIGURED, $this->readiness->state( $deepl ) );

		$this->credentials->save( 'deepl', 'deepl-key' );

		$this->assertSame( ProviderReadiness::READY, $this->readiness->state( $deepl ) );
		$this->assertFalse( $deepl->requires_model_selection() );
	}

	/**
	 * Asserts readiness never claims a remote connection.
	 *
	 * @return void
	 */
	public function test_readiness_never_claims_connectivity() {
		$this->credentials->save( 'deepl', 'deepl-key' );

		$label = strtolower( $this->readiness->label( $this->deepl() ) );

		$this->assertStringNotContainsString( 'connected', $label, 'Configuration state is not proof of reachability.' );
		$this->assertStringNotContainsString( 'online', $label );
	}

	/**
	 * Asserts computing readiness never touches the network.
	 *
	 * The settings screen calls this for every provider on every render.
	 *
	 * @return void
	 */
	public function test_computing_readiness_makes_no_request() {
		$this->credentials->save( ControlPlaneTestProvider::ID, 'test-provider-key' );
		$this->credentials->save( 'deepl', 'deepl-key' );

		foreach ( array( $this->model_provider(), $this->deepl() ) as $provider ) {
			$this->readiness->state( $provider );
			$this->readiness->label( $provider );
			$this->readiness->next_step( $provider );
			$this->readiness->credential_source( $provider );
		}

		$this->assertSame( array(), $this->transport->requests(), 'Rendering state must never reach a provider.' );
	}

	/**
	 * Asserts the model cache keeps only what the screen draws.
	 *
	 * @return void
	 */
	public function test_the_model_cache_stores_only_normalised_fields() {
		$this->models->put(
			ControlPlaneTestProvider::ID,
			array(
				array(
					'id'          => 'gpt-5.4-mini',
					'label'       => 'GPT-5.4 mini',
					'recommended' => true,
					'owned_by'    => 'test-provider',
					'created'     => 1700000000,
				),
			)
		);

		$cached = $this->models->get( ControlPlaneTestProvider::ID );

		$this->assertCount( 1, $cached );
		$this->assertSame( array( 'id', 'label', 'recommended' ), array_keys( $cached[0] ) );
		$this->assertArrayNotHasKey( 'owned_by', $cached[0], 'Raw provider payload must not be persisted.' );
	}

	/**
	 * Asserts a submitted model is checked against what the provider offered.
	 *
	 * @return void
	 */
	public function test_only_offered_models_are_accepted() {
		$this->models->put( ControlPlaneTestProvider::ID, array( array( 'id' => 'test-model', 'label' => 'Test model', 'recommended' => true ) ) );

		$this->assertTrue( $this->models->offers( ControlPlaneTestProvider::ID, 'test-model' ) );
		$this->assertFalse( $this->models->offers( ControlPlaneTestProvider::ID, 'anything-the-form-posted' ) );
		$this->assertFalse( $this->models->offers( ControlPlaneTestProvider::ID, '' ) );
	}

	/**
	 * Asserts an expired model cache leaves the owner's choice alone.
	 *
	 * @return void
	 */
	public function test_an_expired_model_cache_does_not_disturb_the_selection() {
		$this->credentials->save( ControlPlaneTestProvider::ID, 'test-provider-key' );

		$provider = $this->model_provider();
		$provider->set_selected_model( 'test-model' );

		$this->models->put( ControlPlaneTestProvider::ID, array( array( 'id' => 'test-model', 'label' => 'Test model', 'recommended' => true ) ) );

		$GLOBALS['mclogiora_test_clock_offset'] = ModelCache::LIFETIME + 60;

		$this->assertSame( array(), $this->models->get( ControlPlaneTestProvider::ID ), 'The list lapses.' );
		$this->assertSame( 'test-model', $provider->selected_model(), 'The choice must not lapse with it.' );
		$this->assertSame( ProviderReadiness::READY, $this->readiness->state( $provider ) );
		$this->assertSame( array(), $this->transport->requests(), 'A lapsed cache must not trigger a refresh.' );
	}

	/**
	 * Asserts a retired model clears the selection and blocks readiness.
	 *
	 * @return void
	 */
	public function test_a_retired_model_leaves_the_provider_incomplete() {
		$this->credentials->save( ControlPlaneTestProvider::ID, 'test-provider-key' );

		$provider = $this->model_provider();
		$provider->set_selected_model( 'test-model' );

		$refreshed = array( array( 'id' => 'gpt-5.4', 'label' => 'GPT-5.4', 'recommended' => false ) );

		$this->assertTrue( $provider->reconcile_selected_model( $refreshed ) );
		$this->models->put( ControlPlaneTestProvider::ID, $refreshed );

		$this->assertSame( '', $provider->selected_model(), 'No replacement may be chosen.' );
		$this->assertSame( ProviderReadiness::MODEL_REQUIRED, $this->readiness->state( $provider ) );
	}

	/**
	 * Asserts a constant-backed credential is reported as such.
	 *
	 * @return void
	 */
	public function test_a_constant_backed_credential_is_named_in_the_source() {
		$this->assertSame( 'MCLOGIORA_TEST_PROVIDER_API_KEY', $this->credentials->constant_name( ControlPlaneTestProvider::ID ) );
		$this->assertSame( 'MCLOGIORA_DEEPL_API_KEY', $this->credentials->constant_name( 'deepl' ) );
	}

	/**
	 * Asserts the masked form reveals only a short suffix.
	 *
	 * @return void
	 */
	public function test_masking_reveals_only_a_short_suffix() {
		$this->credentials->save( ControlPlaneTestProvider::ID, 'test-live-SECRETBODY-AB12' );

		$masked = $this->credentials->masked( ControlPlaneTestProvider::ID );

		$this->assertStringNotContainsString( 'SECRETBODY', $masked );
		$this->assertStringNotContainsString( 'test-live', $masked, 'The start of a key identifies its type and owner.' );
		$this->assertStringEndsWith( 'AB12', $masked );
	}

	/**
	 * Asserts a short credential reveals nothing at all.
	 *
	 * @return void
	 */
	public function test_a_short_credential_reveals_nothing() {
		$this->credentials->save( 'deepl', 'abcd1234' );

		$this->assertSame( '********', $this->credentials->masked( 'deepl' ) );
	}

	/**
	 * Asserts an empty save never deletes a stored credential.
	 *
	 * Settings forms post every field. If an untouched, deliberately empty
	 * credential box counted as "remove this key", saving an unrelated toggle
	 * would silently disconnect the provider.
	 *
	 * @return void
	 */
	public function test_an_empty_save_never_deletes_a_credential() {
		$this->credentials->save( ControlPlaneTestProvider::ID, 'test-provider-key' );

		$this->assertFalse( $this->credentials->save( ControlPlaneTestProvider::ID, '' ) );
		$this->assertFalse( $this->credentials->save( ControlPlaneTestProvider::ID, '   ' ) );
		$this->assertTrue( $this->credentials->has( ControlPlaneTestProvider::ID ), 'Removal must be a deliberate, separate action.' );

		$this->credentials->remove( ControlPlaneTestProvider::ID );

		$this->assertFalse( $this->credentials->has( ControlPlaneTestProvider::ID ) );
	}

	/**
	 * Asserts a pasted credential is trimmed rather than stored broken.
	 *
	 * @return void
	 */
	public function test_a_pasted_credential_is_trimmed() {
		$this->credentials->save( ControlPlaneTestProvider::ID, "  test-key\n" );

		$this->assertSame( 'test-key', $this->credentials->get( ControlPlaneTestProvider::ID ) );
	}
}
