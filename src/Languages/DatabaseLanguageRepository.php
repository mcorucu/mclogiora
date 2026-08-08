<?php
/**
 * Database-backed language repository.
 *
 * @package McLogiora
 */

namespace McLogiora\Languages;

use McLogiora\Database\SchemaBuilder;
use McLogiora\Database\TableNames;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes language records through mcLogiora tables.
 */
final class DatabaseLanguageRepository implements LanguageRepositoryInterface {
	/**
	 * wpdb instance.
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
	 * Returns all known languages ordered for display.
	 *
	 * @return Language[]
	 */
	public function all() {
		if ( ! $this->table_ready() ) {
			return array();
		}

		$table = $this->tables->languages();
		$rows  = $this->wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, language_code ASC" );

		return array_map( array( $this, 'map_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Finds a language by language code.
	 *
	 * @param string $code Language code.
	 * @return Language|null
	 */
	public function find_by_code( $code ) {
		if ( ! $this->table_ready() ) {
			return null;
		}

		$table = $this->tables->languages();
		$row   = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE language_code = %s LIMIT 1",
				$this->normalize_code( $code )
			)
		);

		return $row ? $this->map_row( $row ) : null;
	}

	/**
	 * Finds a language by locale.
	 *
	 * @param string $locale Locale.
	 * @return Language|null
	 */
	public function find_by_locale( $locale ) {
		if ( ! $this->table_ready() ) {
			return null;
		}

		$table = $this->tables->languages();
		$row   = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE locale = %s LIMIT 1",
				$this->normalize_locale( $locale )
			)
		);

