<?php
/**
 * WordPress plugin detector.
 *
 * @package McLogiora
 */

namespace McLogiora\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Reads active plugin metadata without loading integration code.
 */
final class PluginDetector {
	/**
	 * Returns active plugin basenames.
	 *
	 * @return string[]
	 */
	public function active_plugins() {
		$plugins = function_exists( 'get_option' ) ? get_option( 'active_plugins', array() ) : array();
		$network = function_exists( 'get_site_option' ) ? get_site_option( 'active_sitewide_plugins', array() ) : array();
		$network = is_array( $network ) ? array_keys( $network ) : array();
		$plugins = is_array( $plugins ) ? $plugins : array();

		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_merge( $plugins, $network ) ) ) ) );
	}

	/**
	 * Returns whether a plugin basename is active.
	 *
	 * @param string $basename Plugin basename.
	 * @return bool
	 */
	public function is_active( $basename ) {
		return in_array( sanitize_text_field( $basename ), $this->active_plugins(), true );
	}

	/**
	 * Detects known compatibility plugins.
	 *
	 * @return array[]
	 */
	public function detect_known() {
		$known = array(
			array( 'id' => 'classic-editor', 'label' => __( 'Classic Editor', 'mclogiora' ), 'basename' => 'classic-editor/classic-editor.php' ),
			array( 'id' => 'elementor', 'label' => __( 'Elementor', 'mclogiora' ), 'basename' => 'elementor/elementor.php' ),
			array( 'id' => 'advanced-custom-fields', 'label' => __( 'Advanced Custom Fields', 'mclogiora' ), 'basename' => 'advanced-custom-fields/acf.php' ),
			array( 'id' => 'woocommerce', 'label' => __( 'WooCommerce', 'mclogiora' ), 'basename' => 'woocommerce/woocommerce.php' ),
		);
		$detected = array();

		foreach ( $known as $plugin ) {
			if ( $this->is_active( $plugin['basename'] ) ) {
				$detected[] = $plugin;
			}
		}

		return $detected;
	}
}
