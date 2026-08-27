<?php
/**
 * WordPress object locator gateway.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves locators against real WordPress content, and only ever reads.
 *
 * Every lookup goes through core query APIs rather than direct SQL, and every
 * one of them is asked to do as little as possible: identifiers only, no found
 * rows, no meta cache, no term cache, and a small bound on how many matches
 * come back. The planner never needs the objects themselves -- it needs to
 * know whether a locator names none, one, or more than one of them.
 *
 * Core query filters remain enabled so that the gateway follows the same
 * visibility and compatibility contracts as the rest of the WordPress site.
 * The planner still limits each lookup to identifiers and a small result set;
 * it does not need to load the matched objects.
 */
final class WordPressObjectLocatorGateway implements ObjectLocatorGatewayInterface {
	/**
	 * Describes a post for locator construction.
	 *
	 * @param int $post_id Post identifier.
	 * @return array{post_type:string,slug:string,ancestors:string[]|null}|null
	 */
	public function describe_post( $post_id ) {
		$post = get_post( (int) $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		return array(
			'post_type' => (string) $post->post_type,
			'slug'      => (string) $post->post_name,
			'ancestors' => is_post_type_hierarchical( $post->post_type )
				? $this->ancestor_slugs( $post )
				: null,
		);
	}

	/**
	 * Describes a term for locator construction.
	 *
	 * @param int $term_id Term identifier.
	 * @return array{taxonomy:string,slug:string}|null
	 */
	public function describe_term( $term_id ) {
		$term = get_term( (int) $term_id );

		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		return array(
			'taxonomy' => (string) $term->taxonomy,
			'slug'     => (string) $term->slug,
		);
	}

	/**
	 * Returns the identifiers of every post matching a type and slug.
	 *
	 * @param string $post_type Post type.
	 * @param string $slug Post slug.
	 * @param int    $limit Maximum identifiers to return.
	 * @return int[]
	 */
	public function find_posts( $post_type, $slug, $limit ) {
		$post_type = (string) $post_type;
		$slug      = (string) $slug;
		$limit     = max( 1, (int) $limit );

		if ( '' === $post_type || '' === $slug ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'              => $post_type,
				'name'                   => $slug,
				'post_status'            => $this->searchable_post_statuses(),
				'posts_per_page'         => $limit,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_values( array_map( 'intval', is_array( $posts ) ? $posts : array() ) );
	}

	/**
	 * Returns the identifiers of every term matching a taxonomy and slug.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug Term slug.
	 * @param int    $limit Maximum identifiers to return.
	 * @return int[]
	 */
	public function find_terms( $taxonomy, $slug, $limit ) {
		$taxonomy = (string) $taxonomy;
		$slug     = (string) $slug;
		$limit    = max( 1, (int) $limit );

		if ( '' === $taxonomy || '' === $slug || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'               => $taxonomy,
				'slug'                   => $slug,
				'hide_empty'             => false,
				'number'                 => $limit,
				'orderby'                => 'term_id',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'suppress_filter'        => true,
				'update_term_meta_cache' => false,
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_values( array_map( 'intval', $terms ) );
	}

	/**
	 * Returns whether a post type is registered on this site.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function post_type_exists( $post_type ) {
		return post_type_exists( (string) $post_type );
	}

	/**
	 * Returns whether a taxonomy is registered on this site.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	public function taxonomy_exists( $taxonomy ) {
		return taxonomy_exists( (string) $taxonomy );
	}

	/**
	 * Returns the ancestor slugs of a post, from the root downwards.
	 *
	 * @param \WP_Post $post Post.
	 * @return string[]
	 */
	private function ancestor_slugs( \WP_Post $post ) {
		$ancestors = get_post_ancestors( $post );
		$slugs     = array();

		foreach ( is_array( $ancestors ) ? $ancestors : array() as $ancestor_id ) {
			$ancestor = get_post( (int) $ancestor_id );

			if ( ! $ancestor instanceof \WP_Post ) {
				continue;
			}

			$slugs[] = (string) $ancestor->post_name;
		}

		return array_reverse( $slugs );
	}

	/**
	 * Returns every post status a locator lookup should search.
	 *
	 * `auto-draft` is excluded because it names a post WordPress created for an
	 * editing session and will delete on its own; nothing should be related to
	 * one, and matching one would hand the planner an object that disappears.
	 *
	 * @return string[]
	 */
	private function searchable_post_statuses() {
		$statuses = array_keys( get_post_stati() );

		return array_values( array_diff( $statuses, array( 'auto-draft' ) ) );
	}
}
