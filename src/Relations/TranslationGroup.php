<?php
/**
 * Translation group entity.
 *
 * @package McLogiora
 */

namespace McLogiora\Relations;

defined( 'ABSPATH' ) || exit;

/**
 * Groups an original item with its translated alternatives.
 */
final class TranslationGroup {
	/**
	 * Group identifier.
	 *
	 * @var string
	 */
	private $group_key;

	/**
	 * Group items.
	 *
	 * @var TranslationItem[]
	 */
	private $items;

	/**
	 * Constructor.
	 *
	 * @param string            $group_key Group key.
	 * @param TranslationItem[] $items Group items.
	 */
	public function __construct( $group_key, array $items = array() ) {
		$this->group_key = sanitize_key( $group_key );
		$this->items     = array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return $item instanceof TranslationItem;
				}
			)
		);
	}

	/**
	 * Returns the group key.
	 *
	 * @return string
	 */
	public function group_key() {
		return $this->group_key;
	}

	/**
	 * Returns all group items.
	 *
	 * @return TranslationItem[]
	 */
	public function items() {
		return $this->items;
	}

	/**
	 * Returns the original item.
	 *
	 * @return TranslationItem|null
	 */
	public function original() {
		foreach ( $this->items as $item ) {
			if ( $item->is_original() ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Returns translated target items.
	 *
	 * @return TranslationItem[]
	 */
	public function targets() {
		return array_values(
			array_filter(
				$this->items,
				static function ( TranslationItem $item ) {
					return $item->is_target();
				}
			)
		);
	}

	/**
	 * Returns whether the group contains an item.
	 *
	 * @param TranslationItem $needle Item to match.
	 * @return bool
	 */
	public function contains( TranslationItem $needle ) {
		foreach ( $this->items as $item ) {
			if (
				$item->content_type() === $needle->content_type()
				&& $item->object_key() === $needle->object_key()
				&& $item->language_code() === $needle->language_code()
			) {
				return true;
			}
		}

		return false;
	}
}
