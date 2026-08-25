<?php
/**
 * First-run onboarding integration coverage.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Database\Installer;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Setup\SetupState;
use McLogiora\Setup\SetupWizard;
use WP_UnitTestCase;

/**
 * Proves real language data survives a setup revisit.
 */
final class OnboardingIntegrationTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Installs a clean schema and administrator user.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();
		delete_option( 'mclogiora_db_version' );
		$this->container->get( Installer::class )->install();
		delete_option( SetupState::OPTION_NAME );
	}

	/**
	 * Clears the journey state.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_GET  = array();
		$_POST = array();
		delete_option( SetupState::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * A fresh activation marks the next eligible request without changing data.
	 *
	 * @return void
	 */
	public function test_fresh_activation_marks_pending_on_an_unconfigured_site() {
		SetupState::mark_activation_pending();

		$this->assertSame( SetupState::NOT_STARTED, SetupState::status() );
		$this->assertTrue( SetupState::has_pending_activation() );
	}

	/**
	 * Existing language data prevents a later activation from hijacking the site.
	 *
	 * @return void
	 */
	public function test_existing_configuration_is_preserved_and_not_marked_pending() {
		$languages = $this->container->get( LanguageRepositoryInterface::class );
		$languages->create( new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, false ) );
		$languages->set_default( 'en' );
		$languages->create( new Language( 'tr', 'tr_TR', 'Türkçe', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ) );

		$before = $languages->all();
		SetupState::mark_activation_pending();

		$this->assertFalse( SetupState::has_pending_activation() );
		$this->assertSame( $before[0]->code(), $languages->default_language()->code() );
		$this->assertCount( 2, $languages->all() );
	}

	/**
	 * A completed site revisits as a review and keeps its language records.
	 *
	 * @return void
	 */
	public function test_completed_site_revisit_does_not_delete_language_data() {
		$languages = $this->container->get( LanguageRepositoryInterface::class );
		$languages->create( new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, false ) );
		$languages->set_default( 'en' );
		$languages->create( new Language( 'tr', 'tr_TR', 'Türkçe', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ) );
		SetupState::complete();

		$_GET = array( 'page' => SetupWizard::PAGE_SLUG );
		$wizard = new SetupWizard();
		$wizard->register( $this->container );
		$screen = null;

		foreach ( $this->container->get( \McLogiora\Admin\AdminScreenRegistry::class )->all() as $candidate ) {
			if ( SetupWizard::PAGE_SLUG === $candidate->slug() ) {
				$screen = $candidate;
				break;
			}
		}

		ob_start();
		call_user_func( $screen->callback() );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Review your setup', $html );
		$this->assertStringContainsString( 'Nothing is reset by revisiting this screen.', $html );
		$this->assertCount( 2, $languages->all() );
		$this->assertSame( 'en', $languages->default_language()->code() );
	}

	/**
	 * A real first mutation uses PRG before the admin header is emitted.
	 *
	 * @return void
	 */
	public function test_first_language_mutation_redirects_before_rendering_admin_output() {
		$_GET  = array(
			'page' => SetupWizard::PAGE_SLUG,
			'step' => 'languages',
		);
		$_POST = array(
			'mclogiora_setup_action' => 'add_language',
			SetupWizard::NONCE_FIELD => wp_create_nonce( SetupWizard::NONCE_ACTION ),
			'language_code'          => 'en',
			'locale'                => 'en_US',
			'native_name'           => 'English',
			'english_name'          => 'English',
			'direction'             => 'ltr',
		);

		$wizard = new SetupWizard();
		$wizard->register( $this->container );
		$location = null;
		$redirect = static function ( $url ) use ( &$location ) {
			$location = $url;

			throw new \RuntimeException( 'Captured redirect.' );
		};

		add_filter( 'wp_redirect', $redirect, 10, 1 );

		try {
			$wizard->handle_post();
			$this->fail( 'The first language mutation did not redirect.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'Captured redirect.', $exception->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $redirect, 10 );
		}

		$this->assertSame( admin_url( 'admin.php?page=' . SetupWizard::PAGE_SLUG . '&step=languages' ), $location );
		$this->assertNotNull( $this->container->get( LanguageRepositoryInterface::class )->find_by_code( 'en' ) );

		$_POST = array();
		ob_start();
		$wizard->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-mclogiora-setup-wizard', $html );
		$this->assertStringContainsString( 'Languages', $html );
		$this->assertStringContainsString( 'Continue to default language', $html );
	}

	/**
	 * Invalid explicit steps recover to a visible, safe first step.
	 *
	 * @return void
	 */
	public function test_invalid_step_never_renders_an_empty_callback() {
		$_GET = array(
			'page' => SetupWizard::PAGE_SLUG,
			'step' => 'not-a-real-step',
		);

		$wizard = new SetupWizard();
		$wizard->register( $this->container );
		ob_start();
		$wizard->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-mclogiora-setup-wizard', $html );
		$this->assertStringContainsString( 'Welcome to mcLogiora', $html );
		$this->assertStringContainsString( 'Start setup', $html );
	}
}
