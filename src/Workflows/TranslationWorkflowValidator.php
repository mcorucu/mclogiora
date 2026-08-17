<?php
/**
 * Translation workflow validation rules.
 *
 * @package McLogiora
 */

namespace McLogiora\Workflows;

use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Content\ContentTypeRegistryInterface;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationRelationRepositoryInterface;
use McLogiora\Taxonomies\TaxonomyRegistryInterface;
use McLogiora\WordPress\ContentGatewayInterface;
use McLogiora\ImportExport\ImportAuthorizationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Validates translation workflow requests before anything is written.
 *
 * Every rule returns a WP_Error rather than throwing, so callers can surface
 * a specific admin notice. Validation never mutates state.
 */
final class TranslationWorkflowValidator implements ImportAuthorizationInterface {
	/**
	 * Content gateway.
	 *
	 * @var ContentGatewayInterface
	 */
	private $gateway;

	/**
	 * Language service.
	 *
	 * @var LanguageServiceInterface
	 */
	private $languages;

	/**
	 * Relation repository.
	 *
	 * @var TranslationRelationRepositoryInterface
	 */
	private $relations;

	/**
	 * Content type registry.
	 *
	 * @var ContentTypeRegistryInterface
	 */
	private $content_types;

	/**
	 * Taxonomy registry.
	 *
	 * @var TaxonomyRegistryInterface
	 */
	private $taxonomies;

	/**
	 * Capability registry.
	 *
	 * @var CapabilityRegistry
	 */
	private $capabilities;

	/**
	 * Constructor.
	 *
	 * @param ContentGatewayInterface                $gateway Content gateway.
	 * @param LanguageServiceInterface               $languages Language service.
	 * @param TranslationRelationRepositoryInterface $relations Relation repository.
	 * @param ContentTypeRegistryInterface           $content_types Content type registry.
	 * @param TaxonomyRegistryInterface              $taxonomies Taxonomy registry.
	 * @param CapabilityRegistry                     $capabilities Capability registry.
	 */
	public function __construct(
		ContentGatewayInterface $gateway,
		LanguageServiceInterface $languages,
		TranslationRelationRepositoryInterface $relations,
		ContentTypeRegistryInterface $content_types,
		TaxonomyRegistryInterface $taxonomies,
		CapabilityRegistry $capabilities
	) {
		$this->gateway       = $gateway;
		$this->languages     = $languages;
		$this->relations     = $relations;
		$this->content_types = $content_types;
		$this->taxonomies    = $taxonomies;
		$this->capabilities  = $capabilities;
	}

	/**
	 * Validates that the current user may manage translations at all.
	 *
	 * @return true|\WP_Error
	 */
	public function validate_manage_capability() {
		$capability = $this->capabilities->resolve( CapabilityRegistry::MANAGE_TRANSLATIONS );

		if ( ! $this->gateway->current_user_can( $capability ) ) {
			return new \WP_Error(
				'mclogiora_cannot_manage_translations',
				__( 'You are not allowed to manage translations.', 'mclogiora' )
			);
		}

		return true;
	}

