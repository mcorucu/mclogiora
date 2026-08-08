<?php
/**
 * WordPress menu gateway.
 *
 * @package McLogiora
 */

namespace McLogiora\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Calls the real WordPress navigation menu API.
 *
 * Contains no domain logic. Theme location assignments are never written
 * here: which menu appears in which location per language is a rendering
 * decision that belongs to Phase 12.
 */
final class MenuGateway implements MenuGatewayInterface {
	/**
	 * Returns a menu as an array, or null when it does not exist.
	 *
	 * @param int $menu_id Menu term identifier.
	 * @return array{term_id:int,name:string,slug:string}|null
	 */
	public function get_menu( $menu_id ) {
		$menu = wp_get_nav_menu_object( (int) $menu_id );

		if ( ! $menu instanceof \WP_Term ) {
			return null;
		}

		return array(
			'term_id' => (int) $menu->term_id,
			'name'    => (string) $menu->name,
			'slug'    => (string) $menu->slug,
		);
	}

	/**
	 * Creates a menu and returns its term identifier.
	 *
	 * @param string $name Menu name.
	 * @return int|\WP_Error
	 */
	public function create_menu( $name ) {
		$result = wp_create_nav_menu( (string) $name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return (int) $result;
	}

	/**
	 * Deletes a menu. Used only to compensate a failed creation.
	 *
	 * @param int $menu_id Menu term identifier.
	 * @return bool
	 */
	public function delete_menu( $menu_id ) {
		return true === wp_delete_nav_menu( (int) $menu_id );
	}

	/**
	 * Returns the items of a menu in menu order.
	 *
	 * @param int $menu_id Menu term identifier.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_menu_items( $menu_id ) {
		$items = wp_get_nav_menu_items(
			(int) $menu_id,
			array(
				'order'                  => 'ASC',
				'orderby'                => 'menu_order',
				'update_post_term_cache' => false,
			)
		);

		if ( ! is_array( $items ) ) {
			return array();
		}

		$mapped = array();

		foreach ( $items as $item ) {
			$mapped[] = array(
				'db_id'            => (int) $item->db_id,
				'menu_item_parent' => (int) $item->menu_item_parent,
				'menu_order'       => (int) $item->menu_order,
				'title'            => (string) $item->title,
				'url'              => (string) $item->url,
				'type'             => (string) $item->type,
				'object'           => (string) $item->object,
				'object_id'        => (int) $item->object_id,
				'target'           => (string) $item->target,
				'attr_title'       => (string) $item->attr_title,
				'description'      => (string) $item->description,
				'xfn'              => (string) $item->xfn,
				'classes'          => is_array( $item->classes ) ? implode( ' ', $item->classes ) : (string) $item->classes,
			);
		}

		return $mapped;
	}

	/**
	 * Adds an item to a menu and returns the new item identifier.
	 *
	 * @param int                 $menu_id Menu term identifier.
	 * @param array<string,mixed> $item_data Menu item data.
	 * @return int|\WP_Error
	 */
	public function add_menu_item( $menu_id, array $item_data ) {
		$result = wp_update_nav_menu_item( (int) $menu_id, 0, $item_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return (int) $result;
	}

	/**
	 * Updates the parent of an existing menu item.
	 *
	 * @param int $menu_id Menu term identifier.
	 * @param int $item_id Menu item identifier.
	 * @param int $parent_id New parent menu item identifier.
	 * @return bool|\WP_Error
	 */
	public function set_menu_item_parent( $menu_id, $item_id, $parent_id ) {
		$result = wp_update_nav_menu_item(
			(int) $menu_id,
			(int) $item_id,
			array( 'menu-item-parent-id' => (int) $parent_id )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}
}
