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
		/*
		 * Internal. Deliberately NOT part of the public developer API, and it
		 * carries no @since for that reason.
		 *
		 * It hands out the service container, so supporting it would turn every
		 * service inside into a permanent compatibility contract. It also lets a
		 * consumer return a list with the core modules missing, which disables
		 * the plugin silently. See docs/architecture/developer-api.md.
		 */
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
