<?php
/**
 * One planned import operation.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * A single thing an apply would do, described completely enough to do it.
 *
 * The operation vocabulary is deliberately short, and it is short because of
 * the policy it encodes. Import is **additive**: it creates what the
 * destination is missing and links what it has not linked, and it never
 * overwrites a value that already exists. There is no `update_language` and no
 * `update_status`, because writing a package's idea of a language's name or a
 * translation's review state over the destination's own would be mcLogiora
 * choosing which site is right. Where the two disagree, the plan reports a
 * conflict and leaves the decision with the operator.
 *
 * Every operation names its subject and carries the resolved destination
 * identifiers in `detail`, so applying the plan is a matter of executing it
 * rather than working out what it meant. That is the point of building the plan
 * this way: a later apply consumes exactly this list, and nothing about what to
 * do is discovered a second time by different code that could reach a different
 * answer.
 */
final class PlannedOperation {
	/**
	 * Add a language the destination does not have.
	 */
	const CREATE_LANGUAGE = 'create_language';

	/**
	 * Create a translation group, with its source item, under the package's key.
	 */
	const CREATE_GROUP = 'create_group';

	/**
	 * Attach an existing destination object to a group as a translation.
	 */
	const LINK_ITEM = 'link_item';

	/**
	 * Do nothing, because the destination already matches the package.
	 */
	const SKIP = 'skip';

	/**
	 * Operation type.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * What the operation is about.
	 *
	 * @var string
	 */
	private $subject;

	/**
	 * Everything an apply needs.
	 *
	 * @var array<string,mixed>
	 */
	private $detail;

	/**
	 * Constructor.
	 *
	 * @param string              $type Operation type.
	 * @param string              $subject Subject identifier.
	 * @param array<string,mixed> $detail Operation detail.
	 */
	public function __construct( $type, $subject, array $detail = array() ) {
		$this->type    = (string) $type;
		$this->subject = (string) $subject;
		$this->detail  = $detail;
	}

	/**
	 * Returns the operation type.
	 *
	 * @return string
	 */
	public function type() {
		return $this->type;
	}

	/**
	 * Returns the subject identifier.
	 *
	 * @return string
	 */
	public function subject() {
		return $this->subject;
	}

	/**
	 * Returns the operation detail.
	 *
	 * @return array<string,mixed>
	 */
	public function detail() {
		return $this->detail;
	}

	/**
	 * Returns the representation transports publish.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'type'    => $this->type,
			'subject' => $this->subject,
			'detail'  => $this->detail,
		);
	}
}
