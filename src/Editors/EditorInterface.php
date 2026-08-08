<?php
/**
 * Editor adapter contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors;

defined( 'ABSPATH' ) || exit;

/**
 * Describes a safe, editor-independent integration boundary.
 */
interface EditorInterface {
	/**
	 * Returns the stable adapter identifier.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Returns the human-readable editor label.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Returns whether the editor can be detected in this installation.
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Returns whether the editor can own the supplied context.
	 *
	 * @param EditorContext $context Editor context.
	 * @return bool
	 */
	public function supports_context( EditorContext $context );

	/**
	 * Describes future UI surfaces without registering editor UI yet.
	 *
	 * @return array
	 */
	public function get_placeholder_areas();
}
