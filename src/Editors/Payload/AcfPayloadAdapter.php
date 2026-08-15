<?php
/**
 * ACF translation payload adapter.
 *
 * @package McLogiora
 */

namespace McLogiora\Editors\Payload;

defined( 'ABSPATH' ) || exit;

/**
 * Detects ACF and deliberately copies nothing.
 *
 * A translation is a separate WordPress post, so ACF already stores its field
 * values separately and already renders its field groups on the translated
 * object. Language-specific values are what a multilingual site wants, and
 * they work today with no code from mcLogiora at all. That is the whole of
 * Phase 14's ACF support, and it is deliberate rather than unfinished.
 *
 * What is *not* implemented is seeding a translation with the source's field
 * values. It looks like a small feature and is not. ACF values are not plain
 * post meta: repeaters and flexible content store a count key plus generated
 * per-row keys, clone and group fields rewrite field names, relationship and
 * taxonomy fields hold IDs that may themselves need translating, and every one
 * of those shapes would have to be proven across the free and Pro field sets
 * before the copy could be called safe. A partial implementation would look
 * like it worked and would quietly corrupt the harder field types, which is
 * worse for a translator than an empty field they can see is empty.
 *
 * So this adapter exists to hold the seam, not to fill it. It reports ACF's
 * presence to the compatibility layer and returns success without touching
 * anything. When value seeding is designed properly it replaces `copy()` here
 * and nothing else in the workflow has to move.
 *
 * mcLogiora stores no ACF values of its own and never will: a second store for
 * data ACF already owns would be two sources of truth for one field.
 */
final class AcfPayloadAdapter implements TranslationPayloadAdapterInterface {
	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'acf';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return function_exists( 'acf_get_field_groups' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Always false: detection is useful, copying is not implemented.
	 *
	 * @param int $source_id Source post identifier.
	 */
	public function applies_to( $source_id ) {
		unset( $source_id );

		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $source_id Source post identifier.
	 * @param int $target_id Newly created translation identifier.
	 */
	public function copy( $source_id, $target_id ) {
		unset( $source_id, $target_id );

		return true;
	}

	/**
	 * Returns the field groups ACF would show for a post.
	 *
	 * Read-only, and used by the compatibility screen to report what a
	 * translator will actually see. Nothing is written.
	 *
	 * @param int $post_id Post identifier.
	 * @return string[] Field group titles.
	 */
	public function field_group_titles( $post_id ) {
		if ( ! $this->is_available() || (int) $post_id <= 0 ) {
			return array();
		}

		$groups = acf_get_field_groups( array( 'post_id' => (int) $post_id ) );

		if ( ! is_array( $groups ) ) {
			return array();
		}

		$titles = array();

		foreach ( $groups as $group ) {
			if ( isset( $group['title'] ) ) {
				$titles[] = (string) $group['title'];
			}
		}

		return $titles;
	}
}
