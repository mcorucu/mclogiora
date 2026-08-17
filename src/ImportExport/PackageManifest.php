<?php
/**
 * Portable package manifest.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * The envelope a reader can trust before it has read the payload.
 *
 * Everything here answers a question the reader must be able to ask first:
 * what format is this, can this build read it, what produced it, when, which
 * sections are present, and how much of each. A package without a manifest is
 * refused rather than sniffed.
 *
 * What the manifest deliberately does not carry is the source site. No site
 * URL, no site name, no administrator address, no user, no environment. None
 * of it is needed to read or apply a package, and a file that travels between
 * sites, gets attached to support tickets and sits in backups is the worst
 * possible place to record who produced it.
 */
final class PackageManifest {
	/**
	 * Format identifier.
	 *
	 * @var string
	 */
	private $format;

	/**
	 * Format version.
	 *
	 * @var int
	 */
	private $format_version;

	/**
	 * Producing plugin slug.
	 *
	 * @var string
	 */
	private $generator;

	/**
	 * Producing plugin version.
	 *
	 * @var string
	 */
	private $generator_version;

	/**
	 * Creation timestamp, ISO 8601 in UTC.
	 *
	 * @var string
	 */
	private $created_at;

	/**
	 * Section names present in the payload.
	 *
	 * @var string[]
	 */
	private $sections;

	/**
	 * Payload counts keyed by count name.
	 *
	 * @var array<string,int>
	 */
	private $counts;

	/**
	 * Constructor.
	 *
	 * @param string            $format Format identifier.
	 * @param int               $format_version Format version.
	 * @param string            $generator Producing plugin slug.
	 * @param string            $generator_version Producing plugin version.
	 * @param string            $created_at Creation timestamp.
	 * @param string[]          $sections Section names.
	 * @param array<string,int> $counts Payload counts.
	 */
	public function __construct( $format, $format_version, $generator, $generator_version, $created_at, array $sections, array $counts ) {
		$this->format            = (string) $format;
		$this->format_version    = (int) $format_version;
		$this->generator         = (string) $generator;
		$this->generator_version = (string) $generator_version;
		$this->created_at        = (string) $created_at;
		$this->sections          = array_values( array_map( 'strval', $sections ) );
		$this->counts            = array_map( 'intval', $counts );
	}

	/**
	 * Returns the format identifier.
	 *
	 * @return string
	 */
	public function format() {
		return $this->format;
	}

	/**
	 * Returns the format version.
	 *
	 * @return int
	 */
	public function format_version() {
		return $this->format_version;
	}

	/**
	 * Returns the producing plugin slug.
	 *
	 * @return string
	 */
	public function generator() {
		return $this->generator;
	}

	/**
	 * Returns the producing plugin version.
	 *
	 * @return string
	 */
	public function generator_version() {
		return $this->generator_version;
	}

	/**
	 * Returns the creation timestamp.
	 *
	 * @return string
	 */
	public function created_at() {
		return $this->created_at;
	}

	/**
	 * Returns the section names present in the payload.
	 *
	 * @return string[]
	 */
	public function sections() {
		return $this->sections;
	}

	/**
	 * Returns the payload counts.
	 *
	 * @return array<string,int>
	 */
	public function counts() {
		return $this->counts;
	}

	/**
	 * Returns the package representation with a fixed key order.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'format'            => $this->format,
			'format_version'    => $this->format_version,
			'generator'         => $this->generator,
			'generator_version' => $this->generator_version,
			'created_at'        => $this->created_at,
			'sections'          => $this->sections,
			'counts'            => $this->counts,
		);
	}
}
