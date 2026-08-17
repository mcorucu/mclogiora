<?php
/**
 * Import operation executor contract.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * Applies one already-planned operation through domain services.
 */
interface ImportOperationExecutorInterface {
	/**
	 * Executes exactly one operation.
	 *
	 * @param PlannedOperation $operation Operation from the immutable plan.
	 * @return array<string,mixed>|\WP_Error Structured result or failure.
	 */
	public function execute( PlannedOperation $operation );
}
