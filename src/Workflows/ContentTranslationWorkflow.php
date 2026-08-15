<?php
/**
 * Post, page, and custom post type translation workflows.
 *
 * @package McLogiora
 */

namespace McLogiora\Workflows;

use McLogiora\Editors\Payload\PayloadAdapterRegistry;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationGroup;
use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationRelationServiceInterface;
use McLogiora\Relations\TranslationStatus;
use McLogiora\WordPress\ContentGatewayInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, links, and unlinks post translations.
 *
 * Every method is driven by an explicit user action. Nothing here runs on a
 * schedule, and nothing translates content automatically.
 */
final class ContentTranslationWorkflow {
	/**
	 * Content gateway.
	 *
	 * @var ContentGatewayInterface
	 */
	private $gateway;

	/**
	 * Relation service.
	 *
	 * @var TranslationRelationServiceInterface
	 */
	private $relations;

	/**
	 * Language service.
	 *
	 * @var LanguageServiceInterface
	 */
	private $languages;

	/**
	 * Validator.
	 *
	 * @var TranslationWorkflowValidator
	 */
	private $validator;

	/**
	 * Editor payload adapters.
	 *
	 * @var PayloadAdapterRegistry|null
	 */
	private $payloads;

	/**
	 * Constructor.
	 *
	 * @param ContentGatewayInterface             $gateway Content gateway.
	 * @param TranslationRelationServiceInterface $relations Relation service.
	 * @param LanguageServiceInterface            $languages Language service.
	 * @param TranslationWorkflowValidator        $validator Validator.
	 * @param PayloadAdapterRegistry|null         $payloads Editor payload adapters.
	 */
	public function __construct(
		ContentGatewayInterface $gateway,
		TranslationRelationServiceInterface $relations,
		LanguageServiceInterface $languages,
		TranslationWorkflowValidator $validator,
		?PayloadAdapterRegistry $payloads = null
	) {
		$this->gateway   = $gateway;
		$this->relations = $relations;
		$this->languages = $languages;
		$this->validator = $validator;
		$this->payloads  = $payloads;
	}

	/**
	 * Creates a draft translation of a post.
	 *
	 * The new post is always a draft. Only core content fields are copied;
	 * see ADR 0010 for why arbitrary post meta is deliberately not duplicated.
	 *
	 * @param int    $source_id Source post ID.
	 * @param string $target_language_code Target language code.
	 * @return array{post_id:int,group_key:string,edit_link:string}|\WP_Error
	 */
	public function create_translation( $source_id, $target_language_code ) {
		$allowed = $this->validator->validate_manage_capability();

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$source = $this->validator->validate_source_post( $source_id );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$target_language = $this->validator->validate_target_language( $target_language_code );

		if ( is_wp_error( $target_language ) ) {
			return $target_language;
		}

		$source_language = $this->resolve_source_language( ContentType::POST, (int) $source['ID'] );

		if ( is_wp_error( $source_language ) ) {
			return $source_language;
		}

		$differ = $this->validator->validate_languages_differ( $source_language, $target_language->code() );

		if ( is_wp_error( $differ ) ) {
			return $differ;
		}

		$can_create = $this->validator->validate_can_create_post_type( (string) $source['post_type'] );

		if ( is_wp_error( $can_create ) ) {
			return $can_create;
		}

		$group = $this->resolve_or_create_group( ContentType::POST, (int) $source['ID'], $source_language );

		if ( is_wp_error( $group ) ) {
			return $group;
		}

		$slot_free = $this->validator->validate_language_slot_free( $group->group_key(), $target_language->code() );

		if ( is_wp_error( $slot_free ) ) {
			return $slot_free;
		}

		$created_id = $this->gateway->insert_post( $this->build_translation_postarr( $source ) );

		if ( is_wp_error( $created_id ) ) {
			return $created_id;
		}

		if ( (int) $created_id <= 0 ) {
			return new \WP_Error(
				'mclogiora_translation_post_not_created',
				__( 'The translation draft could not be created.', 'mclogiora' )
			);
		}

		$item = $this->relations->attach_existing_object_as_translation(
			$group->group_key(),
			ContentType::POST,
			(string) $created_id,
			$target_language->code(),
			TranslationStatus::DRAFT
		);

		if ( is_wp_error( $item ) ) {
			/*
			 * Compensating rollback. The draft was created by this operation
			 * and nothing else references it yet, so removing it restores the
			 * pre-operation state. Only the object this call created is ever
			 * deleted; pre-existing content is never touched here.
			 */
			$this->gateway->delete_post( (int) $created_id );

			return $item;
		}

		$prepared = $this->prepare_payload( (int) $source_id, (int) $created_id );

		if ( is_wp_error( $prepared ) ) {
			/*
			 * Same compensating rollback as above, extended over the payload
			 * step. A draft whose builder layout failed to copy is not a
			 * usable translation, and leaving it attached would present a
			 * half-prepared page as a real one. The relation is detached
			 * before the draft is removed so no record outlives the object it
			 * points at.
			 */
			$this->relations->detach_item_safely( ContentType::POST, (string) $created_id, $target_language->code() );
			$this->gateway->delete_post( (int) $created_id );

			return $prepared;
		}

		return array(
			'post_id'   => (int) $created_id,
			'group_key' => $group->group_key(),
			'edit_link' => $this->gateway->post_edit_link( (int) $created_id ),
		);
	}

