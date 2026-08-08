<?php
/**
 * Metadata-only needs-update detector.
 *
 * @package McLogiora
 */

namespace McLogiora\Relations;

defined( 'ABSPATH' ) || exit;

/**
 * Uses stored placeholder metadata without hashing real content.
 */
final class MetadataNeedsUpdateDetector implements NeedsUpdateDetectorInterface {
	/**
	 * Returns whether a target item needs update.
	 *
	 * @param TranslationItem $item Translation item.
	 * @return bool
	 */
	public function needs_update( TranslationItem $item ) {
		if ( TranslationStatus::NEEDS_UPDATE === $item->status() ) {
			return true;
		}

		if ( '' !== $item->source_hash() && '' !== $item->translated_source_hash() ) {
			return $item->source_hash() !== $item->translated_source_hash();
		}

		return $item->source_modified() > 0
			&& $item->translation_modified() > 0
			&& $item->source_modified() > $item->translation_modified();
	}
}
