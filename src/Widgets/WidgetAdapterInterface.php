<?php
/**
 * Widget translation adapter contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Declares which fields of a widget type are translatable.
 *
 * Widget instances are opaque option arrays whose shape is decided entirely
 * by whichever plugin registered the widget. An adapter is how mcLogiora
 * learns that a given key holds human-readable text rather than a colour, an
 * identifier, or a serialized blob. Without one, the plugin has no safe way
 * to tell the difference, which is why unknown widgets are left alone.
 */
interface WidgetAdapterInterface {
	/**
	 * Returns the adapter identifier.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Returns the human-readable adapter label.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Returns whether this adapter handles a widget type.
	 *
	 * @param string $widget_type Widget base identifier.
	 * @return bool
	 */
	public function supports( $widget_type );

	/**
	 * Returns the translatable field keys and their labels.
	 *
	 * @return array<string,string>
	 */
	public function translatable_fields();

	/**
	 * Extracts the translatable source values from a widget instance.
	 *
	 * @param array<string,mixed> $instance Widget instance options.
	 * @return array<string,string>
	 */
	public function extract( array $instance );

	/**
	 * Returns an instance with translated values applied.
	 *
	 * The source instance is never modified in place; a new array is
	 * returned so callers decide what to do with it. Phase 11 stores
	 * translations only, and Phase 12 decides when to apply them.
	 *
	 * @param array<string,mixed>  $instance Widget instance options.
	 * @param array<string,string> $translated Translated field values.
	 * @return array<string,mixed>
	 */
	public function apply( array $instance, array $translated );
}