	/**
	 * Lets editor payload adapters prepare a freshly created translation.
	 *
	 * @param int $source_id Source post identifier.
	 * @param int $created_id Newly created translation identifier.
	 * @return true|\WP_Error
	 */
	private function prepare_payload( $source_id, $created_id ) {
		if ( ! $this->payloads instanceof PayloadAdapterRegistry ) {
			return true;
		}

		return $this->payloads->prepare( $source_id, $created_id );
	}

	/**
	 * Links an existing post as a translation of a source post.
	 *
	 * No content is copied or modified. Only relation records change.
	 *
	 * @param int    $source_id Source post ID.
	 * @param int    $target_id Target post ID.
	 * @param string $target_language_code Target language code.
	 * @return array{post_id:int,group_key:string}|\WP_Error
	 */
	public function link_existing( $source_id, $target_id, $target_language_code ) {
		$allowed = $this->validator->validate_manage_capability();

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$source = $this->validator->validate_source_post( $source_id );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$target_language = $this->validator->validate_target_language( $target_language_code );

		if ( is_wp_error( $target_language ) ) {
			return $target_language;
		}

		$source_language = $this->resolve_source_language( ContentType::POST, (int) $source['ID'] );

		if ( is_wp_error( $source_language ) ) {
			return $source_language;
		}

		$differ = $this->validator->validate_languages_differ( $source_language, $target_language->code() );

		if ( is_wp_error( $differ ) ) {
			return $differ;
		}

		$target = $this->validator->validate_link_target_post( $source, $target_id );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$group = $this->resolve_or_create_group( ContentType::POST, (int) $source['ID'], $source_language );

		if ( is_wp_error( $group ) ) {
			return $group;
		}

		$slot_free = $this->validator->validate_language_slot_free( $group->group_key(), $target_language->code() );

		if ( is_wp_error( $slot_free ) ) {
			return $slot_free;
		}

		$item = $this->relations->attach_existing_object_as_translation(
			$group->group_key(),
			ContentType::POST,
			(string) $target['ID'],
			$target_language->code(),
			TranslationStatus::NEEDS_REVIEW
		);

		if ( is_wp_error( $item ) ) {
			/*
			 * No rollback here on purpose. The target post already existed and
			 * belongs to the user, so a failed relation write must leave it
			 * exactly as it was.
			 */
			return $item;
		}

		return array(
			'post_id'   => (int) $target['ID'],
			'group_key' => $group->group_key(),
		);
	}

