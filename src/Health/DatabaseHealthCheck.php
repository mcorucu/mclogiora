<?php
/**
 * Database health check foundation.
 *
 * @package McLogiora
 */

namespace McLogiora\Health;

use McLogiora\Database\DatabaseVersionManager;
use McLogiora\Database\MigrationRunner;
use McLogiora\Database\SchemaBuilder;
use McLogiora\Database\TableNames;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Relations\TranslationRelationRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Provides internal database health status.
 */
final class DatabaseHealthCheck {
	/**
	 * Database version manager.
	 *
	 * @var DatabaseVersionManager
	 */
	private $version_manager;

	/**
	 * Migration runner.
	 *
	 * @var MigrationRunner
	 */
	private $migration_runner;

	/**
	 * Schema builder.
	 *
	 * @var SchemaBuilder
	 */
	private $schema_builder;

	/**
	 * Table names.
	 *
	 * @var TableNames
	 */
	private $tables;

	/**
	 * Language repository.
	 *
	 * @var LanguageRepositoryInterface
	 */
	private $languages;

	/**
	 * Translation relation repository.
	 *
	 * @var TranslationRelationRepositoryInterface
	 */
	private $relations;

	/**
	 * Constructor.
	 *
	 * @param DatabaseVersionManager                 $version_manager Version manager.
	 * @param MigrationRunner                        $migration_runner Migration runner.
	 * @param SchemaBuilder                          $schema_builder Schema helper.
	 * @param TableNames                             $tables Table names.
	 * @param LanguageRepositoryInterface            $languages Language repository.
	 * @param TranslationRelationRepositoryInterface $relations Relation repository.
	 */
	public function __construct( DatabaseVersionManager $version_manager, MigrationRunner $migration_runner, SchemaBuilder $schema_builder, TableNames $tables, LanguageRepositoryInterface $languages, TranslationRelationRepositoryInterface $relations ) {
		$this->version_manager  = $version_manager;
		$this->migration_runner = $migration_runner;
		$this->schema_builder   = $schema_builder;
		$this->tables           = $tables;
		$this->languages        = $languages;
		$this->relations        = $relations;
	}

	/**
	 * Returns health data.
	 *
	 * @return array<string, mixed>
	 */
	public function status() {
		$tables = array();

		foreach ( $this->tables->all() as $table ) {
			$tables[ $table ] = $this->schema_builder->table_exists( $table );
		}

		return array(
			'database_version'            => $this->version_manager->get_version(),
			'target_version'              => DatabaseVersionManager::CURRENT_VERSION,
			'migrations_current'          => $this->migration_runner->is_current(),
			'tables'                      => $tables,
			'total_languages'             => count( $this->languages->all() ),
			'active_languages'            => count( $this->languages->active() ),
			'default_language_configured' => null !== $this->languages->default_language(),
			'translation_group_count'     => $this->relations->count_groups(),
			'translation_item_count'      => $this->relations->count_items(),
			'orphan_relation_checks'      => 'placeholder',
			'duplicate_relation_checks'   => 'placeholder',
		);
	}
}
