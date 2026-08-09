<?php
/**
 * Block widget translation adapter.
 *
 * @package McLogiora
 */

namespace McLogiora\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the translatable fields of the WordPress Block widget.
 */
final class BlockWidgetAdapter extends AbstractTextFieldAdapter {
	/**
	 * Returns the adapter identifier.
	 *
	 * @return string
	 */
	public function id() {
		return 'block';
	}

	/**
	 * Returns the human-readable adapter label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Block widget', 'mclogiora' );
	}

	/**
	 * Returns whether this adapter handles a widget type.
	 *
	 * @param string $widget_type Widget base identifier.
	 * @return bool
	 */
	public function supports( $widget_type ) {
		return 'block' === (string) $widget_type;
	}

	/**
	 * Returns the translatable field keys and their labels.
	 *
	 * @return array<string,string>
	 */
	public function translatable_fields() {
		return array(
			'content' => __( 'Block content', 'mclogiora' ),
		);
	}
}
