<?php
/**
 * Database-backed translation relation repository.
 *
 * @package McLogiora
 */

namespace McLogiora\Relations;

use McLogiora\Database\SchemaBuilder;
use McLogiora\Database\TableNames;
use McLogiora\Database\UuidGenerator;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes relation records through mcLogiora tables.
 */
final class DatabaseTranslationRelationRepository implements TranslationRelationRepositoryInterface {
	const GROUP_STATUS_ACTIVE   = 'active';
	const GROUP_STATUS_ARCHIVED = 'archived';

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
	 * Schema helper.
	 *
	 * @var SchemaBuilder
	 */
	private $schema_builder;

	/**
	 * UUID generator.
	 *
	 * @var UuidGenerator
	 */
	private $uuid_generator;

	/**
	 * Language repository.
	 *
	 * @var LanguageRepositoryInterface
	 */
	private $languages;

	/**
	 * Constructor.
	 *
	 * @param \wpdb                       $wpdb WordPress database object.
	 * @param TableNames                  $tables Table names.
	 * @param SchemaBuilder               $schema_builder Schema helper.
	 * @param UuidGenerator               $uuid_generator UUID generator.
	 * @param LanguageRepositoryInterface $languages Language repository.
	 */
	public function __construct( $wpdb, TableNames $tables, SchemaBuilder $schema_builder, UuidGenerator $uuid_generator, LanguageRepositoryInterface $languages ) {
		$this->wpdb           = $wpdb;
		$this->tables         = $tables;
		$this->schema_builder = $schema_builder;
		$this->uuid_generator = $uuid_generator;
		$this->languages      = $languages;
	}

