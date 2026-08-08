<?php
/**
 * Source change tracking.
 *
 * @package McLogiora
 */

namespace McLogiora\Workflows;

use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationGroup;
use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationRelationRepositoryInterface;
use McLogiora\Relations\TranslationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Marks translations as needing an update when their source changes.
 *
 * This is deliberately conservative. It compares a hash of the translatable
 * source fields, so a save that changes nothing meaningful marks nothing.
 * There is no semantic diffing here, and there is no attempt to guess how
 * significant a change is.
 */
final class SourceChangeTracker {
	/**
	 * Relation repository.
	 *
	 * @var TranslationRelationRepositoryInterface
	 */
	private $repository;

	/**
	 * Status transition policy.
	 *
	 * @var TranslationStatusTransitions
	 */
	private $transitions;

	/**
	 * Constructor.
	 *
	 * @param TranslationRelationRepositoryInterface $repository Relation repository.
	 * @param TranslationStatusTransitions           $transitions Status transitions.
	 */
	public function __construct(
		TranslationRelationRepositoryInterface $repository,
		TranslationStatusTransitions $transitions
	) {
		$this->repository  = $repository;
		$this->transitions = $transitions;
	}

	/**
	 * Computes the source hash for a set of translatable fields.
	 *
	 * Only fields a translator would act on are hashed. Post status, dates,
	 * author, and meta are excluded, so publishing or reassigning a post does
	 * not invalidate translations that are still accurate.
	 *
	 * @param array<string,mixed> $fields Source fields.
	 * @return string
	 */
	public function hash_source( array $fields ) {
		$relevant = array(
			'title'   => isset( $fields['post_title'] ) ? (string) $fields['post_title'] : '',
			'content' => isset( $fields['post_content'] ) ? (string) $fields['post_content'] : '',
			'excerpt' => isset( $fields['post_excerpt'] ) ? (string) $fields['post_excerpt'] : '',
		);

		return md5( (string) wp_json_encode( $relevant ) );
	}

	/**
	 * Returns whether a save event should be ignored.
	 *
	 * @param array<string,mixed> $context Save context.
	 * @return bool
	 */
	public function should_ignore( array $context ) {
		if ( ! empty( $context['is_autosave'] ) ) {
			return true;
		}

		if ( ! empty( $context['is_revision'] ) ) {
			return true;
		}

		if ( ! empty( $context['is_bulk_edit'] ) ) {
			return true;
		}

		$status = isset( $context['post_status'] ) ? (string) $context['post_status'] : '';

		if ( 'auto-draft' === $status || 'trash' === $status || 'inherit' === $status ) {
			return true;
		}

		return false;
	}

	/**
	 * Handles a saved source object.
	 *
	 * Returns the object IDs whose translations were marked as needing an
	 * update, so callers and tests can assert the effect.
	 *
	 * @param string              $object_type Relation content type.
	 * @param int                 $object_id Object ID.
	 * @param array<string,mixed> $fields Current source fields.
	 * @param array<string,mixed> $context Save context.
	 * @return int[]
	 */
	public function handle_source_saved( $object_type, $object_id, array $fields, array $context = array() ) {
		if ( $this->should_ignore( $context ) ) {
			return array();
		}

		$group = $this->find_group( (string) $object_type, (string) $object_id );

		if ( ! $group instanceof TranslationGroup ) {
			return array();
		}

		$original = $group->original();

		/*
		 * Only a change to the source invalidates translations. Saving a
		 * translation must never mark its siblings outdated, which is also
		 * what stops this from looping when a translation is edited.
		 */
		if ( ! $original instanceof TranslationItem || $original->object_id() !== (string) $object_id ) {
			return array();
		}

		$new_hash = $this->hash_source( $fields );

		if ( $new_hash === $original->source_hash() ) {
			return array();
		}

		$marked = array();

		foreach ( $group->targets() as $target ) {
			if ( ! $this->transitions->is_allowed( $target->status(), TranslationStatus::NEEDS_UPDATE ) ) {
				continue;
			}

			$result = $this->repository->update_item_status(
				$target->object_type(),
				$target->object_id(),
				$target->language_code(),
				TranslationStatus::NEEDS_UPDATE
			);

			if ( ! is_wp_error( $result ) ) {
				$marked[] = (int) $target->object_id();
			}
		}

		$this->repository->update_item_source_metadata(
			new TranslationItem(
				$original->content_type(),
				$original->object_key(),
				$original->language_code(),
				$original->status(),
				true,
				$new_hash,
				$original->translated_source_hash(),
				isset( $context['modified_gmt_timestamp'] ) ? (int) $context['modified_gmt_timestamp'] : $original->source_modified(),
				$original->translation_modified()
			)
		);

		return $marked;
	}

	/**
	 * Returns the group for an object.
	 *
	 * @param string $object_type Relation content type.
	 * @param string $object_id Object ID.
	 * @return TranslationGroup|null
	 */
	private function find_group( $object_type, $object_id ) {
		$item = $this->find_any_item( $object_type, $object_id );

		if ( ! $item instanceof TranslationItem ) {
			return null;
		}

		foreach ( $this->repository->all() as $group ) {
			if ( $group->contains( $item ) ) {
				return $group;
			}
		}

		return null;
	}

	/**
	 * Finds an item for an object regardless of language.
	 *
	 * @param string $object_type Relation content type.
	 * @param string $object_id Object ID.
	 * @return TranslationItem|null
	 */
	private function find_any_item( $object_type, $object_id ) {
		foreach ( $this->repository->all() as $group ) {
			foreach ( $group->items() as $item ) {
				if ( $item->object_type() === $object_type && $item->object_id() === $object_id ) {
					return $item;
				}
			}
		}

		return null;
	}

	/**
	 * Returns the relation content type for a WordPress post.
	 *
	 * @return string
	 */
	public function post_object_type() {
		return ContentType::POST;
	}
}
