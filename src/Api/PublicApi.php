<?php
/**
 * Public developer API resolver.
 *
 * @package McLogiora
 */

namespace McLogiora\Api;

use McLogiora\Core\Application;
use McLogiora\Core\Container;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Languages\LanguageTag;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationGroup;
use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationRelationServiceInterface;
use McLogiora\Routing\LanguageContextInterface;
use McLogiora\Routing\TranslatedUrlGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Backs the public `mclogiora_*` functions.
 *
 * This class exists so that the published developer surface is a set of plain
 * functions returning plain arrays, and never a domain object. Handing a caller
 * a `Language` or a `TranslationGroup` would promote every method on it -- and
 * every field behind it, including the source hashes the change detector owns --
 * into something that can no longer be changed without breaking sites. The
 * projections below are the contract; the value objects stay internal.
 *
 * Nothing here writes. Reads need no nonce and no capability check of their own,
 * but they are also not an authorisation layer: a returned object ID is a
 * relation record, not permission to display that object. Callers rendering
 * content must apply their own checks, exactly as they must after
 * `get_post_meta()`.
 *
 * Every entry point has to survive being called before the plugin has booted,
 * on a site with no languages configured, and on a site whose schema is absent.
 * Themes call template tags from anywhere, and a multilingual helper that
 * fatals during a partial install is worse than one that returns nothing.
 */
final class PublicApi {
	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Returns an instance bound to the running application container.
	 *
	 * Internal. The supported entry points are the `mclogiora_*` functions.
	 *
	 * @return self
	 */
	public static function instance() {
		return new self( Application::instance()->container() );
	}

	/**
	 * Returns the configured languages as public projections.
	 *
	 * @param array<string,mixed> $args Optional. `status` accepts `active`
	 *                                  (default) or `all`.
	 * @return array<int,array<string,mixed>>
	 */
	public function languages( array $args = array() ) {
		$service = $this->service( LanguageServiceInterface::class );

		if ( ! $service instanceof LanguageServiceInterface ) {
			return array();
		}

		$status    = isset( $args['status'] ) ? (string) $args['status'] : 'active';
		$languages = 'all' === $status ? $service->get_languages() : $service->get_active_languages();
		$projected = array();

		foreach ( $languages as $language ) {
			if ( ! $language instanceof Language ) {
				continue;
			}

			$projected[] = $this->project_language( $language );
		}

		return $projected;
	}

	/**
	 * Returns the site default language, or null when none is configured.
	 *
	 * @return array<string,mixed>|null
	 */
	public function default_language() {
		$service = $this->service( LanguageServiceInterface::class );

		if ( ! $service instanceof LanguageServiceInterface ) {
			return null;
		}

		$language = $service->get_default_language();

		return $language instanceof Language ? $this->project_language( $language ) : null;
	}

	/**
	 * Returns the language of the current request, or null.
	 *
	 * Answered by `LanguageContextInterface`, which is the only resolver of
	 * that question in the plugin. A second one would eventually disagree.
	 *
	 * @return array<string,mixed>|null
	 */
	public function current_language() {
		$context = $this->service( LanguageContextInterface::class );

		if ( ! $context instanceof LanguageContextInterface ) {
			return null;
		}

		$language = $context->current();

		return $language instanceof Language ? $this->project_language( $language ) : null;
	}

	/**
	 * Returns the translated object ID for a language, or null.
	 *
	 * @param int    $object_id Source object identifier.
	 * @param string $object_type Relation object type.
	 * @param string $language Target language code.
	 * @return int|null
	 */
	public function translation( $object_id, $object_type, $language ) {
		$group = $this->group_for( $object_id, $object_type );

		if ( null === $group ) {
			return null;
		}

		foreach ( $group->items() as $item ) {
			if ( $item instanceof TranslationItem && $item->language_code() === (string) $language ) {
				return (int) $item->object_id();
			}
		}

		return null;
	}

