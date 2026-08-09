<?php
/**
 * Custom HTML widget translation adapter.
 *
 * @package McLogiora
 */

namespace McLogiora\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the translatable fields of the WordPress Custom HTML widget.
 */
final class CustomHtmlWidgetAdapter extends AbstractTextFieldAdapter {
	/**
	 * Returns the adapter identifier.
	 *
	 * @return string
	 */
	public function id() {
		return 'custom_html';
	}

	/**
	 * Returns the human-readable adapter label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Custom HTML widget', 'mclogiora' );
	}

	/**
	 * Returns whether this adapter handles a widget type.
	 *
	 * @param string $widget_type Widget base identifier.
	 * @return bool
	 */
	public function supports( $widget_type ) {
		return 'custom_html' === (string) $widget_type;
	}

	/**
	 * Returns the translatable field keys and their labels.
	 *
	 * @return array<string,string>
	 */
	public function translatable_fields() {
		return array(
			'title'   => __( 'Title', 'mclogiora' ),
			'content' => __( 'HTML', 'mclogiora' ),
		);
	}
}
