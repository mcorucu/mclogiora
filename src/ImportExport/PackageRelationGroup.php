<?php
/**
 * Portable relation group.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * One translation group as a package carries it.
 *
 * The group key is the UUID mcLogiora already assigns to every group, and it
 * crosses site boundaries unchanged. That is the whole reason the schema uses
 * a UUID rather than the auto-increment column beside it: the UUID is the
 * group's identity, the integer is where the row happens to live. Carrying the
 * UUID makes an import idempotent -- a second import of the same package finds
 * the group it created the first time instead of building a duplicate -- and
 * inventing a second group identity for packages would throw that away.
 */
final class PackageRelationGroup {
	/**
	 * Group UUID.
	 *
	 * @var string
	 */
	private $group_key;

	/**
	 * Group items.
	 *
	 * @var PackageRelationItem[]
	 */
	private $items;

	/**
	 * Constructor.
	 *
	 * @param string                $group_key Group UUID.
	 * @param PackageRelationItem[] $items Group items, ordered by language.
	 */
	public function __construct( $group_key, array $items ) {
		$this->group_key = (string) $group_key;
		$this->items     = array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return $item instanceof PackageRelationItem;
				}
			)
		);
	}

	/**
	 * Returns the group UUID.
	 *
	 * @return string
	 */
	public function group_key() {
		return $this->group_key;
	}

	/**
	 * Returns the group items.
	 *
	 * @return PackageRelationItem[]
	 */
	public function items() {
		return $this->items;
	}

	/**
	 * Returns the source item, or null when the package carries none.
	 *
	 * @return PackageRelationItem|null
	 */
	public function source() {
		foreach ( $this->items as $item ) {
			if ( $item->is_source() ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Returns the items that are not the source.
	 *
	 * @return PackageRelationItem[]
	 */
	public function targets() {
		return array_values(
			array_filter(
				$this->items,
				static function ( PackageRelationItem $item ) {
					return ! $item->is_source();
				}
			)
		);
	}

	/**
	 * Returns the package representation with a fixed key order.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		$items = array();

		foreach ( $this->items as $item ) {
			$items[] = $item->to_array();
		}

		return array(
			'group_key' => $this->group_key,
			'items'     => $items,
		);
	}
}
