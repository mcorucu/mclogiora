<?php
/**
 * Diagnostics service tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Compatibility\BuilderCompatibilityRegistry;
use McLogiora\Compatibility\PluginDetector;
use McLogiora\Compatibility\ThemeDetector;
use McLogiora\Core\Constants;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Database\DatabaseVersionManager;
use McLogiora\Database\MigrationRunner;
use McLogiora\Database\SchemaBuilder;
use McLogiora\Database\TableNames;
use McLogiora\Database\VersionChecker;
use McLogiora\Diagnostics\DiagnosticsService;
use McLogiora\Editors\EditorDetector;
use McLogiora\Editors\EditorRegistry;
use McLogiora\Languages\InMemoryLanguageRepository;
use McLogiora\Relations\InMemoryTranslationRelationRepository;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\LlmInstructions;
use McLogiora\Suggestions\ProviderReadiness;
use McLogiora\Suggestions\ProviderRegistry;
use McLogiora\Suggestions\Providers\OpenAiProvider;
use McLogiora\Suggestions\SuggestionSettings;
use McLogiora\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Proves the shared projection is safe without requiring a real database.
 */
final class DiagnosticsServiceTest extends TestCase {
	/** @var array<string,mixed> */
	private $wpdb;

	/** @var CredentialStore */
	private $credentials;

	/**
	 * Defines the plugin constant used by Constants in the unit-only bootstrap.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'MCLOGIORA_VERSION' ) ) {
			define( 'MCLOGIORA_VERSION', '0.15.0' );
		}

		$this->wpdb = (object) array( 'prefix' => 'wp_' );
		$this->credentials = new CredentialStore();
		update_option( DatabaseVersionManager::OPTION_NAME, '2', false );
	}

	/**
	 * Clears options used by diagnostics.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		delete_option( DatabaseVersionManager::OPTION_NAME );
		delete_option( SuggestionSettings::OPTION_ENABLED );
		delete_option( SuggestionSettings::OPTION_PROVIDER );
		$this->credentials->remove( 'openai' );
		delete_option( 'mclogiora_suggestion_model_openai' );
		parent::tearDown();
	}

	/**
	 * Asserts healthy collection is stable and contains only projections.
	 *
	 * @return void
	 */
	public function test_healthy_projection_is_stable_and_scalar_safe() {
		$service = $this->service();
		$first   = $service->collect();
		$second  = $service->collect();

		$this->assertSame( $first, $second );
		$this->assertTrue( $first['meta']['read_only'] );
		$this->assertSame( 'none', $first['meta']['network'] );
		$this->assertSame( 3, $first['languages']['configured_count'] );
		$this->assertSame( 'en', $first['languages']['default_code'] );
		$this->assertSame( 3, $first['persistence']['group_count'] );
		$this->assertSame( 7, $first['persistence']['item_count'] );
		$this->assertSame( 'mclogiora/translation-package', $first['import_export']['format'] );
		$this->assertStringNotContainsString( 'sk-unit-secret', serialize( $first ) );
	}

	/**
	 * Asserts one missing table degrades the report rather than throwing.
	 *
	 * @return void
	 */
	public function test_missing_table_is_a_degraded_finding_without_repair() {
		$service = $this->service(
			static function ( $table ) {
				return false !== strpos( $table, 'translation_items' ) ? false : true;
			}
		);
		$report = $service->collect();

		$this->assertSame( DiagnosticsService::CRITICAL, $report['meta']['overall'] );
		$this->assertFalse( $report['persistence']['schema_ready'] );
		$this->assertNull( $report['persistence']['item_count'] );
		$this->assertContains( 'schema', array_column( $report['findings'], 'id' ) );
	}

	/**
	 * Asserts disabled suggestions are informational and enabled incomplete
	 * suggestions are recommended, without invoking a provider transport.
	 *
	 * @return void
	 */
	public function test_suggestion_states_are_local_and_conservative() {
		$settings = new SuggestionSettings();
		$providers = new ProviderRegistry();
		$providers->add( new OpenAiProvider( new FakeTransport(), $this->credentials, new LlmInstructions() ) );

		$disabled = $this->service( null, $providers, $settings )->collect();
		$this->assertFalse( $disabled['suggestions']['enabled'] );
		$this->assertSame( DiagnosticsService::INFORMATIONAL, $this->finding( $disabled, 'suggestions' )['status'] );

		$settings->set_enabled( true );
		$settings->set_provider( 'openai' );
		$this->credentials->save( 'openai', 'sk-unit-secret' );
		$incomplete = $this->service( null, $providers, $settings )->collect();

		$this->assertSame( DiagnosticsService::RECOMMENDED, $this->finding( $incomplete, 'suggestions' )['status'] );
		$this->assertFalse( $incomplete['suggestions']['selected_ready'] );
		$this->assertStringNotContainsString( 'FakeTransport', serialize( $incomplete ) );
		$this->assertStringNotContainsString( 'sk-unit-secret', serialize( $incomplete ) );
	}

	/**
	 * Asserts malformed language state is represented as a finding.
	 *
	 * @return void
	 */
	public function test_invalid_language_state_is_reported_without_serializing_entities() {
		$languages = static function () {
			return array(
				new \McLogiora\Languages\Language( 'en', 'en_US', 'English', 'English', 'ltr', \McLogiora\Languages\LanguageStatus::INACTIVE, 0, true ),
			);
		};
		$report = $this->service( null, null, null, $languages )->collect();

		$this->assertSame( DiagnosticsService::CRITICAL, $this->finding( $report, 'default_language' )['status'] );
		$this->assertStringNotContainsString( 'McLogiora\\Languages\\', serialize( $report ) );
	}

	/**
	 * Builds a service with a narrow table-probe seam.
	 *
	 * @param callable|null          $table_exists Table probe.
	 * @param ProviderRegistry|null  $providers Provider registry.
	 * @param SuggestionSettings|null $settings Suggestion settings.
	 * @param callable|null           $language_loader Language read callback.
	 * @return DiagnosticsService
	 */
	private function service( $table_exists = null, $providers = null, $settings = null, $language_loader = null ) {
		$schema      = new SchemaBuilder( $this->wpdb );
		$tables      = new TableNames( $this->wpdb );
		$version     = new DatabaseVersionManager();
		$migrations  = new MigrationRunner( $schema, $version, new VersionChecker(), array() );
		$credentials = $this->credentials;
		$providers   = $providers instanceof ProviderRegistry ? $providers : new ProviderRegistry();
		$settings    = $settings instanceof SuggestionSettings ? $settings : new SuggestionSettings();

		return new DiagnosticsService(
			new Constants( __FILE__ ),
			new RuntimeReadiness(),
			$version,
			$schema,
			$tables,
			$migrations,
			new InMemoryLanguageRepository(),
			new InMemoryTranslationRelationRepository(),
			new RoutingSettings(),
			$settings,
			$providers,
			new ProviderReadiness( $credentials ),
			new EditorDetector( new EditorRegistry() ),
			new BuilderCompatibilityRegistry( new PluginDetector(), new ThemeDetector() ),
			$table_exists ? $table_exists : static function () { return true; },
			$language_loader
		);
	}

	/**
	 * Finds a finding by ID.
	 *
	 * @param array<string,mixed> $report Report.
	 * @param string              $id Finding ID.
	 * @return array<string,string>
	 */
	private function finding( array $report, $id ) {
		foreach ( $report['findings'] as $finding ) {
			if ( $finding['id'] === $id ) {
				return $finding;
			}
		}

		return array();
	}
}
