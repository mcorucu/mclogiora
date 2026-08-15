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

/**
 * Loads any builder plugins present in the test installation.
 *
 * The builder compatibility job installs the free editions of Elementor, ACF,
 * Kadence Blocks and Beaver Builder into the WordPress test install; every
 * other run has none of them. Each is loaded only when its file is really
 * there, and the tests that need one skip when it is not, so the ordinary
 * suites stay fast and a download failure degrades to "nothing extra proven"
 * rather than a false failure.
 *
 * Loaded after mcLogiora so a builder cannot register ahead of the plugin
 * under test, matching the order a real site boots in.
 *
 * @return void
 */
function mclogiora_manually_load_builder_plugins() {
	$core_dir = getenv( 'WP_CORE_DIR' );

	if ( ! $core_dir ) {
		$core_dir = '/tmp/wordpress';
	}

	$candidates = array(
		'elementor/elementor.php',
		'advanced-custom-fields/acf.php',
		'kadence-blocks/kadence-blocks.php',
		'beaver-builder-lite-version/fl-builder.php',
	);

	foreach ( $candidates as $candidate ) {
		$path = $core_dir . '/wp-content/plugins/' . $candidate;

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}

tests_add_filter( 'muplugins_loaded', 'mclogiora_manually_load_builder_plugins' );

require $mclogiora_tests_dir . '/includes/bootstrap.php';
