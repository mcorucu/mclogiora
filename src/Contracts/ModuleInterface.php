<?php
/**
 * Module contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Contracts;

use McLogiora\Core\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for loadable plugin modules.
 */
interface ModuleInterface {
	/**
	 * Registers hooks and services for the module.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container );
}
