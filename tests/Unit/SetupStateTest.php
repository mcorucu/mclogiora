<?php
/**
 * Setup state lifecycle tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Setup\SetupState;
use PHPUnit\Framework\TestCase;

/**
 * Proves that the activation hand-off and user journey are small and idempotent.
 */
final class SetupStateTest extends TestCase {
	/**
	 * Clears the state option before each assertion.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		delete_option( SetupState::OPTION_NAME );
		delete_option( 'mclogiora_db_version' );
	}

	/**
	 * Clears the state option after each assertion.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		delete_option( SetupState::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * A fresh activation creates one pending hand-off.
	 *
	 * @return void
	 */
	public function test_fresh_activation_is_pending_once() {
		SetupState::mark_activation_pending();

		$this->assertTrue( SetupState::has_pending_activation() );
		$this->assertTrue( SetupState::consume_activation() );
		$this->assertFalse( SetupState::has_pending_activation() );
		$this->assertSame( SetupState::IN_PROGRESS, SetupState::status() );
		$this->assertFalse( SetupState::consume_activation() );
	}

	/**
	 * Exiting does not create a recurring activation redirect.
	 *
	 * @return void
	 */
	public function test_exit_for_now_is_dismissed_and_reactivation_is_quiet() {
		SetupState::mark_activation_pending();
		SetupState::consume_activation();
		SetupState::dismiss();
		SetupState::mark_activation_pending();

		$this->assertSame( SetupState::DISMISSED, SetupState::status() );
		$this->assertFalse( SetupState::has_pending_activation() );
	}

	/**
	 * Completion remains complete when submitted more than once.
	 *
	 * @return void
	 */
	public function test_completion_is_idempotent() {
		SetupState::complete();
		SetupState::complete();

		$this->assertSame( SetupState::COMPLETED, SetupState::status() );
		$this->assertFalse( SetupState::has_pending_activation() );
	}
}
