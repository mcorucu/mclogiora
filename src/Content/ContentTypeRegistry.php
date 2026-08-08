<?php
/**
 * Content type registry.
 *
 * @package McLogiora
 */

namespace McLogiora\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Reads WordPress post type registration metadata without mutating content.
 */
final class ContentTypeRegistry implements ContentTypeRegistryInterface {
	/**
	 * Built-in detector.
	 *
	 * @var PostSupportDetector
	 */
	private $post_detector;

	/**
	 * CPT detector.
	 *
	 * @var CustomPostTypeSupportDetector
	 */
	private $cpt_detector;

	/**
	 * Exclusion rules.
	 *
	 * @var ContentExclusionRules
	 */
	private $exclusion_rules;

	/**
	 * Constructor.
	 *
	 * @param PostSupportDetector           $post_detector Built-in detector.
	 * @param CustomPostTypeSupportDetector $cpt_detector CPT detector.
	 * @param ContentExclusionRules         $exclusion_rules Exclusion rules.
	 */
	public function __construct( PostSupportDetector $post_detector, CustomPostTypeSupportDetector $cpt_detector, ContentExclusionRules $exclusion_rules ) {
		$this->post_detector   = $post_detector;
		$this->cpt_detector    = $cpt_detector;
		$this->exclusion_rules = $exclusion_rules;
	}

	/**
	 * Returns all discovered content types.
	 *
	 * @return TranslatableContentType[]
	 */
	public function all() {
		if ( ! function_exists( 'get_post_types' ) ) {
			return $this->fallback_types();
		}

		$objects = get_post_types( array(), 'objects' );
		$types   = array();

		foreach ( $objects as $object ) {
			if ( ! is_object( $object ) || empty( $object->name ) ) {
				continue;
			}

			$name      = sanitize_key( $object->name );
			$label     = ! empty( $object->label ) ? $object->label : $name;
			$public    = ! empty( $object->public );
			$built_in  = ! empty( $object->_builtin );
			$reason    = $this->exclusion_rules->reason_for( $name );
			$supported = $this->post_detector->supports( $name ) || $this->cpt_detector->supports( $object );
			$translate = $public && $supported && '' === $reason;
			$types[]   = new TranslatableContentType( $name, $label, $public, $built_in, $translate, $reason );
		}

		usort(
			$types,
			static function ( TranslatableContentType $a, TranslatableContentType $b ) {
				return strcmp( $a->name(), $b->name() );
			}
		);

		return $types;
	}

	/**
	 * Returns translatable content types.
	 *
	 * @return TranslatableContentType[]
	 */
	public function translatable() {
		return array_values(
			array_filter(
				$this->all(),
				static function ( TranslatableContentType $type ) {
					return $type->is_translatable();
				}
			)
		);
	}

	/**
	 * Returns excluded content types.
	 *
	 * @return TranslatableContentType[]
	 */
	public function excluded() {
		return array_values(
			array_filter(
				$this->all(),
				static function ( TranslatableContentType $type ) {
					return '' !== $type->exclusion_reason();
				}
			)
		);
	}

	/**
	 * Returns whether a content type is translatable.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function is_translatable( $post_type ) {
		$post_type = sanitize_key( $post_type );

		foreach ( $this->translatable() as $type ) {
			if ( $type->name() === $post_type ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns fallback types before WordPress post types are available.
	 *
	 * @return TranslatableContentType[]
	 */
	private function fallback_types() {
		return array(
			new TranslatableContentType( 'post', __( 'Posts', 'mclogiora' ), true, true, true ),
			new TranslatableContentType( 'page', __( 'Pages', 'mclogiora' ), true, true, true ),
		);
	}
}