	/**
	 * Validates a source post and returns it.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function validate_source_post( $post_id ) {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 ) {
			return new \WP_Error( 'mclogiora_invalid_source_id', __( 'The source content identifier is not valid.', 'mclogiora' ) );
		}

		$post = $this->gateway->get_post( $post_id );

		if ( null === $post ) {
			return new \WP_Error( 'mclogiora_source_not_found', __( 'The source content could not be found.', 'mclogiora' ) );
		}

		$post_type = isset( $post['post_type'] ) ? (string) $post['post_type'] : '';

		if ( ! $this->content_types->is_translatable( $post_type ) ) {
			return new \WP_Error(
				'mclogiora_content_type_not_translatable',
				__( 'This content type is not available for translation.', 'mclogiora' )
			);
		}

		if ( ! $this->gateway->current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'mclogiora_cannot_edit_source',
				__( 'You are not allowed to edit this content.', 'mclogiora' )
			);
		}

		return $post;
	}

	/**
	 * Validates a source term and returns it.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function validate_source_term( $term_id, $taxonomy ) {
		$term_id  = (int) $term_id;
		$taxonomy = (string) $taxonomy;

		if ( $term_id <= 0 ) {
			return new \WP_Error( 'mclogiora_invalid_source_id', __( 'The source term identifier is not valid.', 'mclogiora' ) );
		}

		if ( ! $this->taxonomies->is_translatable( $taxonomy ) ) {
			return new \WP_Error(
				'mclogiora_taxonomy_not_translatable',
				__( 'This taxonomy is not available for translation.', 'mclogiora' )
			);
		}

		$term = $this->gateway->get_term( $term_id, $taxonomy );

		if ( null === $term ) {
			return new \WP_Error( 'mclogiora_source_not_found', __( 'The source term could not be found.', 'mclogiora' ) );
		}

		if ( ! $this->gateway->current_user_can( 'manage_categories' ) ) {
			return new \WP_Error(
				'mclogiora_cannot_edit_terms',
				__( 'You are not allowed to edit terms.', 'mclogiora' )
			);
		}

		return $term;
	}

	/**
	 * Validates a target language and returns it.
	 *
	 * @param string $language_code Language code.
	 * @return Language|\WP_Error
	 */
	public function validate_target_language( $language_code ) {
		$language_code = (string) $language_code;

		if ( '' === $language_code ) {
			return new \WP_Error( 'mclogiora_missing_target_language', __( 'Select a target language.', 'mclogiora' ) );
		}

		$language = $this->languages->get_language_by_code( $language_code );

		if ( ! $language instanceof Language ) {
			return new \WP_Error( 'mclogiora_unknown_target_language', __( 'The selected target language does not exist.', 'mclogiora' ) );
		}

		if ( LanguageStatus::ACTIVE !== $language->status() ) {
			return new \WP_Error(
				'mclogiora_inactive_target_language',
				__( 'The selected target language is not active.', 'mclogiora' )
			);
		}

		return $language;
	}

	/**
	 * Validates that a source and target language differ.
	 *
	 * @param string $source_language Source language code.
	 * @param string $target_language Target language code.
	 * @return true|\WP_Error
	 */
	public function validate_languages_differ( $source_language, $target_language ) {
		if ( (string) $source_language === (string) $target_language ) {
			return new \WP_Error(
				'mclogiora_same_language',
				__( 'The target language must be different from the source language.', 'mclogiora' )
			);
		}

		return true;
	}

	/**
	 * Validates that a language slot in a group is free.
	 *
	 * @param string $group_key Group key.
	 * @param string $language_code Language code.
	 * @return true|\WP_Error
	 */
	public function validate_language_slot_free( $group_key, $language_code ) {
		$items = $this->relations->items_for_group( (string) $group_key );

		foreach ( $items as $item ) {
			if ( $item->language_code() === (string) $language_code ) {
				return new \WP_Error(
					'mclogiora_translation_exists',
					__( 'A translation already exists for this language.', 'mclogiora' )
				);
			}
		}

		return true;
	}

	/**
	 * Validates that an object is not already part of a translation group.
	 *
	 * @param string $object_type Relation content type.
	 * @param int    $object_id Object ID.
	 * @return true|\WP_Error
	 */
	public function validate_object_unassigned( $object_type, $object_id ) {
		if ( $this->relations->object_is_assigned( (string) $object_type, (string) $object_id ) ) {
			return new \WP_Error(
				'mclogiora_object_already_related',
				__( 'This content is already part of a translation group.', 'mclogiora' )
			);
		}

		return true;
	}

	/**
	 * Validates that the current user may create content of a post type.
	 *
	 * @param string $post_type Post type.
	 * @return true|\WP_Error
	 */
	public function validate_can_create_post_type( $post_type ) {
		$post_type = (string) $post_type;

		if ( ! $this->gateway->post_type_exists( $post_type ) ) {
			return new \WP_Error( 'mclogiora_unknown_post_type', __( 'The content type is not registered.', 'mclogiora' ) );
		}

		/*
		 * Post type capabilities are dynamic. `create_posts` is not a real
		 * capability for every type, so the singular edit capability of the
		 * type is the reliable gate, and edit_post on the source has already
		 * been checked separately.
		 */
		if ( ! $this->gateway->current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'mclogiora_cannot_create_translation',
				__( 'You are not allowed to create content of this type.', 'mclogiora' )
			);
		}

