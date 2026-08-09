<?php
/**
 * Permalink filter guards.
 *
 * @package McLogiora
 */

namespace McLogiora\Routing;

defined( 'ABSPATH' ) || exit;

/**
 * Guards against recursive permalink generation.
 *
 * The permalink filters ask the URL generator for a translated link, and the
 * generator asks WordPress for a permalink. Without a guard those two call
 * each other until the stack runs out. This flag lets the generator ask for a
 * raw, unfiltered link while inside a filter.
 */
final class PermalinkFilters {
	/**
	 * Whether mcLogiora's permalink filters are currently suspended.
	 *
	 * @var bool
	 */
	private static $suspended = false;

	/**
	 * Returns whether the filters are suspended.
	 *
	 * @return bool
	 */
	public static function suspended() {
		return self::$suspended;
	}

	/**
	 * Runs a callback with mcLogiora's permalink filters suspended.
	 *
	 * @param callable $callback Callback returning a link.
	 * @return mixed
	 */
	public static function without_filters( callable $callback ) {
		$previous        = self::$suspended;
		self::$suspended = true;

		try {
			return $callback();
		} finally {
			self::$suspended = $previous;
		}
	}
}
