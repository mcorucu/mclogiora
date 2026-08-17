<?php
/**
 * Transport-neutral import apply service.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

use McLogiora\Database\TransactionInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Applies one immutable plan with authorization, stale protection, atomicity
 * and final verification. No transport or package re-planning belongs here.
 */
final class ImportApplyService {
	/**
	 * Authorization boundary.
	 *
	 * @var ImportAuthorizationInterface
	 */
	private $authorization;
	/**
	 * Stale-plan checker.
	 *
	 * @var ImportPlanPreconditionChecker
	 */
	private $preconditions;
	/**
	 * Domain operation executor.
	 *
	 * @var ImportOperationExecutorInterface
	 */
	private $executor;
	/**
	 * Final postcondition verifier.
	 *
	 * @var ImportPlanVerifier
	 */
	private $verifier;
	/**
	 * Transaction boundary.
	 *
	 * @var TransactionInterface
	 */
	private $transaction;

	/**
	 * Constructor.
	 *
	 * @param ImportAuthorizationInterface     $authorization Capability validator.
	 * @param ImportPlanPreconditionChecker    $preconditions Stale checker.
	 * @param ImportOperationExecutorInterface $executor Operation executor.
	 * @param ImportPlanVerifier               $verifier Final verifier.
	 * @param TransactionInterface             $transaction Transaction boundary.
	 */
	public function __construct( ImportAuthorizationInterface $authorization, ImportPlanPreconditionChecker $preconditions, ImportOperationExecutorInterface $executor, ImportPlanVerifier $verifier, TransactionInterface $transaction ) {
		$this->authorization = $authorization;
		$this->preconditions = $preconditions;
		$this->executor      = $executor;
		$this->verifier      = $verifier;
		$this->transaction   = $transaction;
	}

	/**
	 * Applies exactly the supplied plan.
	 *
	 * @param ImportPlan $plan Immutable plan from a prior dry run.
	 * @return ImportApplyResult
	 */
	public function apply( ImportPlan $plan ) {
		$authorized = $this->authorization->validate_manage_capability();
		if ( is_wp_error( $authorized ) ) {
			return $this->failure( array( new PlanIssue( PlanIssue::LEVEL_ERROR, $authorized->get_error_code(), $authorized->get_error_message() ) ) );
		}

		if ( array() !== $plan->errors() || array() !== $plan->conflicts() || array() !== $plan->unresolved() ) {
			return $this->failure(
				array(
					new PlanIssue(
						PlanIssue::LEVEL_ERROR,
						'import_plan_not_applicable',
						'The import plan contains errors, conflicts or unresolved references and cannot be applied.',
						array( 'plan_issues' => $this->issue_arrays( $plan->issues() ) )
					),
				)
			);
		}

		foreach ( $plan->operations() as $operation ) {
			if ( ! in_array( $operation->type(), array( PlannedOperation::CREATE_LANGUAGE, PlannedOperation::CREATE_GROUP, PlannedOperation::LINK_ITEM, PlannedOperation::SKIP ), true ) ) {
				return $this->failure( array( new PlanIssue( PlanIssue::LEVEL_ERROR, 'import_plan_invalid', 'The import plan contains an unsupported operation type.', array( 'operation' => $operation->to_array() ) ) ) );
			}
		}

		$precondition_issues = $this->preconditions->check( $plan );
		if ( array() !== $precondition_issues ) {
			return $this->failure( $precondition_issues );
		}

		$operation_results = array();
		$applied           = 0;
		$skipped           = 0;

		foreach ( $plan->operations() as $operation ) {
			if ( PlannedOperation::SKIP === $operation->type() ) {
				++$skipped;
				$operation_results[] = array(
					'type'    => $operation->type(),
					'subject' => $operation->subject(),
					'action'  => 'skipped',
				);
			}
		}

		if ( 0 === count( $plan->operations_of_type( PlannedOperation::CREATE_LANGUAGE ) ) + count( $plan->operations_of_type( PlannedOperation::CREATE_GROUP ) ) + count( $plan->operations_of_type( PlannedOperation::LINK_ITEM ) ) ) {
			$verification_issues = $this->verifier->verify( $plan );
			return array() === $verification_issues
				? new ImportApplyResult( true, 0, $skipped, $operation_results, array() )
				: $this->failure( $verification_issues );
		}

		if ( ! $this->transaction->begin() ) {
			return $this->failure( array( new PlanIssue( PlanIssue::LEVEL_ERROR, 'import_transaction_begin_failed', 'The import transaction could not be started.' ) ) );
		}

		foreach ( $plan->operations() as $operation ) {
			if ( PlannedOperation::SKIP === $operation->type() ) {
				continue;
			}

			try {
				$result = $this->executor->execute( $operation );
			} catch ( \Throwable $exception ) {
				return $this->rollback_failure( $applied, $skipped, $operation_results, $operation, 'import_apply_failed' );
			}

			if ( is_wp_error( $result ) ) {
				return $this->rollback_failure( $applied, $skipped, $operation_results, $operation, $result->get_error_code(), $result->get_error_message() );
			}

			++$applied;
			$operation_results[] = $result;
		}

		$verification_issues = $this->verifier->verify( $plan );
		if ( array() !== $verification_issues ) {
			return $this->rollback_failure( $applied, $skipped, $operation_results, null, 'import_apply_verification_failed', 'The applied import did not produce the exact planned state.', $verification_issues );
		}

		if ( ! $this->transaction->commit() ) {
			return $this->rollback_failure( $applied, $skipped, $operation_results, null, 'import_transaction_commit_failed' );
		}

		return new ImportApplyResult( true, $applied, $skipped, $operation_results, array(), false );
	}

