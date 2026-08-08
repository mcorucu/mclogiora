<?php
/**
 * Builder detector.
 *
 * @package McLogiora
 */

namespace McLogiora\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Detects known builders as metadata only. No builder adapter is loaded here.
 */
final class BuilderDetector {
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
	 * @param PluginDetector $plugin_detector Plugin detector.
	 * @param ThemeDetector  $theme_detector Theme detector.
	 */
	public function __construct( PluginDetector $plugin_detector, ThemeDetector $theme_detector ) {
		$this->plugin_detector = $plugin_detector;
		$this->theme_detector  = $theme_detector;
	}

	/**
	 * Detects known builder plugins and themes.
	 *
	 * @return array[]
	 */
	public function detect() {
		$known = array(
			array( 'id' => 'elementor', 'label' => __( 'Elementor', 'mclogiora' ), 'basename' => 'elementor/elementor.php', 'kind' => 'plugin' ),
			array( 'id' => 'bricks', 'label' => __( 'Bricks', 'mclogiora' ), 'theme' => 'bricks', 'kind' => 'theme' ),
			array( 'id' => 'beaver-builder', 'label' => __( 'Beaver Builder', 'mclogiora' ), 'basename' => 'beaver-builder/beaver-builder.php', 'kind' => 'plugin' ),
			array( 'id' => 'divi', 'label' => __( 'Divi', 'mclogiora' ), 'theme' => 'Divi', 'kind' => 'theme' ),
			array( 'id' => 'oxygen', 'label' => __( 'Oxygen', 'mclogiora' ), 'basename' => 'oxygen/functions.php', 'kind' => 'plugin' ),
			array( 'id' => 'wpbakery', 'label' => __( 'WPBakery', 'mclogiora' ), 'basename' => 'js_composer/js_composer.php', 'kind' => 'plugin' ),
			array( 'id' => 'kadence-blocks', 'label' => __( 'Kadence Blocks', 'mclogiora' ), 'basename' => 'kadence-blocks/kadence-blocks.php', 'kind' => 'plugin' ),
			array( 'id' => 'generateblocks', 'label' => __( 'GenerateBlocks', 'mclogiora' ), 'basename' => 'generateblocks/plugin.php', 'kind' => 'plugin' ),
			array( 'id' => 'spectra', 'label' => __( 'Spectra', 'mclogiora' ), 'basename' => 'ultimate-addons-for-gutenberg/ultimate-addons-for-gutenberg.php', 'kind' => 'plugin' ),
			array( 'id' => 'seedprod', 'label' => __( 'SeedProd', 'mclogiora' ), 'basename' => 'seedprod/seedprod.php', 'kind' => 'plugin' ),
		);
		$theme = $this->theme_detector->detect();
		$found = array();

		foreach ( $known as $builder ) {
			$active = 'plugin' === $builder['kind']
				? $this->plugin_detector->is_active( $builder['basename'] )
				: ( isset( $theme['id'] ) && strtolower( $theme['id'] ) === strtolower( $builder['theme'] ) );

			if ( $active ) {
				unset( $builder['basename'], $builder['theme'] );
				$found[] = $builder;
			}
		}

		return $found;
	}
}
