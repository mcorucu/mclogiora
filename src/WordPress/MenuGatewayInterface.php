<?php
/**
 * WordPress menu gateway contract.
 *
 * @package McLogiora
 */

namespace McLogiora\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Narrow seam over the WordPress navigation menu API.
 *
 * Kept separate from ContentGatewayInterface because menus have their own
 * WordPress API surface, and because menu translation can then be tested
 * without pulling post and term operations into the same double.
 */
interface MenuGatewayInterface {
	/**
	 * Returns a menu as an array, or null when it does not exist.
	 *
	 * @param int $menu_id Menu term identifier.
	 * @return array{term_id:int,name:string,slug:string}|null
	 */
	public function get_menu( $menu_id );

	/**
	 * Creates a menu and returns its term identifier.
	 *
	 * @param string $name Menu name.
	 * @return int|\WP_Error
	 */
	public function create_menu( $name );

	/**
	 * Deletes a menu. Used only to compensate a failed creation.
	 *
	 * @param int $menu_id Menu term identifier.
	 * @return bool
	 */
	public function delete_menu( $menu_id );

	/**
	 * Returns the items of a menu in menu order.
	 *
	 * Each item exposes the core fields needed to reproduce structure:
	 * db_id, menu_item_parent, menu_order, title, url, type, object,
	 * object_id, target, attr_title, description, xfn, and classes.
	 *
	 * @param int $menu_id Menu term identifier.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_menu_items( $menu_id );

	/**
	 * Adds an item to a menu and returns the new item identifier.
	 *
	 * @param int                 $menu_id Menu term identifier.
	 * @param array<string,mixed> $item_data Menu item data.
	 * @return int|\WP_Error
	 */
	public function add_menu_item( $menu_id, array $item_data );

	/**
	 * Updates an existing menu item.
	 *
	 * The full item data must be supplied. wp_update_nav_menu_item() rebuilds
	 * the item from the arguments it is given, so passing only the field being
	 * changed silently blanks every other field.
	 *
	 * @param int                 $menu_id Menu term identifier.
	 * @param int                 $item_id Menu item identifier.
	 * @param array<string,mixed> $item_data Complete menu item data.
	 * @return bool|\WP_Error
	 */
	public function update_menu_item( $menu_id, $item_id, array $item_data );
}
