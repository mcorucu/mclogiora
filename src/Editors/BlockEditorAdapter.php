<?php
/**
 * WordPress Block Editor adapter foundation.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the Block Editor integration boundary without integrating it.
 */
final class BlockEditorAdapter implements EditorInterface {
	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'block';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'WordPress Block Editor', 'mclogiora' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return function_exists( 'register_block_type' ) && function_exists( 'use_block_editor_for_post_type' );
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
				'id'     => 'block-sidebar',
				'label'  => __( 'Block Editor sidebar', 'mclogiora' ),
				'status' => 'planned',
			),
		);
	}
}
