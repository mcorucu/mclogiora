<?php
/**
 * Translation relation service.
 *
 * @package McLogiora
 */

namespace McLogiora\Relations;

use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Provides relation-domain operations through repository contracts.
 */
final class TranslationRelationService implements TranslationRelationServiceInterface {
	/**
	 * Relation repository.
	 *
	 * @var TranslationRelationRepositoryInterface
	 */
	private $repository;

	/**
	 * Needs-update detector.
	 *
	 * @var NeedsUpdateDetectorInterface
	 */
	private $needs_update_detector;

	/**
	 * Language service.
	 *
	 * @var LanguageServiceInterface|null
	 */
	private $language_service;

	/**
	 * Request-local index of active groups by object key.
	 *
	 * @var array<string,TranslationGroup>|null
	 */
	private $object_group_index = null;

	/**
	 * Constructor.
	 *
	 * @param TranslationRelationRepositoryInterface $repository Relation repository.
	 * @param NeedsUpdateDetectorInterface           $needs_update_detector Needs-update detector.
	 * @param LanguageServiceInterface|null          $language_service Language service.
	 */
	public function __construct( TranslationRelationRepositoryInterface $repository, NeedsUpdateDetectorInterface $needs_update_detector, $language_service = null ) {
		$this->repository            = $repository;
		$this->needs_update_detector = $needs_update_detector;
		$this->language_service      = $language_service instanceof LanguageServiceInterface ? $language_service : null;
	}

	/**
	 * Creates an empty translation group.
	 *
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_empty_group() {
		return $this->repository->create_empty_group();
	}

	/**
	 * Creates a placeholder group.
	 *
	 * @param TranslationItem $original Original item.
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_group_placeholder( TranslationItem $original ) {
		$result = $this->repository->create_group_placeholder( $original );
		$this->object_group_index = null;
		return $result;
	}

	/**
	 * Creates a placeholder group with a supplied key.
	 *
	 * @param string          $group_key Group key.
	 * @param TranslationItem $original Original item.
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_group_placeholder_with_key( $group_key, TranslationItem $original ) {
		$result = $this->repository->create_group_placeholder_with_key( $group_key, $original );
		$this->object_group_index = null;
		return $result;
	}

	/**
	 * Creates a group from a source object.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return TranslationGroup|\WP_Error
	 */
	public function create_group_from_source_object( $object_type, $object_id, $language_code ) {
		return $this->repository->create_group_placeholder(
			new TranslationItem(
				$object_type,
				$object_id,
				$language_code,
				TranslationStatus::ORIGINAL,
				true
			)
		);
	}

	/**
	 * Attaches an existing object as a translation item.
	 *
	 * @param string $group_key Group key.
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @param string $status Translation status.
	 * @return TranslationItem|\WP_Error
	 */
	public function attach_existing_object_as_translation( $group_key, $object_type, $object_id, $language_code, $status = TranslationStatus::DRAFT ) {
		if ( ! TranslationStatus::is_valid( $status ) || TranslationStatus::ORIGINAL === $status ) {
			$status = TranslationStatus::DRAFT;
		}

		$result = $this->repository->add_item_to_group(
			$group_key,
			new TranslationItem(
				$object_type,
				$object_id,
				$language_code,
				$status,
				false
			)
		);
		$this->object_group_index = null;
		return $result;
	}

	/**
	 * Safely detaches an item.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return bool|\WP_Error
	 */
	public function detach_item_safely( $object_type, $object_id, $language_code ) {
		$result = $this->repository->detach_item( $object_type, $object_id, $language_code );
		$this->object_group_index = null;
		return $result;
	}

	/**
	 * Returns the translation set for an object.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @return TranslationGroup|null
	 */
	public function get_translation_set_for_object( $object_type, $object_id ) {
		$this->prime_object_group_index();
		$key = sanitize_key( $object_type ) . ':' . sanitize_text_field( (string) $object_id );

		return isset( $this->object_group_index[ $key ] ) ? $this->object_group_index[ $key ] : null;
	}

