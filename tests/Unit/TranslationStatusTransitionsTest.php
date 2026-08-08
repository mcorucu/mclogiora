<?php
/**
 * Status transition tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Relations\TranslationStatus;
use McLogiora\Workflows\TranslationStatusTransitions;
use PHPUnit\Framework\TestCase;

/**
 * Covers the translation status state machine.
 */
final class TranslationStatusTransitionsTest extends TestCase {
	/**
	 * Transitions under test.
	 *
	 * @var TranslationStatusTransitions
	 */
	private $transitions;

	/**
	 * Sets up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->transitions = new TranslationStatusTransitions();
	}

	/**
	 * Provides the transitions the phase requires.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function allowed_transitions() {
		return array(
			'draft to needs review'         => array( TranslationStatus::DRAFT, TranslationStatus::NEEDS_REVIEW ),
			'draft to translated'           => array( TranslationStatus::DRAFT, TranslationStatus::TRANSLATED ),
			'needs review to translated'    => array( TranslationStatus::NEEDS_REVIEW, TranslationStatus::TRANSLATED ),
			'translated to needs update'    => array( TranslationStatus::TRANSLATED, TranslationStatus::NEEDS_UPDATE ),
			'needs update to needs review'  => array( TranslationStatus::NEEDS_UPDATE, TranslationStatus::NEEDS_REVIEW ),
			'needs update to translated'    => array( TranslationStatus::NEEDS_UPDATE, TranslationStatus::TRANSLATED ),
			'translated to needs review'    => array( TranslationStatus::TRANSLATED, TranslationStatus::NEEDS_REVIEW ),
		);
	}

	/**
	 * Asserts each required transition is permitted.
	 *
	 * @dataProvider allowed_transitions
	 * @param string $from Current status.
	 * @param string $to Requested status.
	 * @return void
	 */
	public function test_allowed_transitions_are_permitted( $from, $to ) {
		$this->assertTrue( $this->transitions->is_allowed( $from, $to ) );
		$this->assertTrue( $this->transitions->validate( $from, $to ) );
	}

	/**
	 * Provides transitions that must be rejected.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function forbidden_transitions() {
		return array(
			'cannot assign original'          => array( TranslationStatus::DRAFT, TranslationStatus::ORIGINAL, 'mclogiora_original_not_assignable' ),
			'cannot assign missing'           => array( TranslationStatus::DRAFT, TranslationStatus::MISSING, 'mclogiora_missing_not_assignable' ),
			'cannot assign machine suggested' => array( TranslationStatus::DRAFT, TranslationStatus::MACHINE_SUGGESTED, 'mclogiora_machine_status_reserved' ),
			'original is immutable'           => array( TranslationStatus::ORIGINAL, TranslationStatus::TRANSLATED, 'mclogiora_original_status_immutable' ),
			'unchanged status is rejected'    => array( TranslationStatus::DRAFT, TranslationStatus::DRAFT, 'mclogiora_status_unchanged' ),
			'unknown current status'          => array( 'nonsense', TranslationStatus::TRANSLATED, 'mclogiora_unknown_current_status' ),
			'unknown target status'           => array( TranslationStatus::DRAFT, 'nonsense', 'mclogiora_unknown_target_status' ),
			'disabled cannot go to draft'     => array( TranslationStatus::DISABLED, TranslationStatus::DRAFT, 'mclogiora_invalid_status_transition' ),
		);
	}

	/**
	 * Asserts forbidden transitions return the expected error.
	 *
	 * @dataProvider forbidden_transitions
	 * @param string $from Current status.
	 * @param string $to Requested status.
	 * @param string $expected_code Expected error code.
	 * @return void
	 */
	public function test_forbidden_transitions_are_rejected( $from, $to, $expected_code ) {
		$result = $this->transitions->validate( $from, $to );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $expected_code, $result->get_error_code() );
	}

	/**
	 * Asserts the original role has no outgoing transitions at all.
	 *
	 * @return void
	 */
	public function test_original_has_no_outgoing_transitions() {
		$this->assertSame( array(), $this->transitions->allowed_from( TranslationStatus::ORIGINAL ) );
	}

	/**
	 * Asserts an unknown status exposes no transitions.
	 *
	 * @return void
	 */
	public function test_unknown_status_exposes_no_transitions() {
		$this->assertSame( array(), $this->transitions->allowed_from( 'not-a-status' ) );
	}
}
