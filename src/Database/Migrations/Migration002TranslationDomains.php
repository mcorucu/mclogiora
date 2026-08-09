<?php
/**
 * String, media, and widget translation schema migration.
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
 * Creates the Phase 11 string, media, and widget translation tables.
 *
 * These domains do not fit the generic translation group model. A group
 * relates WordPress objects to each other, but a string has no WordPress
 * object, media translation attaches language-specific text to a single
 * shared attachment, and a widget translation stores named fields rather
 * than an object relation. Forcing them into groups would mean inventing
 * synthetic object IDs, so each gets a purpose-built table instead.
 *
 * Menus are the exception and deliberately reuse the existing relation
 * model: a WordPress menu is a term and its items are posts, so both are
 * already representable as translation groups.
 *
 * Runs through dbDelta via SchemaBuilder, so it is idempotent and safe on
 * both fresh installs and upgrades. Migration001 is never edited.
 */
final class Migration002TranslationDomains implements MigrationInterface {
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
		return '2';
	}

	/**
	 * Returns the tables this migration must have created to be complete.
	 *
	 * @return string[]
	 */
	public function expected_tables() {
		return array(
			$this->tables->strings(),
			$this->tables->string_translations(),
			$this->tables->media_translations(),
			$this->tables->widget_translations(),
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
				$this->strings_table_sql( $charset ),
				$this->string_translations_table_sql( $charset ),
				$this->media_translations_table_sql( $charset ),
				$this->widget_translations_table_sql( $charset ),
			)
		);

		return $this->verify_tables( $schema_builder, $this->expected_tables(), $this->version() );
	}

	/**
	 * Registered source strings table SQL.
	 *
	 * The string_hash column is the stable identity of a source string, derived
	 * from the source text, text domain, and context together. It is unique
	 * so that rescanning is idempotent: a string that is discovered again
	 * updates last_seen_at rather than creating a duplicate row.
	 *
	 * source_text is longtext because translatable strings can be long, so
	 * the hash rather than the text carries the unique index.
	 *
	 * @param string $charset Charset/collation SQL.
	 * @return string
	 */
	private function strings_table_sql( $charset ) {
		$table = $this->tables->strings();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			string_hash char(40) NOT NULL,
			source_text longtext NOT NULL,
			text_domain varchar(191) NOT NULL DEFAULT '',
			context varchar(191) NOT NULL DEFAULT '',
			source_type varchar(20) NOT NULL DEFAULT 'manual',
			source_reference varchar(191) NOT NULL DEFAULT '',
			source_line int(11) unsigned NOT NULL DEFAULT 0,
			is_stale tinyint(1) NOT NULL DEFAULT 0,
			first_seen_at datetime DEFAULT NULL,
			last_seen_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY string_hash (string_hash),
			KEY text_domain (text_domain),
			KEY source_type (source_type),
			KEY is_stale (is_stale)
		) {$charset};";
	}

	/**
	 * String translations table SQL.
	 *
	 * One row per string per language, enforced by a unique key so a
	 * language slot cannot be filled twice.
	 *
	 * @param string $charset Charset/collation SQL.
	 * @return string
	 */
	private function string_translations_table_sql( $charset ) {
		$table = $this->tables->string_translations();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			string_id bigint(20) unsigned NOT NULL,
			language_code varchar(20) NOT NULL,
			translated_text longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY string_language (string_id,language_code),
			KEY language_code (language_code),
			KEY status (status)
		) {$charset};";
	}

	/**
	 * Media metadata translations table SQL.
	 *
	 * A plugin-owned table is used rather than postmeta. Four translated
	 * fields per language per attachment would otherwise mean four unindexed
	 * postmeta rows each, and looking up "the alt text for this attachment in
	 * this language" would become a meta_key LIKE scan. A single indexed row
	 * keyed by attachment and language answers that in one lookup, keeps the
	 * fields together, and leaves postmeta untouched so uninstall is a clean
	 * table drop rather than a meta sweep.
	 *
	 * @param string $charset Charset/collation SQL.
	 * @return string
	 */
	private function media_translations_table_sql( $charset ) {
		$table = $this->tables->media_translations();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			language_code varchar(20) NOT NULL,
			translated_title text NOT NULL,
			translated_alt_text text NOT NULL,
			translated_caption text NOT NULL,
			translated_description longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_language (attachment_id,language_code),
			KEY language_code (language_code)
		) {$charset};";
	}

	/**
	 * Widget translations table SQL.
	 *
	 * The translated_fields column holds a JSON object of the named fields an
	 * adapter declared as translatable. The field set differs per widget type, so
	 * a column per field is not possible; the adapter owns the shape and
	 * validates it on read and write.
	 *
	 * The source widget instance is never modified, so this table is additive
	 * only and removing it restores the site exactly.
	 *
	 * @param string $charset Charset/collation SQL.
	 * @return string
	 */
	private function widget_translations_table_sql( $charset ) {
		$table = $this->tables->widget_translations();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			widget_key varchar(191) NOT NULL,
			adapter_id varchar(64) NOT NULL,
			language_code varchar(20) NOT NULL,
			translated_fields longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY widget_language (widget_key,language_code),
			KEY adapter_id (adapter_id),
			KEY language_code (language_code)
		) {$charset};";
	}
}
