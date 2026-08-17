<?php
/**
 * Portable relation item.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * One language slot of a translation group as a package carries it.
 *
 * The object this item points at is named by an `ObjectLocator` and by nothing
 * else. The relation table's own row identifier, the group's numeric ID, the
 * source hashes and the modified timestamps are all absent: the first two are
 * persistence identity, and the last two are the change detector's private
 * working state, which the public API has already declined to publish twice.
 *
 * The locator is null when the exporting site could not build one -- because
 * the object had been deleted underneath the relation, or because its content
 * type has no locator in this format version. Writing null is deliberate. The
 * alternative, dropping the item, would produce a package that looks complete
 * and quietly is not.
 */
final class PackageRelationItem {
	/**
	 * Relation content type.
	 *
	 * @var string
	 */
	private $object_type;

	/**
	 * Language code.
	 *
	 * @var string
	 */
	private $language;

	/**
	 * Translation status.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Whether this item is the group's source.
	 *
	 * @var bool
	 */
	private $is_source;

	/**
	 * Portable locator, or null when none could be built.
	 *
	 * @var ObjectLocator|null
	 */
	private $locator;

	/**
	 * Constructor.
	 *
	 * @param string             $object_type Relation content type.
	 * @param string             $language Language code.
	 * @param string             $status Translation status.
	 * @param bool               $is_source Whether the item is the source.
	 * @param ObjectLocator|null $locator Portable locator.
	 */
	public function __construct( $object_type, $language, $status, $is_source, ?ObjectLocator $locator = null ) {
		$this->object_type = (string) $object_type;
		$this->language    = (string) $language;
		$this->status      = (string) $status;
		$this->is_source   = (bool) $is_source;
		$this->locator     = $locator;
	}

	/**
	 * Returns the relation content type.
	 *
	 * @return string
	 */
	public function object_type() {
		return $this->object_type;
	}

	/**
	 * Returns the language code.
	 *
	 * @return string
	 */
	public function language() {
		return $this->language;
	}

	/**
	 * Returns the translation status.
	 *
	 * @return string
	 */
	public function status() {
		return $this->status;
	}

	/**
	 * Returns whether the item is the group's source.
	 *
	 * @return bool
	 */
	public function is_source() {
		return $this->is_source;
	}

	/**
	 * Returns the locator, or null.
	 *
	 * @return ObjectLocator|null
	 */
	public function locator() {
		return $this->locator;
	}

	/**
	 * Returns the package representation with a fixed key order.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'object_type' => $this->object_type,
			'language'    => $this->language,
			'status'      => $this->status,
			'is_source'   => $this->is_source,
			'locator'     => null === $this->locator ? null : $this->locator->to_array(),
		);
	}
}
