<?php
/**
 * Structured result of an import apply.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * Transport-neutral import outcome.
 */
final class ImportApplyResult {
	/**
	 * Whether the transaction committed.
	 *
	 * @var bool
	 */
	private $success;
	/**
	 * Number of executable operations applied.
	 *
	 * @var int
	 */
	private $applied;
	/**
	 * Number of skip operations.
	 *
	 * @var int
	 */
	private $skipped;
	/**
	 * Structured operation results.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $operations;
	/**
	 * Structured issues.
	 *
	 * @var PlanIssue[]
	 */
	private $issues;
	/**
	 * Whether rollback succeeded, or null when no transaction was opened.
	 *
	 * @var bool|null
	 */
	private $rolled_back;

	/**
	 * Constructor.
	 *
	 * @param bool                           $success Whether apply committed.
	 * @param int                            $applied Applied operation count.
	 * @param int                            $skipped Skipped operation count.
	 * @param array<int,array<string,mixed>> $operations Operation results.
	 * @param PlanIssue[]                    $issues Issues.
	 * @param bool|null                      $rolled_back Rollback result.
	 */
	public function __construct( $success, $applied, $skipped, array $operations, array $issues, $rolled_back = null ) {
		$this->success     = (bool) $success;
		$this->applied     = (int) $applied;
		$this->skipped     = (int) $skipped;
		$this->operations  = array_values( $operations );
		$this->issues      = array_values( $issues );
		$this->rolled_back = null === $rolled_back ? null : (bool) $rolled_back;
	}

	/** Returns whether apply committed. @return bool */
	public function is_success() {
		return $this->success;
	}

	/** Returns the applied operation count. @return int */
	public function applied_operations() {
		return $this->applied;
	}

	/** Returns the skipped operation count. @return int */
	public function skipped_operations() {
		return $this->skipped;
	}

	/** Returns structured issues. @return PlanIssue[] */
	public function issues() {
		return $this->issues;
	}

	/** Returns rollback status. @return bool|null */
	public function rolled_back() {
		return $this->rolled_back;
	}

	/**
	 * Returns a stable transport representation.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		$issues = array();
		foreach ( $this->issues as $issue ) {
			$issues[] = $issue->to_array();
		}

		return array(
			'success'            => $this->success,
			'applied_operations' => $this->applied,
			'skipped_operations' => $this->skipped,
			'operations'         => $this->operations,
			'issues'             => $issues,
			'rolled_back'        => $this->rolled_back,
		);
	}
}
