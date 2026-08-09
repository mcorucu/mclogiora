<?php
/**
 * WordPress integration test bootstrap.
 *
 * Loads the official WordPress PHPUnit test suite and activates mcLogiora
 * inside it, so these tests exercise real WordPress APIs and a real database
 * rather than doubles.
 *
 * @package McLogiora
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/*
 * The WordPress test suite requires the PHPUnit Polyfills library and looks
 * for it at WP_TESTS_PHPUNIT_POLYFILLS_PATH. It is a dev dependency here, so
 * point the suite at the installed copy rather than asking every contributor
 * to install it separately.
 */
if ( ! getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	putenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH=' . dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

$mclogiora_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $mclogiora_tests_dir ) {
	$mclogiora_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( $mclogiora_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test suite at {$mclogiora_tests_dir}.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI bootstrap.
	echo "Run bin/install-wp-tests.sh first.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI bootstrap.
	exit( 1 );
}

require_once $mclogiora_tests_dir . '/includes/functions.php';

/**
 * Loads mcLogiora before WordPress finishes booting.
 *
 * @return void
 */
function mclogiora_manually_load_plugin() {
	require dirname( __DIR__ ) . '/mclogiora.php';
}

tests_add_filter( 'muplugins_loaded', 'mclogiora_manually_load_plugin' );

require $mclogiora_tests_dir . '/includes/bootstrap.php';
