<?php
/**
 * Text widget translation adapter.
 *
 * @package McLogiora
 */

namespace McLogiora\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the translatable fields of the WordPress Text widget.
 */
final class TextWidgetAdapter extends AbstractTextFieldAdapter {
	/**
	 * Returns the adapter identifier.
	 *
	 * @return string
	 */
	public function id() {
		return 'text';
	}

	/**
	 * Returns the human-readable adapter label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Text widget', 'mclogiora' );
	}

	/**
	 * Returns whether this adapter handles a widget type.
	 *
	 * @param string $widget_type Widget base identifier.
	 * @return bool
	 */
	public function supports( $widget_type ) {
		return 'text' === (string) $widget_type;
	}

	/**
	 * Returns the translatable field keys and their labels.
	 *
	 * @return array<string,string>
	 */
	public function translatable_fields() {
		return array(
			'title' => __( 'Title', 'mclogiora' ),
			'text'  => __( 'Text', 'mclogiora' ),
		);
	}
}
