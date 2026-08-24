<?php
/**
 * Core application orchestrator.
 *
 * @package McLogiora
 */

namespace McLogiora\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use McLogiora\Admin\AdminMenu;
use McLogiora\Admin\AdminScreenRegistry;
use McLogiora\Api\Rest\RestApiModule;
use McLogiora\Cli\CliModule;
use McLogiora\Admin\Settings\SettingsManager;
use McLogiora\Assets\AssetLoader;
use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Cache\CacheInterface;
use McLogiora\Cache\ObjectCache;
use McLogiora\Content\ContentExclusionRules;
use McLogiora\Content\ContentTranslationService;
use McLogiora\Content\ContentTranslationServiceInterface;
use McLogiora\Content\ContentTypeRegistry;
use McLogiora\Content\ContentTypeRegistryInterface;
use McLogiora\Content\CustomPostTypeSupportDetector;
use McLogiora\Content\PostSupportDetector;
use McLogiora\Compatibility\BuilderCompatibilityRegistry;
use McLogiora\Compatibility\BuilderDetector;
use McLogiora\Compatibility\CompatibilityDashboard;
use McLogiora\Compatibility\CompatibilityService;
use McLogiora\Compatibility\PluginDetector;
use McLogiora\Compatibility\ThemeDetector;
use McLogiora\Database\DatabaseVersionManager;
use McLogiora\Database\DatabaseTransaction;
use McLogiora\Database\Installer;
use McLogiora\Database\MigrationRegistry;
use McLogiora\Database\MigrationRunner;
use McLogiora\Database\SchemaBuilder;
use McLogiora\Database\TableNames;
use McLogiora\Database\TransactionInterface;
use McLogiora\Database\UuidGenerator;
use McLogiora\Database\VersionChecker;
use McLogiora\Diagnostics\DiagnosticsService;
use McLogiora\Diagnostics\SiteHealthIntegration;
use McLogiora\Admin\SystemStatusDashboard;
use McLogiora\Editors\BlockEditorPanel;
use McLogiora\Editors\ClassicEditorMetabox;
use McLogiora\Editors\SuggestionEditorController;
use McLogiora\Editors\SuggestionEditorState;
use McLogiora\Editors\EditorDetector;
use McLogiora\Editors\EditorFactory;
use McLogiora\Editors\EditorManager;
use McLogiora\Editors\EditorRegistry;
use McLogiora\Editors\EditorTranslationModel;
use McLogiora\Editors\Payload\PayloadAdapterRegistry;
use McLogiora\Editors\TranslationStatusPresenter;
use McLogiora\Health\DatabaseHealthCheck;
use McLogiora\Health\SeoHealthCheck;
use McLogiora\ImportExport\ImportPlanner;
use McLogiora\ImportExport\ImportApplyService;
use McLogiora\ImportExport\ImportAuthorizationInterface;
use McLogiora\ImportExport\ImportOperationExecutor;
use McLogiora\ImportExport\ImportOperationExecutorInterface;
use McLogiora\ImportExport\ImportPlanPreconditionChecker;
use McLogiora\ImportExport\ImportPlanVerifier;
use McLogiora\ImportExport\ImportRollbackCacheInvalidator;
use McLogiora\ImportExport\ObjectLocatorGatewayInterface;
use McLogiora\ImportExport\PackageEncoder;
use McLogiora\ImportExport\PackageExporter;
use McLogiora\ImportExport\PackageParser;
use McLogiora\ImportExport\PackageValidator;
use McLogiora\ImportExport\WordPressObjectLocatorGateway;
use McLogiora\Languages\CachedLanguageRepository;
use McLogiora\Languages\DatabaseLanguageRepository;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageService;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Languages\LocaleValidator;
use McLogiora\Languages\RtlDetector;
use McLogiora\Languages\LanguageManager;
use McLogiora\Localization\DocumentLanguageModule;
use McLogiora\Localization\Localization;
use McLogiora\Logging\NullLogger;
use McLogiora\Relations\CachedTranslationRelationRepository;
use McLogiora\Relations\DatabaseTranslationRelationRepository;
use McLogiora\Relations\MetadataNeedsUpdateDetector;
use McLogiora\Relations\NeedsUpdateDetectorInterface;
use McLogiora\Relations\TranslationManager;
use McLogiora\Relations\TranslationRelationRepositoryInterface;
use McLogiora\Relations\TranslationRelationService;
use McLogiora\Relations\TranslationRelationServiceInterface;
use McLogiora\Setup\SetupWizard;
use McLogiora\Taxonomies\TaxonomyExclusionRules;
use McLogiora\Taxonomies\TaxonomyRegistry;
use McLogiora\Taxonomies\TaxonomyRegistryInterface;
use McLogiora\Taxonomies\TaxonomySupportDetector;
use McLogiora\Taxonomies\TaxonomyTranslationService;
use McLogiora\Taxonomies\TaxonomyTranslationServiceInterface;
use McLogiora\Admin\TranslationActionController;
use McLogiora\Admin\TranslationColumns;
use McLogiora\WordPress\ContentGateway;
use McLogiora\WordPress\ContentGatewayInterface;
use McLogiora\Workflows\ContentTranslationWorkflow;
use McLogiora\Workflows\SourceChangeTracker;
use McLogiora\Workflows\SourceChangeSubscriber;
use McLogiora\Workflows\TaxonomyTranslationWorkflow;
use McLogiora\Workflows\TranslationStatusTransitions;
use McLogiora\Workflows\TranslationWorkflowService;
use McLogiora\Workflows\TranslationWorkflowValidator;
use McLogiora\Admin\MediaTranslationFields;
use McLogiora\Admin\SuggestionAdminController;
use McLogiora\Admin\SuggestionAdminState;
use McLogiora\Admin\StringActionController;
use McLogiora\Admin\StringManager;
use McLogiora\Admin\WidgetTranslationManager;
use McLogiora\Media\DatabaseMediaTranslationRepository;
use McLogiora\Media\MediaTranslationRepositoryInterface;
use McLogiora\Media\MediaTranslationService;
use McLogiora\Menus\MenuTranslationWorkflow;
use McLogiora\Strings\DatabaseStringRepository;
use McLogiora\Strings\ScanScope;
use McLogiora\Strings\StringRegistry;
use McLogiora\Strings\StringRepositoryInterface;
use McLogiora\Strings\StringScanner;
use McLogiora\Strings\StringTranslationService;
use McLogiora\Widgets\DatabaseWidgetTranslationRepository;
use McLogiora\Widgets\WidgetAdapterRegistry;
use McLogiora\Widgets\WidgetTranslationRepositoryInterface;
use McLogiora\Widgets\WidgetTranslationService;
use McLogiora\WordPress\MenuGateway;
use McLogiora\WordPress\MenuGatewayInterface;
use McLogiora\Routing\FrontendTranslationModule;
use McLogiora\Routing\LanguageContext;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Routing\ObjectLanguageRedirect;
use McLogiora\Routing\PermalinkModule;
use McLogiora\Routing\RoutingModule;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Routing\TranslatedUrlGenerator;
use McLogiora\Switcher\LanguageSwitcher;
use McLogiora\Switcher\SwitcherModule;
use McLogiora\Switcher\SwitcherRenderer;
use McLogiora\Admin\InstallationFailureNotice;
use McLogiora\Admin\RoutingSettingsScreen;
use McLogiora\Admin\SuggestionSettingsScreen;
use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\HttpTransport;
use McLogiora\Suggestions\LlmInstructions;
use McLogiora\Suggestions\ModelCache;
use McLogiora\Suggestions\ProviderReadiness;
use McLogiora\Suggestions\ProviderRegistry;
use McLogiora\Suggestions\SuggestionPreviewStore;
use McLogiora\Suggestions\SuggestionSettings;
use McLogiora\Suggestions\TranslationSuggestionApplyService;
use McLogiora\Suggestions\TranslationSuggestionService;
use McLogiora\Suggestions\Providers\AnthropicProvider;
use McLogiora\Suggestions\Providers\DeepLProvider;
use McLogiora\Suggestions\Providers\GeminiProvider;
use McLogiora\Suggestions\Providers\OpenAiProvider;
use McLogiora\Seo\AlternateUrlService;
use McLogiora\Seo\CanonicalService;
use McLogiora\Seo\OpenGraphLocaleService;
use McLogiora\Seo\SeoCompatibilityManager;
use McLogiora\Seo\SeoContext;
use McLogiora\Seo\SeoModule;
use McLogiora\Seo\SitemapIntegration;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin foundation.
 */
