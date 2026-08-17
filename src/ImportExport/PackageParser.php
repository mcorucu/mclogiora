<?php
/**
 * Portable package parser.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

use McLogiora\Relations\ContentType;

defined( 'ABSPATH' ) || exit;

/**
 * Reads an untrusted package and answers one question: is this a package?
 *
 * Everything arriving here is hostile until proven otherwise. It came off a
 * disk, out of an upload or across a network, and nothing about it has been
 * checked. So the parser decides shape and type only -- whether the envelope is
 * there, whether this build can read the format version, whether every field is
 * the kind of thing it claims to be -- and hands the result to the validator,
 * which asks the different question of whether the package means anything on
 * this particular site.
 *
 * Keeping the two apart matters more than it looks. A parser that also consulted
 * the destination could not be run on a package before choosing a destination,
 * and a validator that also re-read the JSON would be a second reader that can
 * disagree with the first.
 *
 * `unserialize()` appears nowhere in this class and must appear nowhere in this
 * layer. PHP object deserialization on untrusted input is remote code execution
 * with extra steps; a package is data, and JSON cannot instantiate anything.
 *
 * ## Unknown fields
 *
 * Unknown keys inside a manifest, a language or a relation item are ignored, so
 * a package written by a later 1.x producer that added an optional field still
 * reads here. Unknown keys at the top level and unknown section names are
 * refused: the first means the envelope is not the envelope, and the second
 * means the file carries a whole domain of data this build would silently drop
 * while reporting a complete plan. A plan that omits data without saying so is
 * worse than a refusal.
 */
final class PackageParser {
	/**
	 * Parses a package.
	 *
	 * @param string $json Raw package bytes.
	 * @return TranslationPackage|\WP_Error
	 */
	public function parse( $json ) {
		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			return $this->error( 'empty', __( 'The package is empty.', 'mclogiora' ) );
		}

		if ( strlen( $json ) > PackageFormat::MAX_BYTES ) {
			return $this->error(
				'too_large',
				sprintf(
					/* translators: %d: maximum package size in bytes. */
					__( 'The package is larger than the %d bytes the reader accepts.', 'mclogiora' ),
					PackageFormat::MAX_BYTES
				)
			);
		}

