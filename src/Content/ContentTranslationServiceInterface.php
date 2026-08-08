<?php
/**
 * Content translation service contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for content translation foundation reads.
 */
interface ContentTranslationServiceInterface {
	/**
	 * Returns whether a content type is translatable.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function is_content_type_translatable( $post_type );

	/**
	 * Returns translatable content types.
	 *
	 * @return TranslatableContentType[]
	 */
	public function get_translatable_content_types();

	/**
	 * Returns excluded content types.
	 *
	 * @return TranslatableContentType[]
	 */
	public function get_excluded_content_types();

	/**
	 * Returns a read-only support overview.
	 *
	 * @return array<string, int>
	 */
	public function get_support_overview();
}
