<?php
/**
 * Database-backed string repository.
 *
 * @package McLogiora
 */

namespace McLogiora\Strings;

use McLogiora\Database\SchemaBuilder;
use McLogiora\Database\TableNames;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes registered strings and their translations.
 *
 * Every query assigns wpdb to a local variable before use so that WPCS can
 * recognise prepare() calls and machine-check this file from the start. The
 * only suppressions are per-query, for table-name interpolation, which MySQL
 * cannot parameterize. Table names come from TableNames as $wpdb->prefix plus
 * a hard-coded suffix and never contain request data.
 */
final class DatabaseStringRepository implements StringRepositoryInterface {
	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Table names.
	 *
	 * @var TableNames
	 */
	private $tables;

	/**
	 * Schema builder.
	 *
	 * @var SchemaBuilder
	 */
	private $schema_builder;

	/**
	 * Constructor.
	 *
	 * @param \wpdb         $wpdb WordPress database object.
	 * @param TableNames    $tables Table names.
	 * @param SchemaBuilder $schema_builder Schema helper.
	 */
	public function __construct( $wpdb, TableNames $tables, SchemaBuilder $schema_builder ) {
		$this->wpdb           = $wpdb;
		$this->tables         = $tables;
		$this->schema_builder = $schema_builder;
	}

	/**
	 * Returns whether the string tables exist.
	 *
	 * @return bool
	 */
	private function tables_ready() {
		return $this->schema_builder->table_exists( $this->tables->strings() )
			&& $this->schema_builder->table_exists( $this->tables->string_translations() );
	}

	/**
	 * Registers a source string, or refreshes it if already known.
	 *
	 * @param StringSource $source Source string.
	 * @return StringSource|\WP_Error
	 */
	public function register( StringSource $source ) {
		if ( ! $this->tables_ready() ) {
			return new \WP_Error( 'mclogiora_string_tables_missing', __( 'The string tables are not available.', 'mclogiora' ) );
		}

		$db    = $this->wpdb;
		$table = $this->tables->strings();
		$now   = current_time( 'mysql', true );

		$existing = $this->find_by_hash( $source->hash() );

		if ( $existing instanceof StringSource ) {
			$db->update(
				$table,
				array(
					'source_type'      => $source->source_type(),
					'source_reference' => $source->source_reference(),
					'source_line'      => $source->source_line(),
					'is_stale'         => 0,
					'last_seen_at'     => $now,
				),
				array( 'string_hash' => $source->hash() ),
				array( '%s', '%s', '%d', '%d', '%s' ),
				array( '%s' )
			);

			return $this->load_by_hash( $source->hash() );
		}

		$inserted = $db->insert(
			$table,
			array(
				'string_hash'      => $source->hash(),
				'source_text'      => $source->text(),
				'text_domain'      => $source->text_domain(),
				'context'          => $source->context(),
				'source_type'      => $source->source_type(),
				'source_reference' => $source->source_reference(),
				'source_line'      => $source->source_line(),
				'is_stale'         => 0,
				'first_seen_at'    => $now,
				'last_seen_at'     => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'mclogiora_string_register_failed', __( 'The string could not be registered.', 'mclogiora' ) );
		}

		$stored = $this->load_by_hash( $source->hash() );

		if ( ! $stored instanceof StringSource ) {
			return new \WP_Error(
				'mclogiora_string_registered_but_unreadable',
				__( 'The string was registered but could not be loaded afterwards.', 'mclogiora' )
			);
		}

		return $stored;
	}

	/**
	 * Loads a string by hash immediately after writing it.
	 *
	 * Kept separate from find_by_hash() because register() reads the same
	 * hash before and after a write, and the two lookups run against
	 * different database states.
	 *
	 * @param string $hash Identity hash.
	 * @return StringSource|null
	 */
	private function load_by_hash( $hash ) {
		return $this->find_by_hash( $hash );
	}

