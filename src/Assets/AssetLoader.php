<?php
/**
 * Asset loading.
 *
 * @package McLogiora
 */

namespace McLogiora\Assets;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and conditionally loads admin assets.
 */
final class AssetLoader implements ModuleInterface {
	/**
	 * Registered mcLogiora admin screen IDs.
	 *
	 * @var string[]
	 */
	private $admin_screens = array();

	/**
	 * Registers hooks and exposes this loader as a service.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$container->set( self::class, $this );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Registers an admin screen for conditional loading.
	 *
	 * @param string $hook_suffix Admin page hook suffix.
	 * @return void
	 */
	public function add_admin_screen( $hook_suffix ) {
		if ( '' === $hook_suffix ) {
			return;
		}

		$this->admin_screens[] = $hook_suffix;
		$this->admin_screens   = array_values( array_unique( $this->admin_screens ) );
	}

	/**
	 * Enqueues admin assets only on mcLogiora screens.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, $this->admin_screens, true ) ) {
			return;
		}

		$css_file = MCLOGIORA_PATH . 'assets/css/admin.css';
		$js_file  = MCLOGIORA_PATH . 'assets/js/admin/admin.js';

		wp_enqueue_style(
			'mclogiora-admin',
			MCLOGIORA_URL . 'assets/css/admin.css',
			array(),
			file_exists( $css_file ) ? (string) filemtime( $css_file ) : MCLOGIORA_VERSION
		);

		wp_enqueue_script(
			'mclogiora-admin',
			MCLOGIORA_URL . 'assets/js/admin/admin.js',
			array(),
			file_exists( $js_file ) ? (string) filemtime( $js_file ) : MCLOGIORA_VERSION,
			true
		);
	}
}
