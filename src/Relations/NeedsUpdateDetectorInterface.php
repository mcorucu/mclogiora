<?php
/**
 * Needs-update detector contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Relations;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for future outdated translation detection.
 */
interface NeedsUpdateDetectorInterface {
	/**
	 * Returns whether a target item needs update.
	 *
	 * @param TranslationItem $item Translation item.
	 * @return bool
	 */
	public function needs_update( TranslationItem $item );
}
