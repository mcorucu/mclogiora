<?php
/**
 * Lightweight PSR-4 autoloader for environments without Composer.
 *
 * @package McLogiora
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'McLogiora\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );
		$relative_path  = str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';
		$file           = trailingslashit( MCLOGIORA_PATH ) . 'src/' . $relative_path;

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
