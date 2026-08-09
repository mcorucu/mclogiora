<?php
/**
 * In-memory menu gateway for tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Support;

use McLogiora\WordPress\MenuGatewayInterface;

/**
 * Emulates the WordPress menu API well enough to prove structure copying.
 */
final class FakeMenuGateway implements MenuGatewayInterface {
	/**
	 * Menus keyed by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public $menus = array();

	/**
	 * Items keyed by menu id.
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	public $items = array();

	/**
	 * Deleted menu ids.
	 *
	 * @var int[]
	 */
	public $deleted_menus = array();

	/**
	 * Forces add_menu_item() to fail when set.
	 *
	 * @var \WP_Error|null
	 */
	public $add_item_error = null;

	/**
	 * Next generated identifier.
	 *
	 * @var int
	 */
	private $sequence = 100;

	/**
	 * Adds a menu fixture.
	 *
	 * @param int    $id Menu id.
	 * @param string $name Menu name.
	 * @return void
	 */
	public function add_menu( $id, $name ) {
		$this->menus[ (int) $id ] = array(
			'term_id' => (int) $id,
			'name'    => (string) $name,
			'slug'    => sanitize_title( $name ),
		);

		if ( ! isset( $this->items[ (int) $id ] ) ) {
			$this->items[ (int) $id ] = array();
		}
	}

	/**
	 * Adds a menu item fixture.
	 *
	 * @param int                 $menu_id Menu id.
	 * @param array<string,mixed> $item Item data.
	 * @return void
	 */
	public function add_item( $menu_id, array $item ) {
		$this->items[ (int) $menu_id ][] = array_merge(
			array(
				'db_id'            => 0,
				'menu_item_parent' => 0,
				'menu_order'       => 0,
				'title'            => '',
				'url'              => '',
				'type'             => 'custom',
				'object'           => 'custom',
				'object_id'        => 0,
				'target'           => '',
				'attr_title'       => '',
				'description'      => '',
				'xfn'              => '',
				'classes'          => '',
			),
			$item
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $menu_id Menu id.
	 * @return array{term_id:int,name:string,slug:string}|null
	 */
	public function get_menu( $menu_id ) {
		return isset( $this->menus[ (int) $menu_id ] ) ? $this->menus[ (int) $menu_id ] : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name Menu name.
	 * @return int|\WP_Error
	 */
	public function create_menu( $name ) {
		++$this->sequence;
		$this->add_menu( $this->sequence, $name );

		return $this->sequence;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $menu_id Menu id.
	 * @return bool
	 */
	public function delete_menu( $menu_id ) {
		$this->deleted_menus[] = (int) $menu_id;
		unset( $this->menus[ (int) $menu_id ], $this->items[ (int) $menu_id ] );

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $menu_id Menu id.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_menu_items( $menu_id ) {
		return isset( $this->items[ (int) $menu_id ] ) ? $this->items[ (int) $menu_id ] : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                 $menu_id Menu id.
	 * @param array<string,mixed> $item_data Item data.
	 * @return int|\WP_Error
	 */
	public function add_menu_item( $menu_id, array $item_data ) {
		if ( $this->add_item_error instanceof \WP_Error ) {
			return $this->add_item_error;
		}

		++$this->sequence;

		$this->items[ (int) $menu_id ][] = array(
			'db_id'            => $this->sequence,
			'menu_item_parent' => 0,
			'menu_order'       => isset( $item_data['menu-item-position'] ) ? (int) $item_data['menu-item-position'] : 0,
			'title'            => isset( $item_data['menu-item-title'] ) ? (string) $item_data['menu-item-title'] : '',
			'url'              => isset( $item_data['menu-item-url'] ) ? (string) $item_data['menu-item-url'] : '',
			'type'             => isset( $item_data['menu-item-type'] ) ? (string) $item_data['menu-item-type'] : 'custom',
			'object'           => isset( $item_data['menu-item-object'] ) ? (string) $item_data['menu-item-object'] : 'custom',
			'object_id'        => isset( $item_data['menu-item-object-id'] ) ? (int) $item_data['menu-item-object-id'] : 0,
			'target'           => '',
			'attr_title'       => '',
			'description'      => '',
			'xfn'              => '',
			'classes'          => '',
		);

		return $this->sequence;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Rebuilds the item from the supplied data, exactly as
	 * wp_update_nav_menu_item() does, so a caller that omits fields loses them
	 * here too.
	 *
	 * @param int                 $menu_id Menu id.
	 * @param int                 $item_id Item id.
	 * @param array<string,mixed> $item_data Item data.
	 * @return bool|\WP_Error
	 */
	public function update_menu_item( $menu_id, $item_id, array $item_data ) {
		foreach ( $this->items[ (int) $menu_id ] as $index => $item ) {
			if ( (int) $item['db_id'] !== (int) $item_id ) {
				continue;
			}

			$this->items[ (int) $menu_id ][ $index ] = array(
				'db_id'            => (int) $item_id,
				'menu_item_parent' => isset( $item_data['menu-item-parent-id'] ) ? (int) $item_data['menu-item-parent-id'] : 0,
				'menu_order'       => isset( $item_data['menu-item-position'] ) ? (int) $item_data['menu-item-position'] : 0,
				'title'            => isset( $item_data['menu-item-title'] ) ? (string) $item_data['menu-item-title'] : '',
				'url'              => isset( $item_data['menu-item-url'] ) ? (string) $item_data['menu-item-url'] : '',
				'type'             => isset( $item_data['menu-item-type'] ) ? (string) $item_data['menu-item-type'] : 'custom',
				'object'           => isset( $item_data['menu-item-object'] ) ? (string) $item_data['menu-item-object'] : 'custom',
				'object_id'        => isset( $item_data['menu-item-object-id'] ) ? (int) $item_data['menu-item-object-id'] : 0,
				'target'           => '',
				'attr_title'       => '',
				'description'      => '',
				'xfn'              => '',
				'classes'          => '',
			);

			return true;
		}

		return new \WP_Error( 'mclogiora_item_missing', 'Menu item not found.' );
	}
}
