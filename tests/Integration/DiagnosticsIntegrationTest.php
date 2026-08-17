<?php
/**
 * Diagnostics and Site Health integration tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Admin\AdminScreenRegistry;
use McLogiora\Core\Application;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Database\Installer;
use McLogiora\Diagnostics\DiagnosticsService;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Suggestions\SuggestionSettings;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Exercises the real application wiring and native WordPress filters.
 */
final class DiagnosticsIntegrationTest extends WP_UnitTestCase {
	/** @var \McLogiora\Core\Container */
	private $container;

	/**
	 * Installs the real schema and prepares a healthy site.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();
		delete_option( 'mclogiora_db_version' );
		$this->container->get( Installer::class )->install();
		$this->container->get( RuntimeReadiness::class )->reset();

		$languages = $this->container->get( LanguageRepositoryInterface::class );
		if ( ! $languages->find_by_code( 'en' ) instanceof Language ) {
			$languages->create( new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, true ) );
		}

		if ( ! $languages->find_by_code( 'tr' ) instanceof Language ) {
			$languages->create( new Language( 'tr', 'tr_TR', 'Türkçe', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ) );
		}

		if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
			$this->set_permalink_structure( '/%postname%/' );
		}
		delete_option( SuggestionSettings::OPTION_ENABLED );
		delete_option( SuggestionSettings::OPTION_PROVIDER );
		delete_option( RoutingSettings::OPTION_NAME );
	}

	/**
	 * Asserts the shared report is available through the real container.
	 *
	 * @return void
	 */
	public function test_real_collector_reports_healthy_state() {
		$service = $this->container->get( DiagnosticsService::class );
		$report  = $service->collect();

		$this->assertTrue( $report['meta']['read_only'] );
		$this->assertSame( 'none', $report['meta']['network'] );
		$this->assertTrue( $report['persistence']['schema_ready'] );
		$this->assertTrue( $report['languages']['default_active'] );
		$this->assertSame( 'en', $report['languages']['default_code'] );
		$this->assertSame( 'disabled', $report['suggestions']['enabled'] ? 'enabled' : 'disabled' );
		$this->assertSame( 'mclogiora/translation-package', $report['import_export']['format'] );
	}

	/**
	 * Asserts Site Health receives the sanitized section and actionable tests.
	 *
	 * @return void
	 */
	public function test_site_health_filters_receive_real_diagnostics() {
		$info = apply_filters( 'debug_information', array() );
		$this->assertArrayHasKey( 'mclogiora', $info );
		$this->assertArrayHasKey( 'version', $info['mclogiora']['fields'] );
		$this->assertFalse( $info['mclogiora']['fields']['version']['private'] );

		$tests = apply_filters( 'site_status_tests', array( 'direct' => array(), 'async' => array() ) );
		$this->assertArrayHasKey( 'mclogiora_default_language', $tests['direct'] );
		$this->assertArrayHasKey( 'mclogiora_schema', $tests['direct'] );
		$this->assertArrayHasKey( 'mclogiora_permalinks', $tests['direct'] );
		$this->assertArrayNotHasKey( 'mclogiora_suggestions', $tests['direct'], 'Disabled suggestions are not a health failure.' );

		$result = call_user_func( $tests['direct']['mclogiora_schema']['test'] );
		$this->assertSame( 'good', $result['status'] );
	}

	/**
	 * Asserts the real System Status admin screen renders without mutation.
	 *
	 * @return void
	 */
	public function test_system_status_screen_is_registered_authorized_and_read_only() {
		$screens = $this->container->get( AdminScreenRegistry::class )->all();
		$screen  = null;

		foreach ( $screens as $candidate ) {
			if ( 'mclogiora-system-status' === $candidate->slug() ) {
				$screen = $candidate;
				break;
			}
		}

		$this->assertNotNull( $screen );
		$this->assertSame( 'manage_options', $screen->capability() );

		$options_before = get_option( 'mclogiora_db_version' );
		ob_start();
		call_user_func( $screen->callback() );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'System Status', $html );
		$this->assertStringContainsString( 'read-only', strtolower( $html ) );
		$this->assertSame( $options_before, get_option( 'mclogiora_db_version' ) );
		$this->assertStringNotContainsString( 'api_key', strtolower( $html ) );
	}

	/**
	 * Asserts repeated collection and Site Health evaluation make no HTTP call
	 * and leave the language/options state unchanged.
	 *
	 * @return void
	 */
	public function test_diagnostics_are_zero_network_and_zero_mutation() {
		$requests = 0;
		$probe = static function ( $response ) use ( &$requests ) {
				++$requests;

				return $response;
		};
		add_filter( 'pre_http_request', $probe, 10, 3 );

		$before = array(
			'languages' => get_option( 'mclogiora_db_version' ),
			'routing'   => get_option( RoutingSettings::OPTION_NAME ),
			'suggest'   => get_option( SuggestionSettings::OPTION_ENABLED ),
		);
		$first  = $this->container->get( DiagnosticsService::class )->collect();
		$second = $this->container->get( DiagnosticsService::class )->collect();
		apply_filters( 'debug_information', array() );
		apply_filters( 'site_status_tests', array( 'direct' => array(), 'async' => array() ) );
		$after = array(
			'languages' => get_option( 'mclogiora_db_version' ),
			'routing'   => get_option( RoutingSettings::OPTION_NAME ),
			'suggest'   => get_option( SuggestionSettings::OPTION_ENABLED ),
		);

		remove_filter( 'pre_http_request', $probe, 10 );
		$this->assertSame( $first, $second );
		$this->assertSame( $before, $after );
		$this->assertSame( 0, $requests );
	}

	/**
	 * Asserts the historical status route remains unregistered.
	 *
	 * @return void
	 */
	public function test_status_route_is_not_registered() {
		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->assertArrayNotHasKey( '/mclogiora/v1/status', $wp_rest_server->get_routes() );
		$wp_rest_server = null;
	}
}
