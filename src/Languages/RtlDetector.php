<?php
/**
 * RTL language detection.
 *
 * @package McLogiora
 */

namespace McLogiora\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Detects right-to-left languages by language code or locale.
 */
final class RtlDetector {
	/**
	 * Common RTL language codes.
	 *
	 * @var string[]
	 */
	private $rtl_codes = array( 'ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'ku', 'ps', 'sd', 'ug', 'ur', 'yi' );

	/**
	 * Returns whether a language code or locale is RTL.
	 *
	 * @param string $language_code Language code or locale.
	 * @return bool
	 */
	public function is_rtl( $language_code ) {
		$code = strtolower( strtok( (string) $language_code, '_' ) );

		return in_array( $code, $this->rtl_codes, true );
	}
}
