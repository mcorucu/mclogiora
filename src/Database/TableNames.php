<?php
/**
 * Database table names.
 *
 * @package McLogiora
 */

namespace McLogiora\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Provides prefixed table names.
 */
final class TableNames {
	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb WordPress database object.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Languages table.
	 *
	 * @return string
	 */
	public function languages() {
		return $this->wpdb->prefix . 'mclogiora_languages';
	}

	/**
	 * Translation groups table.
	 *
	 * @return string
	 */
	public function translation_groups() {
		return $this->wpdb->prefix . 'mclogiora_translation_groups';
	}

	/**
	 * Translation items table.
	 *
	 * @return string
	 */
	public function translation_items() {
		return $this->wpdb->prefix . 'mclogiora_translation_items';
	}

	/**
	 * Registered source strings table.
	 *
	 * @return string
	 */
	public function strings() {
		return $this->wpdb->prefix . 'mclogiora_strings';
	}

	/**
	 * String translations table.
	 *
	 * @return string
	 */
	public function string_translations() {
		return $this->wpdb->prefix . 'mclogiora_string_translations';
	}

	/**
	 * Media metadata translations table.
	 *
	 * @return string
	 */
	public function media_translations() {
		return $this->wpdb->prefix . 'mclogiora_media_translations';
	}

	/**
	 * Widget translations table.
	 *
	 * @return string
	 */
	public function widget_translations() {
		return $this->wpdb->prefix . 'mclogiora_widget_translations';
	}

	/**
	 * Returns all managed tables.
	 *
	 * @return string[]
	 */
	public function all() {
		return array(
			$this->languages(),
			$this->translation_groups(),
			$this->translation_items(),
			$this->strings(),
			$this->string_translations(),
			$this->media_translations(),
			$this->widget_translations(),
		);
	}
}
