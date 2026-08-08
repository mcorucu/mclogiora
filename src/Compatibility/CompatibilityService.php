<?php
/**
 * Compatibility aggregation service.
 *
 * @package McLogiora
 */

namespace McLogiora\Compatibility;

use McLogiora\Editors\EditorDetector;

defined( 'ABSPATH' ) || exit;

/**
 * Provides one read-only compatibility snapshot for admin diagnostics.
 */
final class CompatibilityService {
	/**
	 * Editor detector.
	 *
	 * @var EditorDetector
	 */
	private $editor_detector;

	/**
	 * Builder detector.
	 *
	 * @var BuilderDetector
	 */
	private $builder_detector;

	/**
	 * Plugin detector.
	 *
	 * @var PluginDetector
	 */
	private $plugin_detector;

	/**
	 * Theme detector.
	 *
	 * @var ThemeDetector
	 */
	private $theme_detector;

	/**
	 * Constructor.
	 *
	 * @param EditorDetector $editor_detector Editor detector.
	 * @param BuilderDetector $builder_detector Builder detector.
	 * @param PluginDetector $plugin_detector Plugin detector.
	 * @param ThemeDetector  $theme_detector Theme detector.
	 */
	public function __construct( EditorDetector $editor_detector, BuilderDetector $builder_detector, PluginDetector $plugin_detector, ThemeDetector $theme_detector ) {
		$this->editor_detector  = $editor_detector;
		$this->builder_detector = $builder_detector;
		$this->plugin_detector  = $plugin_detector;
		$this->theme_detector   = $theme_detector;
	}

	/**
	 * Returns the current compatibility snapshot.
	 *
	 * @return array
	 */
	public function snapshot() {
		return array(
			'editors' => $this->editor_detector->detect(),
			'builders' => $this->builder_detector->detect(),
			'plugins' => $this->plugin_detector->detect_known(),
			'theme' => $this->theme_detector->detect(),
		);
	}
}
