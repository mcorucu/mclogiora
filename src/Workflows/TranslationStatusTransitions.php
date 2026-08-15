<?php
/**
 * Translation status transition policy.
 *
 * @package McLogiora
 */

namespace McLogiora\Workflows;

use McLogiora\Relations\TranslationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Defines which translation status changes are allowed.
 *
 * Statuses were declared in Phase 04 but had no transition rules, so any
 * status could previously be written over any other. This class makes the
 * lifecycle explicit and is the only place transitions are decided.
 */
final class TranslationStatusTransitions {
	/**
	 * Allowed transitions, keyed by current status.
	 *
	 * Notes on the deliberate omissions:
	 *
	 * - `original` is a structural role, not a workflow status. It is set when
	 *   a group is created and is never reachable or leavable through a normal
	 *   translation action. Changing which item is the source is a distinct
	 *   source-reassignment workflow that does not exist yet.
	 * - `missing` is a computed absence rather than a stored state. It
	 *   describes a language slot with no item, so nothing transitions out of
	 *   it; creating or linking content produces a real item instead.
	 * - `machine_suggested` is entered in Phase 16, by applying a translation
	 *   suggestion and by nothing else. Its entry paths are the three states a
	 *   translation can be in while still awaiting work -- draft, needs review
	 *   and needs update -- because those are the ones where replacing the text
	 *   with a machine's proposal loses nothing a human had settled. It is
	 *   deliberately not reachable from `translated`: overwriting finished,
	 *   human-approved text with a machine suggestion would silently undo
	 *   somebody's work, so that route has to go through review first.
	 * - `disabled` is an administrative state, reachable only when a language
	 *   is disabled, and leavable back into review.
	 *
	 * @var array<string,string[]>
	 */
	private static $allowed = array(
		TranslationStatus::DRAFT             => array(
			TranslationStatus::NEEDS_REVIEW,
			TranslationStatus::TRANSLATED,
			TranslationStatus::MACHINE_SUGGESTED,
			TranslationStatus::DISABLED,
		),
		TranslationStatus::NEEDS_REVIEW      => array(
			TranslationStatus::TRANSLATED,
			TranslationStatus::DRAFT,
			TranslationStatus::MACHINE_SUGGESTED,
			TranslationStatus::DISABLED,
		),
		TranslationStatus::TRANSLATED        => array(
			TranslationStatus::NEEDS_UPDATE,
			TranslationStatus::NEEDS_REVIEW,
			TranslationStatus::DISABLED,
		),
		TranslationStatus::NEEDS_UPDATE      => array(
			TranslationStatus::NEEDS_REVIEW,
			TranslationStatus::TRANSLATED,
			TranslationStatus::MACHINE_SUGGESTED,
			TranslationStatus::DISABLED,
		),
		TranslationStatus::MACHINE_SUGGESTED => array(
			TranslationStatus::NEEDS_REVIEW,
			TranslationStatus::TRANSLATED,
			TranslationStatus::DRAFT,
			TranslationStatus::DISABLED,
		),
		TranslationStatus::DISABLED          => array(
			TranslationStatus::NEEDS_REVIEW,
		),
		TranslationStatus::ORIGINAL          => array(),
		TranslationStatus::MISSING           => array(),
	);

	/**
	 * Returns the statuses reachable from a given status.
	 *
	 * @param string $from Current status.
	 * @return string[]
	 */
	public function allowed_from( $from ) {
		$from = (string) $from;

		return isset( self::$allowed[ $from ] ) ? self::$allowed[ $from ] : array();
	}

	/**
	 * Returns whether a transition is allowed.
	 *
	 * @param string $from Current status.
	 * @param string $to Requested status.
	 * @return bool
	 */
	public function is_allowed( $from, $to ) {
		return in_array( (string) $to, $this->allowed_from( $from ), true );
	}

	/**
	 * Validates a transition.
	 *
	 * @param string $from Current status.
	 * @param string $to Requested status.
	 * @return true|\WP_Error
	 */
	public function validate( $from, $to ) {
		$from = (string) $from;
		$to   = (string) $to;

		if ( ! TranslationStatus::is_valid( $from ) ) {
			return new \WP_Error(
				'mclogiora_unknown_current_status',
				__( 'The current translation status is not recognised.', 'mclogiora' )
			);
		}

		if ( ! TranslationStatus::is_valid( $to ) ) {
			return new \WP_Error(
				'mclogiora_unknown_target_status',
				__( 'The requested translation status is not recognised.', 'mclogiora' )
			);
		}

		if ( TranslationStatus::ORIGINAL === $to ) {
			return new \WP_Error(
				'mclogiora_original_not_assignable',
				__( 'The original status cannot be assigned. Changing the source of a translation group is a separate workflow.', 'mclogiora' )
			);
		}

		if ( TranslationStatus::MISSING === $to ) {
			return new \WP_Error(
				'mclogiora_missing_not_assignable',
				__( 'The missing status describes an absent translation and cannot be assigned.', 'mclogiora' )
			);
		}

		/*
		 * Phase 10 blocked every route into `machine_suggested` with a blanket
		 * guard here, on the grounds that it was "reserved for translation
		 * suggestion workflows". Phase 16 is that workflow, so the reservation
		 * is replaced by explicit entries in the transition matrix rather than
		 * simply deleted.
		 *
		 * Removing the guard opens nothing by accident. The three states that
		 * must never reach `machine_suggested` are each protected
		 * independently and still are: `original` has an empty allow-list and
		 * its own immutability check below, `missing` has an empty allow-list,
		 * and `disabled` leads only back to review.
		 */

		if ( TranslationStatus::ORIGINAL === $from ) {
			return new \WP_Error(
				'mclogiora_original_status_immutable',
				__( 'The source item of a translation group cannot change translation status.', 'mclogiora' )
			);
		}

		if ( $from === $to ) {
			return new \WP_Error(
				'mclogiora_status_unchanged',
				__( 'The translation already has this status.', 'mclogiora' )
			);
		}

		if ( ! $this->is_allowed( $from, $to ) ) {
			return new \WP_Error(
				'mclogiora_invalid_status_transition',
				sprintf(
					/* translators: 1: current translation status, 2: requested translation status. */
					__( 'A translation cannot move from %1$s to %2$s.', 'mclogiora' ),
					$from,
					$to
				)
			);
		}

		return true;
	}
}
