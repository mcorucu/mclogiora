<?php
/**
 * WordPress Classic Editor adapter foundation.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the Classic Editor integration boundary without integrating it.
 */
final class ClassicEditorAdapter implements EditorInterface {
	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'classic';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'WordPress Classic Editor', 'mclogiora' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return function_exists( 'use_block_editor_for_post_type' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_context( EditorContext $context ) {
		return in_array( $context->object_type(), array( 'post', 'page' ), true ) || '' !== $context->object_type();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_placeholder_areas() {
		return array(
			array(
				'id'     => 'classic-metabox',
				'label'  => __( 'Classic Editor metabox', 'mclogiora' ),
				'status' => 'planned',
			),
		);
	}
}
