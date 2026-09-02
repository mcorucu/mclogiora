<?php
/**
 * Transport-neutral, read-only diagnostics.
 *
 * @package McLogiora
 */

namespace McLogiora\Diagnostics;

use McLogiora\Compatibility\BuilderCompatibilityRegistry;
use McLogiora\Core\Constants;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Database\DatabaseVersionManager;
use McLogiora\Database\MigrationRunner;
use McLogiora\Database\SchemaBuilder;
use McLogiora\Database\TableNames;
use McLogiora\Editors\EditorDetector;
use McLogiora\ImportExport\PackageFormat;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Relations\TranslationRelationRepositoryInterface;
use McLogiora\Routing\RoutingSettings;
use McLogiora\Suggestions\ProviderReadiness;
use McLogiora\Suggestions\ProviderRegistry;
use McLogiora\Suggestions\SuggestionSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Collects safe scalar projections for operator-facing diagnostics.
 *
 * Collection deliberately owns no cache and performs no writes. Each
 * subsystem is isolated so a missing table or malformed option becomes a
 * finding rather than a fatal error or an excuse for automatic repair.
 */
final class DiagnosticsService {
	const GOOD          = 'good';
	const RECOMMENDED   = 'recommended';
	const CRITICAL      = 'critical';
	const INFORMATIONAL = 'informational';

	/**
	 * Plugin constants.
	 *
	 * @var Constants
	 */
	private $constants;

	/**
	 * Runtime readiness.
	 *
	 * @var RuntimeReadiness
	 */
	private $readiness;

	/**
	 * Database version manager.
	 *
	 * @var DatabaseVersionManager
	 */
	private $database_version;

	/**
	 * Managed table names.
	 *
	 * @var TableNames
	 */
	private $tables;

	/**
	 * Migration runner.
	 *
	 * @var MigrationRunner
	 */
	private $migrations;

	/**
	 * Translation relation repository.
	 *
	 * @var TranslationRelationRepositoryInterface
	 */
	private $relations;

	/**
	 * Routing settings.
	 *
	 * @var RoutingSettings
	 */
	private $routing;

	/**
	 * Suggestion settings.
	 *
	 * @var SuggestionSettings
	 */
	private $suggestions;

	/**
	 * Suggestion providers.
	 *
	 * @var ProviderRegistry
	 */
	private $providers;

	/**
	 * Provider readiness resolver.
	 *
	 * @var ProviderReadiness
	 */
	private $provider_readiness;

	/**
	 * Editor detector.
	 *
	 * @var EditorDetector
	 */
	private $editors;

	/**
	 * Builder compatibility registry.
	 *
	 * @var BuilderCompatibilityRegistry
	 */
	private $builders;

	/**
	 * Table existence callback. Kept injectable so degraded-state tests can
	 * simulate one missing table without damaging the shared qualification DB.
	 *
	 * @var callable
	 */
	private $table_exists;

	/**
	 * Language read callback.
	 *
	 * @var callable
	 */
	private $language_loader;

