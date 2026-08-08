<?php
/**
 * In-memory content gateway for tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Support;

use McLogiora\WordPress\ContentGatewayInterface;

/**
 * Records mutations instead of touching WordPress.
 *
 * Failure modes are injectable so that rollback behaviour can be exercised
 * without a database.
 */
final class FakeContentGateway implements ContentGatewayInterface {
	/**
	 * Posts keyed by ID.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public $posts = array();

	/**
	 * Terms keyed by ID.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public $terms = array();

	/**
	 * Registered post types.
	 *
	 * @var string[]
	 */
	public $post_types = array( 'post', 'page' );

	/**
	 * Registered taxonomies.
	 *
	 * @var string[]
	 */
	public $taxonomies = array( 'category', 'post_tag' );

	/**
	 * Capabilities the current user is denied.
	 *
	 * @var string[]
	 */
	public $denied_capabilities = array();

	/**
	 * IDs deleted through the gateway.
	 *
	 * @var int[]
	 */
	public $deleted_posts = array();

	/**
	 * Term IDs deleted through the gateway.
	 *
	 * @var int[]
	 */
	public $deleted_terms = array();

	/**
	 * Forces insert_post() to fail when set.
	 *
	 * @var \WP_Error|null
	 */
	public $insert_post_error = null;

	/**
	 * Forces insert_term() to fail when set.
	 *
	 * @var \WP_Error|null
	 */
	public $insert_term_error = null;

	/**
	 * Next generated ID.
	 *
	 * @var int
	 */
	private $next_id = 1000;

	/**
	 * Adds a post fixture.
	 *
	 * @param int                 $id Post ID.
	 * @param array<string,mixed> $data Post data.
	 * @return void
	 */
	public function add_post( $id, array $data ) {
		$this->posts[ (int) $id ] = array_merge(
			array(
				'ID'           => (int) $id,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Title',
				'post_content' => 'Content',
				'post_excerpt' => '',
				'post_author'  => 1,
				'post_parent'  => 0,
				'menu_order'   => 0,
			),
			$data
		);
	}

	/**
	 * Adds a term fixture.
	 *
	 * @param int                 $id Term ID.
	 * @param array<string,mixed> $data Term data.
	 * @return void
	 */
	public function add_term( $id, array $data ) {
		$this->terms[ (int) $id ] = array_merge(
			array(
				'term_id'     => (int) $id,
				'taxonomy'    => 'category',
				'name'        => 'Term',
				'slug'        => 'term',
				'description' => '',
				'parent'      => 0,
			),
			$data
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	public function get_post( $post_id ) {
		return isset( $this->posts[ (int) $post_id ] ) ? $this->posts[ (int) $post_id ] : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $postarr Post data.
	 * @return int|\WP_Error
	 */
	public function insert_post( array $postarr ) {
		if ( $this->insert_post_error instanceof \WP_Error ) {
			return $this->insert_post_error;
		}

		$id = ++$this->next_id;
		$this->add_post( $id, $postarr );

		return $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function delete_post( $post_id ) {
		$this->deleted_posts[] = (int) $post_id;
		unset( $this->posts[ (int) $post_id ] );

		return true;
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
	 * @param int    $term_id Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<string,mixed>|null
	 */
	public function get_term( $term_id, $taxonomy ) {
		if ( ! isset( $this->terms[ (int) $term_id ] ) ) {
			return null;
		}

		$term = $this->terms[ (int) $term_id ];

		if ( '' !== (string) $taxonomy && (string) $term['taxonomy'] !== (string) $taxonomy ) {
			return null;
		}

		return $term;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $name Term name.
	 * @param string              $taxonomy Taxonomy name.
	 * @param array<string,mixed> $args Term arguments.
	 * @return int|\WP_Error
	 */
	public function insert_term( $name, $taxonomy, array $args ) {
		if ( $this->insert_term_error instanceof \WP_Error ) {
			return $this->insert_term_error;
		}

		$id = ++$this->next_id;
		$this->add_term(
			$id,
			array_merge(
				$args,
				array(
					'name'     => (string) $name,
					'taxonomy' => (string) $taxonomy,
				)
			)
		);

		return $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $term_id Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function delete_term( $term_id, $taxonomy ) {
		unset( $taxonomy );
		$this->deleted_terms[] = (int) $term_id;
		unset( $this->terms[ (int) $term_id ] );

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function taxonomy_exists( $taxonomy ) {
		return in_array( (string) $taxonomy, $this->taxonomies, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string   $capability Capability name.
	 * @param int|null $object_id Object ID.
	 * @return bool
	 */
	public function current_user_can( $capability, $object_id = null ) {
		unset( $object_id );

		return ! in_array( (string) $capability, $this->denied_capabilities, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function current_user_id() {
		return 1;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public function post_edit_link( $post_id ) {
		return 'https://example.test/wp-admin/post.php?post=' . (int) $post_id . '&action=edit';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $term_id Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	public function term_edit_link( $term_id, $taxonomy ) {
		return 'https://example.test/wp-admin/term.php?taxonomy=' . rawurlencode( (string) $taxonomy ) . '&tag_ID=' . (int) $term_id;
	}
}