	/**
	 * Returns the translation group an object belongs to, or null.
	 *
	 * @param int    $object_id Object identifier.
	 * @param string $object_type Relation object type.
	 * @return array<string,mixed>|null
	 */
	public function translation_group( $object_id, $object_type ) {
		$group = $this->group_for( $object_id, $object_type );

		if ( null === $group ) {
			return null;
		}

		$source       = null;
		$translations = array();

		foreach ( $group->items() as $item ) {
			if ( ! $item instanceof TranslationItem ) {
				continue;
			}

			$projected = $this->project_item( $item );

			$translations[ $projected['language'] ] = $projected;

			if ( $projected['is_source'] ) {
				$source = $projected;
			}
		}

		return array(
			'group_key'    => $group->group_key(),
			'object_type'  => (string) $object_type,
			'source'       => $source,
			'translations' => $translations,
		);
	}

	/**
	 * Returns the URL of an object in a language, or null when untranslated.
	 *
	 * Delegates to `TranslatedUrlGenerator`, which is the only place that
	 * decides what a translated URL looks like. It never invents a URL for a
	 * translation that does not exist, and this API does not either: null means
	 * no translation, not "try the home page".
	 *
	 * With no object, returns the language home URL.
	 *
	 * @param string   $language Target language code.
	 * @param int|null $object_id Optional. Object identifier.
	 * @param string   $object_type Optional. Relation object type. Default `post`.
	 * @param string   $taxonomy Optional. Taxonomy name, required for terms.
	 * @return string|null
	 */
	public function language_url( $language, $object_id = null, $object_type = ContentType::POST, $taxonomy = '' ) {
		$urls = $this->service( TranslatedUrlGenerator::class );

		if ( ! $urls instanceof TranslatedUrlGenerator ) {
			return null;
		}

		$language = (string) $language;

		if ( null === $object_id ) {
			return $urls->home_url_for( $language );
		}

		if ( ContentType::TERM === (string) $object_type ) {
			return $urls->term_url( (int) $object_id, (string) $taxonomy, $language );
		}

		return $urls->post_url( (int) $object_id, $language );
	}

	/**
	 * Resolves the relation group for an object.
	 *
	 * @param int    $object_id Object identifier.
	 * @param string $object_type Relation object type.
	 * @return TranslationGroup|null
	 */
	private function group_for( $object_id, $object_type ) {
		$relations = $this->service( TranslationRelationServiceInterface::class );

		if ( ! $relations instanceof TranslationRelationServiceInterface ) {
			return null;
		}

		$group = $relations->get_translation_set_for_object( (string) $object_type, (string) (int) $object_id );

		return $group instanceof TranslationGroup ? $group : null;
	}

	/**
	 * Projects a language onto the published array shape.
	 *
	 * `status` is deliberately absent: the raw `LanguageStatus` constants are an
	 * internal vocabulary, and `is_active` is the question callers actually ask.
	 *
	 * @param Language $language Language.
	 * @return array<string,mixed>
	 */
	private function project_language( Language $language ) {
		return array(
			'code'         => $language->code(),
			'locale'       => $language->locale(),
			'tag'          => LanguageTag::for_language( $language ),
			'native_name'  => $language->native_name(),
			'english_name' => $language->english_name(),
			'direction'    => $language->direction(),
			'is_active'    => $language->is_active(),
			'is_default'   => $language->is_default(),
			'order'        => $language->order(),
		);
	}

	/**
	 * Projects a relation item onto the published array shape.
	 *
	 * The source hashes and modified timestamps are deliberately absent. They
	 * are the change detector's own working state, their meaning has already
	 * changed once between phases, and publishing them would freeze it.
	 *
	 * @param TranslationItem $item Relation item.
	 * @return array{object_id:int,object_type:string,language:string,status:string,is_source:bool}
	 */
	private function project_item( TranslationItem $item ) {
		return array(
			'object_id'   => (int) $item->object_id(),
			'object_type' => $item->content_type(),
			'language'    => $item->language_code(),
			'status'      => $item->status(),
			'is_source'   => $item->is_original(),
		);
	}

	/**
	 * Resolves a service, or null when it is not registered.
	 *
	 * The container is empty until `Application::boot()` runs, and stays empty
	 * when environment validation fails. Asking it for a service that is not
	 * there throws, so every read goes through this guard.
	 *
	 * @param string $id Service identifier.
	 * @return mixed|null
	 */
	private function service( $id ) {
		if ( ! $this->container->has( $id ) ) {
			return null;
		}

		return $this->container->get( $id );
	}
}