	/**
	 * Constructor.
	 *
	 * @param Constants                              $constants Plugin constants.
	 * @param RuntimeReadiness                       $readiness Runtime readiness.
	 * @param DatabaseVersionManager                 $database_version Database version.
	 * @param SchemaBuilder                          $schema Schema helper.
	 * @param TableNames                             $tables Table names.
	 * @param MigrationRunner                        $migrations Migration runner.
	 * @param LanguageRepositoryInterface            $languages Language repository.
	 * @param TranslationRelationRepositoryInterface $relations Relation repository.
	 * @param RoutingSettings                        $routing Routing settings.
	 * @param SuggestionSettings                     $suggestions Suggestion settings.
	 * @param ProviderRegistry                       $providers Provider registry.
	 * @param ProviderReadiness                      $provider_readiness Provider readiness.
	 * @param EditorDetector                         $editors Editor detector.
	 * @param BuilderCompatibilityRegistry           $builders Builder registry.
	 * @param callable|null                          $table_exists Optional table probe.
	 * @param callable|null                          $language_loader Optional language read.
	 */
	public function __construct( Constants $constants, RuntimeReadiness $readiness, DatabaseVersionManager $database_version, SchemaBuilder $schema, TableNames $tables, MigrationRunner $migrations, LanguageRepositoryInterface $languages, TranslationRelationRepositoryInterface $relations, RoutingSettings $routing, SuggestionSettings $suggestions, ProviderRegistry $providers, ProviderReadiness $provider_readiness, EditorDetector $editors, BuilderCompatibilityRegistry $builders, $table_exists = null, $language_loader = null ) {
		$this->constants          = $constants;
		$this->readiness          = $readiness;
		$this->database_version   = $database_version;
		$this->tables             = $tables;
		$this->migrations         = $migrations;
		$this->relations          = $relations;
		$this->routing            = $routing;
		$this->suggestions        = $suggestions;
		$this->providers          = $providers;
		$this->provider_readiness = $provider_readiness;
		$this->editors            = $editors;
		$this->builders           = $builders;
		$this->table_exists       = is_callable( $table_exists ) ? $table_exists : array( $schema, 'table_exists' );
		$this->language_loader    = is_callable( $language_loader ) ? $language_loader : array( $languages, 'all' );
	}

	/**
	 * Collects a stable, sanitized diagnostic projection.
	 *
	 * @return array<string,mixed>
	 */
	public function collect() {
		$environment = $this->environment();
		$languages   = $this->language_status();
		$persistence = $this->persistence_status();
		$routing     = $this->routing_status();
		$cache       = $this->cache_status();
		$suggestions = $this->suggestion_status();
		$compat      = $this->compatibility_status();

		$findings = array_merge(
			$this->language_findings( $languages ),
			$this->persistence_findings( $persistence ),
			$this->routing_findings( $routing ),
			$this->suggestion_findings( $suggestions ),
			array(
				$this->finding(
					'object_cache',
					$cache['persistent'] ? self::GOOD : self::INFORMATIONAL,
					__( 'Object cache', 'mclogiora' ),
					$cache['persistent']
						? __( 'A persistent WordPress object cache is active.', 'mclogiora' )
						: __( 'WordPress is using its supported non-persistent object cache.', 'mclogiora' )
				),
			)
		);

		return array(
			'meta'          => array(
				'read_only' => true,
				'network'   => 'none',
				'overall'   => $this->overall_status( $findings ),
			),
			'environment'   => $environment,
			'languages'     => $languages,
			'persistence'   => $persistence,
			'routing'       => $routing,
			'cache'         => $cache,
			'suggestions'   => $suggestions,
			'compatibility' => $compat,
			'import_export' => array(
				'format'         => PackageFormat::FORMAT,
				'format_version' => PackageFormat::VERSION,
				'available'      => true,
			),
			'findings'      => $findings,
		);
	}

	/**
	 * Returns environment values without a phpinfo-style dump.
	 *
	 * @return array<string,mixed>
	 */
	private function environment() {
		global $wpdb;

		$database_version = '';

		try {
			$database_version = is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '';
		} catch ( \Throwable $exception ) {
			$database_version = '';
		}

		return array(
			'plugin_version'    => (string) $this->constants->version(),
			'wordpress_version' => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
			'php_version'       => PHP_VERSION,
			'database_version'  => $database_version,
			'locale'            => function_exists( 'get_locale' ) ? (string) get_locale() : '',
			'multisite'         => function_exists( 'is_multisite' ) && is_multisite(),
			'schema_ready'      => $this->readiness->is_schema_ready(),
		);
	}

