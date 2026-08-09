<?php
/**
 * URL strategy constants and policy.
 *
 * @package McLogiora
 */

namespace McLogiora\Routing;

defined( 'ABSPATH' ) || exit;

/**
 * Describes how languages appear in URLs.
 *
 * Phase 12 ships directory routing only. Domain and subdomain strategies are
 * named here so the option has a stable shape, but they are not selectable:
 * offering a control that does nothing is worse than not offering it.
 */
final class UrlStrategy {
	const DIRECTORY = 'directory';

	/**
	 * Returns the strategies that are actually implemented.
	 *
	 * @return string[]
	 */
	public static function supported() {
		return array( self::DIRECTORY );
	}

	/**
	 * Returns whether a strategy is implemented.
	 *
	 * @param string $strategy Strategy key.
	 * @return bool
	 */
	public static function is_supported( $strategy ) {
		return in_array( (string) $strategy, self::supported(), true );
	}
}
