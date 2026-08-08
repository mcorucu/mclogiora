<?php
/**
 * Media translation service.
 *
 * @package McLogiora
 */

namespace McLogiora\Media;

use McLogiora\Cache\CacheInterface;
use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\TranslationStatus;
use McLogiora\WordPress\ContentGatewayInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes language-specific attachment metadata.
 *
 * One physical attachment serves every language. The binary, its mime type,
 * dimensions, generated sizes, and ownership are never touched: only the
 * textual fields a translator actually needs to change are stored per
 * language. Duplicating the file to translate its alt text would multiply
 * storage, break deduplication, and leave every copy to be re-optimised.
 *
 * Lookups take an explicit language. Choosing which language applies to a
 * given request is Phase 12's responsibility.
 */
final class MediaTranslationService {
	/**
	 * Media translation repository.
	 *
	 * @var MediaTranslationRepositoryInterface
	 */
	private $repository;

	/**
	 * Language service.
	 *
	 * @var LanguageServiceInterface
	 */
	private $languages;

	/**
	 * Content gateway.
	 *
	 * @var ContentGatewayInterface
	 */
	private $gateway;

	/**
	 * Capability registry.
	 *
	 * @var CapabilityRegistry
	 */
	private $capabilities;

	/**
	 * Cache.
	 *
	 * @var CacheInterface
	 */
	private $cache;

	/**
	 * Constructor.
	 *
	 * @param MediaTranslationRepositoryInterface $repository Repository.
	 * @param LanguageServiceInterface            $languages Language service.
	 * @param ContentGatewayInterface             $gateway Content gateway.
	 * @param CapabilityRegistry                  $capabilities Capability registry.
	 * @param CacheInterface                      $cache Cache.
	 */
	public function __construct(
		MediaTranslationRepositoryInterface $repository,
		LanguageServiceInterface $languages,
		ContentGatewayInterface $gateway,
		CapabilityRegistry $capabilities,
		CacheInterface $cache
	) {
		$this->repository   = $repository;
		$this->languages    = $languages;
		$this->gateway      = $gateway;
		$this->capabilities = $capabilities;
		$this->cache        = $cache;
	}

	/**
	 * Returns translated metadata for an explicitly named language.
	 *
	 * Fields with no translation fall back to the attachment's own values, so
	 * a partially translated attachment still renders completely.
	 *
	 * @param int    $attachment_id Attachment identifier.
	 * @param string $language_code Language code.
	 * @return array{title:string,alt_text:string,caption:string,description:string}
	 */
	public function metadata_for_language( $attachment_id, $language_code ) {
		$attachment = $this->gateway->get_post( (int) $attachment_id );

		$fallback = array(
			'title'       => isset( $attachment['post_title'] ) ? (string) $attachment['post_title'] : '',
			'alt_text'    => '',
			'caption'     => isset( $attachment['post_excerpt'] ) ? (string) $attachment['post_excerpt'] : '',
			'description' => isset( $attachment['post_content'] ) ? (string) $attachment['post_content'] : '',
		);

		$translation = $this->repository->find( (int) $attachment_id, (string) $language_code );

		if ( ! $translation instanceof MediaTranslation ) {
			return $fallback;
		}

		return array(
			'title'       => '' !== $translation->title() ? $translation->title() : $fallback['title'],
			'alt_text'    => '' !== $translation->alt_text() ? $translation->alt_text() : $fallback['alt_text'],
			'caption'     => '' !== $translation->caption() ? $translation->caption() : $fallback['caption'],
			'description' => '' !== $translation->description() ? $translation->description() : $fallback['description'],
		);
	}

