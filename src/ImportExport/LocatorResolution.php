<?php
/**
 * Result of resolving one locator.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * What the destination site had to say about one locator.
 *
 * Seven outcomes, and six of them are not "here is your object". That ratio is
 * the point. A resolver that returned an ID or null would collapse "this site
 * has no such page", "this site has two of them", "the package could not
 * address this object in the first place" and "this site does not have that
 * post type registered" into one answer, and the operator would have no way to
 * tell which of the four they were looking at -- or that the last two are
 * problems with the package and the site rather than with the content.
 *
 * Nothing here picks a winner when several objects match. Taking the lowest ID
 * would be arbitrary, would look like it worked, and would attach a translation
 * to whichever page happened to be created first.
 */
final class LocatorResolution {
	/**
	 * Exactly one object matched.
	 */
	const RESOLVED = 'resolved';

	/**
	 * Nothing matched.
	 */
	const NOT_FOUND = 'not_found';

	/**
	 * More than one object matched.
	 */
	const AMBIGUOUS = 'ambiguous';

	/**
	 * The locator lacks what it needs to be looked up, typically a slug.
	 */
	const INCOMPLETE = 'incomplete';

	/**
	 * The package carried no locator for this item.
	 */
	const ABSENT = 'absent';

	/**
	 * The post type or taxonomy is not registered on this site.
	 */
	const TYPE_UNKNOWN = 'type_unknown';

	/**
	 * Resolution outcome.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Matching object identifiers.
	 *
	 * @var int[]
	 */
	private $ids;

	/**
	 * Constructor.
	 *
	 * @param string $status Resolution outcome.
	 * @param int[]  $ids Matching identifiers.
	 */
	private function __construct( $status, array $ids = array() ) {
		$this->status = (string) $status;
		$this->ids    = array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Builds a resolution from the identifiers a lookup returned.
	 *
	 * @param int[] $ids Matching identifiers.
	 * @return self
	 */
	public static function from_ids( array $ids ) {
		if ( array() === $ids ) {
			return new self( self::NOT_FOUND );
		}

		if ( 1 === count( $ids ) ) {
			return new self( self::RESOLVED, $ids );
		}

		return new self( self::AMBIGUOUS, $ids );
	}

	/**
	 * Builds a resolution that did not reach a lookup.
	 *
	 * @param string $status One of the non-lookup outcomes.
	 * @return self
	 */
	public static function of( $status ) {
		return new self( $status );
	}

	/**
	 * Returns the resolution outcome.
	 *
	 * @return string
	 */
	public function status() {
		return $this->status;
	}

	/**
	 * Returns whether exactly one object matched.
	 *
	 * @return bool
	 */
	public function is_resolved() {
		return self::RESOLVED === $this->status;
	}

	/**
	 * Returns the single matching identifier, or 0.
	 *
	 * @return int
	 */
	public function object_id() {
		return $this->is_resolved() ? $this->ids[0] : 0;
	}

	/**
	 * Returns every matching identifier.
	 *
	 * @return int[]
	 */
	public function ids() {
		return $this->ids;
	}
}