	/**
	 * Returns language configuration facts and anomalies.
	 *
	 * @return array<string,mixed>
	 */
	private function language_status() {
		$languages = $this->safe_languages();
		$active    = array_values(
			array_filter(
				$languages,
				static function ( $language ) {
					return $language instanceof Language && $language->is_active();
				}
			)
		);
		$defaults  = array_values(
			array_filter(
				$languages,
				static function ( $language ) {
					return $language instanceof Language && $language->is_default();
				}
			)
		);
		$codes     = array();
		$locales   = array();

		foreach ( $languages as $language ) {
			if ( ! $language instanceof Language ) {
				continue;
			}

			$codes[]   = $language->code();
			$locales[] = $language->locale();
		}

		$default = 1 === count( $defaults ) ? $defaults[0] : null;

		return array(
			'configured_count'   => count( $languages ),
			'active_count'       => count( $active ),
			'default_configured' => null !== $default,
			'default_active'     => $default instanceof Language && $default->is_active(),
			'default_code'       => $default instanceof Language ? $default->code() : '',
			'duplicate_codes'    => $this->duplicates( $codes ),
			'duplicate_locales'  => $this->duplicates( $locales ),
			'persistence_ready'  => $this->table_present( $this->tables->languages() ),
		);
	}

	/**
	 * Returns schema and relation persistence facts.
	 *
	 * @return array<string,mixed>
	 */
	private function persistence_status() {
		$table_definitions = array(
			'languages'           => $this->tables->languages(),
			'translation_groups'  => $this->tables->translation_groups(),
			'translation_items'   => $this->tables->translation_items(),
			'strings'             => $this->tables->strings(),
			'string_translations' => $this->tables->string_translations(),
			'media_translations'  => $this->tables->media_translations(),
			'widget_translations' => $this->tables->widget_translations(),
		);
		$tables            = array();

		foreach ( $table_definitions as $key => $table ) {
			$tables[ $key ] = array(
				'present' => $this->table_present( $table ),
			);
		}

		$migrations_current = false;
		try {
			$migrations_current = (bool) $this->migrations->is_current();
		} catch ( \Throwable $exception ) {
			$migrations_current = false;
		}

		$required_ready = $tables['languages']['present'] && $tables['translation_groups']['present'] && $tables['translation_items']['present'];
		$all_ready      = $required_ready;

		foreach ( $tables as $table ) {
			$all_ready = $all_ready && $table['present'];
		}

		$group_count = null;
		$item_count  = null;

		if ( $tables['translation_groups']['present'] && $tables['translation_items']['present'] ) {
			$group_count = $this->safe_integer( array( $this->relations, 'count_groups' ) );
			$item_count  = $this->safe_integer( array( $this->relations, 'count_items' ) );
		}

		return array(
			'database_version'   => (string) $this->database_version->get_version(),
			'target_version'     => DatabaseVersionManager::CURRENT_VERSION,
			'migrations_current' => $migrations_current,
			'schema_ready'       => $all_ready && $migrations_current,
			'tables'             => $tables,
			'group_count'        => $group_count,
			'item_count'         => $item_count,
			'integrity_scan'     => 'not_scanned',
		);
	}

	/**
	 * Returns routing facts.
	 *
	 * @return array<string,mixed>
	 */
	private function routing_status() {
		$structure = function_exists( 'get_option' ) ? (string) get_option( 'permalink_structure', '' ) : '';

		return array(
			'pretty_permalinks'       => '' !== $structure,
			'url_strategy'            => (string) $this->routing->url_strategy(),
			'default_language_prefix' => $this->routing->default_language_has_prefix(),
		);
	}

	/**
	 * Returns object-cache facts.
	 *
	 * @return array<string,bool>
	 */
	private function cache_status() {
		$persistent = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();

		return array(
			'active'     => true,
			'persistent' => (bool) $persistent,
		);
	}

