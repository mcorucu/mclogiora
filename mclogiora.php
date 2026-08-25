<?php
/**
 * Plugin Name:       mcLogiora
 * Plugin URI:        https://mcorucu.com/
 * Description:       A multilingual translation and language management plugin for WordPress.
 * Version:           0.16.0
 * Requires at least: 6.5
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Author:            Mehmet Can Orucu
 * Author URI:        https://mcorucu.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mclogiora
 * Domain Path:       /languages
 *
 * @package McLogiora
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'MCLOGIORA_VERSION' ) ) {
	define( 'MCLOGIORA_VERSION', '0.16.0' );
}

if ( ! defined( 'MCLOGIORA_FILE' ) ) {
	define( 'MCLOGIORA_FILE', __FILE__ );
}

if ( ! defined( 'MCLOGIORA_PATH' ) ) {
	define( 'MCLOGIORA_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'MCLOGIORA_URL' ) ) {
	define( 'MCLOGIORA_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'MCLOGIORA_BASENAME' ) ) {
	define( 'MCLOGIORA_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'MCLOGIORA_TEXT_DOMAIN' ) ) {
	define( 'MCLOGIORA_TEXT_DOMAIN', 'mclogiora' );
}

if ( ! defined( 'MCLOGIORA_MINIMUM_PHP' ) ) {
	define( 'MCLOGIORA_MINIMUM_PHP', '7.4' );
}

if ( ! defined( 'MCLOGIORA_MINIMUM_WP' ) ) {
	define( 'MCLOGIORA_MINIMUM_WP', '6.5' );
}

if ( file_exists( MCLOGIORA_PATH . 'vendor/autoload.php' ) ) {
	require_once MCLOGIORA_PATH . 'vendor/autoload.php';
} else {
	require_once MCLOGIORA_PATH . 'src/Autoload.php';
}

require_once MCLOGIORA_PATH . 'src/Api/functions.php';
require_once MCLOGIORA_PATH . 'src/Switcher/template-tags.php';

register_activation_hook( __FILE__, array( '\\McLogiora\\Core\\Activation', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\McLogiora\\Core\\Deactivation', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\McLogiora\Core\Application::instance( MCLOGIORA_FILE )->boot();
	}
);
