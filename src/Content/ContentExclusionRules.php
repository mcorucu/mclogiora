<?php
/**
 * Content exclusion rules.
 *
 * @package McLogiora
 */

namespace McLogiora\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Defines free-version content type boundaries.
 */
final class ContentExclusionRules {
	/**
	 * Explicit WooCommerce post types excluded from the free foundation.
	 *
	 * @var string[]
	 */
	private $woocommerce_types = array(
		'product',
		'product_variation',
		'shop_order',
		'shop_order_placehold',
		'shop_coupon',
		'shop_webhook',
	);

	/**
	 * Common LMS post types excluded from the free foundation.
	 *
	 * @var string[]
	 */
	private $lms_types = array(
		'course',
		'courses',
		'lesson',
		'lessons',
		'quiz',
		'quizzes',
		'sfwd-courses',
		'sfwd-lessons',
		'sfwd-quiz',
		'tutor_course',
		'tutor_quiz',
		'lp_course',
		'lp_lesson',
		'lp_quiz',
	);

	/**
	 * WordPress-owned or internal object types.
	 *
	 * @var string[]
	 */
	private $internal_types = array(
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
	);

	/**
	 * Returns exclusion reason for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	public function reason_for( $post_type ) {
		$post_type = sanitize_key( $post_type );

		if ( 'attachment' === $post_type ) {
			return __( 'Media translation is not available for this content type.', 'mclogiora' );
		}

		if ( in_array( $post_type, $this->internal_types, true ) ) {
			return __( 'Internal WordPress content is not available for translation.', 'mclogiora' );
		}

		$woocommerce = apply_filters( 'mclogiora_is_woocommerce_post_type', false, $post_type );
		if ( true === $woocommerce || in_array( $post_type, $this->woocommerce_types, true ) || 0 === strpos( $post_type, 'wc_' ) ) {
			return __( 'WooCommerce content is not currently supported by mcLogiora.', 'mclogiora' );
		}

		if ( in_array( $post_type, $this->lms_types, true ) || false !== strpos( $post_type, 'learndash' ) || false !== strpos( $post_type, 'lifter' ) || false !== strpos( $post_type, 'sensei' ) ) {
			return __( 'LMS content is not currently supported by mcLogiora.', 'mclogiora' );
		}

		return '';
	}

	/**
	 * Returns whether a post type is excluded.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function is_excluded( $post_type ) {
		return '' !== $this->reason_for( $post_type );
	}
}
