<?php
/**
 * In-memory media translation repository for tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Support;

use McLogiora\Media\MediaTranslation;
use McLogiora\Media\MediaTranslationRepositoryInterface;

/**
 * Stores media translations in memory.
 */
final class FakeMediaRepository implements MediaTranslationRepositoryInterface {
	/**
	 * Translations keyed by "attachment:language".
	 *
	 * @var array<string,MediaTranslation>
	 */
	private $translations = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param MediaTranslation $translation Translation.
	 * @return MediaTranslation|\WP_Error
	 */
	public function save( MediaTranslation $translation ) {
		$this->translations[ $translation->attachment_id() . ':' . $translation->language_code() ] = $translation;

		return $translation;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $language_code Language code.
	 * @return MediaTranslation|null
	 */
	public function find( $attachment_id, $language_code ) {
		$key = (int) $attachment_id . ':' . (string) $language_code;

		return isset( $this->translations[ $key ] ) ? $this->translations[ $key ] : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $attachment_id Attachment id.
	 * @return MediaTranslation[]
	 */
	public function all_for_attachment( $attachment_id ) {
		$found = array();

		foreach ( $this->translations as $translation ) {
			if ( $translation->attachment_id() === (int) $attachment_id ) {
				$found[] = $translation;
			}
		}

		return $found;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $language_code Language code.
	 * @return bool|\WP_Error
	 */
	public function delete( $attachment_id, $language_code ) {
		unset( $this->translations[ (int) $attachment_id . ':' . (string) $language_code ] );

		return true;
	}
}