	/**
	 * Primes the request-local object index with one bounded repository read.
	 *
	 * @return void
	 */
	private function prime_object_group_index() {
		if ( is_array( $this->object_group_index ) ) {
			return;
		}

		$this->object_group_index = array();
		foreach ( $this->repository->all() as $group ) {
			foreach ( $group->items() as $item ) {
				$this->object_group_index[ $item->object_type() . ':' . $item->object_id() ] = $group;
			}
		}
	}

	/**
	 * Returns missing languages for an object.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @return string[]
	 */
	public function get_missing_languages_for_object( $object_type, $object_id ) {
		$group = $this->get_translation_set_for_object( $object_type, $object_id );

		if ( ! $group instanceof TranslationGroup ) {
			return $this->active_language_codes();
		}

		return $this->determine_missing_languages_placeholder( $group, $this->active_language_codes() );
	}

	/**
	 * Marks an item with a status.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @param string $status Translation status.
	 * @return TranslationItem|\WP_Error
	 */
	public function mark_status( $object_type, $object_id, $language_code, $status ) {
		return $this->repository->update_item_status( $object_type, $object_id, $language_code, $status );
	}

	/**
	 * Marks an item as needing review.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return TranslationItem|\WP_Error
	 */
	public function mark_needs_review( $object_type, $object_id, $language_code ) {
		return $this->mark_status( $object_type, $object_id, $language_code, TranslationStatus::NEEDS_REVIEW );
	}

	/**
	 * Marks an item as needing update.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return TranslationItem|\WP_Error
	 */
	public function mark_needs_update( $object_type, $object_id, $language_code ) {
		return $this->mark_status( $object_type, $object_id, $language_code, TranslationStatus::NEEDS_UPDATE );
	}

	/**
	 * Marks an item as translated.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return TranslationItem|\WP_Error
	 */
	public function mark_translated( $object_type, $object_id, $language_code ) {
		return $this->mark_status( $object_type, $object_id, $language_code, TranslationStatus::TRANSLATED );
	}

	/**
	 * Marks an item as machine suggested.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id Object ID.
	 * @param string $language_code Language code.
	 * @return TranslationItem|\WP_Error
	 */
	public function mark_machine_suggested( $object_type, $object_id, $language_code ) {
		return $this->mark_status( $object_type, $object_id, $language_code, TranslationStatus::MACHINE_SUGGESTED );
	}

	/**
	 * Returns a group by source item.
	 *
	 * @param TranslationItem $source Source item.
	 * @return TranslationGroup|null
	 */
	public function get_group_by_source_placeholder( TranslationItem $source ) {
		return $this->repository->find_group_by_source( $source );
	}

	/**
	 * Returns translations for an item.
	 *
	 * @param TranslationItem $item Translation item.
	 * @return TranslationItem[]
	 */
	public function get_translations_for_item_placeholder( TranslationItem $item ) {
		return $this->repository->translations_for_item( $item );
	}

	/**
	 * Determines missing languages for a group.
	 *
	 * @param TranslationGroup $group Translation group.
	 * @param string[]         $language_codes Language codes.
	 * @return string[]
	 */
	public function determine_missing_languages_placeholder( TranslationGroup $group, array $language_codes ) {
		$present = array();

		foreach ( $group->items() as $item ) {
			if ( TranslationStatus::DISABLED !== $item->status() ) {
				$present[] = $item->language_code();
			}
		}

		return array_values( array_diff( array_map( 'sanitize_key', $language_codes ), $present ) );
	}

	/**
	 * Determines outdated translation items.
	 *
	 * @param TranslationGroup $group Translation group.
	 * @return TranslationItem[]
	 */
	public function determine_outdated_translations_placeholder( TranslationGroup $group ) {
		return array_values(
			array_filter(
				$group->targets(),
				array( $this->needs_update_detector, 'needs_update' )
			)
		);
	}

	/**
	 * Returns placeholder groups.
	 *
	 * @return TranslationGroup[]
	 */
	public function get_placeholder_groups() {
		return $this->repository->all();
	}

	/**
	 * Returns active language codes.
	 *
	 * @return string[]
	 */
	private function active_language_codes() {
		if ( ! $this->language_service instanceof LanguageServiceInterface ) {
			return array();
		}

		$codes = array();

		foreach ( $this->language_service->get_active_languages() as $language ) {
			if ( $language instanceof Language ) {
				$codes[] = $language->code();
			}
		}

		return $codes;
	}
}
