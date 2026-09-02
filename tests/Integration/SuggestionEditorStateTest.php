<?php
/**
 * Editor suggestion state safety tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Database\Installer;
use McLogiora\Editors\SuggestionEditorState;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\HttpTransport;
use McLogiora\Suggestions\LlmInstructions;
use McLogiora\Suggestions\ProviderRegistry;
use McLogiora\Suggestions\Providers\DeepLProvider;
use McLogiora\Suggestions\Providers\WordPressAiProvider;
use McLogiora\Suggestions\SuggestionSettings;
use McLogiora\Tests\Support\FakeTransport;
use WP_UnitTestCase;

/**
 * Proves what does and does not reach the editor's JavaScript.
 *
 * Everything this class returns is inlined into a `<script>` tag on an admin
 * page, so its audience is anyone who can open the editor and view source.
 * The claim made when it was written -- that no credential and no source text
 * appear there -- was reasoned about but never evidenced. This file is that
 * evidence.
 *
 * The tests search the serialised payload for the actual secret rather than
 * checking that a field called `credential` is absent. A key can leak through
 * a field nobody thought to name.
 */
final class SuggestionEditorStateTest extends WP_UnitTestCase {
	/**
	 * A credential distinctive enough to find anywhere.
	 */
	const SECRET = 'sk-live-EDITORSTATE-MUST-NOT-LEAK-7731';

	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Recording transport.
	 *
	 * @var FakeTransport
	 */
	private $transport;

	/**
	 * Post being edited.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Sets up a configured site with a post.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();

		delete_option( 'mclogiora_db_version' );
		$this->container->get( Installer::class )->install();

		$languages = $this->container->get( LanguageRepositoryInterface::class );

		if ( ! $languages->find_by_code( 'en' ) instanceof Language ) {
			$languages->create( new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, false ) );
			$languages->set_default( 'en' );
		}

		$this->transport = new FakeTransport();

		$this->container->set( HttpTransport::class, $this->transport );
		$this->container->set(
			ProviderRegistry::class,
			function () {
				$registry    = new ProviderRegistry();
				$credentials = new CredentialStore();
				$prompts     = new LlmInstructions();

				$registry->add( new WordPressAiProvider( $prompts ) );
				$registry->add( new DeepLProvider( $this->transport, $credentials ) );

				return $registry;
			}
		);

		$this->post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Distinctive source title 8842',
				'post_excerpt' => 'Distinctive source excerpt 9913',
				'post_content' => 'Distinctive source body 5521',
			)
		);
	}

	/**
	 * Clears configuration between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		$credentials = new CredentialStore();

		foreach ( array( 'deepl' ) as $id ) {
			$credentials->remove( $id );
			delete_option( 'mclogiora_suggestion_model_' . $id );
		}

		delete_option( SuggestionSettings::OPTION_ENABLED );
		delete_option( SuggestionSettings::OPTION_PROVIDER );

		parent::tear_down();
	}

	/**
	 * Builds the state exactly as the editor would receive it.
	 *
	 * @return array<string,mixed>
	 */
	private function state() {
		return $this->container->get( SuggestionEditorState::class )->for_post( $this->post_id );
	}

	/**
	 * Returns the state serialised the way it reaches the page.
	 *
	 * @return string
	 */
	private function serialised() {
		return (string) wp_json_encode( $this->state() );
	}

	/**
	 * Configures a ready DeepL provider.
	 *
	 * @return void
	 */
	private function configure_deepl() {
		( new CredentialStore() )->save( 'deepl', self::SECRET );

		$settings = $this->container->get( SuggestionSettings::class );
		$settings->set_enabled( true );
		$settings->set_provider( 'deepl' );
	}

	/**
	 * Asserts the payload keys are exactly the agreed set.
	 *
	 * Pinned so a future change cannot quietly add a field carrying something
	 * sensitive. Adding a key here should be a deliberate act with a reviewer
	 * looking at it.
	 *
	 * @return void
	 */
	public function test_the_payload_shape_is_pinned() {
		$this->configure_deepl();

		$this->assertSame(
			array( 'available', 'reason', 'fields', 'providerLabel', 'modelLabel', 'settingsUrl', 'nonce', 'actions' ),
			array_keys( $this->state() )
		);

		$this->assertSame( array( 'title', 'excerpt' ), $this->state()['fields'] );
		$this->assertSame( array( 'generate', 'apply', 'discard' ), array_keys( $this->state()['actions'] ) );
	}

