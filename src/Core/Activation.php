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

		$installed = InstallerFactory::create()->install();

		/*
		 * A failed migration must not read as a successful activation. It also
		 * must not kill the site: the database can be briefly unavailable for
		 * reasons that have nothing to do with this plugin, and refusing to
		 * activate would turn a transient fault into a support ticket. The
		 * failure is recorded instead, surfaced as an admin notice, and cleared
		 * automatically as soon as an install succeeds.
		 */
		if ( is_wp_error( $installed ) ) {
			InstallationFailure::record( $installed );
		} else {
			InstallationFailure::clear();
		}

		do_action( 'mclogiora_activated', $installed );
	}
}
