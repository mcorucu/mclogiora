<?php
/**
 * Elementor adapter foundation.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors;

use McLogiora\Compatibility\PluginDetector;

defined( 'ABSPATH' ) || exit;

/**
 * Describes Elementor availability without loading Elementor classes or UI.
 */
final class ElementorAdapter implements EditorInterface {
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
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'elementor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Elementor', 'mclogiora' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return $this->plugin_detector->is_active( 'elementor/elementor.php' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param EditorContext $context Editor context.
	 */
	public function supports_context( EditorContext $context ) {
		return $this->is_available() && '' !== $context->object_type();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_placeholder_areas() {
		return array(
			array(
				'id'     => 'elementor-panel',
				'label'  => __( 'Elementor panel', 'mclogiora' ),
				'status' => 'planned',
			),
		);
	}
}
