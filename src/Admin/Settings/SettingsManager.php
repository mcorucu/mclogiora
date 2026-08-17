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
		/*
		 * Not yet a public contract, and it carries no @since for that reason.
		 *
		 * The concept -- third parties registering settings alongside
		 * mcLogiora's own -- is real, but there is nothing to register against:
		 * this passes no registry, and mcLogiora itself registers no setting
		 * through it. Today it is a private alias for `admin_init`, which
		 * consumers already have. Deferred until a settings registry exists to
		 * hand out. See docs/architecture/developer-api.md.
		 */
		do_action( 'mclogiora_register_settings' );
	}
}
