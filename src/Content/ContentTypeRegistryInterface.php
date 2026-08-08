<?php
/**
 * Content type registry contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for reading supported content type metadata.
 */
interface ContentTypeRegistryInterface {
	/**
	 * Returns all discovered content types.
	 *
	 * @return TranslatableContentType[]
	 */
	public function all();

	/**
	 * Returns translatable content types.
	 *
	 * @return TranslatableContentType[]
	 */
	public function translatable();

	/**
	 * Returns excluded content types.
	 *
	 * @return TranslatableContentType[]
	 */
	public function excluded();

	/**
	 * Returns whether a content type is translatable.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function is_translatable( $post_type );
}