	/**
	 * Returns local provider configuration facts only.
	 *
	 * @return array<string,mixed>
	 */
	private function suggestion_status() {
		$enabled  = $this->suggestions->is_enabled();
		$selected = $this->suggestions->provider_id();
		$records  = array();

		foreach ( $this->providers->all() as $id => $provider ) {
			$state                   = $this->provider_readiness->state( $provider );
			$records[ (string) $id ] = array(
				'label'                 => (string) $provider->get_label(),
				'credential_configured' => ProviderReadiness::NOT_CONFIGURED !== $state,
				'model_selected'        => ! $provider->requires_model_selection() || '' !== (string) $provider->selected_model(),
				'readiness'             => (string) $state,
				'selected'              => (string) $id === $selected,
			);
		}

		$selected_ready = '' !== $selected && isset( $records[ $selected ] ) && ProviderReadiness::READY === $records[ $selected ]['readiness'];

		return array(
			'enabled'           => $enabled,
			'selected_provider' => $selected,
			'selected_ready'    => $selected_ready,
			'providers'         => $records,
		);
	}

	/**
	 * Returns compatibility metadata without serializing adapter objects.
	 *
	 * @return array<string,mixed>
	 */
	private function compatibility_status() {
		$editors = array();

		try {
			foreach ( $this->editors->detect() as $editor ) {
				$editors[] = array(
					'id'    => (string) $editor->get_id(),
					'label' => (string) $editor->get_label(),
				);
			}
		} catch ( \Throwable $exception ) {
			$editors = array();
		}

		$builders = array();

		try {
			foreach ( $this->builders->all() as $builder ) {
				$builders[] = array(
					'id'                => $builder->id(),
					'label'             => $builder->label(),
					'detected'          => $builder->detected(),
					'qualification'     => $builder->qualification(),
					'strategy'          => $builder->strategy(),
					'installed_version' => $builder->installed_version(),
				);
			}
		} catch ( \Throwable $exception ) {
			$builders = array();
		}

		return array(
			'editors'  => $editors,
			'builders' => $builders,
		);
	}

	/**
	 * Returns language findings.
	 *
	 * @param array<string,mixed> $status Language status.
	 * @return array<int,array<string,string>>
	 */
	private function language_findings( array $status ) {
		$findings = array();

		if ( ! $status['persistence_ready'] ) {
			$findings[] = $this->finding( 'language_persistence', self::CRITICAL, __( 'Language persistence is unavailable', 'mclogiora' ), __( 'The language table could not be found.', 'mclogiora' ) );
		} elseif ( ! $status['default_configured'] || ! $status['default_active'] ) {
			$findings[] = $this->finding( 'default_language', self::CRITICAL, __( 'A valid default language is required', 'mclogiora' ), __( 'Configure one active default language before relying on translated routing.', 'mclogiora' ) );
		} elseif ( ! empty( $status['duplicate_codes'] ) || ! empty( $status['duplicate_locales'] ) ) {
			$findings[] = $this->finding( 'language_anomalies', self::RECOMMENDED, __( 'Language configuration needs review', 'mclogiora' ), __( 'Duplicate language codes or locales were detected.', 'mclogiora' ) );
		} else {
			$findings[] = $this->finding( 'default_language', self::GOOD, __( 'Default language', 'mclogiora' ), __( 'One active default language is configured.', 'mclogiora' ) );
		}

		return $findings;
	}

	/**
	 * Returns persistence findings.
	 *
	 * @param array<string,mixed> $status Persistence status.
	 * @return array<int,array<string,string>>
	 */
	private function persistence_findings( array $status ) {
		return array(
			$this->finding(
				'schema',
				$status['schema_ready'] ? self::GOOD : self::CRITICAL,
				__( 'mcLogiora database schema', 'mclogiora' ),
				$status['schema_ready']
					? __( 'Required tables and migrations are ready.', 'mclogiora' )
					: __( 'Required tables or migrations are not ready. No repair was attempted.', 'mclogiora' )
			),
		);
	}

