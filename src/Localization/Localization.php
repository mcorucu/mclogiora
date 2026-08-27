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
 * Registers the plugin's translation integration.
 *
 * WordPress.org supplies plugin language packs and loads them automatically
 * for the plugin text domain. The POT file remains in the source tree for
 * translators; this module intentionally does not call the discouraged
 * load_plugin_textdomain() function.
 */
final class Localization implements ModuleInterface {
	/**
	 * Registers localization hooks.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		unset( $container );
	}
}
