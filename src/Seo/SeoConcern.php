<?php
/**
 * SEO concern identifiers.
 *
 * @package McLogiora
 */

namespace McLogiora\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * The separable pieces of SEO output that can have different owners.
 *
 * Ownership is per concern rather than all-or-nothing, because that is how the
 * plugins actually divide up. Yoast writes a canonical tag and replaces the
 * sitemap but emits no `hreflang`; suppressing mcLogiora entirely because Yoast
 * is active would leave a multilingual site with no language annotation at all,
 * which is the one thing only mcLogiora can provide.
 */
final class SeoConcern {
	const CANONICAL = 'canonical';
	const HREFLANG  = 'hreflang';
	const OG_LOCALE = 'og_locale';
	const SITEMAP   = 'sitemap';

	/**
	 * Returns every concern identifier.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array(
			self::CANONICAL,
			self::HREFLANG,
			self::OG_LOCALE,
			self::SITEMAP,
		);
	}

	/**
	 * Returns whether a value is a known concern.
	 *
	 * @param string $concern Concern identifier.
	 * @return bool
	 */
	public static function is_valid( $concern ) {
		return in_array( (string) $concern, self::all(), true );
	}
}
