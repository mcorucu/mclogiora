<?php
/**
 * Translation Suggestions settings integration tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Admin\SuggestionSettingsScreen;
use McLogiora\Core\Application;
use McLogiora\Database\Installer;
use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\HttpTransport;
use McLogiora\Suggestions\ProviderRegistry;
use McLogiora\Suggestions\SuggestionSettings;
use McLogiora\Suggestions\SuggestionSurface;
use McLogiora\Suggestions\TranslationSuggestionService;
use McLogiora\Tests\Support\FakeTransport;
use WP_UnitTestCase;

/**
 * Proves the control plane's two hardest promises against real WordPress.
 *
 * The first is that looking at the settings screen costs nothing. A settings
	 * page that quietly probed providers on every load would bill the owner
 * for curiosity and leak configuration state to third parties on every admin
 * page view.
 *
 * The second is that a stored credential never reaches the browser. Rendering
 * is where a secret escapes if it is going to: an input value, a hidden field,
 * a data attribute, an inline script. The test greps the real rendered markup
 * rather than trusting the template to be careful.
 */
final class SuggestionSettingsIntegrationTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Recording transport swapped in for the real one.
	 *
	 * @var FakeTransport
	 */
	private $transport;

	/**
	 * Administrator identifier.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Sets up the plugin with a recording transport.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();

		delete_option( 'mclogiora_db_version' );
		$this->container->get( Installer::class )->install();

		/*
		 * Swapping the transport before the registry is resolved means every
		 * provider is built against a double, so any stray outbound call shows
		 * up as a recorded request instead of a real one.
		 */
		$this->transport = new FakeTransport();

		/*
		 * Only the transport is replaced. The registry still builds the two
		 * real providers, so the screen renders exactly what a site would show
		 * and any stray outbound call is recorded rather than sent.
		 */
		$this->container->set( HttpTransport::class, $this->transport );

		delete_option( SuggestionSettings::OPTION_ENABLED );
		delete_option( SuggestionSettings::OPTION_PROVIDER );
	}

	/**
	 * Clears credentials between tests.
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
	 * Returns a screen wired to the real container.
	 *
	 * @return SuggestionSettingsScreen
	 */
	private function screen() {
		$screen = new SuggestionSettingsScreen();

		$screen->register( $this->container );

		return $screen;
	}

	/**
	 * Captures the rendered settings markup.
	 *
	 * @return string
	 */
	private function rendered() {
		ob_start();

		$this->screen()->render();

		return (string) ob_get_clean();
	}

	/**
	 * Asserts a fresh site shows suggestions switched off.
	 *
	 * @return void
	 */
	public function test_a_fresh_site_shows_suggestions_disabled() {
		$settings = $this->container->get( SuggestionSettings::class );

		$this->assertFalse( $settings->is_enabled() );
		$this->assertSame( '', $settings->provider_id() );

		$html = $this->rendered();

		$this->assertStringContainsString( 'Translation Suggestions', $html );
		$this->assertStringNotContainsString( 'checked=\'checked\'', $html, 'The master switch must render unchecked on a fresh site.' );
	}

	/**
	 * Asserts the master switch persists through real options.
	 *
	 * @return void
	 */
	public function test_the_master_switch_persists() {
		$settings = $this->container->get( SuggestionSettings::class );

		$settings->set_enabled( true );
		$settings->set_provider( 'deepl' );

		$fresh = new SuggestionSettings();

		$this->assertTrue( $fresh->is_enabled() );
		$this->assertSame( 'deepl', $fresh->provider_id() );
	}

	/**
	 * Asserts rendering the settings screen reaches no provider.
	 *
	 * @return void
	 */
	public function test_rendering_the_settings_screen_makes_no_provider_request() {
		( new CredentialStore() )->save( 'deepl', 'deepl-render-test' );

		$this->rendered();
		$this->rendered();

		$this->assertSame(
			array(),
			$this->transport->requests(),
			'Opening a settings page must never contact a provider.'
		);
	}

	/**
	 * Asserts a stored credential never reaches the rendered markup.
	 *
	 * @return void
	 */
	public function test_a_stored_credential_never_reaches_the_browser() {
		$secret = 'sk-live-DO-NOT-LEAK-THIS-VALUE-9911';

		( new CredentialStore() )->save( 'deepl', $secret );

		$html = $this->rendered();

		$this->assertStringNotContainsString( $secret, $html, 'The raw credential must never be rendered.' );
		$this->assertStringNotContainsString( 'DO-NOT-LEAK', $html );
		$this->assertStringNotContainsString( 'sk-live', $html, 'Even the key prefix identifies the credential type.' );
		$this->assertStringContainsString( '9911', $html, 'The masked suffix is what the owner recognises.' );
		$this->assertStringContainsString( 'value=""', $html, 'The key field must render empty, never repopulated.' );
	}

	/**
	 * Asserts suggestions are refused while the master switch is off.
	 *
	 * @return void
	 */
	public function test_generation_is_refused_while_suggestions_are_disabled() {
		( new CredentialStore() )->save( 'deepl', 'deepl-key' );

		$this->container->get( SuggestionSettings::class )->set_provider( 'deepl' );

		$result = $this->container->get( TranslationSuggestionService::class )
			->generate( SuggestionSurface::POST_TITLE, 'Hello', 'en', 'tr' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( array(), $this->transport->requests(), 'A disabled site must never reach a provider.' );
	}

	/**
	 * Asserts suggestions are refused when no provider has been chosen.
	 *
	 * @return void
	 */
	public function test_generation_is_refused_without_a_chosen_provider() {
		$settings = $this->container->get( SuggestionSettings::class );

		$settings->set_enabled( true );

		$result = $this->container->get( TranslationSuggestionService::class )
			->generate( SuggestionSurface::POST_TITLE, 'Hello', 'en', 'tr' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( array(), $this->transport->requests() );
	}

	/**
	 * Asserts plugin boot and installation reach no provider.
	 *
	 * @return void
	 */
	public function test_boot_and_installation_reach_no_provider() {
		delete_option( 'mclogiora_db_version' );

		$this->container->get( Installer::class )->install();
		$this->container->get( ProviderRegistry::class );

		/*
		 * Neither `init` nor `admin_init` is re-fired here. The harness has
		 * already run them, and firing them again re-registers the switcher
		 * block, which makes core emit a legitimate "already registered"
		 * notice that has nothing to do with provider traffic. Installing and
		 * resolving the registry is what the claim is actually about.
		 */

		$this->assertSame(
			array(),
			$this->transport->requests(),
			'Booting the plugin or installing it must never contact a provider.'
		);
	}

	/**
	 * Asserts an ordinary frontend request reaches no provider.
	 *
	 * @return void
	 */
	public function test_a_frontend_request_reaches_no_provider() {
		( new CredentialStore() )->save( 'deepl', 'deepl-key' );

		$this->container->get( SuggestionSettings::class )->set_enabled( true );
		$this->container->get( SuggestionSettings::class )->set_provider( 'deepl' );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		do_action( 'wp' );
		do_action( 'wp_head' );
		ob_end_clean();

		$this->assertSame(
			array(),
			$this->transport->requests(),
			'A visitor must never trigger a provider request.'
		);
	}

	/**
	 * Asserts the screen reports the result of the last action.
	 *
	 * Every handler here finishes by redirecting with a short result code. The
	 * screen shipped without reading that code back, so Test connection, Save
	 * key and Refresh model list all completed and told the owner nothing --
	 * found only by clicking the real buttons in a browser.
	 *
	 * @return void
	 */
	public function test_the_screen_reports_the_result_of_the_last_action() {
		$expected = array(
			'test_passed'          => 'The provider accepted the key.',
			'test_failed'          => 'The provider did not accept the key.',
			'credential_saved'     => 'The key was saved.',
			'credential_removed'   => 'The stored key was removed.',
			'credential_unchanged' => 'the stored key was left as it was',
			'models_refreshed'     => 'The model list was refreshed.',
			'model_retired'        => 'no longer offered',
			'refresh_failed'       => 'The model list could not be refreshed.',
			'saved'                => 'Settings saved.',
			'unknown_provider'     => 'That provider is not available.',
			'unknown_model'        => 'That model is not in the current list',
		);

		foreach ( $expected as $code => $message ) {
			$_GET['mclogiora_notice'] = $code;

			$html = $this->rendered();

			$this->assertStringContainsString(
				$message,
				$html,
				sprintf( 'The %s result must be reported to the owner.', $code )
			);
			$this->assertStringContainsString( 'notice', $html, 'The result must render as a WordPress notice.' );
		}

		$_GET['mclogiora_notice'] = 'test_failed';

		$this->assertStringContainsString( 'notice-error', $this->rendered(), 'A failure must not read as success.' );

		unset( $_GET['mclogiora_notice'] );
	}

	/**
	 * Asserts nothing is reported when no action has just run.
	 *
	 * @return void
	 */
	public function test_no_result_is_reported_without_an_action() {
		unset( $_GET['mclogiora_notice'] );

		$this->assertStringNotContainsString( 'notice-success', $this->rendered(), 'A plain visit must report nothing.' );

		$_GET['mclogiora_notice'] = 'not_a_real_code';

		$this->assertStringNotContainsString( 'notice-success', $this->rendered(), 'An unknown code must report nothing.' );
		$this->assertStringNotContainsString( 'notice-error', $this->rendered() );

		unset( $_GET['mclogiora_notice'] );
	}
}
