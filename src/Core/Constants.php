<?php
/**
 * Plugin constants value object.
 *
 * @package McLogiora
 */

namespace McLogiora\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Provides normalized access to plugin constants.
 */
final class Constants {
	/**
	 * Absolute plugin file path.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Absolute plugin file path.
	 */
	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	/**
	 * Returns the plugin version.
	 *
	 * @return string
	 */
	public function version() {
		return MCLOGIORA_VERSION;
	}

	/**
	 * Returns the plugin text domain.
	 *
	 * @return string
	 */
	public function text_domain() {
		return MCLOGIORA_TEXT_DOMAIN;
	}

	/**
	 * Returns the absolute plugin file path.
	 *
	 * @return string
	 */
	public function file() {
		return $this->plugin_file;
	}

	/**
	 * Returns the plugin base directory.
	 *
	 * @return string
	 */
	public function path() {
		return MCLOGIORA_PATH;
	}

	/**
	 * Returns the plugin base URL.
	 *
	 * @return string
	 */
	public function url() {
		return MCLOGIORA_URL;
	}

	/**
	 * Returns the plugin basename.
	 *
	 * @return string
	 */
	public function basename() {
		return MCLOGIORA_BASENAME;
	}
}
