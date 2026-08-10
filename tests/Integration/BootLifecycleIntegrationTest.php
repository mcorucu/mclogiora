<?php
/**
 * Boot lifecycle hardening tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Admin\AdminScreenRegistry;
use McLogiora\Admin\RoutingSettingsScreen;
use McLogiora\Admin\StringManager;
use McLogiora\Admin\WidgetTranslationManager;
use McLogiora\Compatibility\CompatibilityDashboard;
use McLogiora\Core\Application;
use McLogiora\Core\InstallationFailure;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Database\Installer;
use McLogiora\Database\InstallerFactory;
use McLogiora\Languages\LanguageManager;
use McLogiora\Relations\TranslationManager;
use McLogiora\Setup\SetupWizard;
use WP_UnitTestCase;

/**
 * Covers the two boot problems Phase 12.1 documented but left open.
 *
 * Both are the same shape: something that should be loud is silent. A failed
 * schema installation looked like a successful activation, and translating a
 * menu label during registration produced a WordPress error notice on every
 * page load that nobody had connected to its cause.
 */
final class BootLifecycleIntegrationTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Sets up an installed site.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();

		InstallationFailure::clear();

		delete_option( 'mclogiora_db_version' );
		$this->container->get( Installer::class )->install();
		$this->container->get( RuntimeReadiness::class )->reset();
	}

	/**
	 * Clears any recorded failure.
	 *
	 * @return void
	 */
	public function tear_down() {
		InstallationFailure::clear();

		parent::tear_down();
	}

	/**
	 * Returns the modules that contribute an admin screen.
	 *
	 * @return \McLogiora\Contracts\ModuleInterface[]
	 */
	private function screen_modules() {
		return array(
			new LanguageManager(),
			new TranslationManager(),
			new StringManager(),
			new WidgetTranslationManager(),
			new RoutingSettingsScreen(),
			new CompatibilityDashboard(),
			new SetupWizard(),
		);
	}

	/**
	 * Asserts registering a module translates nothing.
	 *
	 * WordPress 6.7 reports translation before `init` through
	 * `_load_textdomain_just_in_time()`, and every one of these modules used to
	 * trigger it by naming its admin screen while registering. The probe counts
	 * calls into the gettext filter rather than watching for the notice,
	 * because the notice only fires before `init` and a test suite has long
	 * since passed it.
	 *
	 * @return void
	 */
	public function test_module_registration_translates_nothing() {
		$calls = array();

		$probe = static function ( $translation, $text = '', $domain = '' ) use ( &$calls ) {
			if ( 'mclogiora' === $domain ) {
				$calls[] = $text;
			}

			return $translation;
		};

		add_filter( 'gettext', $probe, 1, 3 );
		add_filter( 'gettext_with_context', $probe, 1, 3 );

		foreach ( $this->screen_modules() as $module ) {
			$module->register( $this->container );
		}

		remove_filter( 'gettext', $probe, 1 );
		remove_filter( 'gettext_with_context', $probe, 1 );

		$this->assertSame(
			array(),
			$calls,
			'Registering a module must not translate anything: ' . implode( ', ', $calls )
		);
	}

	/**
	 * Asserts the deferred titles still produce real, translated labels.
	 *
	 * Deferring is only correct if the label still appears. A screen with an
	 * empty menu title would satisfy the previous test perfectly.
	 *
	 * @return void
	 */
	public function test_deferred_titles_still_resolve_to_labels() {
		foreach ( $this->screen_modules() as $module ) {
			$module->register( $this->container );
		}

		$screens = $this->container->get( AdminScreenRegistry::class )->all();

		$this->assertNotEmpty( $screens );

		$calls = 0;

		$probe = static function ( $translation ) use ( &$calls ) {
			++$calls;

			return $translation;
		};

		add_filter( 'gettext', $probe, 1 );

		foreach ( $screens as $screen ) {
			$this->assertNotSame( '', $screen->page_title(), 'Every screen needs a page title.' );
			$this->assertNotSame( '', $screen->menu_title(), 'Every screen needs a menu title.' );
		}

		remove_filter( 'gettext', $probe, 1 );

		$this->assertGreaterThan( 0, $calls, 'Titles must be translated when they are finally asked for.' );
	}

	/**
	 * Asserts a successful activation records no failure.
	 *
	 * @return void
	 */
	public function test_successful_installation_records_no_failure() {
		delete_option( 'mclogiora_db_version' );

		$result = InstallerFactory::create()->install();

		$this->assertNotWPError( $result );
		$this->assertFalse( InstallationFailure::exists() );
	}

	/**
	 * Asserts a recorded failure survives a round trip through the database.
	 *
	 * @return void
	 */
	public function test_recorded_failure_survives_a_database_round_trip() {
		InstallationFailure::record( new \WP_Error( 'mclogiora_migration_incomplete', 'Migration 2 did not create: wp_mclogiora_strings' ) );

		wp_cache_flush();

		$stored = InstallationFailure::get();

		$this->assertIsArray( $stored );
		$this->assertSame( 'mclogiora_migration_incomplete', $stored['code'] );
		$this->assertStringContainsString( 'wp_mclogiora_strings', $stored['detail'] );
	}

	/**
	 * Asserts the failure record is not autoloaded on every request.
	 *
	 * @return void
	 */
	public function test_failure_record_is_not_autoloaded() {
		InstallationFailure::record( new \WP_Error( 'x', 'detail' ) );

		$this->assertArrayNotHasKey( InstallationFailure::OPTION_NAME, wp_load_alloptions() );
	}

	/**
	 * Asserts a failed installation is visible to the SEO health report.
	 *
	 * @return void
	 */
	public function test_failed_installation_appears_in_the_health_report() {
		InstallationFailure::record( new \WP_Error( 'mclogiora_migration_incomplete', 'Migration 2 did not create: wp_mclogiora_strings' ) );

		$report = $this->container->get( \McLogiora\Health\SeoHealthCheck::class )->report();
		$ids    = array_column( $report, 'id' );

		$this->assertContains( 'installation_failed', $ids );
	}
}
