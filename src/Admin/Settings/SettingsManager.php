<?php
/**
 * Settings framework.
 *
 * @package McLogiora
 */

namespace McLogiora\Admin\Settings;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Provides a future settings registration point without storing options yet.
 */
final class SettingsManager implements ModuleInterface {
	/**
	 * Registers settings hooks.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Reserved settings hook.
	 *
	 * No settings, options, sections, or fields are created in Phase 02.
	 *
	 * @return void
	 */
	public function register_settings() {
		do_action( 'mclogiora_register_settings' );
	}
}