	/**
	 * Unlinks a post from its translation group.
	 *
	 * This removes the relation record only. The WordPress post keeps its
	 * content, meta, status, and revisions, and is never trashed or deleted.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $language_code Language code.
	 * @return true|\WP_Error
	 */
	public function unlink( $post_id, $language_code ) {
		$allowed = $this->validator->validate_manage_capability();

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$post_id = (int) $post_id;

		if ( $post_id <= 0 ) {
			return new \WP_Error( 'mclogiora_invalid_source_id', __( 'The content identifier is not valid.', 'mclogiora' ) );
		}

		if ( ! $this->gateway->current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'mclogiora_cannot_edit_target', __( 'You are not allowed to edit this content.', 'mclogiora' ) );
		}

		$result = $this->relations->detach_item_safely( ContentType::POST, (string) $post_id, (string) $language_code );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Returns the language of an object that is already in a group.
	 *
	 * When the object is not related yet, the site default language is used,
	 * because an untranslated object is by definition in the default language.
	 *
	 * @param string $object_type Relation content type.
	 * @param int    $object_id Object ID.
	 * @return string|\WP_Error
	 */
	private function resolve_source_language( $object_type, $object_id ) {
		$group = $this->relations->get_translation_set_for_object( (string) $object_type, (string) $object_id );

		if ( $group instanceof TranslationGroup ) {
			foreach ( $group->items() as $item ) {
				if ( $item->object_id() === (string) $object_id ) {
					return $item->language_code();
				}
			}
		}

		$default = $this->languages->get_default_language();

		if ( ! $default instanceof Language ) {
			return new \WP_Error(
				'mclogiora_no_default_language',
				__( 'Set a default language before creating translations.', 'mclogiora' )
			);
		}

		return $default->code();
	}

	/**
	 * Returns the group for a source object, creating it when necessary.
	 *
	 * @param string $object_type Relation content type.
	 * @param int    $object_id Object ID.
	 * @param string $source_language Source language code.
	 * @return TranslationGroup|\WP_Error
	 */
	private function resolve_or_create_group( $object_type, $object_id, $source_language ) {
		$existing = $this->relations->get_translation_set_for_object( (string) $object_type, (string) $object_id );

		if ( $existing instanceof TranslationGroup ) {
			return $existing;
		}

		return $this->relations->create_group_from_source_object(
			(string) $object_type,
			(string) $object_id,
			(string) $source_language
		);
	}

	/**
	 * Builds the post array for a new translation draft.
	 *
	 * Only fields that are useful as a translation starting point are copied.
	 * The slug is deliberately left to WordPress: translated slugs are a
	 * Phase 12 concern and inventing one now would create data that the slug
	 * layer would have to unpick.
	 *
	 * @param array<string,mixed> $source Source post.
	 * @return array<string,mixed>
	 */
	private function build_translation_postarr( array $source ) {
		$author = isset( $source['post_author'] ) ? (int) $source['post_author'] : 0;

		if ( $author <= 0 ) {
			$author = $this->gateway->current_user_id();
		}

		return array(
			'post_type'    => isset( $source['post_type'] ) ? (string) $source['post_type'] : 'post',
			'post_status'  => 'draft',
			'post_title'   => isset( $source['post_title'] ) ? (string) $source['post_title'] : '',
			'post_content' => isset( $source['post_content'] ) ? (string) $source['post_content'] : '',
			'post_excerpt' => isset( $source['post_excerpt'] ) ? (string) $source['post_excerpt'] : '',
			'menu_order'   => isset( $source['menu_order'] ) ? (int) $source['menu_order'] : 0,
			'post_author'  => $author,
		);
	}

	/**
	 * Returns the translation item for an object and language.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $language_code Language code.
	 * @return TranslationItem|null
	 */
	public function find_item( $post_id, $language_code ) {
		$group = $this->relations->get_translation_set_for_object( ContentType::POST, (string) $post_id );

		if ( ! $group instanceof TranslationGroup ) {
			return null;
		}

		foreach ( $group->items() as $item ) {
			if ( $item->language_code() === (string) $language_code ) {
				return $item;
			}
		}

		return null;
	}
}
