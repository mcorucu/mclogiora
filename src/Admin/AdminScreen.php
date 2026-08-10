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
 *
 * Titles may be given as a callable rather than a string, and for anything
 * translated they must be. Modules register on `plugins_loaded`, and calling
 * `__()` there asks WordPress for translations before `init`, which WordPress
 * 6.7 and later reports through `_load_textdomain_just_in_time()` as an error
 * on every single page load. Deferring the call until the menu is actually
 * built keeps registration inert, which is the same principle that keeps the
 * routing layer safe during installation.
 */
final class AdminScreen {
	/**
	 * Page title, or a callable returning one.
	 *
	 * @var string|callable
	 */
	private $page_title;

	/**
	 * Menu title, or a callable returning one.
	 *
	 * @var string|callable
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
	 * @param string|callable $page_title Page title, or a callable returning one.
	 * @param string|callable $menu_title Menu title, or a callable returning one.
	 * @param string          $capability Required capability.
	 * @param string          $slug Menu slug.
	 * @param callable        $callback Render callback.
	 */
	public function __construct( $page_title, $menu_title, $capability, $slug, $callback ) {
		$this->page_title = $page_title;
		$this->menu_title = $menu_title;
		$this->capability = $capability;
		$this->slug       = $slug;
		$this->callback   = $callback;
	}

	/**
	 * Returns the page title, resolving it if it was deferred.
	 *
	 * @return string
	 */
	public function page_title() {
		return $this->resolve( $this->page_title );
	}

	/**
	 * Returns the menu title, resolving it if it was deferred.
	 *
	 * @return string
	 */
	public function menu_title() {
		return $this->resolve( $this->menu_title );
	}

	/**
	 * Resolves a title that may have been deferred.
	 *
	 * @param string|callable $title Title or callable.
	 * @return string
	 */
	private function resolve( $title ) {
		return is_callable( $title ) ? (string) call_user_func( $title ) : (string) $title;
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
