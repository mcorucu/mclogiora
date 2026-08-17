<?php
/**
 * Portable package exporter.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationGroup;
use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationRelationRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a portable package out of mcLogiora's own domain state.
 *
 * This is an application service and it belongs to no transport. A REST route,
 * a WP-CLI command and an admin screen would each be a caller; none of them may
 * be where a package is assembled, because three callers assembling a package
 * is three subtly different package formats.
 *
 * It reads and only reads. Export never normalises state on the way past: it
 * does not repair a relation whose object has been deleted, does not touch a
 * status, does not refresh a cache and does not save a setting. An export that
 * changed the site it was describing would make the description wrong the
 * moment it was taken.
 *
 * Nothing is serialised by reflection or by handing an object to the encoder.
 * Every field in the output is written out by name below, which is what keeps
 * the internals -- repositories, value objects, the schema, the source-change
 * hashes -- free to change without changing the wire format.
 */
final class PackageExporter {
	/**
	 * Groups read per page while walking the relation table.
	 */
	const GROUP_PAGE_SIZE = 200;

	/**
	 * Language service.
	 *
	 * @var LanguageServiceInterface
	 */
	private $languages;

	/**
	 * Relation repository.
	 *
	 * @var TranslationRelationRepositoryInterface
	 */
	private $relations;

	/**
	 * Object locator gateway.
	 *
	 * @var ObjectLocatorGatewayInterface
	 */
	private $objects;

	/**
	 * Producing plugin version.
	 *
	 * @var string
	 */
	private $plugin_version;

	/**
	 * Constructor.
	 *
	 * @param LanguageServiceInterface               $languages Language service.
	 * @param TranslationRelationRepositoryInterface $relations Relation repository.
	 * @param ObjectLocatorGatewayInterface          $objects Object locator gateway.
	 * @param string                                 $plugin_version Producing plugin version.
	 */
	public function __construct(
		LanguageServiceInterface $languages,
		TranslationRelationRepositoryInterface $relations,
		ObjectLocatorGatewayInterface $objects,
		$plugin_version
	) {
		$this->languages      = $languages;
		$this->relations      = $relations;
		$this->objects        = $objects;
		$this->plugin_version = (string) $plugin_version;
	}

	/**
	 * Builds a package describing the current site.
	 *
	 * @return TranslationPackage
	 */
	public function export() {
		$languages = $this->export_languages();
		$relations = $this->export_relations();

		return new TranslationPackage(
			$this->build_manifest( $languages, $relations ),
			$languages,
			$relations
		);
	}

	/**
	 * Exports the language configuration.
	 *
	 * Ordered by language code rather than by display order. Display order is
	 * itself an exported field, so sorting by it would make the file's shape
	 * depend on a value the file already carries, and reordering languages
	 * would rewrite the whole section for no semantic change.
	 *
	 * @return PackageLanguage[]
	 */
	private function export_languages() {
		$exported = array();

		foreach ( $this->languages->get_languages() as $language ) {
			if ( ! $language instanceof Language ) {
				continue;
			}

			$exported[ $language->code() ] = new PackageLanguage(
				$language->code(),
				$language->locale(),
				$language->native_name(),
				$language->english_name(),
				$language->direction(),
				$language->is_active(),
				$language->is_default(),
				$language->order()
			);
		}

		ksort( $exported, SORT_STRING );

		return array_values( $exported );
	}

	/**
	 * Exports every active translation group.
	 *
	 * Read one page at a time. A site with fifty thousand groups must be able
	 * to export without holding every relation row in memory at once, and the
	 * page reader is ordered by group key so the pages join up into one stable
	 * sequence.
	 *
	 * @return PackageRelationGroup[]
	 */
	private function export_relations() {
		$exported = array();
		$offset   = 0;

		do {
			$keys = $this->relations->active_group_keys( self::GROUP_PAGE_SIZE, $offset );
			$read = count( $keys );

			foreach ( $keys as $key ) {
				$group = $this->relations->find_group( $key );

				if ( ! $group instanceof TranslationGroup ) {
					continue;
				}

				$exported[] = $this->export_group( $group );
			}

			$offset += $read;
		} while ( self::GROUP_PAGE_SIZE === $read );

		usort(
			$exported,
			static function ( PackageRelationGroup $a, PackageRelationGroup $b ) {
				return strcmp( $a->group_key(), $b->group_key() );
			}
		);

		return $exported;
	}

	/**
	 * Exports one translation group.
	 *
	 * @param TranslationGroup $group Translation group.
	 * @return PackageRelationGroup
	 */
	private function export_group( TranslationGroup $group ) {
		$items = array();

		foreach ( $group->items() as $item ) {
			if ( ! $item instanceof TranslationItem ) {
				continue;
			}

			$items[] = new PackageRelationItem(
				$item->content_type(),
				$item->language_code(),
				$item->status(),
				$item->is_original(),
				$this->locate( $item )
			);
		}

		usort(
			$items,
			static function ( PackageRelationItem $a, PackageRelationItem $b ) {
				return strcmp( $a->language(), $b->language() );
			}
		);

		return new PackageRelationGroup( $group->group_key(), $items );
	}

	/**
	 * Builds the portable locator for a relation item.
	 *
	 * Returns null in the two cases where no honest locator exists: a content
	 * type this format version has no addressing scheme for, and an object the
	 * site can no longer read. Neither is invented around.
	 *
	 * @param TranslationItem $item Relation item.
	 * @return ObjectLocator|null
	 */
	private function locate( TranslationItem $item ) {
		$object_id = (int) $item->object_key();

		if ( ContentType::POST === $item->content_type() ) {
			$post = $this->objects->describe_post( $object_id );

			if ( null === $post ) {
				return null;
			}

			return ObjectLocator::for_post( $post['post_type'], $post['slug'], $post['ancestors'] );
		}

		if ( ContentType::TERM === $item->content_type() ) {
			$term = $this->objects->describe_term( $object_id );

			if ( null === $term ) {
				return null;
			}

			return ObjectLocator::for_term( $term['taxonomy'], $term['slug'] );
		}

		return null;
	}

	/**
	 * Builds the manifest for an exported payload.
	 *
	 * @param PackageLanguage[]      $languages Languages.
	 * @param PackageRelationGroup[] $relations Relation groups.
	 * @return PackageManifest
	 */
	private function build_manifest( array $languages, array $relations ) {
		$items = 0;

		foreach ( $relations as $group ) {
			$items += count( $group->items() );
		}

		return new PackageManifest(
			PackageFormat::FORMAT,
			PackageFormat::VERSION,
			PackageFormat::GENERATOR,
			$this->plugin_version,
			gmdate( 'Y-m-d\TH:i:s\Z' ),
			PackageFormat::sections(),
			array(
				'languages'       => count( $languages ),
				'relation_groups' => count( $relations ),
				'relation_items'  => $items,
			)
		);
	}
}