	/**
	 * Returns routing findings.
	 *
	 * @param array<string,mixed> $status Routing status.
	 * @return array<int,array<string,string>>
	 */
	private function routing_findings( array $status ) {
		return array(
			$this->finding(
				'permalinks',
				$status['pretty_permalinks'] ? self::GOOD : self::RECOMMENDED,
				__( 'Translated routing prerequisites', 'mclogiora' ),
				$status['pretty_permalinks']
					? __( 'Pretty permalinks are enabled.', 'mclogiora' )
					: __( 'Pretty permalinks are disabled; review WordPress permalink settings before using translated paths.', 'mclogiora' )
			),
		);
	}

	/**
	 * Returns suggestion findings.
	 *
	 * @param array<string,mixed> $status Suggestion status.
	 * @return array<int,array<string,string>>
	 */
	private function suggestion_findings( array $status ) {
		if ( ! $status['enabled'] ) {
			return array(
				$this->finding( 'suggestions', self::INFORMATIONAL, __( 'Translation Suggestions', 'mclogiora' ), __( 'Suggestions are disabled. No provider request will be made.', 'mclogiora' ) ),
			);
		}

		if ( '' === $status['selected_provider'] || ! $status['selected_ready'] ) {
			return array(
				$this->finding( 'suggestions', self::RECOMMENDED, __( 'Translation Suggestions need configuration', 'mclogiora' ), __( 'Suggestions are enabled, but the selected provider is not ready. Configure WordPress AI in Settings → Connectors or configure the dedicated translation service.', 'mclogiora' ) ),
			);
		}

		return array(
			$this->finding( 'suggestions', self::GOOD, __( 'Translation Suggestions', 'mclogiora' ), __( 'Suggestions are enabled and the selected provider is ready.', 'mclogiora' ) ),
		);
	}

	/**
	 * Reads all languages safely.
	 *
	 * @return Language[]
	 */
	private function safe_languages() {
		try {
			$languages = call_user_func( $this->language_loader );

			return is_array( $languages ) ? $languages : array();
		} catch ( \Throwable $exception ) {
			return array();
		}
	}

	/**
	 * Probes one table without leaking database errors.
	 *
	 * @param string $table Table name from TableNames.
	 * @return bool
	 */
	private function table_present( $table ) {
		try {
			return (bool) call_user_func( $this->table_exists, $table );
		} catch ( \Throwable $exception ) {
			return false;
		}
	}

	/**
	 * Reads a non-negative count safely.
	 *
	 * @param callable $callback Count callback.
	 * @return int|null
	 */
	private function safe_integer( $callback ) {
		try {
			return absint( call_user_func( $callback ) );
		} catch ( \Throwable $exception ) {
			return null;
		}
	}

	/**
	 * Finds repeated scalar values.
	 *
	 * @param string[] $values Values.
	 * @return string[]
	 */
	private function duplicates( array $values ) {
		$counts = array_count_values( array_map( 'strval', $values ) );

		return array_keys(
			array_filter(
				$counts,
				static function ( $count ) {
					return $count > 1;
				}
			)
		);
	}

	/**
	 * Builds one sanitized finding.
	 *
	 * @param string $id Finding ID.
	 * @param string $status Finding status.
	 * @param string $label Finding label.
	 * @param string $detail Finding detail.
	 * @return array<string,string>
	 */
	private function finding( $id, $status, $label, $detail ) {
		return array(
			'id'     => (string) $id,
			'status' => (string) $status,
			'label'  => (string) $label,
			'detail' => (string) $detail,
		);
	}

	/**
	 * Computes the highest severity without exposing exception details.
	 *
	 * @param array<int,array<string,string>> $findings Findings.
	 * @return string
	 */
	private function overall_status( array $findings ) {
		$rank = array(
			self::CRITICAL      => 3,
			self::RECOMMENDED   => 2,
			self::GOOD          => 1,
			self::INFORMATIONAL => 0,
		);
		$best = self::GOOD;

		foreach ( $findings as $finding ) {
			$status = isset( $finding['status'] ) ? $finding['status'] : self::INFORMATIONAL;

			if ( isset( $rank[ $status ] ) && $rank[ $status ] > $rank[ $best ] ) {
				$best = $status;
			}
		}

		return $best;
	}
}
