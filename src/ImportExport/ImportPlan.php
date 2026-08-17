<?php
/**
 * Immutable import plan.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * What an import would do, computed without doing any of it.
 *
 * The plan is the whole reason the import path is shaped the way it is. The
 * dry run is not a rehearsal that a later apply repeats from scratch; the dry
 * run *is* the plan, and applying it means executing this list. Two code paths
 * that each worked out what to do would eventually work out different things,
 * and the operator would have approved the wrong one.
 *
 * It is immutable and completely inert. Nothing here queries, resolves or
 * writes when read: every accessor returns state the planner already computed,
 * so reading a plan twice cannot produce two answers and cannot touch the site.
 */
final class ImportPlan {
	/**
	 * Planned operations, in the order they would be applied.
	 *
	 * @var PlannedOperation[]
	 */
	private $operations;

	/**
	 * Issues raised while planning.
	 *
	 * @var PlanIssue[]
	 */
	private $issues;

	/**
	 * Constructor.
	 *
	 * @param PlannedOperation[] $operations Planned operations.
	 * @param PlanIssue[]        $issues Issues.
	 */
	public function __construct( array $operations, array $issues ) {
		$this->operations = array_values( $operations );
		$this->issues     = array_values( $issues );
	}

	/**
	 * Returns every planned operation, including skips.
	 *
	 * @return PlannedOperation[]
	 */
	public function operations() {
		return $this->operations;
	}

	/**
	 * Returns the operations of one type.
	 *
	 * @param string $type Operation type.
	 * @return PlannedOperation[]
	 */
	public function operations_of_type( $type ) {
		return array_values(
			array_filter(
				$this->operations,
				static function ( PlannedOperation $operation ) use ( $type ) {
					return $operation->type() === (string) $type;
				}
			)
		);
	}

	/**
	 * Returns every issue.
	 *
	 * @return PlanIssue[]
	 */
	public function issues() {
		return $this->issues;
	}

	/**
	 * Returns the issues that stop the package being applied at all.
	 *
	 * @return PlanIssue[]
	 */
	public function errors() {
		return $this->issues_at( PlanIssue::LEVEL_ERROR );
	}

	/**
	 * Returns the destination contradictions.
	 *
	 * @return PlanIssue[]
	 */
	public function conflicts() {
		return $this->issues_at( PlanIssue::LEVEL_CONFLICT );
	}

	/**
	 * Returns the references that could not be followed.
	 *
	 * @return PlanIssue[]
	 */
	public function unresolved() {
		return $this->issues_at( PlanIssue::LEVEL_UNRESOLVED );
	}

	/**
	 * Returns the informational issues.
	 *
	 * @return PlanIssue[]
	 */
	public function warnings() {
		return $this->issues_at( PlanIssue::LEVEL_WARNING );
	}

	/**
	 * Returns whether a later apply could run this plan.
	 *
	 * False when the package cannot be applied to this site at all. Conflicts
	 * and unresolved references do not make a plan inapplicable: they are the
	 * parts that were left out of it, and the operations that remain are still
	 * coherent on their own.
	 *
	 * @return bool
	 */
	public function is_applicable() {
		return array() === $this->errors();
	}

	/**
	 * Returns whether the plan would change anything.
	 *
	 * @return bool
	 */
	public function is_empty() {
		return array() === $this->operations_of_type( PlannedOperation::CREATE_LANGUAGE )
			&& array() === $this->operations_of_type( PlannedOperation::CREATE_GROUP )
			&& array() === $this->operations_of_type( PlannedOperation::LINK_ITEM );
	}

	/**
	 * Returns the plan summary counts.
	 *
	 * @return array<string,int>
	 */
	public function counts() {
		return array(
			'create_language' => count( $this->operations_of_type( PlannedOperation::CREATE_LANGUAGE ) ),
			'create_group'    => count( $this->operations_of_type( PlannedOperation::CREATE_GROUP ) ),
			'link_item'       => count( $this->operations_of_type( PlannedOperation::LINK_ITEM ) ),
			'skip'            => count( $this->operations_of_type( PlannedOperation::SKIP ) ),
			'error'           => count( $this->errors() ),
			'conflict'        => count( $this->conflicts() ),
			'unresolved'      => count( $this->unresolved() ),
			'warning'         => count( $this->warnings() ),
		);
	}

	/**
	 * Returns the representation transports publish.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		$operations = array();

		foreach ( $this->operations as $operation ) {
			$operations[] = $operation->to_array();
		}

		$issues = array();

		foreach ( $this->issues as $issue ) {
			$issues[] = $issue->to_array();
		}

		return array(
			'applicable' => $this->is_applicable(),
			'counts'     => $this->counts(),
			'operations' => $operations,
			'issues'     => $issues,
		);
	}

	/**
	 * Returns the issues at one level.
	 *
	 * @param string $level Issue level.
	 * @return PlanIssue[]
	 */
	private function issues_at( $level ) {
		return array_values(
			array_filter(
				$this->issues,
				static function ( PlanIssue $issue ) use ( $level ) {
					return $issue->level() === (string) $level;
				}
			)
		);
	}
}
