<?php
/**
 * Scan scope confinement tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Strings\ScanScope;
use PHPUnit\Framework\TestCase;

/**
 * Covers the filesystem confinement rules of the string scanner.
 */
final class ScanScopeTest extends TestCase {
	/**
	 * Temporary root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Scope under test.
	 *
	 * @var ScanScope
	 */
	private $scope;

	/**
	 * Sets up a temporary directory tree.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->root = sys_get_temp_dir() . '/mclogiora-scope-' . uniqid( '', true );

		mkdir( $this->root . '/plugins/demo', 0777, true );
		mkdir( $this->root . '/outside', 0777, true );

		$this->scope = new ScanScope( array( 'plugin' => $this->root . '/plugins' ) );
	}

	/**
	 * Removes the temporary tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( array( '/plugins/demo', '/plugins', '/outside', '' ) as $path ) {
			if ( is_dir( $this->root . $path ) ) {
				@rmdir( $this->root . $path );
			}
		}

		parent::tearDown();
	}

	/**
	 * Asserts a legitimate target resolves inside the root.
	 *
	 * @return void
	 */
	public function test_resolves_a_legitimate_target() {
		$resolved = $this->scope->resolve( 'plugin', 'demo' );

		$this->assertIsString( $resolved );
		$this->assertStringEndsWith( 'demo', $resolved );
	}

	/**
	 * Provides slugs that must be rejected outright.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function malicious_slugs() {
		return array(
			'parent traversal'      => array( '../outside' ),
			'nested traversal'      => array( 'demo/../../outside' ),
			'absolute path'         => array( '/etc' ),
			'separator'             => array( 'demo/sub' ),
			'null byte'             => array( "demo\0" ),
			'encoded traversal'     => array( '..%2Foutside' ),
			'backslash separator'   => array( 'demo\\sub' ),
			'empty'                 => array( '' ),
		);
	}

	/**
	 * Asserts traversal and absolute paths are refused.
	 *
	 * @dataProvider malicious_slugs
	 * @param string $slug Candidate slug.
	 * @return void
	 */
	public function test_rejects_paths_that_escape_the_root( $slug ) {
		$result = $this->scope->resolve( 'plugin', $slug );

		$this->assertInstanceOf( \WP_Error::class, $result, 'Request data must never reach the filesystem as a path.' );
	}

	/**
	 * Asserts an unknown scope kind is refused.
	 *
	 * @return void
	 */
	public function test_rejects_unknown_scope_kind() {
		$result = $this->scope->resolve( 'core', 'demo' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_unknown_scan_scope', $result->get_error_code() );
	}

	/**
	 * Asserts a missing target is refused.
	 *
	 * @return void
	 */
	public function test_rejects_missing_target() {
		$result = $this->scope->resolve( 'plugin', 'does-not-exist' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mclogiora_scan_target_missing', $result->get_error_code() );
	}

	/**
	 * Asserts containment checking is accurate.
	 *
	 * @return void
	 */
	public function test_is_inside_rejects_sibling_prefixes() {
		$this->assertTrue( $this->scope->is_inside( '/var/www/plugins', '/var/www/plugins/demo' ) );
		$this->assertFalse( $this->scope->is_inside( '/var/www/plugins', '/var/www/plugins-evil/demo' ) );
		$this->assertFalse( $this->scope->is_inside( '/var/www/plugins', '/var/www' ) );
	}
}
