<?php
/**
 * Capability registry foundation.
 *
 * @package McLogiora
 */

namespace McLogiora\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Defines future mcLogiora capabilities without mutating roles.
 */
final class CapabilityRegistry {
	const MANAGE = 'manage_mclogiora';
	const MANAGE_LANGUAGES = 'manage_mclogiora_languages';
	const MANAGE_TRANSLATIONS = 'manage_mclogiora_translations';
	const MANAGE_SETTINGS = 'manage_mclogiora_settings';

	/**
	 * Returns all planned capabilities.
	 *
	 * @return string[]
	 */
	public function all() {
		return array(
			self::MANAGE,
			self::MANAGE_LANGUAGES,
			self::MANAGE_TRANSLATIONS,
			self::MANAGE_SETTINGS,
		);
	}

	/**
	 * Returns the effective WordPress capability for a planned capability.
	 *
	 * Phase 03 does not alter roles, so admin pages temporarily map to
	 * `manage_options` while preserving future capability identifiers.
	 *
	 * @param string $planned_capability Planned mcLogiora capability.
	 * @return string
	 */
	public function resolve( $planned_capability ) {
		return (string) apply_filters( 'mclogiora_resolved_capability', 'manage_options', $planned_capability );
	}
}
