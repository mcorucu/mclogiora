<?php
/**
 * Admin screen value object.
 *
 * @package McLogiora
 */

namespace McLogiora\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Describes a submenu screen.
 */
final class AdminScreen {
	/**
	 * Page title.
	 *
	 * @var string
	 */
	private $page_title;

	/**
	 * Menu title.
	 *
	 * @var string
	 */
	private $menu_title;

	/**
	 * Required capability.
	 *
	 * @var string
	 */
	private $capability;

	/**
	 * Menu slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Render callback.
	 *
	 * @var callable
	 */
	private $callback;

	/**
	 * Constructor.
	 *
	 * @param string   $page_title Page title.
	 * @param string   $menu_title Menu title.
	 * @param string   $capability Required capability.
	 * @param string   $slug Menu slug.
	 * @param callable $callback Render callback.
	 */
	public function __construct( $page_title, $menu_title, $capability, $slug, $callback ) {
		$this->page_title = $page_title;
		$this->menu_title = $menu_title;
		$this->capability = $capability;
		$this->slug       = $slug;
		$this->callback   = $callback;
	}

	/**
	 * Returns the page title.
	 *
	 * @return string
	 */
	public function page_title() {
		return $this->page_title;
	}

	/**
	 * Returns the menu title.
	 *
	 * @return string
	 */
	public function menu_title() {
		return $this->menu_title;
	}

	/**
	 * Returns the required capability.
	 *
	 * @return string
	 */
	public function capability() {
		return $this->capability;
	}

	/**
	 * Returns the menu slug.
	 *
	 * @return string
	 */
	public function slug() {
		return $this->slug;
	}

	/**
	 * Returns the render callback.
	 *
	 * @return callable
	 */
	public function callback() {
		return $this->callback;
	}
}
