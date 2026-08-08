<?php
/**
 * Module loader.
 *
 * @package McLogiora
 */

namespace McLogiora\Core;

use McLogiora\Contracts\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registers foundation and future domain modules.
 */
final class ModuleLoader {
	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Modules queued for registration.
	 *
	 * @var ModuleInterface[]
	 */
	private $modules = array();

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Adds a module.
	 *
	 * @param ModuleInterface $module Module instance.
	 * @return void
	 */
	public function add( ModuleInterface $module ) {
		$this->modules[] = $module;
	}

	/**
	 * Registers all modules.
	 *
	 * @return void
	 */
	public function register() {
		$modules = apply_filters( 'mclogiora_register_modules', $this->modules, $this->container );

		if ( ! is_array( $modules ) ) {
			$modules = $this->modules;
		}

		foreach ( $modules as $module ) {
			if ( $module instanceof ModuleInterface ) {
				$module->register( $this->container );
			}
		}
	}
}