	/**
	 * Saves translated metadata for an attachment.
	 *
	 * @param int                  $attachment_id Attachment identifier.
	 * @param string               $language_code Language code.
	 * @param array<string,string> $fields Translated fields.
	 * @return MediaTranslation|\WP_Error
	 */
	public function save( $attachment_id, $language_code, array $fields ) {
		$capability = $this->capabilities->resolve( CapabilityRegistry::MANAGE_TRANSLATIONS );

		if ( ! $this->gateway->current_user_can( $capability ) ) {
			return new \WP_Error( 'mclogiora_cannot_manage_translations', __( 'You are not allowed to manage translations.', 'mclogiora' ) );
		}

		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 ) {
			return new \WP_Error( 'mclogiora_invalid_attachment', __( 'The attachment identifier is not valid.', 'mclogiora' ) );
		}

		if ( ! $this->gateway->current_user_can( 'edit_post', $attachment_id ) ) {
			return new \WP_Error( 'mclogiora_cannot_edit_attachment', __( 'You are not allowed to edit this attachment.', 'mclogiora' ) );
		}

		$attachment = $this->gateway->get_post( $attachment_id );

		if ( null === $attachment ) {
			return new \WP_Error( 'mclogiora_attachment_not_found', __( 'The attachment could not be found.', 'mclogiora' ) );
		}

		if ( isset( $attachment['post_type'] ) && 'attachment' !== (string) $attachment['post_type'] ) {
			return new \WP_Error( 'mclogiora_not_an_attachment', __( 'That content is not an attachment.', 'mclogiora' ) );
		}

		$language = $this->languages->get_language_by_code( (string) $language_code );

		if ( ! $language instanceof Language ) {
			return new \WP_Error( 'mclogiora_unknown_target_language', __( 'The selected target language does not exist.', 'mclogiora' ) );
		}

		if ( LanguageStatus::ACTIVE !== $language->status() ) {
			return new \WP_Error( 'mclogiora_inactive_target_language', __( 'The selected target language is not active.', 'mclogiora' ) );
		}

		$translation = new MediaTranslation(
			$attachment_id,
			$language->code(),
			isset( $fields['title'] ) ? (string) $fields['title'] : '',
			isset( $fields['alt_text'] ) ? (string) $fields['alt_text'] : '',
			isset( $fields['caption'] ) ? (string) $fields['caption'] : '',
			isset( $fields['description'] ) ? (string) $fields['description'] : '',
			TranslationStatus::TRANSLATED
		);

		if ( $translation->is_empty() ) {
			$deleted = $this->repository->delete( $attachment_id, $language->code() );

			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}

			$this->invalidate( $attachment_id, $language->code() );

			return $translation;
		}

		$saved = $this->repository->save( $translation );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$this->invalidate( $attachment_id, $language->code() );

		return $saved;
	}

	/**
	 * Returns every stored translation for an attachment.
	 *
	 * @param int $attachment_id Attachment identifier.
	 * @return MediaTranslation[]
	 */
	public function all_for_attachment( $attachment_id ) {
		return $this->repository->all_for_attachment( (int) $attachment_id );
	}

	/**
	 * Returns the featured attachment a translated post should use.
	 *
	 * Translations reference the same attachment as their source. mcLogiora
	 * never duplicates the file: a translated alt text is a text change, not a
	 * new image. An editor who genuinely needs a different image for one
	 * language sets it through the normal WordPress featured image control,
	 * and that choice is respected here because the post's own thumbnail wins.
	 *
	 * @param int $translated_post_thumbnail_id Thumbnail already set on the translation.
	 * @param int $source_post_thumbnail_id Thumbnail set on the source.
	 * @return int
	 */
	public function resolve_featured_attachment( $translated_post_thumbnail_id, $source_post_thumbnail_id ) {
		$own = (int) $translated_post_thumbnail_id;

		if ( $own > 0 ) {
			return $own;
		}

		return (int) $source_post_thumbnail_id;
	}

	/**
	 * Invalidates cached metadata for an attachment and language.
	 *
	 * @param int    $attachment_id Attachment identifier.
	 * @param string $language_code Language code.
	 * @return void
	 */
	private function invalidate( $attachment_id, $language_code ) {
		$this->cache->delete( 'mclogiora_media_' . (int) $attachment_id . '_' . (string) $language_code );
	}
}
