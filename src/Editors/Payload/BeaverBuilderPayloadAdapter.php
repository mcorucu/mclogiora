<?php
/**
 * Beaver Builder translation payload adapter.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors\Payload;

defined( 'ABSPATH' ) || exit;

/**
 * Gives a Beaver Builder translation the source page's layout to translate.
 *
 * Beaver Builder keeps the authoritative layout in post meta rather than in
 * `post_content`, so copying the post is not enough: without this a Beaver
 * translation opens as an empty page.
 *
 * The sequence is not invented. Beaver Builder ships `duplicate_wpml_layout()`
 * for exactly this case -- handing a layout to a translation of the same post
 * -- and this adapter follows the same steps through the same public
 * `FLBuilderModel` methods: read the published and draft layouts, carry the
 * enabled flag, and write both layouts to the target. It cannot call that
 * method directly, because it is an AJAX handler that reads `$_POST`, verifies
 * its own nonce, and can `wp_die()`.
 *
 * Two deliberate choices follow Beaver Builder's own precedent:
 *
 * - **Layout settings are not copied.** `get_layout_settings()` holds
 *   page-level custom CSS and JavaScript. Beaver Builder's own multilingual
 *   duplication does not carry them, and diverging from that without evidence
 *   would be guessing at what a translation should inherit.
 * - **The draft layout travels too.** A source with unpublished builder
 *   changes would otherwise hand the translator a layout that does not match
 *   what the source shows in the builder.
 *
 * Generated CSS and JavaScript are never copied. Beaver Builder caches them
 * per post as files keyed by post id, and the target's cache is cleared
 * through the public `delete_all_asset_cache()` so it is rebuilt from the
 * translation's own layout.
 */
final class BeaverBuilderPayloadAdapter implements TranslationPayloadAdapterInterface {
	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'beaver-builder';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return class_exists( '\\FLBuilderModel' )
			&& method_exists( '\\FLBuilderModel', 'get_layout_data' )
			&& method_exists( '\\FLBuilderModel', 'update_layout_data' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $source_id Source post identifier.
	 */
	public function applies_to( $source_id ) {
		if ( ! $this->is_available() ) {
			return false;
		}

		$source_id = (int) $source_id;

		if ( $source_id <= 0 ) {
			return false;
		}

		if ( get_post_meta( $source_id, '_fl_builder_enabled', true ) ) {
			return true;
		}

		/*
		 * A page can hold a layout without the builder currently being
		 * switched on for it. That layout is still the thing a translator
		 * needs, so its presence is enough.
		 */
		return ! empty( \FLBuilderModel::get_layout_data( 'published', $source_id ) )
			|| ! empty( \FLBuilderModel::get_layout_data( 'draft', $source_id ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $source_id Source post identifier.
	 * @param int $target_id Newly created translation identifier.
	 */
	public function copy( $source_id, $target_id ) {
		if ( ! $this->applies_to( $source_id ) ) {
			return true;
		}

		$source_id = (int) $source_id;
		$target_id = (int) $target_id;

		try {
			$published = \FLBuilderModel::get_layout_data( 'published', $source_id );
			$draft     = \FLBuilderModel::get_layout_data( 'draft', $source_id );

			if ( empty( $published ) && empty( $draft ) ) {
				return new \WP_Error(
					'mclogiora_beaver_no_layout',
					__( 'The Beaver Builder layout could not be read from the source content.', 'mclogiora' )
				);
			}

			if ( get_post_meta( $source_id, '_fl_builder_enabled', true ) ) {
				update_post_meta( $target_id, '_fl_builder_enabled', true );
			}

			if ( ! empty( $published ) ) {
				\FLBuilderModel::update_layout_data( $published, 'published', $target_id );
			}

			if ( ! empty( $draft ) ) {
				\FLBuilderModel::update_layout_data( $draft, 'draft', $target_id );
			}

			$this->clear_target_cache( $target_id );
		} catch ( \Throwable $error ) {
			/*
			 * Third-party module code runs during a layout write, so this
			 * boundary catches problems belonging to neither plugin. The
			 * workflow turns a WP_Error here into a rollback of the draft it
			 * just created; letting the throwable escape would strand that
			 * draft with no relation and no explanation.
			 */
			return new \WP_Error(
				'mclogiora_beaver_copy_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'The Beaver Builder layout could not be copied: %s', 'mclogiora' ),
					$error->getMessage()
				)
			);
		}

		return true;
	}

	/**
	 * Clears the generated asset cache for the translation.
	 *
	 * @param int $target_id Translation identifier.
	 * @return void
	 */
	private function clear_target_cache( $target_id ) {
		if ( method_exists( '\\FLBuilderModel', 'delete_all_asset_cache' ) ) {
			\FLBuilderModel::delete_all_asset_cache( $target_id );
		}
	}
}
