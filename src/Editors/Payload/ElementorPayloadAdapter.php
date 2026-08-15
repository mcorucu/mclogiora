<?php
/**
 * Elementor translation payload adapter.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors\Payload;

defined( 'ABSPATH' ) || exit;

/**
 * Gives an Elementor translation the source page's layout to translate.
 *
 * Phase 10 deliberately copied no Elementor metadata, which left a translator
 * facing an empty canvas and asking them to rebuild a design rather than
 * translate one. This copies the layout so the work is translation.
 *
 * It goes through Elementor's own document API rather than post meta. Copying
 * `_elementor_*` keys by hand would mean guessing at an internal storage
 * format, and would carry across exactly the things that must not travel:
 * generated CSS, cache markers, and the version stamp of the source. The
 * document's `save()` writes the elements, records the template type, stamps
 * the current Elementor version, and drops the stale generated CSS so it is
 * rebuilt for the translation. Everything correct about that is Elementor's
 * doing, not a list of keys maintained here that would rot at the next
 * release.
 *
 * No Elementor Pro API is used, and every entry point is guarded so a site
 * without Elementor loads this class without fataling.
 */
final class ElementorPayloadAdapter implements TranslationPayloadAdapterInterface {
	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'elementor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->documents );
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

		$document = $this->document( (int) $source_id );

		return null !== $document && $document->is_built_with_elementor();
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

		$source = $this->document( (int) $source_id );
		$target = $this->document( (int) $target_id );

		if ( null === $source || null === $target ) {
			return new \WP_Error(
				'mclogiora_elementor_document_missing',
				__( 'The Elementor document for this content could not be loaded.', 'mclogiora' )
			);
		}

		try {
			$elements = $source->get_elements_data();

			if ( ! is_array( $elements ) ) {
				return new \WP_Error(
					'mclogiora_elementor_no_elements',
					__( 'The Elementor layout could not be read from the source content.', 'mclogiora' )
				);
			}

			/*
			 * Marking the target first, through Elementor's own setter rather
			 * than the meta key behind it. Without this the translation ends
			 * up holding a layout Elementor will not render: the elements are
			 * stored, but the page is not flagged as built with Elementor, so
			 * the theme template runs and the translator sees an empty page
			 * with their layout invisibly present.
			 */
			$target->set_is_built_with_elementor( true );

			$saved = $target->save( array( 'elements' => $elements ) );
		} catch ( \Throwable $error ) {
			/*
			 * Elementor runs third-party widget code during save, so this
			 * boundary catches problems that belong to neither plugin. The
			 * workflow turns a WP_Error here into a rollback of the draft it
			 * just created; letting the throwable escape would leave that
			 * draft behind with no relation and no explanation.
			 */
			return new \WP_Error(
				'mclogiora_elementor_copy_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'The Elementor layout could not be copied: %s', 'mclogiora' ),
					$error->getMessage()
				)
			);
		}

		if ( ! $saved ) {
			return new \WP_Error(
				'mclogiora_elementor_save_failed',
				__( 'The Elementor layout could not be saved to the translation.', 'mclogiora' )
			);
		}

		return true;
	}

	/**
	 * Returns the Elementor document for a post, when there is one.
	 *
	 * @param int $post_id Post identifier.
	 * @return \Elementor\Core\Base\Document|null
	 */
	private function document( $post_id ) {
		if ( ! $this->is_available() || $post_id <= 0 ) {
			return null;
		}

		$document = \Elementor\Plugin::$instance->documents->get( $post_id );

		return $document instanceof \Elementor\Core\Base\Document ? $document : null;
	}
}
