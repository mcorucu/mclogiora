<?php
/**
 * WordPress theme detector.
 *
 * @package McLogiora
 */

namespace McLogiora\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the active theme identity without changing theme state.
 */
final class ThemeDetector {
	/**
	 * Detects the active theme.
	 *
	 * @return array<string, string>
	 */
	public function detect() {
		if ( ! function_exists( 'wp_get_theme' ) ) {
			return array(
				'id'      => '',
				'name'    => __( 'Unavailable before WordPress loads themes.', 'mclogiora' ),
				'version' => '',
			);
		}

		$theme = wp_get_theme();

		if ( ! is_object( $theme ) ) {
			return array(
				'id'      => '',
				'name'    => __( 'Theme could not be detected.', 'mclogiora' ),
				'version' => '',
			);
		}

		return array(
			'id'      => method_exists( $theme, 'get_stylesheet' ) ? sanitize_key( $theme->get_stylesheet() ) : '',
			'name'    => method_exists( $theme, 'get' ) ? sanitize_text_field( $theme->get( 'Name' ) ) : __( 'Active theme', 'mclogiora' ),
			'version' => method_exists( $theme, 'get' ) ? sanitize_text_field( $theme->get( 'Version' ) ) : '',
		);
	}
}
