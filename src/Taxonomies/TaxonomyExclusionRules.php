<?php
/**
 * Taxonomy exclusion rules.
 *
 * @package McLogiora
 */

namespace McLogiora\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Defines free-version taxonomy boundaries.
 */
final class TaxonomyExclusionRules {
	/**
	 * WooCommerce taxonomies excluded from the free foundation.
	 *
	 * @var string[]
	 */
	private $woocommerce_taxonomies = array(
		'product_cat',
		'product_tag',
		'product_shipping_class',
		'product_type',
		'product_visibility',
	);

	/**
	 * Common LMS taxonomies excluded from the free foundation.
	 *
	 * @var string[]
	 */
	private $lms_taxonomies = array(
		'course_category',
		'course_tag',
		'ld_course_category',
		'ld_course_tag',
		'tutor_course_category',
		'tutor_course_tag',
		'ld_lesson_category',
		'ld_lesson_tag',
	);

	/** @var string[] WordPress-owned or internal taxonomies. */
	private $internal_taxonomies = array( 'nav_menu', 'link_category', 'post_format', 'wp_theme' );

	/**
	 * Returns exclusion reason for a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	public function reason_for( $taxonomy ) {
		$taxonomy = sanitize_key( $taxonomy );

		if ( in_array( $taxonomy, $this->internal_taxonomies, true ) ) {
			return __( 'Internal WordPress taxonomies are not available for translation.', 'mclogiora' );
		}

		$woocommerce = apply_filters( 'mclogiora_is_woocommerce_taxonomy', false, $taxonomy );
		if ( true === $woocommerce || in_array( $taxonomy, $this->woocommerce_taxonomies, true ) || 0 === strpos( $taxonomy, 'pa_' ) ) {
			return __( 'WooCommerce taxonomies are not currently supported by mcLogiora.', 'mclogiora' );
		}

		if ( in_array( $taxonomy, $this->lms_taxonomies, true ) || false !== strpos( $taxonomy, 'learndash' ) || false !== strpos( $taxonomy, 'lifter' ) || false !== strpos( $taxonomy, 'sensei' ) ) {
			return __( 'LMS taxonomies are not currently supported by mcLogiora.', 'mclogiora' );
		}

		return '';
	}

	/**
	 * Returns whether a taxonomy is excluded.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function is_excluded( $taxonomy ) {
		return '' !== $this->reason_for( $taxonomy );
	}
}
