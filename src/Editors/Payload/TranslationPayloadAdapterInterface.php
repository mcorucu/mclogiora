<?php
/**
 * Translation payload adapter contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors\Payload;

defined( 'ABSPATH' ) || exit;

/**
 * Gives a newly created translation the starting state its editor expects.
 *
 * Deliberately separate from `EditorInterface`. Presenting translation state
 * inside an editor and preparing a translation's stored content are different
 * jobs with different lifetimes: the first runs on an admin screen, the second
 * runs once, server-side, inside a workflow that may have to roll back. Fusing
 * them would have produced one interface where every implementation ignored
 * half the methods.
 *
 * An adapter copies structure, never meaning. Nothing here translates text.
 */
interface TranslationPayloadAdapterInterface {
	/**
	 * Returns the adapter identifier.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Returns whether the integration this adapter targets is installed.
	 *
	 * Must be safe to call when the target plugin is absent.
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Returns whether this adapter has anything to do for a source object.
	 *
	 * @param int $source_id Source post identifier.
	 * @return bool
	 */
	public function applies_to( $source_id );

	/**
	 * Copies the source's editor payload onto a freshly created translation.
	 *
	 * The target is always an object the workflow has just created, so an
	 * adapter never overwrites content a person authored.
	 *
	 * @param int $source_id Source post identifier.
	 * @param int $target_id Newly created translation identifier.
	 * @return true|\WP_Error
	 */
	public function copy( $source_id, $target_id );
}