	/**
	 * Asserts a stored credential never reaches the editor payload.
	 *
	 * @return void
	 */
	public function test_a_stored_credential_never_reaches_the_editor() {
		$this->configure_deepl();

		$serialised = $this->serialised();

		$this->assertTrue( $this->state()['available'], 'The provider should be ready for this test to mean anything.' );

		$this->assertStringNotContainsString( self::SECRET, $serialised, 'The credential must never be localized.' );
		$this->assertStringNotContainsString( 'EDITORSTATE', $serialised );
		$this->assertStringNotContainsString( 'sk-live', $serialised, 'Even a key prefix identifies the credential.' );
		$this->assertStringNotContainsString( 'DeepL-Auth-Key', $serialised );
		$this->assertStringNotContainsString( 'Authorization', $serialised );
		$this->assertStringNotContainsString( 'mclogiora_suggestion_key_', $serialised, 'The option name is a lookup hint.' );
	}

	/**
	 * Asserts the dedicated service credential stays out of the editor.
	 *
	 * @return void
	 */
	public function test_a_credential_never_reaches_the_editor() {
		$credentials = new CredentialStore();

		$credentials->save( 'deepl', 'deepl-SECRET-D' );

		$settings = $this->container->get( SuggestionSettings::class );
		$settings->set_enabled( true );
		$settings->set_provider( 'deepl' );

		$serialised = $this->serialised();

		foreach ( array( 'SECRET-D' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $serialised );
		}
	}

	/**
	 * Asserts no source content is shipped into the page.
	 *
	 * The editor shows the source itself; it must not be handed the text from
	 * here, because a payload that already contains the source invites a later
	 * change to post it back -- which is the design that turns the endpoint
	 * into a translation proxy.
	 *
	 * @return void
	 */
	public function test_no_source_content_is_localized() {
		$this->configure_deepl();

		$serialised = $this->serialised();

		$this->assertStringNotContainsString( 'Distinctive source title 8842', $serialised );
		$this->assertStringNotContainsString( 'Distinctive source excerpt 9913', $serialised );
		$this->assertStringNotContainsString( 'Distinctive source body 5521', $serialised );
	}

	/**
	 * Asserts an unavailable feature hands out no usable nonce.
	 *
	 * Not because a nonce is authorization -- the server revalidates
	 * everything -- but because a disabled panel should not carry the one
	 * token that makes a hand-built request look well-formed.
	 *
	 * @return void
	 */
	public function test_an_unavailable_feature_issues_no_nonce() {
		$state = $this->state();

		$this->assertFalse( $state['available'] );
		$this->assertSame( '', $state['nonce'] );
		$this->assertNotSame( '', $state['reason'], 'An unavailable feature must say why.' );

		$settings = $this->container->get( SuggestionSettings::class );
		$settings->set_enabled( true );
		$settings->set_provider( 'wordpress-ai' );

		$core_unavailable = $this->state();

		$this->assertFalse( $core_unavailable['available'], 'A site without a Core AI connection is not ready.' );
		$this->assertSame( '', $core_unavailable['nonce'] );

		$this->configure_deepl();

		$ready = $this->state();

		$this->assertTrue( $ready['available'] );
		$this->assertNotSame( '', $ready['nonce'], 'A ready feature needs its token.' );
	}

	/**
	 * Asserts the unavailable reason is reported for each distinct cause.
	 *
	 * @return void
	 */
	public function test_each_unavailable_cause_is_reported() {
		$this->assertStringContainsString( 'switched off', strtolower( $this->state()['reason'] ) );

		$settings = $this->container->get( SuggestionSettings::class );
		$settings->set_enabled( true );

		$this->assertStringContainsString( 'provider', strtolower( $this->state()['reason'] ) );

		$settings->set_provider( 'wordpress-ai' );

		$this->assertStringContainsString( 'connect', strtolower( $this->state()['reason'] ) );
	}

	/**
	 * Asserts building the state never reaches a provider.
	 *
	 * Called on every editor load, for every post.
	 *
	 * @return void
	 */
	public function test_building_the_state_makes_no_provider_request() {
		$this->configure_deepl();

		for ( $i = 0; $i < 5; $i++ ) {
			$this->state();
		}

		$this->container->get( SuggestionSettings::class )->set_provider( 'wordpress-ai' );

		$this->state();

		$this->assertSame(
			array(),
			$this->transport->requests(),
			'Opening the editor must never contact a provider.'
		);
	}

	/**
	 * Asserts a user who cannot edit the post gets no nonce.
	 *
	 * @return void
	 */
	public function test_a_user_without_edit_rights_gets_no_nonce() {
		$this->configure_deepl();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$state = $this->state();

		$this->assertFalse( $state['available'] );
		$this->assertSame( '', $state['nonce'] );
		$this->assertSame( '', $state['settingsUrl'], 'A subscriber has no business being linked to settings.' );
	}
}
