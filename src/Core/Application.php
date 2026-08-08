<?php
/**
 * Core application orchestrator.
 *
 * @package McLogiora
 */

namespace McLogiora\Core;

use McLogiora\Admin\AdminMenu;
use McLogiora\Admin\AdminScreenRegistry;
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
use McLogiora\Compatibility\BuilderDetector;
use McLogiora\Compatibility\CompatibilityDashboard;
use McLogiora\Compatibility\CompatibilityService;
use McLogiora\Compatibility\PluginDetector;
use McLogiora\Compatibility\ThemeDetector;
use McLogiora\Database\DatabaseVersionManager;
use McLogiora\Database\Installer;
use McLogiora\Database\MigrationRunner;
use McLogiora\Database\Migrations\Migration001InitialSchema;
use McLogiora\Database\SchemaBuilder;
use McLogiora\Database\TableNames;
use McLogiora\Database\UuidGenerator;
use McLogiora\Database\VersionChecker;
use McLogiora\Editors\EditorDetector;
use McLogiora\Editors\EditorFactory;
use McLogiora\Editors\EditorManager;
use McLogiora\Editors\EditorRegistry;
use McLogiora\Health\DatabaseHealthCheck;
use McLogiora\Languages\CachedLanguageRepository;
use McLogiora\Languages\DatabaseLanguageRepository;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageService;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Languages\LocaleValidator;
use McLogiora\Languages\RtlDetector;
use McLogiora\Languages\LanguageManager;
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
		$modules->add( new EditorManager() );
		$modules->add( new CompatibilityDashboard() );
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
					array(
						new Migration001InitialSchema( $container->get( TableNames::class ) ),
					)
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
	}
}