	/**
	 * Rolls back and creates a structured operation failure.
	 *
	 * @param int                            $applied Applied operation count.
	 * @param int                            $skipped Skipped operation count.
	 * @param array<int,array<string,mixed>> $operation_results Results.
	 * @param PlannedOperation|null          $operation Failed operation.
	 * @param string                         $code Stable failure code.
	 * @param string                         $message Failure message.
	 * @param PlanIssue[]                    $issues Existing issues.
	 * @return ImportApplyResult
	 */
	private function rollback_failure( $applied, $skipped, array $operation_results, $operation, $code, $message = 'The import could not be applied.', array $issues = array() ) {
		$rolled_back = $this->transaction->rollback();
		$this->clear_runtime_caches();
		if ( array() === $issues ) {
			$context = array();
			if ( $operation instanceof PlannedOperation ) {
				$context['operation'] = $operation->to_array();
			}
			$issues[] = new PlanIssue( PlanIssue::LEVEL_ERROR, (string) $code, (string) $message, $context );
		}

		return new ImportApplyResult( false, $applied, $skipped, $operation_results, $issues, $rolled_back );
	}

	/**
	 * Creates a failure result without opening a transaction.
	 *
	 * @param PlanIssue[] $issues Issues.
	 * @return ImportApplyResult
	 */
	private function failure( array $issues ) {
		return new ImportApplyResult( false, 0, 0, array(), $issues, false );
	}

	/**
	 * Removes repository values written before a rollback.
	 *
	 * Repository decorators invalidate after successful writes, but a
	 * transaction rollback leaves those same values in the request cache. A
	 * subsequent dry run in the same request would then plan against state that
	 * no longer exists in the database.
	 *
	 * @return void
	 */
	private function clear_runtime_caches() {
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
	}

	/**
	 * Projects issues into a nested result context.
	 *
	 * @param PlanIssue[] $issues Issues.
	 * @return array<int,array<string,mixed>>
	 */
	private function issue_arrays( array $issues ) {
		$result = array();
		foreach ( $issues as $issue ) {
			$result[] = $issue->to_array();
		}

		return $result;
	}
}
