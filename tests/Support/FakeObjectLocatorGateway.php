<?php
/**
 * In-memory object locator gateway for tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Support;

use McLogiora\ImportExport\ObjectLocatorGatewayInterface;

/**
 * Serves a fixed set of posts and terms.
 *
 * Deliberately allows two posts to share a type and slug, which WordPress's
 * own insert path will not produce. Real sites reach that state through
 * migrations and bad imports, and it is the case the locator resolver has to
 * report rather than resolve.
 */
final class FakeObjectLocatorGateway implements ObjectLocatorGatewayInterface {
	/**
	 * Posts keyed by identifier.
	 *
	 * @var array<int,array{post_type:string,slug:string,ancestors:string[]|null}>
	 */
	private $posts = array();

	/**
	 * Terms keyed by identifier.
	 *
	 * @var array<int,array{taxonomy:string,slug:string}>
	 */
	private $terms = array();

	/**
	 * Registered post types.
	 *
	 * @var string[]
	 */
	private $post_types = array( 'post', 'page' );

	/**
	 * Registered taxonomies.
	 *
	 * @var string[]
	 */
	private $taxonomies = array( 'category', 'post_tag' );

	/**
	 * Records a post.
	 *
	 * @param int           $id Post identifier.
	 * @param string        $post_type Post type.
	 * @param string        $slug Slug.
	 * @param string[]|null $ancestors Ancestor slugs, or null for a flat type.
	 * @return void
	 */
	public function add_post( $id, $post_type, $slug, $ancestors = null ) {
		$this->posts[ (int) $id ] = array(
			'post_type' => (string) $post_type,
			'slug'      => (string) $slug,
			'ancestors' => $ancestors,
		);
	}

	/**
	 * Records a term.
	 *
	 * @param int    $id Term identifier.
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug Slug.
	 * @return void
	 */
	public function add_term( $id, $taxonomy, $slug ) {
		$this->terms[ (int) $id ] = array(
			'taxonomy' => (string) $taxonomy,
			'slug'     => (string) $slug,
		);
	}

	/**
	 * Replaces the registered post types.
	 *
	 * @param string[] $post_types Post types.
	 * @return void
	 */
	public function set_post_types( array $post_types ) {
		$this->post_types = $post_types;
	}

	/**
	 * Replaces the registered taxonomies.
	 *
	 * @param string[] $taxonomies Taxonomies.
	 * @return void
	 */
	public function set_taxonomies( array $taxonomies ) {
		$this->taxonomies = $taxonomies;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post identifier.
	 * @return array{post_type:string,slug:string,ancestors:string[]|null}|null
	 */
	public function describe_post( $post_id ) {
		return isset( $this->posts[ (int) $post_id ] ) ? $this->posts[ (int) $post_id ] : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $term_id Term identifier.
	 * @return array{taxonomy:string,slug:string}|null
	 */
	public function describe_term( $term_id ) {
		return isset( $this->terms[ (int) $term_id ] ) ? $this->terms[ (int) $term_id ] : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type.
	 * @param string $slug Slug.
	 * @param int    $limit Maximum identifiers.
	 * @return int[]
	 */
	public function find_posts( $post_type, $slug, $limit ) {
		$matches = array();

		foreach ( $this->posts as $id => $post ) {
			if ( $post['post_type'] === (string) $post_type && $post['slug'] === (string) $slug ) {
				$matches[] = (int) $id;
			}
		}

		sort( $matches );

		return array_slice( $matches, 0, max( 1, (int) $limit ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug Slug.
	 * @param int    $limit Maximum identifiers.
	 * @return int[]
	 */
	public function find_terms( $taxonomy, $slug, $limit ) {
		$matches = array();

		foreach ( $this->terms as $id => $term ) {
			if ( $term['taxonomy'] === (string) $taxonomy && $term['slug'] === (string) $slug ) {
				$matches[] = (int) $id;
			}
		}

		sort( $matches );

		return array_slice( $matches, 0, max( 1, (int) $limit ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function post_type_exists( $post_type ) {
		return in_array( (string) $post_type, $this->post_types, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	public function taxonomy_exists( $taxonomy ) {
		return in_array( (string) $taxonomy, $this->taxonomies, true );
	}
}
