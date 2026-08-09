<?php
/**
 * Database-backed media translation repository.
 *
 * @package McLogiora
 */

namespace McLogiora\Media;

use McLogiora\Database\SchemaBuilder;
use McLogiora\Database\TableNames;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes language-specific attachment metadata.
 *
 * Written so WPCS can machine-check it: wpdb is assigned to a local variable
 * before every query, and the only suppressions are per-query for table-name
 * interpolation. Table names come from TableNames and never contain request
 * data.
 */
final class DatabaseMediaTranslationRepository implements MediaTranslationRepositoryInterface {
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
	 * Returns whether the media translation table exists.
	 *
	 * @return bool
	 */
	private function table_ready() {
		return $this->schema_builder->table_exists( $this->tables->media_translations() );
	}

	/**
	 * Saves a media translation.
	 *
	 * @param MediaTranslation $translation Translation.
	 * @return MediaTranslation|\WP_Error
	 */
	public function save( MediaTranslation $translation ) {
		if ( ! $this->table_ready() ) {
			return new \WP_Error( 'mclogiora_media_table_missing', __( 'The media translation table is not available.', 'mclogiora' ) );
		}

		$db    = $this->wpdb;
		$table = $this->tables->media_translations();
		$now   = current_time( 'mysql', true );

		$existing = $this->find( $translation->attachment_id(), $translation->language_code() );

		$data = array(
			'translated_title'       => $translation->title(),
			'translated_alt_text'    => $translation->alt_text(),
			'translated_caption'     => $translation->caption(),
			'translated_description' => $translation->description(),
			'status'                 => $translation->status(),
			'updated_at'             => $now,
		);

		if ( $existing instanceof MediaTranslation ) {
			$db->update(
				$table,
				$data,
				array(
					'attachment_id' => $translation->attachment_id(),
					'language_code' => $translation->language_code(),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);

			return $translation;
		}

		$data['attachment_id'] = $translation->attachment_id();
		$data['language_code'] = $translation->language_code();

		$inserted = $db->insert(
			$table,
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'mclogiora_media_translation_failed', __( 'The media translation could not be saved.', 'mclogiora' ) );
		}

		return $translation;
	}

	/**
	 * Finds a media translation for an attachment and language.
	 *
	 * @param int    $attachment_id Attachment identifier.
	 * @param string $language_code Language code.
	 * @return MediaTranslation|null
	 */
	public function find( $attachment_id, $language_code ) {
		if ( ! $this->table_ready() ) {
			return null;
		}

		$db    = $this->wpdb;
		$table = $this->tables->media_translations();

		$row = $db->get_row(
			$db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from TableNames, not request data.
				"SELECT * FROM {$table} WHERE attachment_id = %d AND language_code = %s LIMIT 1",
				(int) $attachment_id,
				(string) $language_code
			)
		);

		return $row ? $this->map_row( $row ) : null;
	}

	/**
	 * Returns every translation for an attachment.
	 *
	 * @param int $attachment_id Attachment identifier.
	 * @return MediaTranslation[]
	 */
	public function all_for_attachment( $attachment_id ) {
		if ( ! $this->table_ready() ) {
			return array();
		}

		$db    = $this->wpdb;
		$table = $this->tables->media_translations();

		$rows = $db->get_results(
			$db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from TableNames, not request data.
				"SELECT * FROM {$table} WHERE attachment_id = %d ORDER BY language_code ASC",
				(int) $attachment_id
			)
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'map_row' ), $rows );
	}

	/**
	 * Deletes a media translation.
	 *
	 * @param int    $attachment_id Attachment identifier.
	 * @param string $language_code Language code.
	 * @return bool|\WP_Error
	 */
	public function delete( $attachment_id, $language_code ) {
		if ( ! $this->table_ready() ) {
			return new \WP_Error( 'mclogiora_media_table_missing', __( 'The media translation table is not available.', 'mclogiora' ) );
		}

		$db = $this->wpdb;

		$db->delete(
			$this->tables->media_translations(),
			array(
				'attachment_id' => (int) $attachment_id,
				'language_code' => (string) $language_code,
			),
			array( '%d', '%s' )
		);

		return true;
	}

	/**
	 * Maps a database row to a media translation.
	 *
	 * @param object $row Database row.
	 * @return MediaTranslation
	 */
	private function map_row( $row ) {
		return new MediaTranslation(
			isset( $row->attachment_id ) ? (int) $row->attachment_id : 0,
			isset( $row->language_code ) ? (string) $row->language_code : '',
			isset( $row->translated_title ) ? (string) $row->translated_title : '',
			isset( $row->translated_alt_text ) ? (string) $row->translated_alt_text : '',
			isset( $row->translated_caption ) ? (string) $row->translated_caption : '',
			isset( $row->translated_description ) ? (string) $row->translated_description : '',
			isset( $row->status ) ? (string) $row->status : ''
		);
	}
}
