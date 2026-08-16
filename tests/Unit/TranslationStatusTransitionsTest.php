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

			/*
			 * Phase 16. A machine suggestion may replace text in any state
			 * that is still awaiting work, and leaving the state again is a
			 * human decision -- including the one that matters most,
			 * machine_suggested to translated, which is the review action.
			 */
			'draft to machine suggested'        => array( TranslationStatus::DRAFT, TranslationStatus::MACHINE_SUGGESTED ),
			'needs review to machine suggested' => array( TranslationStatus::NEEDS_REVIEW, TranslationStatus::MACHINE_SUGGESTED ),
			'needs update to machine suggested' => array( TranslationStatus::NEEDS_UPDATE, TranslationStatus::MACHINE_SUGGESTED ),
			'machine suggested to needs review' => array( TranslationStatus::MACHINE_SUGGESTED, TranslationStatus::NEEDS_REVIEW ),
			'machine suggested to translated'   => array( TranslationStatus::MACHINE_SUGGESTED, TranslationStatus::TRANSLATED ),
			'machine suggested to draft'        => array( TranslationStatus::MACHINE_SUGGESTED, TranslationStatus::DRAFT ),
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
			/*
			 * Phase 10 forbade every route into machine_suggested while the
			 * state was reserved. Phase 16 opens the three defensible entries
			 * and keeps the rest closed, so what matters now is that a
			 * finished translation cannot be overwritten by a machine, and
			 * that the structural and administrative states stay unreachable.
			 */
			'translated cannot be machine suggested' => array( TranslationStatus::TRANSLATED, TranslationStatus::MACHINE_SUGGESTED, 'mclogiora_invalid_status_transition' ),
			'disabled cannot be machine suggested' => array( TranslationStatus::DISABLED, TranslationStatus::MACHINE_SUGGESTED, 'mclogiora_invalid_status_transition' ),
			'missing cannot be machine suggested' => array( TranslationStatus::MISSING, TranslationStatus::MACHINE_SUGGESTED, 'mclogiora_invalid_status_transition' ),
			'original cannot be machine suggested' => array( TranslationStatus::ORIGINAL, TranslationStatus::MACHINE_SUGGESTED, 'mclogiora_original_status_immutable' ),
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