final class Application {
	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Absolute plugin file path.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Whether the application has booted.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Absolute plugin file path.
	 */
	private function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;
		$this->container   = new Container();
	}

	/**
	 * Returns the application singleton.
	 *
	 * @param string $plugin_file Absolute plugin file path.
	 * @return self
	 */
	public static function instance( $plugin_file = '' ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $plugin_file );
		}

		return self::$instance;
	}

	/**
	 * Boots foundation services and modules.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->register_services();

		$validator = $this->container->get( EnvironmentValidator::class );

		if ( ! $validator->is_valid() ) {
			add_action( 'admin_notices', array( $validator, 'render_admin_notice' ) );
			$this->booted = true;
			return;
		}

		$modules = new ModuleLoader( $this->container );
		$modules->add( new Localization() );
		$modules->add( new AssetLoader() );
		$modules->add( new SettingsManager() );
		$modules->add( new LanguageManager() );
		$modules->add( new SetupWizard() );
		$modules->add( new TranslationManager() );
		$modules->add( new TranslationActionController() );
		$modules->add( new TranslationColumns() );
		$modules->add( new SourceChangeSubscriber() );
		$modules->add( new StringManager() );
		$modules->add( new WidgetTranslationManager() );
		$modules->add( new StringActionController() );
		$modules->add( new MediaTranslationFields() );
		$modules->add( new RoutingModule() );
		$modules->add( new PermalinkModule() );
		$modules->add( new ObjectLanguageRedirect() );
		$modules->add( new FrontendTranslationModule() );
		$modules->add( new DocumentLanguageModule() );
		$modules->add( $this->container->get( SwitcherModule::class ) );
		$modules->add( new RoutingSettingsScreen() );
		$modules->add( new SuggestionSettingsScreen() );
		$modules->add( new SeoModule() );
		$modules->add( new SitemapIntegration() );
		$modules->add( new RestApiModule() );
		$modules->add( new CliModule() );
		$modules->add( new InstallationFailureNotice() );
		$modules->add( new EditorManager() );
		$modules->add( new BlockEditorPanel() );
		$modules->add( new ClassicEditorMetabox() );
		$modules->add( new SuggestionEditorController() );
		$modules->add( new SuggestionAdminController() );
		$modules->add( new CompatibilityDashboard() );
		$modules->add( new SystemStatusDashboard() );
		$modules->add( new SiteHealthIntegration() );
		$modules->add( new AdminMenu() );
		$modules->register();

		$this->container->set( ModuleLoader::class, $modules );
		$this->booted = true;
	}

	/**
	 * Returns the service container.
	 *
	 * @return Container
	 */
	public function container() {
		return $this->container;
	}

	/**
	 * Registers foundation services.
	 *
	 * @return void
	 */
	private function register_services() {
		$plugin_file = $this->plugin_file;
		global $wpdb;

		$this->container->set(
			Constants::class,
			static function () use ( $plugin_file ) {
				return new Constants( $plugin_file );
			}
		);

		$this->container->set(
			EnvironmentValidator::class,
			static function () {
				return new EnvironmentValidator();
			}
		);

		$this->container->set(
			NullLogger::class,
			static function () {
				return new NullLogger();
			}
		);

		$this->container->set(
			CapabilityRegistry::class,
			static function () {
				return new CapabilityRegistry();
			}
		);

		$this->container->set(
			FeatureFlags::class,
			static function () {
				return new FeatureFlags();
			}
		);

		$this->container->set(
			PluginDetector::class,
			static function () {
				return new PluginDetector();
			}
		);

		$this->container->set(
			ThemeDetector::class,
			static function () {
				return new ThemeDetector();
			}
		);

		$this->container->set(
			BuilderDetector::class,
			static function ( Container $container ) {
				return new BuilderDetector(
					$container->get( PluginDetector::class ),
					$container->get( ThemeDetector::class )
				);
			}
		);

		$this->container->set(
			EditorRegistry::class,
			static function () {
				return new EditorRegistry();
			}
		);

		$this->container->set(
			EditorFactory::class,
			static function ( Container $container ) {
				return new EditorFactory( $container->get( PluginDetector::class ) );
			}
		);

		$this->container->set(
			EditorDetector::class,
			static function ( Container $container ) {
				return new EditorDetector( $container->get( EditorRegistry::class ) );
			}
		);

		$this->container->set(
			TranslationStatusPresenter::class,
			static function () {
				return new TranslationStatusPresenter();
			}
		);

		$this->container->set(
			PayloadAdapterRegistry::class,
			static function () {
				return PayloadAdapterRegistry::with_core_adapters();
			}
		);

		$this->container->set(
			EditorTranslationModel::class,
			static function ( Container $container ) {
				return new EditorTranslationModel(
					$container->get( LanguageServiceInterface::class ),
					$container->get( TranslationRelationServiceInterface::class ),
					$container->get( TranslatedUrlGenerator::class ),
					$container->get( TranslationStatusPresenter::class ),
					$container->get( CapabilityRegistry::class )
				);
			}
		);

		$this->container->set(
			BuilderCompatibilityRegistry::class,
			static function ( Container $container ) {
				return new BuilderCompatibilityRegistry(
					$container->get( PluginDetector::class ),
					$container->get( ThemeDetector::class )
				);
			}
		);

		$this->container->set(
			CompatibilityService::class,
			static function ( Container $container ) {
				return new CompatibilityService(
					$container->get( EditorDetector::class ),
					$container->get( BuilderDetector::class ),
					$container->get( PluginDetector::class ),
					$container->get( ThemeDetector::class )
				);
			}
		);

		$this->container->set(
			AdminScreenRegistry::class,
			static function () {
				return new AdminScreenRegistry();
			}
		);

		$this->container->set(
			TableNames::class,
			static function () use ( $wpdb ) {
				return new TableNames( $wpdb );
			}
		);

		$this->container->set(
			SchemaBuilder::class,
			static function () use ( $wpdb ) {
				return new SchemaBuilder( $wpdb );
			}
		);

		$this->container->set(
			TransactionInterface::class,
			static function () use ( $wpdb ) {
				return new DatabaseTransaction( $wpdb );
			}
		);

		$this->container->set(
			DatabaseVersionManager::class,
			static function () {
				return new DatabaseVersionManager();
			}
		);

		$this->container->set(
			VersionChecker::class,
			static function () {
				return new VersionChecker();
			}
		);

		$this->container->set(
			UuidGenerator::class,
			static function () {
				return new UuidGenerator();
			}
		);

		$this->container->set(
			CacheInterface::class,
			static function () {
				return new ObjectCache();
			}
		);

		$this->container->set(
			MigrationRunner::class,
			static function ( Container $container ) {
				return new MigrationRunner(
					$container->get( SchemaBuilder::class ),
					$container->get( DatabaseVersionManager::class ),
					$container->get( VersionChecker::class ),
					MigrationRegistry::all( $container->get( TableNames::class ) )
				);
			}
		);

		$this->container->set(
			Installer::class,
			static function ( Container $container ) {
				return new Installer( $container->get( MigrationRunner::class ) );
			}
		);

		$this->container->set(
			LanguageRepositoryInterface::class,
			static function ( Container $container ) use ( $wpdb ) {
				return new CachedLanguageRepository(
					new DatabaseLanguageRepository(
						$wpdb,
						$container->get( TableNames::class ),
						$container->get( SchemaBuilder::class )
					),
					$container->get( CacheInterface::class )
				);
			}
		);

		$this->container->set(
			LanguageServiceInterface::class,
			static function ( Container $container ) {
				return new LanguageService(
					$container->get( LanguageRepositoryInterface::class ),
					$container->get( LocaleValidator::class ),
					$container->get( RtlDetector::class )
				);
			}
		);

		$this->container->set(
			LocaleValidator::class,
			static function () {
				return new LocaleValidator();
			}
		);

		$this->container->set(
			RtlDetector::class,
			static function () {
				return new RtlDetector();
			}
		);

		$this->container->set(
			TranslationRelationRepositoryInterface::class,
			static function ( Container $container ) use ( $wpdb ) {
				return new CachedTranslationRelationRepository(
					new DatabaseTranslationRelationRepository(
						$wpdb,
						$container->get( TableNames::class ),
						$container->get( SchemaBuilder::class ),
						$container->get( UuidGenerator::class ),
						$container->get( LanguageRepositoryInterface::class )
					),
					$container->get( CacheInterface::class )
				);
			}
		);

		$this->container->set(
			NeedsUpdateDetectorInterface::class,
			static function () {
				return new MetadataNeedsUpdateDetector();
			}
		);

		$this->container->set(
			TranslationRelationServiceInterface::class,
			static function ( Container $container ) {
				return new TranslationRelationService(
					$container->get( TranslationRelationRepositoryInterface::class ),
					$container->get( NeedsUpdateDetectorInterface::class ),
					$container->get( LanguageServiceInterface::class )
				);
			}
		);

		$this->container->set(
			ContentTypeRegistryInterface::class,
			static function () {
				return new ContentTypeRegistry(
					new PostSupportDetector(),
					new CustomPostTypeSupportDetector(),
					new ContentExclusionRules()
				);
			}
		);

		$this->container->set(
			ContentTranslationServiceInterface::class,
			static function ( Container $container ) {
				return new ContentTranslationService( $container->get( ContentTypeRegistryInterface::class ) );
			}
		);

		$this->container->set(
			TaxonomyRegistryInterface::class,
			static function () {
				return new TaxonomyRegistry(
					new TaxonomySupportDetector(),
					new TaxonomyExclusionRules()
				);
			}
		);

		$this->container->set(
			TaxonomyTranslationServiceInterface::class,
			static function ( Container $container ) {
				return new TaxonomyTranslationService( $container->get( TaxonomyRegistryInterface::class ) );
			}
		);

		$this->container->set(
			DatabaseHealthCheck::class,
			static function ( Container $container ) {
				return new DatabaseHealthCheck(
					$container->get( DatabaseVersionManager::class ),
					$container->get( MigrationRunner::class ),
					$container->get( SchemaBuilder::class ),
					$container->get( TableNames::class ),
					$container->get( LanguageRepositoryInterface::class ),
					$container->get( TranslationRelationRepositoryInterface::class )
				);
			}
		);

		$this->container->set(
			ContentGatewayInterface::class,
			static function () {
				return new ContentGateway();
			}
		);

		$this->container->set(
			TranslationStatusTransitions::class,
			static function () {
				return new TranslationStatusTransitions();
			}
		);

		$this->container->set(
			TranslationWorkflowValidator::class,
			static function ( Container $container ) {
				return new TranslationWorkflowValidator(
					$container->get( ContentGatewayInterface::class ),
					$container->get( LanguageServiceInterface::class ),
					$container->get( TranslationRelationRepositoryInterface::class ),
					$container->get( ContentTypeRegistryInterface::class ),
					$container->get( TaxonomyRegistryInterface::class ),
					$container->get( CapabilityRegistry::class )
				);
			}
		);

		$this->container->set(
			ImportAuthorizationInterface::class,
			static function ( Container $container ) {
				return $container->get( TranslationWorkflowValidator::class );
			}
		);

		$this->container->set(
			ContentTranslationWorkflow::class,
			static function ( Container $container ) {
				return new ContentTranslationWorkflow(
					$container->get( ContentGatewayInterface::class ),
					$container->get( TranslationRelationServiceInterface::class ),
					$container->get( LanguageServiceInterface::class ),
					$container->get( TranslationWorkflowValidator::class ),
					$container->get( PayloadAdapterRegistry::class )
				);
			}
		);

		$this->container->set(
			TaxonomyTranslationWorkflow::class,
			static function ( Container $container ) {
				return new TaxonomyTranslationWorkflow(
					$container->get( ContentGatewayInterface::class ),
					$container->get( TranslationRelationServiceInterface::class ),
					$container->get( LanguageServiceInterface::class ),
					$container->get( TranslationWorkflowValidator::class )
				);
			}
		);

		$this->container->set(
			SourceChangeTracker::class,
			static function ( Container $container ) {
				return new SourceChangeTracker(
					$container->get( TranslationRelationRepositoryInterface::class ),
					$container->get( TranslationStatusTransitions::class )
				);
			}
		);

		$this->container->set(
			TranslationWorkflowService::class,
			static function ( Container $container ) {
				return new TranslationWorkflowService(
					$container->get( ContentTranslationWorkflow::class ),
					$container->get( TaxonomyTranslationWorkflow::class ),
					$container->get( TranslationStatusTransitions::class ),
					$container->get( TranslationRelationRepositoryInterface::class ),
					$container->get( TranslationRelationServiceInterface::class ),
					$container->get( TranslationWorkflowValidator::class )
				);
			}
		);

		$this->container->set(
			MenuGatewayInterface::class,
			static function () {
				return new MenuGateway();
			}
		);

		$this->container->set(
			StringRepositoryInterface::class,
			static function ( Container $container ) use ( $wpdb ) {
				return new DatabaseStringRepository(
					$wpdb,
					$container->get( TableNames::class ),
					$container->get( SchemaBuilder::class )
				);
			}
		);

		$this->container->set(
			StringScanner::class,
			static function () {
				return new StringScanner();
			}
		);

		$this->container->set(
			ScanScope::class,
			static function () {
				return ScanScope::from_wordpress();
			}
		);

		$this->container->set(
			StringRegistry::class,
			static function ( Container $container ) {
				return new StringRegistry(
					$container->get( StringRepositoryInterface::class ),
					$container->get( StringScanner::class ),
					$container->get( ScanScope::class ),
					$container->get( ContentGatewayInterface::class ),
					$container->get( CapabilityRegistry::class )
				);
			}
		);

		$this->container->set(
			StringTranslationService::class,
			static function ( Container $container ) {
				return new StringTranslationService(
					$container->get( StringRepositoryInterface::class ),
					$container->get( CacheInterface::class )
				);
			}
		);

		$this->container->set(
			MediaTranslationRepositoryInterface::class,
			static function ( Container $container ) use ( $wpdb ) {
				return new DatabaseMediaTranslationRepository(
					$wpdb,
					$container->get( TableNames::class ),
					$container->get( SchemaBuilder::class )
				);
			}
		);

		$this->container->set(
			MediaTranslationService::class,
			static function ( Container $container ) {
				return new MediaTranslationService(
					$container->get( MediaTranslationRepositoryInterface::class ),
					$container->get( LanguageServiceInterface::class ),
					$container->get( ContentGatewayInterface::class ),
					$container->get( CapabilityRegistry::class ),
					$container->get( CacheInterface::class )
				);
			}
		);

		$this->container->set(
			MenuTranslationWorkflow::class,
			static function ( Container $container ) {
				return new MenuTranslationWorkflow(
					$container->get( MenuGatewayInterface::class ),
					$container->get( ContentGatewayInterface::class ),
					$container->get( TranslationRelationServiceInterface::class ),
					$container->get( LanguageServiceInterface::class ),
					$container->get( CapabilityRegistry::class )
				);
			}
		);

		$this->container->set(
			WidgetAdapterRegistry::class,
			static function () {
				return WidgetAdapterRegistry::with_core_adapters();
			}
		);

		$this->container->set(
			WidgetTranslationRepositoryInterface::class,
			static function ( Container $container ) use ( $wpdb ) {
				return new DatabaseWidgetTranslationRepository(
					$wpdb,
					$container->get( TableNames::class ),
					$container->get( SchemaBuilder::class )
				);
			}
		);

		$this->container->set(
			WidgetTranslationService::class,
			static function ( Container $container ) {
				return new WidgetTranslationService(
					$container->get( WidgetTranslationRepositoryInterface::class ),
					$container->get( WidgetAdapterRegistry::class ),
					$container->get( LanguageServiceInterface::class ),
					$container->get( ContentGatewayInterface::class ),
					$container->get( CapabilityRegistry::class )
				);
			}
		);

		$this->container->set(
			RoutingSettings::class,
			static function () {
				return new RoutingSettings();
			}
		);

		$this->container->set(
			SuggestionSettings::class,
			static function () {
				return new SuggestionSettings();
			}
		);

		$this->container->set(
			CredentialStore::class,
			static function () {
				return new CredentialStore();
			}
		);

		$this->container->set(
			ModelCache::class,
			static function () {
				return new ModelCache();
			}
		);

		$this->container->set(
			LlmInstructions::class,
			static function () {
				return new LlmInstructions();
			}
		);

		$this->container->set(
			HttpTransport::class,
			static function ( Container $container ) {
				return new HttpTransport( $container->get( SuggestionSettings::class )->timeout() );
			}
		);

		$this->container->set(
			SuggestionAdminState::class,
			static function ( Container $container ) {
				return new SuggestionAdminState(
					$container->get( SuggestionSettings::class ),
					$container->get( ProviderRegistry::class ),
					$container->get( ProviderReadiness::class ),
					$container->get( CapabilityRegistry::class )
				);
			}
		);

		$this->container->set(
			SuggestionEditorState::class,
			static function ( Container $container ) {
				return new SuggestionEditorState(
					$container->get( SuggestionSettings::class ),
					$container->get( ProviderRegistry::class ),
					$container->get( ProviderReadiness::class ),
					$container->get( CapabilityRegistry::class )
				);
			}
		);

		$this->container->set(
			ProviderReadiness::class,
			static function ( Container $container ) {
				return new ProviderReadiness( $container->get( CredentialStore::class ) );
			}
		);

		$this->container->set(
			ProviderRegistry::class,
			static function ( Container $container ) {
				$registry    = new ProviderRegistry();
				$transport   = $container->get( HttpTransport::class );
				$credentials = $container->get( CredentialStore::class );
				$prompts     = $container->get( LlmInstructions::class );

				/*
				 * Every provider is registered on every site and each reports
				 * its own configured state. Registering is not enabling: none
				 * of these touches the network until an owner supplies a
				 * credential and explicitly asks for something.
				 */
				$registry->add( new OpenAiProvider( $transport, $credentials, $prompts ) );
				$registry->add( new AnthropicProvider( $transport, $credentials, $prompts ) );
				$registry->add( new GeminiProvider( $transport, $credentials, $prompts ) );
				$registry->add( new DeepLProvider( $transport, $credentials ) );

				return $registry;
			}
		);

		$this->container->set(
			SuggestionPreviewStore::class,
			static function () {
				return new SuggestionPreviewStore();
			}
		);

		$this->container->set(
			TranslationSuggestionService::class,
			static function ( Container $container ) {
				return new TranslationSuggestionService(
					$container->get( SuggestionSettings::class ),
					$container->get( ProviderRegistry::class )
				);
			}
		);

		$this->container->set(
			TranslationSuggestionApplyService::class,
			static function ( Container $container ) {
				return new TranslationSuggestionApplyService(
					$container->get( SuggestionPreviewStore::class ),
					$container->get( TranslationWorkflowService::class ),
					$container->get( MediaTranslationService::class ),
					$container->get( StringTranslationService::class )
				);
			}
		);

		$this->container->set(
			RuntimeReadiness::class,
			static function () {
				return new RuntimeReadiness();
			}
		);

		$this->container->set(
			LanguageContextInterface::class,
			static function ( Container $container ) {
				return new LanguageContext( $container->get( LanguageServiceInterface::class ) );
			}
		);

		$this->container->set(
			TranslatedUrlGenerator::class,
			static function ( Container $container ) {
				return new TranslatedUrlGenerator(
					$container->get( TranslationRelationServiceInterface::class ),
					$container->get( RoutingSettings::class ),
					$container->get( LanguageContextInterface::class )
				);
			}
		);

		$this->container->set(
			LanguageSwitcher::class,
			static function ( Container $container ) {
				return new LanguageSwitcher(
					$container->get( LanguageContextInterface::class ),
					$container->get( TranslatedUrlGenerator::class ),
					$container->get( RoutingSettings::class )
				);
			}
		);

		$this->container->set(
			SwitcherRenderer::class,
			static function ( Container $container ) {
				return new SwitcherRenderer( $container->get( LanguageSwitcher::class ) );
			}
		);

		$this->container->set(
			SwitcherModule::class,
			static function () {
				return new SwitcherModule();
			}
		);

		$this->container->set(
			SeoContext::class,
			static function ( Container $container ) {
				return new SeoContext(
					$container->get( RuntimeReadiness::class ),
					$container->get( ContentTypeRegistryInterface::class ),
					$container->get( TaxonomyRegistryInterface::class )
				);
			}
		);

		$this->container->set(
			AlternateUrlService::class,
			static function ( Container $container ) {
				return new AlternateUrlService(
					$container->get( LanguageContextInterface::class ),
					$container->get( TranslatedUrlGenerator::class )
				);
			}
		);

		$this->container->set(
			CanonicalService::class,
			static function ( Container $container ) {
				return new CanonicalService(
					$container->get( SeoContext::class ),
					$container->get( AlternateUrlService::class ),
					$container->get( LanguageContextInterface::class )
				);
			}
		);

		$this->container->set(
			OpenGraphLocaleService::class,
			static function ( Container $container ) {
				return new OpenGraphLocaleService(
					$container->get( LanguageContextInterface::class ),
					$container->get( AlternateUrlService::class )
				);
			}
		);

		$this->container->set(
			SeoCompatibilityManager::class,
			static function ( Container $container ) {
				return new SeoCompatibilityManager( $container->get( PluginDetector::class ) );
			}
		);

		$this->container->set(
			SeoHealthCheck::class,
			static function ( Container $container ) {
				return new SeoHealthCheck(
					$container->get( LanguageRepositoryInterface::class ),
					$container->get( SeoCompatibilityManager::class )
				);
			}
		);

		$this->container->set(
			DiagnosticsService::class,
			static function ( Container $container ) {
				return new DiagnosticsService(
					$container->get( Constants::class ),
					$container->get( RuntimeReadiness::class ),
					$container->get( DatabaseVersionManager::class ),
					$container->get( SchemaBuilder::class ),
					$container->get( TableNames::class ),
					$container->get( MigrationRunner::class ),
					$container->get( LanguageRepositoryInterface::class ),
					$container->get( TranslationRelationRepositoryInterface::class ),
					$container->get( RoutingSettings::class ),
					$container->get( SuggestionSettings::class ),
					$container->get( ProviderRegistry::class ),
					$container->get( ProviderReadiness::class ),
					$container->get( EditorDetector::class ),
					$container->get( BuilderCompatibilityRegistry::class )
				);
			}
		);

		/*
		 * The portable package layer. Registered as services and added to no
		 * module: nothing here hooks WordPress, registers a route or a command,
		 * or runs on any request. A transport that needs a package resolves the
		 * exporter or the planner; until one exists, this costs a closure.
		 */
		$this->container->set(
			ObjectLocatorGatewayInterface::class,
			static function () {
				return new WordPressObjectLocatorGateway();
			}
		);

		$this->container->set(
			PackageExporter::class,
			static function ( Container $container ) {
				return new PackageExporter(
					$container->get( LanguageServiceInterface::class ),
					$container->get( TranslationRelationRepositoryInterface::class ),
					$container->get( ObjectLocatorGatewayInterface::class ),
					$container->get( Constants::class )->version()
				);
			}
		);

		$this->container->set(
			PackageEncoder::class,
			static function () {
				return new PackageEncoder();
			}
		);

		$this->container->set(
			PackageParser::class,
			static function () {
				return new PackageParser();
			}
		);

		$this->container->set(
			PackageValidator::class,
			static function ( Container $container ) {
				return new PackageValidator(
					$container->get( RuntimeReadiness::class ),
					$container->get( Constants::class )->version()
				);
			}
		);

		$this->container->set(
			ImportPlanner::class,
			static function ( Container $container ) {
				return new ImportPlanner(
					$container->get( PackageValidator::class ),
					$container->get( LanguageServiceInterface::class ),
					$container->get( TranslationRelationRepositoryInterface::class ),
					$container->get( ObjectLocatorGatewayInterface::class ),
					$container->get( TranslationStatusTransitions::class )
				);
			}
		);

		$this->container->set(
			ImportPlanPreconditionChecker::class,
			static function ( Container $container ) {
				return new ImportPlanPreconditionChecker(
					$container->get( LanguageServiceInterface::class ),
					$container->get( TranslationRelationRepositoryInterface::class ),
					$container->get( ObjectLocatorGatewayInterface::class )
				);
			}
		);

		$this->container->set(
			ImportPlanVerifier::class,
			static function ( Container $container ) {
				return new ImportPlanVerifier(
					$container->get( LanguageServiceInterface::class ),
					$container->get( TranslationRelationRepositoryInterface::class ),
					$container->get( ObjectLocatorGatewayInterface::class )
				);
			}
		);

		$this->container->set(
			ImportOperationExecutorInterface::class,
			static function ( Container $container ) {
				return new ImportOperationExecutor(
					$container->get( LanguageServiceInterface::class ),
					$container->get( TranslationRelationServiceInterface::class )
				);
			}
		);

		$this->container->set(
			ImportApplyService::class,
			static function ( Container $container ) {
				return new ImportApplyService(
					$container->get( ImportAuthorizationInterface::class ),
					$container->get( ImportPlanPreconditionChecker::class ),
					$container->get( ImportOperationExecutorInterface::class ),
					$container->get( ImportPlanVerifier::class ),
					$container->get( TransactionInterface::class ),
					$container->get( ImportRollbackCacheInvalidator::class )
				);
			}
		);

		$this->container->set(
			ImportRollbackCacheInvalidator::class,
			static function ( Container $container ) {
				return new ImportRollbackCacheInvalidator( $container->get( CacheInterface::class ) );
			}
		);
	}
}
