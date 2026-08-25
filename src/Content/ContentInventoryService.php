<?php
/**
 * Read-only inventory of translatable WordPress objects.
 *
 * @package McLogiora
 */

namespace McLogiora\Content;

use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationGroup;
use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationRelationServiceInterface;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Taxonomies\TaxonomyRegistryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Builds bounded, normalized inventory rows without changing WordPress data.
 *
 * Inventory and relation membership are deliberately separate concepts: an
 * eligible object is returned even when it has never been assigned to a
 * relation group.
 */
final class ContentInventoryService {
	/** @var ContentTypeRegistryInterface */
	private $content_types;

	/** @var TaxonomyRegistryInterface */
	private $taxonomies;

	/** @var TranslationRelationServiceInterface */
	private $relations;

	/** @var LanguageServiceInterface */
	private $languages;

	/**
	 * Constructor.
	 *
	 * @param ContentTypeRegistryInterface       $content_types Content registry.
	 * @param TaxonomyRegistryInterface          $taxonomies Taxonomy registry.
	 * @param TranslationRelationServiceInterface $relations Relation service.
	 * @param LanguageServiceInterface            $languages Language service.
	 */
	public function __construct( ContentTypeRegistryInterface $content_types, TaxonomyRegistryInterface $taxonomies, TranslationRelationServiceInterface $relations, LanguageServiceInterface $languages ) {
		$this->content_types = $content_types;
		$this->taxonomies    = $taxonomies;
		$this->relations     = $relations;
		$this->languages     = $languages;
	}

	/**
	 * Returns a paged inventory slice.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array{items:array<int,array<string,mixed>>,total:int,total_pages:int,page:int,per_page:int}
	 */
	public function query( array $args = array() ) {
		$kind     = isset( $args['kind'] ) && 'term' === $args['kind'] ? 'term' : 'post';
		$page     = max( 1, isset( $args['page'] ) ? absint( $args['page'] ) : 1 );
		$per_page = min( 50, max( 20, isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 25 ) );
		$search   = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';

		if ( 'term' === $kind ) {
			return $this->query_terms( $page, $per_page, $search, isset( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : '' );
		}

		$post_types = array();
		foreach ( $this->content_types->translatable() as $type ) {
			$post_types[] = $type->name();
		}

		if ( ! empty( $args['post_type'] ) && in_array( sanitize_key( $args['post_type'] ), $post_types, true ) ) {
			$post_types = array( sanitize_key( $args['post_type'] ) );
		}

		$query = new \WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => $per_page,
				'paged'          => $page,
				's'              => $search,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => false,
			)
		);
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->post_row( $post );
		}

		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Returns one normalized post row.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array<string,mixed>
	 */
	private function post_row( \WP_Post $post ) {
		return $this->object_row( ContentType::POST, (string) $post->ID, (string) $post->post_type, get_the_title( $post ), get_edit_post_link( $post->ID, 'raw' ), $post->post_status );
	}

	/**
	 * Returns a paged term inventory. Taxonomy is intentionally bounded to one
	 * selected taxonomy when the manager requests a term view.
	 *
	 * @param int    $page Page number.
	 * @param int    $per_page Page size.
	 * @param string $search Search term.
	 * @param string $taxonomy Taxonomy name.
	 * @return array{items:array<int,array<string,mixed>>,total:int,total_pages:int,page:int,per_page:int}
	 */
	private function query_terms( $page, $per_page, $search, $taxonomy ) {
		$allowed = array();
		foreach ( $this->taxonomies->translatable() as $type ) {
			$allowed[] = $type->name();
		}
		if ( '' === $taxonomy || ! in_array( $taxonomy, $allowed, true ) ) {
			$taxonomy = isset( $allowed[0] ) ? $allowed[0] : '';
		}
		if ( '' === $taxonomy ) {
			return array( 'items' => array(), 'total' => 0, 'total_pages' => 0, 'page' => $page, 'per_page' => $per_page );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => $per_page,
				'offset'     => ( $page - 1 ) * $per_page,
				'search'     => $search,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		$total = wp_count_terms( $taxonomy, array( 'hide_empty' => false, 'search' => $search ) );
		$total = is_wp_error( $total ) ? 0 : (int) $total;
		$items = array();

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$items[] = $this->object_row( ContentType::TERM, (string) $term->term_id, $term->taxonomy, $term->name, get_edit_term_link( $term->term_id, $term->taxonomy ), 'term' );
		}

		return array( 'items' => $items, 'total' => $total, 'total_pages' => (int) ceil( $total / $per_page ), 'page' => $page, 'per_page' => $per_page );
	}

	/**
	 * Normalizes relation, source, target, and missing-language data.
	 *
	 * @param string $object_type Relation object type.
	 * @param string $object_id Object ID.
	 * @param string $subtype Post type or taxonomy.
	 * @param string $title Object title.
	 * @param string $edit_url Edit URL.
	 * @param string $status Object status.
	 * @return array<string,mixed>
	 */
	private function object_row( $object_type, $object_id, $subtype, $title, $edit_url, $status ) {
		$group       = $this->relations->get_translation_set_for_object( $object_type, $object_id );
		$active      = $this->languages->get_active_languages();
		$codes       = array();
		$default     = $this->languages->get_default_language();
		$source_code = $default instanceof Language ? $default->code() : '';
		$targets     = array();

		foreach ( $active as $language ) {
			if ( $language instanceof Language ) {
				$codes[] = $language->code();
			}
		}

		if ( $group instanceof TranslationGroup ) {
			foreach ( $group->items() as $item ) {
				if ( $item->object_type() === $object_type && $item->object_id() === (string) $object_id ) {
					$source_code = $item->language_code();
				}
				if ( ! $item->is_original() && $item->object_type() === $object_type ) {
					$targets[ $item->language_code() ] = array(
						'id' => (int) $item->object_id(), 'status' => $item->status(), 'edit_url' => $this->edit_link( $object_type, $item->object_id(), $subtype ),
					);
				}
			}
		}

		$missing = array();
		foreach ( $codes as $code ) {
			if ( $code !== $source_code && ! isset( $targets[ $code ] ) ) {
				$missing[] = $code;
			}
		}

		return array(
			'kind'            => ContentType::TERM === $object_type ? 'term' : 'post',
			'object_type'     => $object_type,
			'object_id'       => (int) $object_id,
			'object_subtype'  => $subtype,
			'title'           => (string) $title,
			'source_language' => $source_code,
			'status'          => (string) $status,
			'group_key'       => $group instanceof TranslationGroup ? $group->group_key() : '',
			'targets'         => $targets,
			'missing'         => $missing,
			'edit_url'        => (string) $edit_url,
		);
	}

	/**
	 * Returns a safe target edit link.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $subtype Post type or taxonomy.
	 * @return string
	 */
	private function edit_link( $object_type, $object_id, $subtype ) {
		if ( ContentType::TERM === $object_type ) {
			return (string) get_edit_term_link( (int) $object_id, $subtype );
		}

		return (string) get_edit_post_link( (int) $object_id, 'raw' );
	}
}
