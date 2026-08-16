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
	const MANAGE              = 'manage_mclogiora';
	const MANAGE_LANGUAGES    = 'manage_mclogiora_languages';
	const MANAGE_TRANSLATIONS = 'manage_mclogiora_translations';
	const MANAGE_SETTINGS     = 'manage_mclogiora_settings';

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
		/*
		 * Internal. Deliberately NOT part of the public developer API, and it
		 * carries no @since for that reason.
		 *
		 * This is the security boundary. Every mcLogiora admin screen and every
		 * write path -- translations, menus, widgets, media, strings, languages,
		 * suggestions -- checks whatever this returns. A callback returning
		 * 'read' opens all of it to any logged-in subscriber.
		 *
		 * It is not narrowed to "equal or stronger" because WordPress has no
		 * capability ordering to compare against: current_user_can() is a
		 * boolean per capability, and role plugins add capabilities that no
		 * lattice here could rank. Inventing one would be a guess enforcing a
		 * security rule. Keeping the hook unsupported is the honest option.
		 *
		 * See docs/architecture/developer-api.md.
		 */
		return (string) apply_filters( 'mclogiora_resolved_capability', 'manage_options', $planned_capability );
	}
}
