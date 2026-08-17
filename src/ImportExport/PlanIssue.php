<?php
/**
 * Import plan issue.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * Something the operator has to know before anything is written.
 *
 * The four levels are not a severity ladder, they are four different things to
 * do about it:
 *
 * - `error` — the package cannot be applied to this site at all. Nothing in the
 *   plan is worth reading until it is fixed.
 * - `conflict` — the destination already holds something that contradicts the
 *   package. mcLogiora will not decide which of the two is right, so the item
 *   is left out of the plan and named here instead.
 * - `unresolved` — a locator names no object on this site, or names more than
 *   one. The reference cannot be followed, and picking the first match would be
 *   a guess with consequences.
 * - `warning` — worth reading, changes nothing about what would be applied.
 *
 * Every issue carries a stable machine code as well as a sentence, because a
 * transport that has to react to a specific case must not have to match on
 * translated prose.
 */
final class PlanIssue {
	/**
	 * The package cannot be applied at all.
	 */
	const LEVEL_ERROR = 'error';

	/**
	 * The destination contradicts the package.
	 */
	const LEVEL_CONFLICT = 'conflict';

	/**
	 * A locator could not be followed.
	 */
	const LEVEL_UNRESOLVED = 'unresolved';

	/**
	 * Informational.
	 */
	const LEVEL_WARNING = 'warning';

	/**
	 * Issue level.
	 *
	 * @var string
	 */
	private $level;

	/**
	 * Stable machine code.
	 *
	 * @var string
	 */
	private $code;

	/**
	 * Human-readable sentence.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Structured context.
	 *
	 * @var array<string,mixed>
	 */
	private $context;

	/**
	 * Constructor.
	 *
	 * @param string              $level Issue level.
	 * @param string              $code Stable machine code.
	 * @param string              $message Human-readable sentence.
	 * @param array<string,mixed> $context Structured context.
	 */
	public function __construct( $level, $code, $message, array $context = array() ) {
		$this->level   = (string) $level;
		$this->code    = (string) $code;
		$this->message = (string) $message;
		$this->context = $context;
	}

	/**
	 * Returns the issue level.
	 *
	 * @return string
	 */
	public function level() {
		return $this->level;
	}

	/**
	 * Returns the stable machine code.
	 *
	 * @return string
	 */
	public function code() {
		return $this->code;
	}

	/**
	 * Returns the human-readable sentence.
	 *
	 * @return string
	 */
	public function message() {
		return $this->message;
	}

	/**
	 * Returns the structured context.
	 *
	 * @return array<string,mixed>
	 */
	public function context() {
		return $this->context;
	}

	/**
	 * Returns the representation transports publish.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'level'   => $this->level,
			'code'    => $this->code,
			'message' => $this->message,
			'context' => $this->context,
		);
	}
}
