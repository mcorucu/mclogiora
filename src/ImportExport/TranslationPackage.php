<?php
/**
 * Portable translation package.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * A whole package, in memory, in one shape for both directions.
 *
 * The exporter builds one of these and the parser rebuilds one of these, which
 * is what makes an export/parse round trip meaningful: there is no second
 * in-memory model that the reading side interprets slightly differently from
 * the writing side. Everything downstream -- validation, planning and, in a
 * later slice, applying -- reads this object and never the JSON.
 *
 * It is immutable, holds only arrays, scalars and its own value objects, and
 * contains no repository, no container and no WordPress object.
 */
final class TranslationPackage {
	/**
	 * Manifest.
	 *
	 * @var PackageManifest
	 */
	private $manifest;

	/**
	 * Languages, ordered by code.
	 *
	 * @var PackageLanguage[]
	 */
	private $languages;

	/**
	 * Relation groups, ordered by group key.
	 *
	 * @var PackageRelationGroup[]
	 */
	private $relations;

	/**
	 * Constructor.
	 *
	 * @param PackageManifest        $manifest Manifest.
	 * @param PackageLanguage[]      $languages Languages.
	 * @param PackageRelationGroup[] $relations Relation groups.
	 */
	public function __construct( PackageManifest $manifest, array $languages, array $relations ) {
		$this->manifest  = $manifest;
		$this->languages = array_values( $languages );
		$this->relations = array_values( $relations );
	}

	/**
	 * Returns the manifest.
	 *
	 * @return PackageManifest
	 */
	public function manifest() {
		return $this->manifest;
	}

	/**
	 * Returns the languages.
	 *
	 * @return PackageLanguage[]
	 */
	public function languages() {
		return $this->languages;
	}

	/**
	 * Returns the relation groups.
	 *
	 * @return PackageRelationGroup[]
	 */
	public function relations() {
		return $this->relations;
	}

	/**
	 * Returns the language a package declares as the default, or null.
	 *
	 * @return PackageLanguage|null
	 */
	public function default_language() {
		foreach ( $this->languages as $language ) {
			if ( $language->is_default() ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Returns whether the package carries a language code.
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	public function has_language( $code ) {
		foreach ( $this->languages as $language ) {
			if ( $language->code() === (string) $code ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the wire representation with a fixed key order.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		$languages = array();

		foreach ( $this->languages as $language ) {
			$languages[] = $language->to_array();
		}

		$relations = array();

		foreach ( $this->relations as $group ) {
			$relations[] = $group->to_array();
		}

		return array(
			'manifest' => $this->manifest->to_array(),
			'payload'  => array(
				PackageFormat::SECTION_LANGUAGES => $languages,
				PackageFormat::SECTION_RELATIONS => $relations,
			),
		);
	}
}
