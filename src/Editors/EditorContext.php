<?php
/**
 * Editor context value object.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors;

defined( 'ABSPATH' ) || exit;

/**
 * Carries normalized context for future editor workflows.
 */
final class EditorContext {
	/**
	 * WordPress object type.
	 *
	 * @var string
	 */
	private $object_type;

	/**
	 * WordPress object identifier.
	 *
	 * @var int
	 */
	private $object_id;

	/**
	 * Current screen identifier.
	 *
	 * @var string
	 */
	private $screen;

	/**
	 * Constructor.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id Object identifier.
	 * @param string $screen Screen identifier.
	 */
	public function __construct( $object_type = '', $object_id = 0, $screen = '' ) {
		$this->object_type = sanitize_key( $object_type );
		$this->object_id   = absint( $object_id );
		$this->screen      = sanitize_key( $screen );
	}

	/**
	 * Returns the object type.
	 *
	 * @return string
	 */
	public function object_type() {
		return $this->object_type;
	}

	/**
	 * Returns the object identifier.
	 *
	 * @return int
	 */
	public function object_id() {
		return $this->object_id;
	}

	/**
	 * Returns the screen identifier.
	 *
	 * @return string
	 */
	public function screen() {
		return $this->screen;
	}
}
