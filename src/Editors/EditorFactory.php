<?php
/**
 * Editor adapter factory.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors;

use McLogiora\Compatibility\PluginDetector;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the core editor adapter set.
 */
final class EditorFactory {
	/**
	 * Plugin detector.
	 *
	 * @var PluginDetector
	 */
	private $plugin_detector;

	/**
	 * Constructor.
	 *
	 * @param PluginDetector $plugin_detector Plugin detector.
	 */
	public function __construct( PluginDetector $plugin_detector ) {
		$this->plugin_detector = $plugin_detector;
	}

	/**
	 * Creates the editor adapters shipped by the free foundation.
	 *
	 * @return EditorInterface[]
	 */
	public function create_defaults() {
		return array(
			new ClassicEditorAdapter(),
			new BlockEditorAdapter(),
			new ElementorAdapter( $this->plugin_detector ),
		);
	}
}
