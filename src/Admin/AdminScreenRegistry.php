<?php
/**
 * Admin screen registry.
 *
 * @package McLogiora
 */

namespace McLogiora\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Collects submenu screens before WordPress registers admin menus.
 */
final class AdminScreenRegistry {
	/**
	 * Registered screens.
	 *
	 * @var AdminScreen[]
	 */
	private $screens = array();

	/**
	 * Registers a submenu screen.
	 *
	 * @param AdminScreen $screen Screen definition.
	 * @return void
	 */
	public function add( AdminScreen $screen ) {
		$this->screens[ $screen->slug() ] = $screen;
	}

	/**
	 * Returns registered screens.
	 *
	 * @return AdminScreen[]
	 */
	public function all() {
		return array_values( $this->screens );
	}
}
