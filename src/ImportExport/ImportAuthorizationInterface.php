<?php
/**
 * Import authorization contract.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * Security boundary used by the transport-neutral apply service.
 */
interface ImportAuthorizationInterface {
	/**
	 * Validates that the current actor may mutate translation state.
	 *
	 * @return true|\WP_Error
	 */
	public function validate_manage_capability();
}
