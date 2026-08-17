<?php
/**
 * Object locator gateway contract.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * The only WordPress content lookup the package layer performs.
 *
 * Both directions need it. The exporter turns an object ID into a locator, and
 * the planner turns a locator back into whatever objects the destination
 * happens to have. Putting both behind one read-only contract keeps the two
 * sides symmetrical -- an export that addresses posts by type and slug and a
 * planner that resolved them some other way would be a package format with two
 * meanings -- and lets the planner be tested without a database.
 *
 * Every method reads. Nothing here creates, updates or deletes anything, and
 * no implementation of this interface may.
 */
interface ObjectLocatorGatewayInterface {
	/**
	 * Describes a post for locator construction.
	 *
	 * @param int $post_id Post identifier.
	 * @return array{post_type:string,slug:string,ancestors:string[]|null}|null Null when
	 *         the post does not exist.
	 */
	public function describe_post( $post_id );

	/**
	 * Describes a term for locator construction.
	 *
	 * @param int $term_id Term identifier.
	 * @return array{taxonomy:string,slug:string}|null Null when the term does not exist.
	 */
	public function describe_term( $term_id );

	/**
	 * Returns the identifiers of every post matching a type and slug.
	 *
	 * All post statuses are searched, including drafts, private posts and the
	 * trash. A relation can legitimately point at any of them, and a resolver
	 * that quietly ignored a trashed post would report a language slot as free
	 * when it is not.
	 *
	 * @param string $post_type Post type.
	 * @param string $slug Post slug.
	 * @param int    $limit Maximum identifiers to return.
	 * @return int[] Ascending by identifier.
	 */
	public function find_posts( $post_type, $slug, $limit );

	/**
	 * Returns the identifiers of every term matching a taxonomy and slug.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug Term slug.
	 * @param int    $limit Maximum identifiers to return.
	 * @return int[] Ascending by identifier.
	 */
	public function find_terms( $taxonomy, $slug, $limit );

	/**
	 * Returns whether a post type is registered on this site.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function post_type_exists( $post_type );

	/**
	 * Returns whether a taxonomy is registered on this site.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	public function taxonomy_exists( $taxonomy );
}
