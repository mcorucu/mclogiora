<?php
/**
 * Installation failure record tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Core\InstallationFailure;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Pins that a failed installation cannot be mistaken for a successful one.
 *
 * Phase 12.1 found a real site running with half its schema because activation
 * called the installer and threw the result away. Nothing was broken loudly;
 * string, media, and widget translation simply had nowhere to store anything.
 */
final class InstallationFailureTest extends TestCase {
	/**
	 * Clears any recorded failure.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		InstallationFailure::clear();
	}

	/**
	 * Clears any recorded failure.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		InstallationFailure::clear();

		parent::tearDown();
	}

	/**
	 * Asserts a healthy install reports nothing.
	 *
	 * @return void
	 */
	public function test_healthy_installation_records_nothing() {
		$this->assertNull( InstallationFailure::get() );
		$this->assertFalse( InstallationFailure::exists() );
	}

	/**
	 * Asserts a failure is recorded with its code and detail.
	 *
	 * @return void
	 */
	public function test_failure_is_recorded() {
		InstallationFailure::record( new WP_Error( 'mclogiora_migration_incomplete', 'Migration 2 did not create: wp_mclogiora_strings' ) );

		$this->assertTrue( InstallationFailure::exists() );

		$stored = InstallationFailure::get();

		$this->assertSame( 'mclogiora_migration_incomplete', $stored['code'] );
		$this->assertStringContainsString( 'wp_mclogiora_strings', $stored['detail'] );
		$this->assertGreaterThan( 0, $stored['time'] );
	}

	/**
	 * Asserts a later success clears the record.
	 *
	 * The notice must describe a present condition, not a historical one.
	 *
	 * @return void
	 */
	public function test_success_clears_a_previous_failure() {
		InstallationFailure::record( new WP_Error( 'broken', 'Something failed' ) );
		InstallationFailure::clear();

		$this->assertFalse( InstallationFailure::exists() );
	}

	/**
	 * Asserts a malformed stored value is treated as no failure.
	 *
	 * @return void
	 */
	public function test_malformed_record_is_ignored() {
		update_option( InstallationFailure::OPTION_NAME, 'not-an-array', false );

		$this->assertNull( InstallationFailure::get() );
	}

	/**
	 * Asserts recorded detail carries no markup.
	 *
	 * The detail reaches an admin screen, so it must arrive already inert
	 * rather than relying on every future caller to escape it.
	 *
	 * @return void
	 */
	public function test_recorded_detail_is_sanitized() {
		InstallationFailure::record( new WP_Error( 'x', '<script>alert(1)</script>Tables missing' ) );

		$stored = InstallationFailure::get();

		$this->assertStringNotContainsString( '<script>', $stored['detail'] );
		$this->assertStringContainsString( 'Tables missing', $stored['detail'] );
	}
}