	/**
	 * Finds a source string by its identity hash.
	 *
	 * @param string $hash Identity hash.
	 * @return StringSource|null
	 */
	public function find_by_hash( $hash ) {
		if ( ! $this->tables_ready() ) {
			return null;
		}

		$db    = $this->wpdb;
		$table = $this->tables->strings();

		$row = $db->get_row(
			$db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from TableNames, not request data.
				"SELECT * FROM {$table} WHERE string_hash = %s LIMIT 1",
				(string) $hash
			)
		);

		return $row ? $this->map_string_row( $row ) : null;
	}

	/**
	 * Finds a source string by internal identifier.
	 *
	 * @param int $id Internal identifier.
	 * @return StringSource|null
	 */
	public function find( $id ) {
		if ( ! $this->tables_ready() ) {
			return null;
		}

		$db    = $this->wpdb;
		$table = $this->tables->strings();

		$row = $db->get_row(
			$db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from TableNames, not request data.
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				(int) $id
			)
		);

		return $row ? $this->map_string_row( $row ) : null;
	}

	/**
	 * Returns source strings matching optional filters.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return StringSource[]
	 */
	public function query( array $filters = array() ) {
		if ( ! $this->tables_ready() ) {
			return array();
		}

		$db     = $this->wpdb;
		$table  = $this->tables->strings();
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['text_domain'] ) ) {
			$where[]  = 'text_domain = %s';
			$params[] = (string) $filters['text_domain'];
		}

		if ( ! empty( $filters['source_type'] ) ) {
			$where[]  = 'source_type = %s';
			$params[] = (string) $filters['source_type'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$where[]  = 'source_text LIKE %s';
			$params[] = '%' . $db->esc_like( (string) $filters['search'] ) . '%';
		}

		$limit    = isset( $filters['limit'] ) ? max( 1, min( 200, (int) $filters['limit'] ) ) : 50;
		$offset   = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;
		$clause   = implode( ' AND ', $where );
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from TableNames; $clause is built only from the fixed fragments above.
		$sql = "SELECT * FROM {$table} WHERE {$clause} ORDER BY text_domain ASC, id ASC LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is assembled from constants above and bound here.
		$rows = $db->get_results( $db->prepare( $sql, $params ) );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'map_string_row' ), $rows );
	}

	/**
	 * Returns the number of registered source strings.
	 *
	 * @return int
	 */
	public function count_strings() {
		if ( ! $this->tables_ready() ) {
			return 0;
		}

		$db    = $this->wpdb;
		$table = $this->tables->strings();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from TableNames, not request data.
		$count = $db->get_var( "SELECT COUNT(*) FROM {$table}" );

		return (int) $count;
	}

	/**
	 * Marks every string of a source type as stale before a rescan.
	 *
	 * Strings are never deleted by a rescan. A string that temporarily
	 * disappears keeps its translations, which would otherwise be lost the
	 * first time somebody deactivated a plugin.
	 *
	 * @param string $source_type Source type.
	 * @param string $source_reference Optional reference prefix.
	 * @return int
	 */
	public function mark_scope_stale( $source_type, $source_reference = '' ) {
		if ( ! $this->tables_ready() ) {
			return 0;
		}

		$db    = $this->wpdb;
		$table = $this->tables->strings();

		if ( '' === (string) $source_reference ) {
			$affected = $db->query(
				$db->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from TableNames, not request data.
					"UPDATE {$table} SET is_stale = 1 WHERE source_type = %s",
					(string) $source_type
				)
			);

			return (int) $affected;
		}

		$affected = $db->query(
			$db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from TableNames, not request data.
				"UPDATE {$table} SET is_stale = 1 WHERE source_type = %s AND source_reference LIKE %s",
				(string) $source_type,
				$db->esc_like( (string) $source_reference ) . '%'
			)
		);

		return (int) $affected;
	}

	/**
	 * Saves a translation for a source string.
	 *
	 * @param StringTranslation $translation Translation.
	 * @return StringTranslation|\WP_Error
	 */
	public function save_translation( StringTranslation $translation ) {
		if ( ! $this->tables_ready() ) {
			return new \WP_Error( 'mclogiora_string_tables_missing', __( 'The string tables are not available.', 'mclogiora' ) );
		}

		$db    = $this->wpdb;
		$table = $this->tables->string_translations();
		$now   = current_time( 'mysql', true );

		$existing = $this->find_translation( $translation->string_id(), $translation->language_code() );

		if ( $existing instanceof StringTranslation ) {
			$db->update(
				$table,
				array(
					'translated_text' => $translation->text(),
					'status'          => $translation->status(),
					'updated_at'      => $now,
				),
				array(
					'string_id'     => $translation->string_id(),
					'language_code' => $translation->language_code(),
				),
				array( '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);

			return $translation;
		}

		$inserted = $db->insert(
			$table,
			array(
				'string_id'       => $translation->string_id(),
				'language_code'   => $translation->language_code(),
				'translated_text' => $translation->text(),
				'status'          => $translation->status(),
				'updated_at'      => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'mclogiora_string_translation_failed', __( 'The translation could not be saved.', 'mclogiora' ) );
		}

		return $translation;
	}

	/**
	 * Finds a translation for a source string in a language.
	 *
	 * @param int    $string_id Source string identifier.
	 * @param string $language_code Language code.
	 * @return StringTranslation|null
	 */
	public function find_translation( $string_id, $language_code ) {
		if ( ! $this->tables_ready() ) {
			return null;
		}

		$db    = $this->wpdb;
		$table = $this->tables->string_translations();

		$row = $db->get_row(
			$db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from TableNames, not request data.
				"SELECT * FROM {$table} WHERE string_id = %d AND language_code = %s LIMIT 1",
				(int) $string_id,
				(string) $language_code
			)
		);

		return $row ? $this->map_translation_row( $row ) : null;
	}

	/**
	 * Returns all translations for a source string.
	 *
	 * @param int $string_id Source string identifier.
	 * @return StringTranslation[]
	 */
	public function translations_for( $string_id ) {
		if ( ! $this->tables_ready() ) {
			return array();
		}

		$db    = $this->wpdb;
		$table = $this->tables->string_translations();

		$rows = $db->get_results(
			$db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from TableNames, not request data.
				"SELECT * FROM {$table} WHERE string_id = %d ORDER BY language_code ASC",
				(int) $string_id
			)
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'map_translation_row' ), $rows );
	}

	/**
	 * Deletes a translation.
	 *
	 * @param int    $string_id Source string identifier.
	 * @param string $language_code Language code.
	 * @return bool|\WP_Error
	 */
	public function delete_translation( $string_id, $language_code ) {
		if ( ! $this->tables_ready() ) {
			return new \WP_Error( 'mclogiora_string_tables_missing', __( 'The string tables are not available.', 'mclogiora' ) );
		}

		$db = $this->wpdb;

		$db->delete(
			$this->tables->string_translations(),
			array(
				'string_id'     => (int) $string_id,
				'language_code' => (string) $language_code,
			),
			array( '%d', '%s' )
		);

		return true;
	}

	/**
	 * Maps a database row to a source string.
	 *
	 * @param object $row Database row.
	 * @return StringSource
	 */
	private function map_string_row( $row ) {
		return new StringSource(
			isset( $row->id ) ? (int) $row->id : 0,
			isset( $row->source_text ) ? (string) $row->source_text : '',
			isset( $row->text_domain ) ? (string) $row->text_domain : '',
			isset( $row->context ) ? (string) $row->context : '',
			isset( $row->source_type ) ? (string) $row->source_type : StringSourceType::MANUAL,
			isset( $row->source_reference ) ? (string) $row->source_reference : '',
			isset( $row->source_line ) ? (int) $row->source_line : 0,
			isset( $row->is_stale ) ? (bool) $row->is_stale : false
		);
	}

	/**
	 * Maps a database row to a translation.
	 *
	 * @param object $row Database row.
	 * @return StringTranslation
	 */
	private function map_translation_row( $row ) {
		return new StringTranslation(
			isset( $row->string_id ) ? (int) $row->string_id : 0,
			isset( $row->language_code ) ? (string) $row->language_code : '',
			isset( $row->translated_text ) ? (string) $row->translated_text : '',
			isset( $row->status ) ? (string) $row->status : ''
		);
	}
}