	/**
	 * Creates an empty translation group.
	 *
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_empty_group() {
		if ( ! $this->tables_ready() ) {
			return $this->database_unavailable_error();
		}

		$group_uuid = $this->uuid_generator->generate();
		$now        = current_time( 'mysql', true );
		$result     = $this->wpdb->insert(
			$this->tables->translation_groups(),
			array(
				'group_uuid'          => $group_uuid,
				'source_content_type' => ContentType::FUTURE,
				'source_content_id'   => '',
				'source_language'     => '',
				'status'              => self::GROUP_STATUS_ACTIVE,
				'created_at'          => $now,
				'updated_at'          => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'mclogiora_relation_group_create_failed', __( 'The translation group could not be created.', 'mclogiora' ) );
		}

		return new TranslationGroup( $group_uuid, array() );
	}

	/**
	 * Creates a placeholder group around an original item.
	 *
	 * @param TranslationItem $original Original item.
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_group_placeholder( TranslationItem $original ) {
		if ( ! $this->tables_ready() ) {
			return $this->database_unavailable_error();
		}

		$validation = $this->validate_item_for_insert( '', $original, true );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( $this->object_is_assigned( $original->object_type(), $original->object_id() ) ) {
			return new \WP_Error( 'mclogiora_relation_object_assigned', __( 'This object is already assigned to an active translation group.', 'mclogiora' ) );
		}

		$group_uuid = $this->uuid_generator->generate();
		$now        = current_time( 'mysql', true );
		$result     = $this->wpdb->insert(
			$this->tables->translation_groups(),
			array(
				'group_uuid'          => $group_uuid,
				'source_content_type' => $original->object_type(),
				'source_content_id'   => $original->object_id(),
				'source_language'     => $original->language_code(),
				'status'              => self::GROUP_STATUS_ACTIVE,
				'created_at'          => $now,
				'updated_at'          => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'mclogiora_relation_group_create_failed', __( 'The translation group could not be created.', 'mclogiora' ) );
		}

		$inserted = $this->insert_item( $group_uuid, $original, true, $now );

		if ( is_wp_error( $inserted ) ) {
			$this->archive_group( $group_uuid );
			return $inserted;
		}

		return $this->find_group( $group_uuid );
	}

	/**
	 * Finds a group by its key.
	 *
	 * @param string $group_key Group key.
	 * @return TranslationGroup|null
	 */
	public function find_group( $group_key ) {
		if ( ! $this->tables_ready() ) {
			return null;
		}

		$group_uuid = $this->normalize_group_key( $group_key );
		$table      = $this->tables->translation_groups();
		$group      = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT group_uuid FROM {$table} WHERE group_uuid = %s LIMIT 1",
				$group_uuid
			)
		);

		if ( ! $group ) {
			return null;
		}

		return new TranslationGroup( $group_uuid, $this->items_for_group( $group_uuid ) );
	}

	/**
	 * Finds a group by internal numeric ID.
	 *
	 * @param int $group_id Internal group ID.
	 * @return TranslationGroup|null
	 */
	public function find_group_by_id( $group_id ) {
		if ( ! $this->tables_ready() ) {
			return null;
		}

		$table      = $this->tables->translation_groups();
		$group_uuid = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT group_uuid FROM {$table} WHERE id = %d LIMIT 1",
				absint( $group_id )
			)
		);

		return $group_uuid ? $this->find_group( $group_uuid ) : null;
	}

	/**
	 * Finds a group by source item.
	 *
	 * @param TranslationItem $source Source item.
	 * @return TranslationGroup|null
	 */
	public function find_group_by_source( TranslationItem $source ) {
		if ( ! $this->tables_ready() ) {
			return null;
		}

		$table      = $this->tables->translation_groups();
		$group_uuid = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT group_uuid FROM {$table} WHERE source_content_type = %s AND source_content_id = %s AND source_language = %s AND status = %s LIMIT 1",
				$source->object_type(),
				$source->object_id(),
				$source->language_code(),
				self::GROUP_STATUS_ACTIVE
			)
		);

		return $group_uuid ? $this->find_group( $group_uuid ) : null;
	}

	/**
	 * Updates group metadata.
	 *
	 * @param string $group_key Group key.
	 * @param array  $metadata Group metadata.
	 * @return TranslationGroup|\WP_Error
	 */
	public function update_group_metadata( $group_key, array $metadata ) {
		$group = $this->find_group( $group_key );

		if ( ! $group instanceof TranslationGroup ) {
			return new \WP_Error( 'mclogiora_relation_group_not_found', __( 'The translation group could not be found.', 'mclogiora' ) );
		}

		$data   = array( 'updated_at' => current_time( 'mysql', true ) );
		$format = array( '%s' );

		if ( isset( $metadata['status'] ) ) {
			$status = sanitize_key( wp_unslash( $metadata['status'] ) );

			if ( ! in_array( $status, array( self::GROUP_STATUS_ACTIVE, self::GROUP_STATUS_ARCHIVED ), true ) ) {
				return new \WP_Error( 'mclogiora_relation_group_status_invalid', __( 'The translation group status is invalid.', 'mclogiora' ) );
			}

			$data['status'] = $status;
			$format[]       = '%s';
		}

		$result = $this->wpdb->update(
			$this->tables->translation_groups(),
			$data,
			array( 'group_uuid' => $group->group_key() ),
			$format,
			array( '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'mclogiora_relation_group_update_failed', __( 'The translation group could not be updated.', 'mclogiora' ) );
		}

		return $this->find_group( $group->group_key() );
	}

	/**
	 * Archives a group without deleting data.
	 *
	 * @param string $group_key Group key.
	 * @return bool|\WP_Error
	 */
	public function archive_group( $group_key ) {
		$result = $this->update_group_metadata(
			$group_key,
			array(
				'status' => self::GROUP_STATUS_ARCHIVED,
			)
		);

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Adds an item to a group.
	 *
	 * @param string          $group_key Group key.
	 * @param TranslationItem $item Translation item.
	 * @return TranslationItem|\WP_Error
	 */
	public function add_item_to_group( $group_key, TranslationItem $item ) {
		$group = $this->find_group( $group_key );

		if ( ! $group instanceof TranslationGroup ) {
			return new \WP_Error( 'mclogiora_relation_group_not_found', __( 'The translation group could not be found.', 'mclogiora' ) );
		}

		$is_original = $item->is_original();
		$validation  = $this->validate_item_for_insert( $group->group_key(), $item, $is_original );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( $is_original && $group->original() instanceof TranslationItem ) {
			return new \WP_Error( 'mclogiora_relation_original_exists', __( 'A translation group can only have one original item.', 'mclogiora' ) );
		}

		if ( $this->group_has_language( $group->group_key(), $item->language_code() ) ) {
			return new \WP_Error( 'mclogiora_relation_language_exists', __( 'This translation group already has an item for that language.', 'mclogiora' ) );
		}

		if ( $this->object_is_assigned( $item->object_type(), $item->object_id() ) ) {
			return new \WP_Error( 'mclogiora_relation_object_assigned', __( 'This object is already assigned to an active translation group.', 'mclogiora' ) );
		}

		$inserted = $this->insert_item( $group->group_key(), $item, $is_original, current_time( 'mysql', true ) );

		if ( is_wp_error( $inserted ) ) {
			return $inserted;
		}

		if ( $is_original ) {
			$this->update_group_source( $group->group_key(), $item );
		}

		return $this->find_item( $item->object_type(), $item->object_id(), $item->language_code() );
	}

	/**
	 * Updates an item status.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @param string $status Translation status.
	 * @return TranslationItem|\WP_Error
	 */
	public function update_item_status( $object_type, $object_id, $language_code, $status ) {
		$status = sanitize_key( (string) $status );

		if ( ! TranslationStatus::is_valid( $status ) ) {
			return new \WP_Error( 'mclogiora_relation_status_invalid', __( 'The translation status is invalid.', 'mclogiora' ) );
		}

		$item = $this->find_item( $object_type, $object_id, $language_code );

		if ( ! $item instanceof TranslationItem ) {
			return new \WP_Error( 'mclogiora_relation_item_not_found', __( 'The translation item could not be found.', 'mclogiora' ) );
		}

		if ( $item->is_original() && TranslationStatus::ORIGINAL !== $status ) {
			return new \WP_Error( 'mclogiora_relation_original_status_locked', __( 'The original item status cannot be changed in this phase.', 'mclogiora' ) );
		}

		if ( ! $item->is_original() && TranslationStatus::ORIGINAL === $status ) {
			return new \WP_Error( 'mclogiora_relation_original_status_invalid', __( 'Only the source item may use the original status.', 'mclogiora' ) );
		}

		return $this->update_item_fields(
			$object_type,
			$object_id,
			$language_code,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Updates an item language.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Current language code.
	 * @param string $new_language_code New language code.
	 * @return TranslationItem|\WP_Error
	 */
	public function update_item_language( $object_type, $object_id, $language_code, $new_language_code ) {
		$item = $this->find_item( $object_type, $object_id, $language_code );

		if ( ! $item instanceof TranslationItem ) {
			return new \WP_Error( 'mclogiora_relation_item_not_found', __( 'The translation item could not be found.', 'mclogiora' ) );
		}

		$language = $this->languages->find_by_code( $new_language_code );

		if ( ! $language instanceof Language || ! $language->is_active() ) {
			return new \WP_Error( 'mclogiora_relation_language_inactive', __( 'Only active configured languages can receive relation items.', 'mclogiora' ) );
		}

		$group_uuid = $this->group_uuid_for_item( $item );

		if ( $group_uuid && $this->group_has_language( $group_uuid, $language->code() ) ) {
			return new \WP_Error( 'mclogiora_relation_language_exists', __( 'This translation group already has an item for that language.', 'mclogiora' ) );
		}

		return $this->update_item_fields(
			$item->object_type(),
			$item->object_id(),
			$item->language_code(),
			array(
				'language_code' => $language->code(),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s' ),
			$language->code()
		);
	}

	/**
	 * Updates source metadata placeholder fields.
	 *
	 * @param TranslationItem $item Translation item.
	 * @return TranslationItem|\WP_Error
	 */
	public function update_item_source_metadata( TranslationItem $item ) {
		return $this->update_item_fields(
			$item->object_type(),
			$item->object_id(),
			$item->language_code(),
			array(
				'source_hash'             => $item->source_hash(),
				'translated_source_hash'  => $item->translated_source_hash(),
				'source_modified_at'      => $this->timestamp_to_mysql( $item->source_modified() ),
				'translation_modified_at' => $this->timestamp_to_mysql( $item->translation_modified() ),
				'updated_at'              => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Finds an item by object identity and language.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return TranslationItem|null
	 */
	public function find_item( $object_type, $object_id, $language_code ) {
		if ( ! $this->tables_ready() ) {
			return null;
		}

		$table = $this->tables->translation_items();
		$row   = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE content_type = %s AND content_id = %s AND language_code = %s LIMIT 1",
				$this->normalize_object_type( $object_type ),
				$this->normalize_object_id( $object_id ),
				$this->normalize_language_code( $language_code )
			)
		);

		return $row ? $this->map_item_row( $row ) : null;
	}

	/**
	 * Returns items for a group.
	 *
	 * @param string $group_key Group key.
	 * @return TranslationItem[]
	 */
	public function items_for_group( $group_key ) {
		if ( ! $this->tables_ready() ) {
			return array();
		}

		$table = $this->tables->translation_items();
		$rows  = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE group_uuid = %s ORDER BY is_original DESC, language_code ASC",
				$this->normalize_group_key( $group_key )
			)
		);

		return array_map( array( $this, 'map_item_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Returns items by status.
	 *
	 * @param string $status Translation status.
	 * @return TranslationItem[]
	 */
	public function items_by_status( $status ) {
		if ( ! $this->tables_ready() || ! TranslationStatus::is_valid( $status ) ) {
			return array();
		}

		$table = $this->tables->translation_items();
		$rows  = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY updated_at DESC LIMIT 100",
				sanitize_key( $status )
			)
		);

		return array_map( array( $this, 'map_item_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Returns original item for a group.
	 *
	 * @param string $group_key Group key.
	 * @return TranslationItem|null
	 */
	public function original_for_group( $group_key ) {
		if ( ! $this->tables_ready() ) {
			return null;
		}

		$table = $this->tables->translation_items();
		$row   = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE group_uuid = %s AND is_original = 1 LIMIT 1",
				$this->normalize_group_key( $group_key )
			)
		);

		return $row ? $this->map_item_row( $row ) : null;
	}

	/**
	 * Returns whether an object is assigned to an active group.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @return bool
	 */
	public function object_is_assigned( $object_type, $object_id ) {
		if ( ! $this->tables_ready() ) {
			return false;
		}

		$items_table  = $this->tables->translation_items();
		$groups_table = $this->tables->translation_groups();
		$count        = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$items_table} i INNER JOIN {$groups_table} g ON i.group_uuid = g.group_uuid WHERE i.content_type = %s AND i.content_id = %s AND i.status <> %s AND g.status = %s",
				$this->normalize_object_type( $object_type ),
				$this->normalize_object_id( $object_id ),
				TranslationStatus::DISABLED,
				self::GROUP_STATUS_ACTIVE
			)
		);

		return absint( $count ) > 0;
	}

	/**
	 * Safely detaches a target item by disabling it.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return bool|\WP_Error
	 */
	public function detach_item( $object_type, $object_id, $language_code ) {
		$item = $this->find_item( $object_type, $object_id, $language_code );

		if ( ! $item instanceof TranslationItem ) {
			return new \WP_Error( 'mclogiora_relation_item_not_found', __( 'The translation item could not be found.', 'mclogiora' ) );
		}

		if ( $item->is_original() ) {
			return new \WP_Error( 'mclogiora_relation_detach_original', __( 'The original item cannot be detached in this phase.', 'mclogiora' ) );
		}

		$result = $this->update_item_status( $item->object_type(), $item->object_id(), $item->language_code(), TranslationStatus::DISABLED );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Returns translations for an item.
	 *
	 * @param TranslationItem $item Translation item.
	 * @return TranslationItem[]
	 */
	public function translations_for_item( TranslationItem $item ) {
		if ( ! $this->tables_ready() ) {
			return array();
		}

		$group_uuid = $this->group_uuid_for_item( $item );

		if ( ! $group_uuid ) {
			return array();
		}

		$group = $this->find_group( $group_uuid );

		return $group instanceof TranslationGroup ? $group->targets() : array();
	}

	/**
	 * Returns all active groups.
	 *
	 * @return TranslationGroup[]
	 */
	public function all() {
		if ( ! $this->tables_ready() ) {
			return array();
		}

		$table  = $this->tables->translation_groups();
		$rows   = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT group_uuid FROM {$table} WHERE status = %s ORDER BY updated_at DESC LIMIT 100",
				self::GROUP_STATUS_ACTIVE
			)
		);
		$groups = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! empty( $row->group_uuid ) ) {
				$group = $this->find_group( $row->group_uuid );

				if ( $group instanceof TranslationGroup ) {
					$groups[] = $group;
				}
			}
		}

		return $groups;
	}

	/**
	 * Counts active translation groups.
	 *
	 * @return int
	 */
	public function count_groups() {
		if ( ! $this->tables_ready() ) {
			return 0;
		}

		$table = $this->tables->translation_groups();

		return absint(
			$this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE status = %s",
					self::GROUP_STATUS_ACTIVE
				)
			)
		);
	}

	/**
	 * Counts translation items.
	 *
	 * @return int
	 */
	public function count_items() {
		if ( ! $this->tables_ready() ) {
			return 0;
		}

		$table = $this->tables->translation_items();

		return absint( $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
	}

	/**
	 * Inserts a translation item.
	 *
	 * @param string          $group_uuid Group UUID.
	 * @param TranslationItem $item Translation item.
	 * @param bool            $original Whether this is original.
	 * @param string          $now Current datetime.
	 * @return true|\WP_Error
	 */
	private function insert_item( $group_uuid, TranslationItem $item, $original, $now ) {
		$result = $this->wpdb->insert(
			$this->tables->translation_items(),
			array(
				'group_uuid'              => $group_uuid,
				'content_type'            => $item->object_type(),
				'content_id'              => $item->object_id(),
				'language_code'           => $item->language_code(),
				'status'                  => $original ? TranslationStatus::ORIGINAL : $item->status(),
				'is_original'             => $original ? 1 : 0,
				'source_hash'             => $item->source_hash(),
				'translated_source_hash'  => $item->translated_source_hash(),
				'source_modified_at'      => $this->timestamp_to_mysql( $item->source_modified() ),
				'translation_modified_at' => $this->timestamp_to_mysql( $item->translation_modified() ),
				'created_at'              => $now,
				'updated_at'              => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'mclogiora_relation_item_insert_failed', __( 'The translation item could not be added.', 'mclogiora' ) );
		}

		return true;
	}

	/**
	 * Updates item fields.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @param array  $data Update data.
	 * @param array  $format Update format.
	 * @param string $return_language_code Return language override.
	 * @return TranslationItem|\WP_Error
	 */
	private function update_item_fields( $object_type, $object_id, $language_code, array $data, array $format, $return_language_code = '' ) {
		$item = $this->find_item( $object_type, $object_id, $language_code );

		if ( ! $item instanceof TranslationItem ) {
			return new \WP_Error( 'mclogiora_relation_item_not_found', __( 'The translation item could not be found.', 'mclogiora' ) );
		}

		$result = $this->wpdb->update(
			$this->tables->translation_items(),
			$data,
			array(
				'content_type'  => $item->object_type(),
				'content_id'    => $item->object_id(),
				'language_code' => $item->language_code(),
			),
			$format,
			array( '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'mclogiora_relation_item_update_failed', __( 'The translation item could not be updated.', 'mclogiora' ) );
		}

		$group_uuid = $this->group_uuid_for_item(
			new TranslationItem(
				$item->object_type(),
				$item->object_id(),
				'' !== $return_language_code ? $return_language_code : $item->language_code(),
				$item->status()
			)
		);

		if ( $item->is_original() && $group_uuid ) {
			$updated = $this->find_item( $item->object_type(), $item->object_id(), '' !== $return_language_code ? $return_language_code : $item->language_code() );

			if ( $updated instanceof TranslationItem ) {
				$this->update_group_source( $group_uuid, $updated );
			}
		}

		return $this->find_item( $item->object_type(), $item->object_id(), '' !== $return_language_code ? $return_language_code : $item->language_code() );
	}

	/**
	 * Updates the group source columns.
	 *
	 * @param string          $group_uuid Group UUID.
	 * @param TranslationItem $item Original item.
	 * @return void
	 */
	private function update_group_source( $group_uuid, TranslationItem $item ) {
		$this->wpdb->update(
			$this->tables->translation_groups(),
			array(
				'source_content_type' => $item->object_type(),
				'source_content_id'   => $item->object_id(),
				'source_language'     => $item->language_code(),
				'updated_at'          => current_time( 'mysql', true ),
			),
			array(
				'group_uuid' => $group_uuid,
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Validates an item before insert.
	 *
	 * @param string          $group_uuid Group UUID.
	 * @param TranslationItem $item Translation item.
	 * @param bool            $original Whether item is original.
	 * @return true|\WP_Error
	 */
	private function validate_item_for_insert( $group_uuid, TranslationItem $item, $original ) {
		if ( '' === $item->object_type() || '' === $item->object_id() ) {
			return new \WP_Error( 'mclogiora_relation_object_required', __( 'Object type and object ID are required.', 'mclogiora' ) );
		}

		$language = $this->languages->find_by_code( $item->language_code() );

		if ( ! $language instanceof Language ) {
			return new \WP_Error( 'mclogiora_relation_language_missing', __( 'The item language must exist before a relation item is created.', 'mclogiora' ) );
		}

		if ( ! $language->is_active() ) {
			return new \WP_Error( 'mclogiora_relation_language_inactive', __( 'Disabled languages cannot receive new relation items.', 'mclogiora' ) );
		}

		if ( $original && TranslationStatus::ORIGINAL !== $item->status() ) {
			return true;
		}

		if ( ! $original && TranslationStatus::ORIGINAL === $item->status() ) {
			return new \WP_Error( 'mclogiora_relation_original_status_invalid', __( 'Only the original item may use the original status.', 'mclogiora' ) );
		}

		if ( '' !== $group_uuid && $this->group_has_language( $group_uuid, $item->language_code() ) ) {
			return new \WP_Error( 'mclogiora_relation_language_exists', __( 'This translation group already has an item for that language.', 'mclogiora' ) );
		}

		return true;
	}

	/**
	 * Returns whether a group already has a language item.
	 *
	 * @param string $group_uuid Group UUID.
	 * @param string $language_code Language code.
	 * @return bool
	 */
	private function group_has_language( $group_uuid, $language_code ) {
		if ( ! $this->tables_ready() ) {
			return false;
		}

		$table = $this->tables->translation_items();
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE group_uuid = %s AND language_code = %s AND status <> %s",
				$this->normalize_group_key( $group_uuid ),
				$this->normalize_language_code( $language_code ),
				TranslationStatus::DISABLED
			)
		);

		return absint( $count ) > 0;
	}

	/**
	 * Returns the group UUID for an item.
	 *
	 * @param TranslationItem $item Translation item.
	 * @return string
	 */
	private function group_uuid_for_item( TranslationItem $item ) {
		if ( ! $this->tables_ready() ) {
			return '';
		}

		$table = $this->tables->translation_items();

		return (string) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT group_uuid FROM {$table} WHERE content_type = %s AND content_id = %s AND language_code = %s LIMIT 1",
				$item->object_type(),
				$item->object_id(),
				$item->language_code()
			)
		);
	}

	/**
	 * Maps an item database row to an entity.
	 *
	 * @param object $row Database row.
	 * @return TranslationItem
	 */
	private function map_item_row( $row ) {
		return new TranslationItem(
			isset( $row->content_type ) ? $row->content_type : ContentType::FUTURE,
			isset( $row->content_id ) ? $row->content_id : '',
			isset( $row->language_code ) ? $row->language_code : '',
			isset( $row->status ) ? $row->status : TranslationStatus::MISSING,
			! empty( $row->is_original ),
			isset( $row->source_hash ) ? $row->source_hash : '',
			isset( $row->translated_source_hash ) ? $row->translated_source_hash : '',
			isset( $row->source_modified_at ) ? strtotime( $row->source_modified_at ) : 0,
			isset( $row->translation_modified_at ) ? strtotime( $row->translation_modified_at ) : 0
		);
	}

	/**
	 * Returns whether relation tables exist.
	 *
	 * @return bool
	 */
	private function tables_ready() {
		return $this->schema_builder->table_exists( $this->tables->translation_groups() )
			&& $this->schema_builder->table_exists( $this->tables->translation_items() );
	}

	/**
	 * Creates a database unavailable error.
	 *
	 * @return \WP_Error
	 */
	private function database_unavailable_error() {
		return new \WP_Error( 'mclogiora_relation_tables_unavailable', __( 'The relation tables are not available. Reactivate the plugin to run database migrations.', 'mclogiora' ) );
	}

	/**
	 * Converts a timestamp to MySQL datetime.
	 *
	 * @param int $timestamp Timestamp.
	 * @return string|null
	 */
	private function timestamp_to_mysql( $timestamp ) {
		$timestamp = absint( $timestamp );

		if ( 0 === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Normalizes a group key.
	 *
	 * @param string $group_key Group key.
	 * @return string
	 */
	private function normalize_group_key( $group_key ) {
		return sanitize_key( (string) $group_key );
	}

	/**
	 * Normalizes object type.
	 *
	 * @param string $object_type Object type.
	 * @return string
	 */
	private function normalize_object_type( $object_type ) {
		return sanitize_key( (string) $object_type );
	}

	/**
	 * Normalizes object ID.
	 *
	 * @param string $object_id Object ID.
	 * @return string
	 */
	private function normalize_object_id( $object_id ) {
		return sanitize_text_field( (string) $object_id );
	}

	/**
	 * Normalizes language code.
	 *
	 * @param string $language_code Language code.
	 * @return string
	 */
	private function normalize_language_code( $language_code ) {
		return sanitize_key( (string) $language_code );
	}
}
