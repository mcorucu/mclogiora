<?php
/**
 * Localization bootstrap.
 *
 * @package McLogiora
 */

namespace McLogiora\Localization;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Loads plugin translations.
 */
final class Localization implements ModuleInterface {
	/**
	 * Registers localization hooks.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Loads the plugin text domain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'mclogiora', false, dirname( MCLOGIORA_BASENAME ) . '/languages' );
	}
}
