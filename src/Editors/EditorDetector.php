<?php
/**
 * Editor detector.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves available editor adapters without loading editor assets.
 */
final class EditorDetector {
	/**
	 * Editor registry.
	 *
	 * @var EditorRegistry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param EditorRegistry $registry Editor registry.
	 */
	public function __construct( EditorRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Detects available editor adapters.
	 *
	 * @return EditorInterface[]
	 */
	public function detect() {
		return $this->registry->available();
	}

	/**
	 * Returns the editor for a context, if one is available.
	 *
	 * This only resolves an adapter. It does not register metaboxes, panels,
	 * scripts, or content hooks.
	 *
	 * @param EditorContext $context Editor context.
	 * @return EditorInterface|null
	 */
	public function for_context( EditorContext $context ) {
		foreach ( $this->detect() as $editor ) {
			if ( $editor->supports_context( $context ) ) {
				return $editor;
			}
		}

		return null;
	}
}
