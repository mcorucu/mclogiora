<?php
/**
 * Media translation repository contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Stores language-specific attachment metadata.
 */
interface MediaTranslationRepositoryInterface {
	/**
	 * Saves a media translation.
	 *
	 * @param MediaTranslation $translation Translation.
	 * @return MediaTranslation|\WP_Error
	 */
	public function save( MediaTranslation $translation );

	/**
	 * Finds a media translation for an attachment and language.
	 *
	 * @param int    $attachment_id Attachment identifier.
	 * @param string $language_code Language code.
	 * @return MediaTranslation|null
	 */
	public function find( $attachment_id, $language_code );

	/**
	 * Returns every translation for an attachment.
	 *
	 * @param int $attachment_id Attachment identifier.
	 * @return MediaTranslation[]
	 */
	public function all_for_attachment( $attachment_id );

	/**
	 * Deletes a media translation.
	 *
	 * @param int    $attachment_id Attachment identifier.
	 * @param string $language_code Language code.
	 * @return bool|\WP_Error
	 */
	public function delete( $attachment_id, $language_code );
}