		$decoded = json_decode( $json, true, PackageFormat::MAX_DEPTH );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return $this->error( 'invalid_json', __( 'The package is not valid JSON.', 'mclogiora' ) );
		}

		if ( ! $this->is_map( $decoded ) ) {
			return $this->error( 'not_an_object', __( 'The package is not a JSON object.', 'mclogiora' ) );
		}

		foreach ( array_keys( $decoded ) as $key ) {
			if ( 'manifest' !== $key && 'payload' !== $key ) {
				return $this->error(
					'unknown_member',
					sprintf(
						/* translators: %s: unexpected package member name. */
						__( 'The package carries an unexpected top-level member: %s.', 'mclogiora' ),
						(string) $key
					)
				);
			}
		}

		if ( ! isset( $decoded['manifest'] ) || ! $this->is_map( $decoded['manifest'] ) ) {
			return $this->error( 'missing_manifest', __( 'The package has no manifest.', 'mclogiora' ) );
		}

		if ( ! isset( $decoded['payload'] ) || ! $this->is_map( $decoded['payload'] ) ) {
			return $this->error( 'missing_payload', __( 'The package has no payload.', 'mclogiora' ) );
		}

		$manifest = $this->parse_manifest( $decoded['manifest'] );

		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$payload = $this->parse_payload( $decoded['payload'], $manifest );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$counts = $this->verify_counts( $manifest, $payload );

		if ( is_wp_error( $counts ) ) {
			return $counts;
		}

		return new TranslationPackage(
			$manifest,
			$payload[ PackageFormat::SECTION_LANGUAGES ],
			$payload[ PackageFormat::SECTION_RELATIONS ]
		);
	}

	/**
	 * Parses the manifest.
	 *
	 * @param array<string,mixed> $data Decoded manifest.
	 * @return PackageManifest|\WP_Error
	 */
	private function parse_manifest( array $data ) {
		if ( ! isset( $data['format'] ) || ! is_string( $data['format'] ) || PackageFormat::FORMAT !== $data['format'] ) {
			return $this->error(
				'unknown_format',
				sprintf(
					/* translators: %s: expected package format identifier. */
					__( 'The file does not declare itself as %s.', 'mclogiora' ),
					PackageFormat::FORMAT
				)
			);
		}

		if ( ! array_key_exists( 'format_version', $data ) ) {
			return $this->error( 'missing_version', __( 'The package declares no format version.', 'mclogiora' ) );
		}

		if ( ! PackageFormat::supports_version( $data['format_version'] ) ) {
			return $this->error(
				'unsupported_version',
				sprintf(
					/* translators: 1: declared format version, 2: supported format version. */
					__( 'The package uses format version %1$s, and this version of mcLogiora reads format version %2$d.', 'mclogiora' ),
					is_scalar( $data['format_version'] ) ? (string) $data['format_version'] : gettype( $data['format_version'] ),
					PackageFormat::VERSION
				)
			);
		}

		foreach ( array( 'generator', 'generator_version', 'created_at' ) as $field ) {
			if ( ! isset( $data[ $field ] ) || ! is_string( $data[ $field ] ) ) {
				return $this->error(
					'invalid_manifest_field',
					sprintf(
						/* translators: %s: manifest field name. */
						__( 'The manifest field %s is missing or is not a string.', 'mclogiora' ),
						$field
					)
				);
			}
		}

		if ( ! isset( $data['sections'] ) || ! $this->is_list( $data['sections'] ) ) {
			return $this->error( 'invalid_manifest_field', __( 'The manifest does not list its sections.', 'mclogiora' ) );
		}

		$sections = array();

		foreach ( $data['sections'] as $section ) {
			if ( ! is_string( $section ) || ! PackageFormat::is_known_section( $section ) ) {
				return $this->error(
					'unknown_section',
					sprintf(
						/* translators: %s: section name. */
						__( 'The package declares a section this version cannot read: %s.', 'mclogiora' ),
						is_string( $section ) ? $section : gettype( $section )
					)
				);
			}

			$sections[] = $section;
		}

		if ( ! isset( $data['counts'] ) || ! $this->is_map( $data['counts'] ) ) {
			return $this->error( 'invalid_manifest_field', __( 'The manifest carries no counts.', 'mclogiora' ) );
		}

		$counts = array();

		foreach ( $data['counts'] as $name => $count ) {
			if ( ! is_int( $count ) || $count < 0 ) {
				return $this->error(
					'invalid_manifest_field',
					sprintf(
						/* translators: %s: count name. */
						__( 'The manifest count %s is not a whole number.', 'mclogiora' ),
						(string) $name
					)
				);
			}

			$counts[ (string) $name ] = $count;
		}

		return new PackageManifest(
			$data['format'],
			$data['format_version'],
			$data['generator'],
			$data['generator_version'],
			$data['created_at'],
			$sections,
			$counts
		);
	}

	/**
	 * Parses the payload sections.
	 *
	 * @param array<string,mixed> $data Decoded payload.
	 * @param PackageManifest     $manifest Parsed manifest.
	 * @return array{languages:PackageLanguage[],relations:PackageRelationGroup[]}|\WP_Error
	 */
	private function parse_payload( array $data, PackageManifest $manifest ) {
		foreach ( array_keys( $data ) as $section ) {
			if ( ! PackageFormat::is_known_section( (string) $section ) ) {
				return $this->error(
					'unknown_section',
					sprintf(
						/* translators: %s: section name. */
						__( 'The payload carries a section this version cannot read: %s.', 'mclogiora' ),
						(string) $section
					)
				);
			}

			if ( ! in_array( (string) $section, $manifest->sections(), true ) ) {
				return $this->error(
					'undeclared_section',
					sprintf(
						/* translators: %s: section name. */
						__( 'The payload carries a section the manifest does not declare: %s.', 'mclogiora' ),
						(string) $section
					)
				);
			}
		}

		foreach ( $manifest->sections() as $section ) {
			if ( ! isset( $data[ $section ] ) || ! $this->is_list( $data[ $section ] ) ) {
				return $this->error(
					'invalid_section',
					sprintf(
						/* translators: %s: section name. */
						__( 'The section %s is missing or is not a list.', 'mclogiora' ),
						$section
					)
				);
			}
		}

		$languages = $this->parse_languages(
			isset( $data[ PackageFormat::SECTION_LANGUAGES ] ) ? $data[ PackageFormat::SECTION_LANGUAGES ] : array()
		);

		if ( is_wp_error( $languages ) ) {
			return $languages;
		}

		$relations = $this->parse_relations(
			isset( $data[ PackageFormat::SECTION_RELATIONS ] ) ? $data[ PackageFormat::SECTION_RELATIONS ] : array()
		);

		if ( is_wp_error( $relations ) ) {
			return $relations;
		}

		return array(
			PackageFormat::SECTION_LANGUAGES => $languages,
			PackageFormat::SECTION_RELATIONS => $relations,
		);
	}

	/**
	 * Parses the language section.
	 *
	 * @param array<int,mixed> $entries Decoded language entries.
	 * @return PackageLanguage[]|\WP_Error
	 */
	private function parse_languages( array $entries ) {
		$languages = array();
		$seen      = array();
		$defaults  = 0;

		foreach ( $entries as $entry ) {
			if ( ! $this->is_map( $entry ) ) {
				return $this->error( 'invalid_language', __( 'A language entry is not an object.', 'mclogiora' ) );
			}

			$code = isset( $entry['code'] ) && is_string( $entry['code'] ) ? $entry['code'] : '';

			if ( '' === $code || sanitize_key( $code ) !== $code ) {
				return $this->error( 'invalid_language', __( 'A language entry has no usable language code.', 'mclogiora' ) );
			}

			if ( isset( $seen[ $code ] ) ) {
				return $this->error(
					'duplicate_language',
					sprintf(
						/* translators: %s: language code. */
						__( 'The package lists the language %s more than once.', 'mclogiora' ),
						$code
					)
				);
			}

			foreach ( array( 'locale', 'native_name', 'english_name', 'direction' ) as $field ) {
				if ( ! isset( $entry[ $field ] ) || ! is_string( $entry[ $field ] ) ) {
					return $this->error(
						'invalid_language',
						sprintf(
							/* translators: 1: language code, 2: field name. */
							__( 'The language %1$s has a missing or non-textual %2$s.', 'mclogiora' ),
							$code,
							$field
						)
					);
				}
			}

			if ( 'ltr' !== $entry['direction'] && 'rtl' !== $entry['direction'] ) {
				return $this->error(
					'invalid_language',
					sprintf(
						/* translators: %s: language code. */
						__( 'The language %s declares a text direction that is neither ltr nor rtl.', 'mclogiora' ),
						$code
					)
				);
			}

			foreach ( array( 'is_active', 'is_default' ) as $field ) {
				if ( ! isset( $entry[ $field ] ) || ! is_bool( $entry[ $field ] ) ) {
					return $this->error(
						'invalid_language',
						sprintf(
							/* translators: 1: language code, 2: field name. */
							__( 'The language %1$s has a missing or non-boolean %2$s.', 'mclogiora' ),
							$code,
							$field
						)
					);
				}
			}

			if ( ! isset( $entry['order'] ) || ! is_int( $entry['order'] ) || $entry['order'] < 0 ) {
				return $this->error(
					'invalid_language',
					sprintf(
						/* translators: %s: language code. */
						__( 'The language %s has no whole-number display order.', 'mclogiora' ),
						$code
					)
				);
			}

			if ( $entry['is_default'] ) {
				++$defaults;
			}

			$seen[ $code ] = true;
			$languages[]   = new PackageLanguage(
				$code,
				$entry['locale'],
				$entry['native_name'],
				$entry['english_name'],
				$entry['direction'],
				$entry['is_active'],
				$entry['is_default'],
				$entry['order']
			);
		}

		if ( $defaults > 1 ) {
			return $this->error( 'invalid_language', __( 'The package marks more than one language as the default.', 'mclogiora' ) );
		}

		return $languages;
	}

	/**
	 * Parses the relation section.
	 *
	 * @param array<int,mixed> $entries Decoded relation groups.
	 * @return PackageRelationGroup[]|\WP_Error
	 */
	private function parse_relations( array $entries ) {
		$groups = array();
		$seen   = array();

		foreach ( $entries as $entry ) {
			if ( ! $this->is_map( $entry ) ) {
				return $this->error( 'invalid_relation_group', __( 'A relation group is not an object.', 'mclogiora' ) );
			}

			$key = isset( $entry['group_key'] ) && is_string( $entry['group_key'] ) ? $entry['group_key'] : '';

			if ( '' === $key || sanitize_key( $key ) !== $key ) {
				return $this->error( 'invalid_relation_group', __( 'A relation group has no usable group key.', 'mclogiora' ) );
			}

			if ( isset( $seen[ $key ] ) ) {
				return $this->error(
					'duplicate_relation_group',
					sprintf(
						/* translators: %s: translation group key. */
						__( 'The package lists the translation group %s more than once.', 'mclogiora' ),
						$key
					)
				);
			}

			if ( ! isset( $entry['items'] ) || ! $this->is_list( $entry['items'] ) || array() === $entry['items'] ) {
				return $this->error(
					'invalid_relation_group',
					sprintf(
						/* translators: %s: translation group key. */
						__( 'The translation group %s carries no items.', 'mclogiora' ),
						$key
					)
				);
			}

			$items = $this->parse_relation_items( $key, $entry['items'] );

			if ( is_wp_error( $items ) ) {
				return $items;
			}

			$seen[ $key ] = true;
			$groups[]     = new PackageRelationGroup( $key, $items );
		}

		return $groups;
	}

	/**
	 * Parses the items of one relation group.
	 *
	 * @param string           $group_key Group key.
	 * @param array<int,mixed> $entries Decoded items.
	 * @return PackageRelationItem[]|\WP_Error
	 */
	private function parse_relation_items( $group_key, array $entries ) {
		$items   = array();
		$seen    = array();
		$sources = 0;

		foreach ( $entries as $entry ) {
			if ( ! $this->is_map( $entry ) ) {
				return $this->error( 'invalid_relation_item', __( 'A relation item is not an object.', 'mclogiora' ) );
			}

			$language = isset( $entry['language'] ) && is_string( $entry['language'] ) ? $entry['language'] : '';

			if ( '' === $language || sanitize_key( $language ) !== $language ) {
				return $this->error(
					'invalid_relation_item',
					sprintf(
						/* translators: %s: translation group key. */
						__( 'An item of translation group %s has no usable language code.', 'mclogiora' ),
						$group_key
					)
				);
			}

			if ( isset( $seen[ $language ] ) ) {
				return $this->error(
					'duplicate_relation_item',
					sprintf(
						/* translators: 1: translation group key, 2: language code. */
						__( 'Translation group %1$s carries two items in the language %2$s.', 'mclogiora' ),
						$group_key,
						$language
					)
				);
			}

			$object_type = isset( $entry['object_type'] ) && is_string( $entry['object_type'] ) ? $entry['object_type'] : '';

			if ( '' === $object_type || ! ContentType::is_valid( $object_type ) ) {
				return $this->error(
					'invalid_relation_item',
					sprintf(
						/* translators: 1: translation group key, 2: language code. */
						__( 'The %2$s item of translation group %1$s has no usable object type.', 'mclogiora' ),
						$group_key,
						$language
					)
				);
			}

			if ( ! isset( $entry['status'] ) || ! is_string( $entry['status'] ) || '' === $entry['status'] ) {
				return $this->error(
					'invalid_relation_item',
					sprintf(
						/* translators: 1: translation group key, 2: language code. */
						__( 'The %2$s item of translation group %1$s has no status.', 'mclogiora' ),
						$group_key,
						$language
					)
				);
			}

			if ( ! isset( $entry['is_source'] ) || ! is_bool( $entry['is_source'] ) ) {
				return $this->error(
					'invalid_relation_item',
					sprintf(
						/* translators: 1: translation group key, 2: language code. */
						__( 'The %2$s item of translation group %1$s does not say whether it is the source.', 'mclogiora' ),
						$group_key,
						$language
					)
				);
			}

			if ( ! array_key_exists( 'locator', $entry ) ) {
				return $this->error(
					'invalid_relation_item',
					sprintf(
						/* translators: 1: translation group key, 2: language code. */
						__( 'The %2$s item of translation group %1$s carries no locator member.', 'mclogiora' ),
						$group_key,
						$language
					)
				);
			}

			$locator = null;

			if ( null !== $entry['locator'] ) {
				$locator = ObjectLocator::from_array( $entry['locator'] );

				if ( null === $locator ) {
					return $this->error(
						'invalid_relation_item',
						sprintf(
							/* translators: 1: translation group key, 2: language code. */
							__( 'The %2$s item of translation group %1$s has a locator this version cannot read.', 'mclogiora' ),
							$group_key,
							$language
						)
					);
				}
			}

			if ( $entry['is_source'] ) {
				++$sources;
			}

			$seen[ $language ] = true;
			$items[]           = new PackageRelationItem( $object_type, $language, $entry['status'], $entry['is_source'], $locator );
		}

		if ( 1 !== $sources ) {
			return $this->error(
				'invalid_relation_group',
				sprintf(
					/* translators: %s: translation group key. */
					__( 'Translation group %s must carry exactly one source item.', 'mclogiora' ),
					$group_key
				)
			);
		}

		return $items;
	}

	/**
	 * Checks the manifest counts against the payload that arrived.
	 *
	 * This is a truncation check and nothing more. It catches a file that was
	 * cut short in transit or edited by hand into disagreeing with itself, and
	 * it proves nothing whatever about who produced the package: anyone editing
	 * a payload can edit the counts beside it. mcLogiora therefore does not
	 * sign packages and does not imply that it does.
	 *
	 * @param PackageManifest                                                     $manifest Manifest.
	 * @param array{languages:PackageLanguage[],relations:PackageRelationGroup[]} $payload Parsed payload.
	 * @return true|\WP_Error
	 */
	private function verify_counts( PackageManifest $manifest, array $payload ) {
		$items = 0;

		foreach ( $payload[ PackageFormat::SECTION_RELATIONS ] as $group ) {
			$items += count( $group->items() );
		}

		$actual = array(
			'languages'       => count( $payload[ PackageFormat::SECTION_LANGUAGES ] ),
			'relation_groups' => count( $payload[ PackageFormat::SECTION_RELATIONS ] ),
			'relation_items'  => $items,
		);

		foreach ( $manifest->counts() as $name => $declared ) {
			if ( isset( $actual[ $name ] ) && $actual[ $name ] !== $declared ) {
				return $this->error(
					'count_mismatch',
					sprintf(
						/* translators: 1: count name, 2: declared count, 3: counted value. */
						__( 'The manifest declares %2$d for %1$s and the payload contains %3$d.', 'mclogiora' ),
						(string) $name,
						$declared,
						$actual[ $name ]
					)
				);
			}
		}

		return true;
	}

	/**
	 * Returns whether a decoded value is a JSON object.
	 *
	 * @param mixed $value Decoded value.
	 * @return bool
	 */
	private function is_map( $value ) {
		return is_array( $value ) && ! $this->is_list( $value );
	}

	/**
	 * Returns whether a decoded value is a JSON array.
	 *
	 * @param mixed $value Decoded value.
	 * @return bool
	 */
	private function is_list( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}

		if ( array() === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Builds a namespaced parser error.
	 *
	 * @param string $code Error code without the shared prefix.
	 * @param string $message Human-readable message.
	 * @return \WP_Error
	 */
	private function error( $code, $message ) {
		return new \WP_Error( 'mclogiora_package_' . $code, $message );
	}
}
