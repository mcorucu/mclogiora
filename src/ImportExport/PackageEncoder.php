<?php
/**
 * Portable package encoder.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a package into the bytes that leave the site.
 *
 * JSON, because a package is a thing an operator has to be able to open, read
 * and compare. `serialize()` would be shorter and would tie every package to a
 * PHP version and to class names that are explicitly not public; a database
 * dump would carry table prefixes, row identifiers and whatever else happened
 * to be in the row; WordPress's own export XML describes posts, which is a
 * different subject entirely. None of those can be diffed by a human, and a
 * dry run that an operator cannot check against the file is a dry run they
 * have to take on trust.
 *
 * Encoding is deterministic. The package's value objects write their keys in a
 * fixed order and PHP preserves it, the flags are fixed by `PackageFormat`, and
 * pretty printing changes whitespace and nothing else. Two exports of unchanged
 * state produce identical bytes apart from the manifest's timestamp.
 */
final class PackageEncoder {
	/**
	 * Encodes a package.
	 *
	 * @param TranslationPackage $package Package.
	 * @param bool               $pretty Whether to indent for human reading.
	 * @return string Empty string when encoding fails.
	 */
	public function encode( TranslationPackage $package, $pretty = false ) {
		$json = wp_json_encode( $package->to_array(), PackageFormat::json_flags( (bool) $pretty ) );

		return is_string( $json ) ? $json : '';
	}
}
