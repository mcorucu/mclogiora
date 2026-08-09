<?php
/**
 * Widget adapter registry.
 *
 * @package McLogiora
 */

namespace McLogiora\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the adapters that describe translatable widget fields.
 *
 * A widget with no adapter is reported as unsupported and left completely
 * untouched. That is the safe default: guessing which keys of an unknown
 * option array hold translatable text would eventually rewrite a setting that
 * only looked like a sentence.
 *
 * Third-party widgets are supported by registering an adapter through the
 * `mclogiora_widget_adapters` filter.
 */
final class WidgetAdapterRegistry {
	/**
	 * Registered adapters.
	 *
	 * @var WidgetAdapterInterface[]
	 */
	private $adapters;

	/**
	 * Constructor.
	 *
	 * @param WidgetAdapterInterface[] $adapters Adapters.
	 */
	public function __construct( array $adapters = array() ) {
		$this->adapters = array();

		foreach ( $adapters as $adapter ) {
			if ( $adapter instanceof WidgetAdapterInterface ) {
				$this->adapters[] = $adapter;
			}
		}
	}

	/**
	 * Builds the registry with the core adapters and any filtered additions.
	 *
	 * @return self
	 */
	public static function with_core_adapters() {
		$adapters = array(
			new TextWidgetAdapter(),
			new CustomHtmlWidgetAdapter(),
			new BlockWidgetAdapter(),
		);

		$filtered = apply_filters( 'mclogiora_widget_adapters', $adapters );

		return new self( is_array( $filtered ) ? $filtered : $adapters );
	}

	/**
	 * Returns every registered adapter.
	 *
	 * @return WidgetAdapterInterface[]
	 */
	public function all() {
		return $this->adapters;
	}

	/**
	 * Returns the adapter for a widget type, or null when unsupported.
	 *
	 * @param string $widget_type Widget base identifier.
	 * @return WidgetAdapterInterface|null
	 */
	public function for_type( $widget_type ) {
		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->supports( (string) $widget_type ) ) {
				return $adapter;
			}
		}

		return null;
	}

	/**
	 * Returns whether a widget type has an adapter.
	 *
	 * @param string $widget_type Widget base identifier.
	 * @return bool
	 */
	public function supports( $widget_type ) {
		return null !== $this->for_type( $widget_type );
	}
}
