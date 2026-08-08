<?php
/**
 * WordPress content gateway.
 *
 * @package McLogiora
 */

namespace McLogiora\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Calls the real WordPress post and term APIs.
 *
 * This class contains no domain logic. It exists so that everything above it
 * can be tested without WordPress loaded.
 */
final class ContentGateway implements ContentGatewayInterface {
	/**
	 * Returns a post as an array, or null when it does not exist.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	public function get_post( $post_id ) {
		$post = get_post( (int) $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		return array(
			'ID'                => (int) $post->ID,
			'post_type'         => (string) $post->post_type,
			'post_status'       => (string) $post->post_status,
			'post_title'        => (string) $post->post_title,
			'post_content'      => (string) $post->post_content,
			'post_excerpt'      => (string) $post->post_excerpt,
			'post_author'       => (int) $post->post_author,
			'post_parent'       => (int) $post->post_parent,
			'menu_order'        => (int) $post->menu_order,
			'post_modified_gmt' => (string) $post->post_modified_gmt,
		);
	}

	/**
	 * Inserts a post and returns the new ID.
	 *
	 * @param array<string,mixed> $postarr Post data.
	 * @return int|\WP_Error
	 */
	public function insert_post( array $postarr ) {
		$result = wp_insert_post( $postarr, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return (int) $result;
	}

	/**
	 * Permanently deletes a post. Used only to compensate a failed creation.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function delete_post( $post_id ) {
		return (bool) wp_delete_post( (int) $post_id, true );
	}

	/**
	 * Returns whether a post type is registered and public.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function post_type_exists( $post_type ) {
		return post_type_exists( (string) $post_type );
	}

	/**
	 * Returns a term as an array, or null when it does not exist.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<string,mixed>|null
	 */
	public function get_term( $term_id, $taxonomy ) {
		$term = get_term( (int) $term_id, (string) $taxonomy );

		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		return array(
			'term_id'     => (int) $term->term_id,
			'taxonomy'    => (string) $term->taxonomy,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'parent'      => (int) $term->parent,
		);
	}

	/**
	 * Inserts a term and returns the new term ID.
	 *
	 * @param string              $name Term name.
	 * @param string              $taxonomy Taxonomy name.
	 * @param array<string,mixed> $args Term arguments.
	 * @return int|\WP_Error
	 */
	public function insert_term( $name, $taxonomy, array $args ) {
		$result = wp_insert_term( (string) $name, (string) $taxonomy, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['term_id'] ) ? (int) $result['term_id'] : 0;
	}

	/**
	 * Deletes a term. Used only to compensate a failed creation.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function delete_term( $term_id, $taxonomy ) {
		return true === wp_delete_term( (int) $term_id, (string) $taxonomy );
	}

	/**
	 * Returns whether a taxonomy is registered.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function taxonomy_exists( $taxonomy ) {
		return taxonomy_exists( (string) $taxonomy );
	}

	/**
	 * Returns whether the current user has a capability.
	 *
	 * @param string   $capability Capability name.
	 * @param int|null $object_id Optional object ID.
	 * @return bool
	 */
	public function current_user_can( $capability, $object_id = null ) {
		if ( null === $object_id ) {
			return current_user_can( (string) $capability );
		}

		return current_user_can( (string) $capability, (int) $object_id );
	}

	/**
	 * Returns the current user ID.
	 *
	 * @return int
	 */
	public function current_user_id() {
		return (int) get_current_user_id();
	}

	/**
	 * Returns the edit link for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public function post_edit_link( $post_id ) {
		return (string) get_edit_post_link( (int) $post_id, 'raw' );
	}

	/**
	 * Returns the edit link for a term.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	public function term_edit_link( $term_id, $taxonomy ) {
		return (string) get_edit_term_link( (int) $term_id, (string) $taxonomy );
	}
}
