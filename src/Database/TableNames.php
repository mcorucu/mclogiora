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
	 * wpdb instance.
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
	 * Returns all managed tables.
	 *
	 * @return string[]
	 */
	public function all() {
		return array(
			$this->languages(),
			$this->translation_groups(),
			$this->translation_items(),
		);
	}
}
