<?php
/**
 * Portable package format constants.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * The single source of truth for the portable package contract.
 *
 * The format version is deliberately an integer of its own, and never the
 * plugin version. A package produced by 0.16.0 and a package produced by
 * 1.4.0 are interchangeable as long as both say `format_version: 1`, and a
 * plugin release that changes nothing about serialization must not invalidate
 * every package a site has already exported. Plugin releases and wire
 * compatibility are separate concerns, so they get separate version numbers.
 */
final class PackageFormat {
	/**
	 * Format identifier written into and required from every package.
	 */
	const FORMAT = 'mclogiora/translation-package';

	/**
	 * Current, and currently only supported, format version.
	 */
	const VERSION = 1;

	/**
	 * Name of the producing plugin recorded in the manifest.
	 */
	const GENERATOR = 'mclogiora';

	/**
	 * Language configuration section.
	 */
	const SECTION_LANGUAGES = 'languages';

	/**
	 * Translation relation section.
	 */
	const SECTION_RELATIONS = 'relations';

	/**
	 * Largest package the parser will accept, in bytes.
	 *
	 * This is a resource guard rather than a product limit. A site with tens of
	 * thousands of relations must still be able to export and re-read its own
	 * package, so the bound is set well above any realistic payload: the
	 * relation section costs on the order of a hundred bytes per item, which
	 * puts 64 MB somewhere past half a million translated objects. What it
	 * stops is a hostile or corrupt file being decoded into memory in full
	 * before anything has looked at it.
	 */
	const MAX_BYTES = 67108864;

	/**
	 * Maximum JSON nesting depth accepted by the parser.
	 *
	 * The format's own deepest path is six levels. Anything beyond this bound
	 * is not a package, and refusing it before `json_decode()` recurses is the
	 * cheapest place to say so.
	 */
	const MAX_DEPTH = 32;

	/**
	 * Returns the sections a package of the current format version may carry.
	 *
	 * @return string[]
	 */
	public static function sections() {
		return array(
			self::SECTION_LANGUAGES,
			self::SECTION_RELATIONS,
		);
	}

	/**
	 * Returns whether a section name belongs to the current format version.
	 *
	 * @param string $section Section name.
	 * @return bool
	 */
	public static function is_known_section( $section ) {
		return in_array( (string) $section, self::sections(), true );
	}

	/**
	 * Returns whether a format version can be read by this build.
	 *
	 * @param mixed $version Declared format version.
	 * @return bool
	 */
	public static function supports_version( $version ) {
		return is_int( $version ) && self::VERSION === $version;
	}

	/**
	 * Returns the JSON encoding flags every transport must use.
	 *
	 * Fixed rather than per-caller. Escaped slashes and `\uXXXX` sequences
	 * would still decode to the same package, but two transports encoding the
	 * same domain state into two different byte strings makes it impossible to
	 * compare packages, and comparing packages is how determinism is proven.
	 * Pretty printing is the one deliberate variation, and it is whitespace
	 * only.
	 *
	 * @param bool $pretty Whether to indent the output for human reading.
	 * @return int
	 */
	public static function json_flags( $pretty = false ) {
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

		return $pretty ? $flags | JSON_PRETTY_PRINT : $flags;
	}
}
