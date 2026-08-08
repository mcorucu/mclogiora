<?php
/**
 * WordPress content gateway contract.
 *
 * @package McLogiora
 */

namespace McLogiora\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Narrow seam over the WordPress post and term APIs.
 *
 * Workflow services depend on this contract instead of calling WordPress
 * functions directly, so their branching can be tested without a database.
 * The contract is intentionally thin: it exposes only the operations the
 * translation workflows need, and it performs no domain logic of its own.
 */
interface ContentGatewayInterface {
	/**
	 * Returns a post as an array, or null when it does not exist.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	public function get_post( $post_id );

	/**
	 * Inserts a post and returns the new ID.
	 *
	 * @param array<string,mixed> $postarr Post data.
	 * @return int|\WP_Error
	 */
	public function insert_post( array $postarr );

	/**
	 * Permanently deletes a post. Used only to compensate a failed creation.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function delete_post( $post_id );

	/**
	 * Returns whether a post type is registered and public.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function post_type_exists( $post_type );

	/**
	 * Returns a term as an array, or null when it does not exist.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<string,mixed>|null
	 */
	public function get_term( $term_id, $taxonomy );

	/**
	 * Inserts a term and returns the new term ID.
	 *
	 * @param string              $name Term name.
	 * @param string              $taxonomy Taxonomy name.
	 * @param array<string,mixed> $args Term arguments.
	 * @return int|\WP_Error
	 */
	public function insert_term( $name, $taxonomy, array $args );

	/**
	 * Deletes a term. Used only to compensate a failed creation.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function delete_term( $term_id, $taxonomy );

	/**
	 * Returns whether a taxonomy is registered.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function taxonomy_exists( $taxonomy );

	/**
	 * Returns whether the current user has a capability.
	 *
	 * @param string   $capability Capability name.
	 * @param int|null $object_id Optional object ID.
	 * @return bool
	 */
	public function current_user_can( $capability, $object_id = null );

	/**
	 * Returns the current user ID.
	 *
	 * @return int
	 */
	public function current_user_id();

	/**
	 * Returns the edit link for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public function post_edit_link( $post_id );

	/**
	 * Returns the edit link for a term.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	public function term_edit_link( $term_id, $taxonomy );
}
