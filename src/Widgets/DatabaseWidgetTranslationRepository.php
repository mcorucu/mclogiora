<?php
/**
 * Database-backed widget translation repository.
 *
 * @package McLogiora
 */

namespace McLogiora\Widgets;

use McLogiora\Database\SchemaBuilder;
use McLogiora\Database\TableNames;

defined( 'ABSPATH' ) || exit;

/**
 * Stores translated widget field values.
 *
 * Translations live beside the widget instance rather than inside it. The
 * source `widget_*` option is never rewritten, so the original text always
 * survives, uninstalling is a table drop, and a language never overwrites
 * another language's copy.
 *
 * Written so WPCS can machine-check it from the start.
 */
final class DatabaseWidgetTranslationRepository implements WidgetTranslationRepositoryInterface {
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
	 * Returns whether the widget translation table exists.
	 *
	 * @return bool
	 */
	private function table_ready() {
		return $this->schema_builder->table_exists( $this->tables->widget_translations() );
	}

	/**
	 * Saves a widget translation.
	 *
	 * @param WidgetTranslation $translation Translation.
	 * @return WidgetTranslation|\WP_Error
	 */
	public function save( WidgetTranslation $translation ) {
		if ( ! $this->table_ready() ) {
			return new \WP_Error( 'mclogiora_widget_table_missing', __( 'The widget translation table is not available.', 'mclogiora' ) );
		}

		$db      = $this->wpdb;
		$table   = $this->tables->widget_translations();
		$now     = current_time( 'mysql', true );
		$encoded = (string) wp_json_encode( $translation->fields() );

		$existing = $this->find( $translation->widget_key(), $translation->language_code() );

		if ( $existing instanceof WidgetTranslation ) {
			$db->update(
				$table,
				array(
					'adapter_id'        => $translation->adapter_id(),
					'translated_fields' => $encoded,
					'status'            => $translation->status(),
					'updated_at'        => $now,
				),
				array(
					'widget_key'    => $translation->widget_key(),
					'language_code' => $translation->language_code(),
				),
				array( '%s', '%s', '%s', '%s' ),
				array( '%s', '%s' )
			);

			return $translation;
		}

		$inserted = $db->insert(
			$table,
			array(
				'widget_key'        => $translation->widget_key(),
				'adapter_id'        => $translation->adapter_id(),
				'language_code'     => $translation->language_code(),
				'translated_fields' => $encoded,
				'status'            => $translation->status(),
				'updated_at'        => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'mclogiora_widget_translation_failed', __( 'The widget translation could not be saved.', 'mclogiora' ) );
		}

		return $translation;
	}

	/**
	 * Finds a widget translation.
	 *
	 * @param string $widget_key Widget instance key.
	 * @param string $language_code Language code.
	 * @return WidgetTranslation|null
	 */
	public function find( $widget_key, $language_code ) {
		if ( ! $this->table_ready() ) {
			return null;
		}

		$db    = $this->wpdb;
		$table = $this->tables->widget_translations();

		$row = $db->get_row(
			$db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from TableNames, not request data.
				"SELECT * FROM {$table} WHERE widget_key = %s AND language_code = %s LIMIT 1",
				(string) $widget_key,
				(string) $language_code
			)
		);

		return $row ? $this->map_row( $row ) : null;
	}

	/**
	 * Returns every translation for a widget instance.
	 *
	 * @param string $widget_key Widget instance key.
	 * @return WidgetTranslation[]
	 */
	public function all_for_widget( $widget_key ) {
		if ( ! $this->table_ready() ) {
			return array();
		}

		$db    = $this->wpdb;
		$table = $this->tables->widget_translations();

		$rows = $db->get_results(
			$db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from TableNames, not request data.
				"SELECT * FROM {$table} WHERE widget_key = %s ORDER BY language_code ASC",
				(string) $widget_key
			)
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'map_row' ), $rows );
	}

	/**
	 * Deletes a widget translation.
	 *
	 * @param string $widget_key Widget instance key.
	 * @param string $language_code Language code.
	 * @return bool|\WP_Error
	 */
	public function delete( $widget_key, $language_code ) {
		if ( ! $this->table_ready() ) {
			return new \WP_Error( 'mclogiora_widget_table_missing', __( 'The widget translation table is not available.', 'mclogiora' ) );
		}

		$db = $this->wpdb;

		$db->delete(
			$this->tables->widget_translations(),
			array(
				'widget_key'    => (string) $widget_key,
				'language_code' => (string) $language_code,
			),
			array( '%s', '%s' )
		);

		return true;
	}

	/**
	 * Maps a database row to a widget translation.
	 *
	 * @param object $row Database row.
	 * @return WidgetTranslation
	 */
	private function map_row( $row ) {
		$decoded = isset( $row->translated_fields ) ? json_decode( (string) $row->translated_fields, true ) : array();

		return new WidgetTranslation(
			isset( $row->widget_key ) ? (string) $row->widget_key : '',
			isset( $row->adapter_id ) ? (string) $row->adapter_id : '',
			isset( $row->language_code ) ? (string) $row->language_code : '',
			is_array( $decoded ) ? $decoded : array(),
			isset( $row->status ) ? (string) $row->status : ''
		);
	}
}