		return true;
	}

	/**
	 * Validates a link-existing request for posts.
	 *
	 * @param array<string,mixed> $source Source post.
	 * @param int                 $target_id Target post ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function validate_link_target_post( array $source, $target_id ) {
		$target_id = (int) $target_id;

		if ( $target_id <= 0 ) {
			return new \WP_Error( 'mclogiora_invalid_target_id', __( 'The target content identifier is not valid.', 'mclogiora' ) );
		}

		if ( isset( $source['ID'] ) && (int) $source['ID'] === $target_id ) {
			return new \WP_Error( 'mclogiora_cannot_link_to_self', __( 'Content cannot be linked to itself.', 'mclogiora' ) );
		}

		$target = $this->gateway->get_post( $target_id );

		if ( null === $target ) {
			return new \WP_Error( 'mclogiora_target_not_found', __( 'The target content could not be found.', 'mclogiora' ) );
		}

		$source_type = isset( $source['post_type'] ) ? (string) $source['post_type'] : '';
		$target_type = isset( $target['post_type'] ) ? (string) $target['post_type'] : '';

		/*
		 * Translations stay within one post type. A page is not a translation
		 * of a post: the two have different templates, capabilities, and
		 * archive behaviour, and mixing them would make later URL and SEO
		 * phases ambiguous.
		 */
		if ( $source_type !== $target_type ) {
			return new \WP_Error(
				'mclogiora_post_type_mismatch',
				__( 'A translation must use the same content type as its source.', 'mclogiora' )
			);
		}

		if ( ! $this->content_types->is_translatable( $target_type ) ) {
			return new \WP_Error(
				'mclogiora_content_type_not_translatable',
				__( 'This content type is not available for translation.', 'mclogiora' )
			);
		}

		if ( ! $this->gateway->current_user_can( 'edit_post', $target_id ) ) {
			return new \WP_Error(
				'mclogiora_cannot_edit_target',
				__( 'You are not allowed to edit the target content.', 'mclogiora' )
			);
		}

		$unassigned = $this->validate_object_unassigned( ContentType::POST, $target_id );

		if ( is_wp_error( $unassigned ) ) {
			return $unassigned;
		}

		return $target;
	}

	/**
	 * Validates a link-existing request for terms.
	 *
	 * @param array<string,mixed> $source Source term.
	 * @param int                 $target_id Target term ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function validate_link_target_term( array $source, $target_id ) {
		$target_id = (int) $target_id;
		$taxonomy  = isset( $source['taxonomy'] ) ? (string) $source['taxonomy'] : '';

		if ( $target_id <= 0 ) {
			return new \WP_Error( 'mclogiora_invalid_target_id', __( 'The target term identifier is not valid.', 'mclogiora' ) );
		}

		if ( isset( $source['term_id'] ) && (int) $source['term_id'] === $target_id ) {
			return new \WP_Error( 'mclogiora_cannot_link_to_self', __( 'A term cannot be linked to itself.', 'mclogiora' ) );
		}

		$target = $this->gateway->get_term( $target_id, $taxonomy );

		if ( null === $target ) {
			return new \WP_Error( 'mclogiora_target_not_found', __( 'The target term could not be found in this taxonomy.', 'mclogiora' ) );
		}

		if ( (string) $target['taxonomy'] !== $taxonomy ) {
			return new \WP_Error(
				'mclogiora_taxonomy_mismatch',
				__( 'A translated term must belong to the same taxonomy as its source.', 'mclogiora' )
			);
		}

		$unassigned = $this->validate_object_unassigned( ContentType::TERM, $target_id );

		if ( is_wp_error( $unassigned ) ) {
			return $unassigned;
		}

		return $target;
	}
}
