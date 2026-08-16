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

		/**
		 * Fires once mcLogiora has finished activating.
		 *
		 * Runs last in the activation routine: the environment has already
		 * validated, the schema install has been attempted, and any failure has
		 * been recorded. It does not fire at all when validation fails, because
		 * activation is aborted before this point.
		 *
		 * A `WP_Error` argument means the tables are not there. Anything that
		 * seeds data must check it rather than assume a successful activation.
		 *
		 * @since 0.1.0
		 *
		 * @param true|\WP_Error $installed Whether the schema install succeeded.
		 */
		do_action( 'mclogiora_activated', $installed );
	}
}
