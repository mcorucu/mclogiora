<?php
/**
 * Activation lifecycle.
 *
 * @package McLogiora
 */

namespace McLogiora\Core;

use McLogiora\Database\InstallerFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Handles activation through the installer architecture.
 */
final class Activation {
	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		$validator = new EnvironmentValidator();

		if ( ! $validator->is_valid() ) {
			deactivate_plugins( MCLOGIORA_BASENAME );

			wp_die(
				wp_kses_post( implode( '<br>', array_map( 'esc_html', $validator->get_errors() ) ) ),
				esc_html__( 'mcLogiora activation stopped', 'mclogiora' ),
				array( 'back_link' => true )
			);
		}

		InstallerFactory::create()->install();

		do_action( 'mclogiora_activated' );
	}
}
