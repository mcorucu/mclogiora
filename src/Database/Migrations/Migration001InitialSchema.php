<?php
/**
 * Initial database schema migration.
 *
 * @package McLogiora
 */

namespace McLogiora\Database\Migrations;

use McLogiora\Database\MigrationInterface;
use McLogiora\Database\MigrationPostconditions;
use McLogiora\Database\SchemaBuilder;
use McLogiora\Database\TableNames;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the initial language and relation tables.
 */
final class Migration001InitialSchema implements MigrationInterface {
	use MigrationPostconditions;

	/**
	 * Table names.
	 *
	 * @var TableNames
	 */
	private $tables;

	/**
	 * Constructor.
	 *
	 * @param TableNames $tables Table names.
	 */
	public function __construct( TableNames $tables ) {
		$this->tables = $tables;
	}

	/**
	 * Returns the target database version.
	 *
	 * @return string
	 */
	public function version() {
		return '1';
	}

	/**
	 * Returns the tables this migration must have created to be complete.
	 *
	 * @return string[]
	 */
	public function expected_tables() {
		return array(
			$this->tables->languages(),
			$this->tables->translation_groups(),
			$this->tables->translation_items(),
		);
	}

	/**
	 * Runs the migration and verifies its postconditions.
	 *
	 * @param SchemaBuilder $schema_builder Schema builder.
	 * @return true|\WP_Error
	 */
	public function up( SchemaBuilder $schema_builder ) {
		$charset = $schema_builder->charset_collate();

		$schema_builder->apply(
			array(
				$this->languages_table_sql( $charset ),
				$this->translation_groups_table_sql( $charset ),
				$this->translation_items_table_sql( $charset ),
			)
		);

		return $this->verify_tables( $schema_builder, $this->expected_tables(), $this->version() );
	}

	/**
	 * Languages table SQL.
	 *
	 * @param string $charset Charset/collation SQL.
	 * @return string
	 */
	private function languages_table_sql( $charset ) {
		$table = $this->tables->languages();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			language_code varchar(20) NOT NULL,
			locale varchar(20) NOT NULL,
			slug varchar(32) NOT NULL,
			native_name varchar(191) NOT NULL,
			english_name varchar(191) NOT NULL,
			text_direction varchar(3) NOT NULL DEFAULT 'ltr',
			status varchar(20) NOT NULL DEFAULT 'inactive',
			fallback_language_code varchar(20) DEFAULT NULL,
			sort_order int(11) NOT NULL DEFAULT 0,
			is_default tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY language_code (language_code),
			UNIQUE KEY locale (locale),
			KEY status (status),
			KEY sort_order (sort_order),
			KEY updated_at (updated_at)
		) {$charset};";
	}

	/**
	 * Translation groups table SQL.
	 *
	 * @param string $charset Charset/collation SQL.
	 * @return string
	 */
	private function translation_groups_table_sql( $charset ) {
		$table = $this->tables->translation_groups();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			group_uuid char(36) NOT NULL,
			source_content_type varchar(32) NOT NULL,
			source_content_id varchar(191) NOT NULL,
			source_language varchar(20) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY group_uuid (group_uuid),
			KEY source_lookup (source_content_type, source_content_id, source_language),
			KEY source_language (source_language),
			KEY status (status),
			KEY updated_at (updated_at)
		) {$charset};";
	}

	/**
	 * Translation items table SQL.
	 *
	 * @param string $charset Charset/collation SQL.
	 * @return string
	 */
	private function translation_items_table_sql( $charset ) {
		$table = $this->tables->translation_items();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			group_uuid char(36) NOT NULL,
			content_type varchar(32) NOT NULL,
			content_id varchar(191) NOT NULL,
			language_code varchar(20) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'missing',
			is_original tinyint(1) NOT NULL DEFAULT 0,
			source_hash varchar(64) DEFAULT NULL,
			translated_source_hash varchar(64) DEFAULT NULL,
			source_modified_at datetime DEFAULT NULL,
			translation_modified_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY group_uuid (group_uuid),
			KEY language_code (language_code),
			KEY content_lookup (content_type, content_id),
			KEY status (status),
			KEY updated_at (updated_at),
			UNIQUE KEY content_language (content_type, content_id, language_code)
		) {$charset};";
	}
}