		return $row ? $this->map_row( $row ) : null;
	}

	/**
	 * Returns active languages.
	 *
	 * @return Language[]
	 */
	public function active() {
		if ( ! $this->table_ready() ) {
			return array();
		}

		$table = $this->tables->languages();
		$rows  = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY sort_order ASC, language_code ASC",
				LanguageStatus::ACTIVE
			)
		);

		return array_map( array( $this, 'map_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Returns the default language.
	 *
	 * @return Language|null
	 */
	public function default_language() {
		if ( ! $this->table_ready() ) {
			return null;
		}

		$table = $this->tables->languages();
		$row   = $this->wpdb->get_row( "SELECT * FROM {$table} WHERE is_default = 1 ORDER BY sort_order ASC LIMIT 1" );

		return $row ? $this->map_row( $row ) : null;
	}

	/**
	 * Creates a language.
	 *
	 * @param Language $language Language entity.
	 * @return Language|\WP_Error
	 */
	public function create( Language $language ) {
		$validation = $this->validate_language( $language );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( $this->find_by_code( $language->code() ) instanceof Language ) {
			return new \WP_Error( 'mclogiora_duplicate_language_code', __( 'A language with this language code already exists.', 'mclogiora' ) );
		}

		if ( $this->find_by_locale( $language->locale() ) instanceof Language ) {
			return new \WP_Error( 'mclogiora_duplicate_locale', __( 'A language with this locale already exists.', 'mclogiora' ) );
		}

		$now    = current_time( 'mysql', true );
		$table  = $this->tables->languages();
		$result = $this->wpdb->insert(
			$table,
			array(
				'language_code'          => $language->code(),
				'locale'                 => $language->locale(),
				'slug'                   => $this->slug_for_language( $language ),
				'native_name'            => $language->native_name(),
				'english_name'           => $language->english_name(),
				'text_direction'         => $language->direction(),
				'status'                 => $language->status(),
				'fallback_language_code' => null,
				'sort_order'             => $language->order(),
				'is_default'             => 0,
				'created_at'             => $now,
				'updated_at'             => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'mclogiora_language_create_failed', __( 'The language could not be created.', 'mclogiora' ) );
		}

		if ( $language->is_default() ) {
			return $this->set_default( $language->code() );
		}

		return $this->find_by_code( $language->code() );
	}

	/**
	 * Updates a language.
	 *
	 * @param Language $language Language entity.
	 * @return Language|\WP_Error
	 */
	public function update( Language $language ) {
		$existing = $this->find_by_code( $language->code() );

		if ( ! $existing instanceof Language ) {
			return new \WP_Error( 'mclogiora_language_not_found', __( 'The language could not be found.', 'mclogiora' ) );
		}

		$validation = $this->validate_language( $language );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$locale_owner = $this->find_by_locale( $language->locale() );

		if ( $locale_owner instanceof Language && $locale_owner->code() !== $language->code() ) {
			return new \WP_Error( 'mclogiora_duplicate_locale', __( 'A language with this locale already exists.', 'mclogiora' ) );
		}

		if ( $existing->is_default() && ! $language->is_default() ) {
			return new \WP_Error( 'mclogiora_default_language_required', __( 'Use Set Default to move the default marker before changing this language.', 'mclogiora' ) );
		}

		$table  = $this->tables->languages();
		$result = $this->wpdb->update(
			$table,
			array(
				'locale'         => $language->locale(),
				'slug'           => $this->slug_for_language( $language ),
				'native_name'    => $language->native_name(),
				'english_name'   => $language->english_name(),
				'text_direction' => $language->direction(),
				'status'         => $language->status(),
				'sort_order'     => $language->order(),
				'is_default'     => $existing->is_default() ? 1 : 0,
				'updated_at'     => current_time( 'mysql', true ),
			),
			array(
				'language_code' => $language->code(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ),
			array( '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'mclogiora_language_update_failed', __( 'The language could not be updated.', 'mclogiora' ) );
		}

		if ( $language->is_default() && ! $existing->is_default() ) {
			return $this->set_default( $language->code() );
		}

		return $this->find_by_code( $language->code() );
	}

	/**
	 * Enables a language.
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function enable( $code ) {
		return $this->set_status( $code, LanguageStatus::ACTIVE );
	}

	/**
	 * Disables a language.
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function disable( $code ) {
		$language = $this->find_by_code( $code );

		if ( $language instanceof Language && $language->is_default() ) {
			return new \WP_Error( 'mclogiora_disable_default_language', __( 'The default language cannot be disabled.', 'mclogiora' ) );
		}

		return $this->set_status( $code, LanguageStatus::INACTIVE );
	}

	/**
	 * Deletes a language when no integrity rule blocks it.
	 *
	 * @param string $code Language code.
	 * @return bool|\WP_Error
	 */
	public function delete( $code ) {
		$language = $this->find_by_code( $code );

		if ( ! $language instanceof Language ) {
			return new \WP_Error( 'mclogiora_language_not_found', __( 'The language could not be found.', 'mclogiora' ) );
		}

		if ( $language->is_default() ) {
			return new \WP_Error( 'mclogiora_delete_default_language', __( 'The default language cannot be deleted.', 'mclogiora' ) );
		}

		if ( $this->language_is_referenced( $language->code() ) ) {
			return new \WP_Error( 'mclogiora_language_referenced', __( 'This language is still referenced by translation records and cannot be deleted safely.', 'mclogiora' ) );
		}

		$table  = $this->tables->languages();
		$result = $this->wpdb->delete(
			$table,
			array(
				'language_code' => $language->code(),
			),
			array( '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'mclogiora_language_delete_failed', __( 'The language could not be deleted.', 'mclogiora' ) );
		}

		return true;
	}

	/**
	 * Sets the default language.
	 *
	 * @param string $code Language code.
	 * @return Language|\WP_Error
	 */
	public function set_default( $code ) {
		$language = $this->find_by_code( $code );

		if ( ! $language instanceof Language ) {
			return new \WP_Error( 'mclogiora_language_not_found', __( 'The language could not be found.', 'mclogiora' ) );
		}

		$table = $this->tables->languages();
		$this->clear_default_languages();

		$result = $this->wpdb->update(
			$table,
			array(
				'is_default' => 1,
				'status'     => LanguageStatus::ACTIVE,
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'language_code' => $language->code(),
			),
			array( '%d', '%s', '%s' ),
			array( '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'mclogiora_default_language_failed', __( 'The default language could not be updated.', 'mclogiora' ) );
		}

		return $this->find_by_code( $language->code() );
	}

	/**
	 * Reorders languages by language code sequence.
	 *
	 * @param string[] $language_codes Ordered language codes.
	 * @return bool|\WP_Error
	 */
	public function reorder( array $language_codes ) {
		if ( ! $this->table_ready() ) {
			return $this->database_unavailable_error();
		}

		$order = 1;

		foreach ( $language_codes as $code ) {
			$code = $this->normalize_code( $code );

			if ( '' === $code ) {
				continue;
			}

			$this->wpdb->update(
				$this->tables->languages(),
				array(
					'sort_order' => $order,
					'updated_at' => current_time( 'mysql', true ),
				),
				array(
					'language_code' => $code,
				),
				array( '%d', '%s' ),
				array( '%s' )
			);

			++$order;
		}

		return true;
	}

	/**
	 * Updates a language status.
	 *
	 * @param string $code Language code.
	 * @param string $status Status.
	 * @return Language|\WP_Error
	 */
	private function set_status( $code, $status ) {
		$language = $this->find_by_code( $code );

		if ( ! $language instanceof Language ) {
			return new \WP_Error( 'mclogiora_language_not_found', __( 'The language could not be found.', 'mclogiora' ) );
		}

		$result = $this->wpdb->update(
			$this->tables->languages(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'language_code' => $language->code(),
			),
			array( '%s', '%s' ),
			array( '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'mclogiora_language_status_failed', __( 'The language status could not be updated.', 'mclogiora' ) );
		}

		return $this->find_by_code( $language->code() );
	}

	/**
	 * Validates a language before storage.
	 *
	 * @param Language $language Language entity.
	 * @return true|\WP_Error
	 */
	private function validate_language( Language $language ) {
		if ( ! $this->table_ready() ) {
			return $this->database_unavailable_error();
		}

		if ( ! $this->is_valid_code( $language->code() ) ) {
			return new \WP_Error( 'mclogiora_invalid_language_code', __( 'Use a valid language code such as en, tr, or pt-br.', 'mclogiora' ) );
		}

		if ( ! $this->is_valid_locale( $language->locale() ) ) {
			return new \WP_Error( 'mclogiora_invalid_locale', __( 'Use a valid WordPress locale such as en_US or tr_TR.', 'mclogiora' ) );
		}

		if ( '' === $language->native_name() || '' === $language->english_name() ) {
			return new \WP_Error( 'mclogiora_language_name_required', __( 'Native and English language names are required.', 'mclogiora' ) );
		}

		if ( $this->has_multiple_default_languages() ) {
			return new \WP_Error( 'mclogiora_multiple_default_languages', __( 'More than one default language is currently configured. Resolve this before saving.', 'mclogiora' ) );
		}

		return true;
	}

	/**
	 * Clears all default language markers.
	 *
	 * @return void
	 */
	private function clear_default_languages() {
		if ( ! $this->table_ready() ) {
			return;
		}

		$this->wpdb->update(
			$this->tables->languages(),
			array(
				'is_default' => 0,
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'is_default' => 1,
			),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Returns whether multiple defaults are stored.
	 *
	 * @return bool
	 */
	private function has_multiple_default_languages() {
		if ( ! $this->table_ready() ) {
			return false;
		}

		$table = $this->tables->languages();
		$count = $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_default = 1" );

		return absint( $count ) > 1;
	}

	/**
	 * Returns whether a language is referenced by relation records.
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	private function language_is_referenced( $code ) {
		$code = $this->normalize_code( $code );

		if ( $this->schema_builder->table_exists( $this->tables->translation_groups() ) ) {
			$groups_table = $this->tables->translation_groups();
			$groups_count = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM {$groups_table} WHERE source_language = %s",
					$code
				)
			);

			if ( absint( $groups_count ) > 0 ) {
				return true;
			}
		}

		if ( $this->schema_builder->table_exists( $this->tables->translation_items() ) ) {
			$items_table = $this->tables->translation_items();
			$items_count = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM {$items_table} WHERE language_code = %s",
					$code
				)
			);

			if ( absint( $items_count ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether the language table exists.
	 *
	 * @return bool
	 */
	private function table_ready() {
		return $this->schema_builder->table_exists( $this->tables->languages() );
	}

	/**
	 * Creates a database unavailable error.
	 *
	 * @return \WP_Error
	 */
	private function database_unavailable_error() {
		return new \WP_Error( 'mclogiora_language_table_unavailable', __( 'The language table is not available. Reactivate the plugin to run database migrations.', 'mclogiora' ) );
	}

	/**
	 * Returns whether a language code is structurally valid.
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	private function is_valid_code( $code ) {
		return 1 === preg_match( '/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})?$/', $this->normalize_code( $code ) );
	}

	/**
	 * Returns whether a locale is structurally valid.
	 *
	 * @param string $locale Locale.
	 * @return bool
	 */
	private function is_valid_locale( $locale ) {
		return 1 === preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2})?$/', $this->normalize_locale( $locale ) );
	}

	/**
	 * Normalizes a language code.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	private function normalize_code( $code ) {
		return sanitize_key( (string) $code );
	}

	/**
	 * Normalizes a locale.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	private function normalize_locale( $locale ) {
		return sanitize_text_field( (string) $locale );
	}

	/**
	 * Returns a storage slug for a language.
	 *
	 * @param Language $language Language entity.
	 * @return string
	 */
	private function slug_for_language( Language $language ) {
		$slug = sanitize_title( $language->code() );

		return '' !== $slug ? $slug : $language->code();
	}

	/**
	 * Maps a database row to a language entity.
	 *
	 * @param object $row Database row.
	 * @return Language
	 */
	private function map_row( $row ) {
		return new Language(
			isset( $row->language_code ) ? $row->language_code : '',
			isset( $row->locale ) ? $row->locale : '',
			isset( $row->native_name ) ? $row->native_name : '',
			isset( $row->english_name ) ? $row->english_name : '',
			isset( $row->text_direction ) ? $row->text_direction : 'ltr',
			isset( $row->status ) ? $row->status : LanguageStatus::INACTIVE,
			isset( $row->sort_order ) ? absint( $row->sort_order ) : 0,
			! empty( $row->is_default )
		);
	}
}
