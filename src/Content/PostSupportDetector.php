<?php
/**
 * Built-in post support detector.
 *
 * @package McLogiora
 */

namespace McLogiora\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Detects built-in post and page support.
 */
final class PostSupportDetector {
	/**
	 * Returns whether the post type is a supported built-in content type.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function supports( $post_type ) {
		return in_array( sanitize_key( $post_type ), array( 'post', 'page' ), true );
	}
}
