<?php
/**
 * Category, tag, and custom taxonomy translation workflows.
 *
 * @package McLogiora
 */

namespace McLogiora\Workflows;

use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationGroup;
use McLogiora\Relations\TranslationRelationServiceInterface;
use McLogiora\Relations\TranslationStatus;
use McLogiora\WordPress\ContentGatewayInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, links, and unlinks term translations.
 *
 * Unlike posts, a term translation is not created by copying the source. A
 * duplicated term name is not a translation and would leave meaningless rows
 * behind, so the user must supply the translated name.
 */
final class TaxonomyTranslationWorkflow {
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
	 * Constructor.
	 *
	 * @param ContentGatewayInterface             $gateway Content gateway.
	 * @param TranslationRelationServiceInterface $relations Relation service.
	 * @param LanguageServiceInterface            $languages Language service.
	 * @param TranslationWorkflowValidator        $validator Validator.
	 */
	public function __construct(
		ContentGatewayInterface $gateway,
		TranslationRelationServiceInterface $relations,
		LanguageServiceInterface $languages,
		TranslationWorkflowValidator $validator
	) {
		$this->gateway   = $gateway;
		$this->relations = $relations;
		$this->languages = $languages;
		$this->validator = $validator;
	}

	/**
	 * Creates a translated term.
	 *
	 * @param int    $source_term_id Source term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param string $target_language_code Target language code.
	 * @param string $translated_name Translated term name.
	 * @param string $translated_description Optional translated description.
	 * @return array{term_id:int,group_key:string,edit_link:string}|\WP_Error
	 */
	public function create_translation( $source_term_id, $taxonomy, $target_language_code, $translated_name, $translated_description = '' ) {
		$allowed = $this->validator->validate_manage_capability();

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$translated_name = trim( (string) $translated_name );

		if ( '' === $translated_name ) {
			return new \WP_Error(
				'mclogiora_missing_translated_name',
				__( 'Enter the translated term name.', 'mclogiora' )
			);
		}

		$source = $this->validator->validate_source_term( $source_term_id, $taxonomy );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$target_language = $this->validator->validate_target_language( $target_language_code );

		if ( is_wp_error( $target_language ) ) {
			return $target_language;
		}

		$source_language = $this->resolve_source_language( (int) $source['term_id'] );

		if ( is_wp_error( $source_language ) ) {
			return $source_language;
		}

		$differ = $this->validator->validate_languages_differ( $source_language, $target_language->code() );

		if ( is_wp_error( $differ ) ) {
			return $differ;
		}

		$group = $this->resolve_or_create_group( (int) $source['term_id'], $source_language );

		if ( is_wp_error( $group ) ) {
			return $group;
		}

		$slot_free = $this->validator->validate_language_slot_free( $group->group_key(), $target_language->code() );

		if ( is_wp_error( $slot_free ) ) {
			return $slot_free;
		}

		$created_id = $this->gateway->insert_term(
			$translated_name,
			(string) $source['taxonomy'],
			$this->build_term_args( $source, $target_language, $translated_name, $translated_description )
		);

		if ( is_wp_error( $created_id ) ) {
			return $created_id;
		}

		if ( (int) $created_id <= 0 ) {
			return new \WP_Error(
				'mclogiora_translation_term_not_created',
				__( 'The translated term could not be created.', 'mclogiora' )
			);
		}

		$item = $this->relations->attach_existing_object_as_translation(
			$group->group_key(),
			ContentType::TERM,
			(string) $created_id,
			$target_language->code(),
			TranslationStatus::DRAFT
		);

		if ( is_wp_error( $item ) ) {
			// Compensating rollback for the term this operation just created.
			$this->gateway->delete_term( (int) $created_id, (string) $source['taxonomy'] );

			return $item;
		}

		return array(
			'term_id'   => (int) $created_id,
			'group_key' => $group->group_key(),
			'edit_link' => $this->gateway->term_edit_link( (int) $created_id, (string) $source['taxonomy'] ),
		);
	}

	/**
	 * Links an existing term as a translation.
	 *
	 * @param int    $source_term_id Source term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param int    $target_term_id Target term ID.
	 * @param string $target_language_code Target language code.
	 * @return array{term_id:int,group_key:string}|\WP_Error
	 */
	public function link_existing( $source_term_id, $taxonomy, $target_term_id, $target_language_code ) {
		$allowed = $this->validator->validate_manage_capability();

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$source = $this->validator->validate_source_term( $source_term_id, $taxonomy );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$target_language = $this->validator->validate_target_language( $target_language_code );

		if ( is_wp_error( $target_language ) ) {
			return $target_language;
		}

		$source_language = $this->resolve_source_language( (int) $source['term_id'] );

		if ( is_wp_error( $source_language ) ) {
			return $source_language;
		}

		$differ = $this->validator->validate_languages_differ( $source_language, $target_language->code() );

		if ( is_wp_error( $differ ) ) {
			return $differ;
		}

		$target = $this->validator->validate_link_target_term( $source, $target_term_id );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$group = $this->resolve_or_create_group( (int) $source['term_id'], $source_language );

		if ( is_wp_error( $group ) ) {
			return $group;
		}

		$slot_free = $this->validator->validate_language_slot_free( $group->group_key(), $target_language->code() );

		if ( is_wp_error( $slot_free ) ) {
			return $slot_free;
		}

		$item = $this->relations->attach_existing_object_as_translation(
			$group->group_key(),
			ContentType::TERM,
			(string) $target['term_id'],
			$target_language->code(),
			TranslationStatus::NEEDS_REVIEW
		);

		if ( is_wp_error( $item ) ) {
			return $item;
		}

		return array(
			'term_id'   => (int) $target['term_id'],
			'group_key' => $group->group_key(),
		);
	}

	/**
	 * Unlinks a term from its translation group.
	 *
	 * The WordPress term itself is never deleted, and its assigned objects are
	 * untouched.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $language_code Language code.
	 * @return true|\WP_Error
	 */
	public function unlink( $term_id, $language_code ) {
		$allowed = $this->validator->validate_manage_capability();

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$term_id = (int) $term_id;

		if ( $term_id <= 0 ) {
			return new \WP_Error( 'mclogiora_invalid_source_id', __( 'The term identifier is not valid.', 'mclogiora' ) );
		}

		if ( ! $this->gateway->current_user_can( 'manage_categories' ) ) {
			return new \WP_Error( 'mclogiora_cannot_edit_terms', __( 'You are not allowed to edit terms.', 'mclogiora' ) );
		}

		$result = $this->relations->detach_item_safely( ContentType::TERM, (string) $term_id, (string) $language_code );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Builds the arguments for a translated term.
	 *
	 * The parent is only preserved when the source parent already has a
	 * translation in the target language. Otherwise the translated term is
	 * created at the top level rather than being attached to a parent in the
	 * wrong language, which would produce a mixed-language hierarchy.
	 *
	 * @param array<string,mixed> $source Source term.
	 * @param Language            $language Target language.
	 * @param string              $translated_name Translated name.
	 * @param string              $translated_description Translated description.
	 * @return array<string,mixed>
	 */
	private function build_term_args( array $source, Language $language, $translated_name, $translated_description ) {
		$args = array(
			'description' => (string) $translated_description,
			'parent'      => $this->resolve_translated_parent( $source, $language ),
		);

		/*
		 * A provisional slug keeps WordPress from colliding with the source
		 * term when the translated name transliterates to the same slug. It is
		 * deterministic and language-scoped so that the Phase 12 slug manager
		 * can recognise and replace it. It is not a translated slug and is not
		 * presented as one.
		 */
		$args['slug'] = sanitize_title( $translated_name . '-' . $language->code() );

		return $args;
	}

	/**
	 * Resolves the parent term for a translated term.
	 *
	 * @param array<string,mixed> $source Source term.
	 * @param Language            $language Target language.
	 * @return int
	 */
	private function resolve_translated_parent( array $source, Language $language ) {
		$source_parent = isset( $source['parent'] ) ? (int) $source['parent'] : 0;

		if ( $source_parent <= 0 ) {
			return 0;
		}

		$parent_group = $this->relations->get_translation_set_for_object( ContentType::TERM, (string) $source_parent );

		if ( ! $parent_group instanceof TranslationGroup ) {
			return 0;
		}

		foreach ( $parent_group->items() as $item ) {
			if ( $item->language_code() === $language->code() ) {
				return (int) $item->object_id();
			}
		}

		return 0;
	}

	/**
	 * Returns the language of a term that is already in a group.
	 *
	 * @param int $term_id Term ID.
	 * @return string|\WP_Error
	 */
	private function resolve_source_language( $term_id ) {
		$group = $this->relations->get_translation_set_for_object( ContentType::TERM, (string) $term_id );

		if ( $group instanceof TranslationGroup ) {
			foreach ( $group->items() as $item ) {
				if ( $item->object_id() === (string) $term_id ) {
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
	 * Returns the group for a source term, creating it when necessary.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $source_language Source language code.
	 * @return TranslationGroup|\WP_Error
	 */
	private function resolve_or_create_group( $term_id, $source_language ) {
		$existing = $this->relations->get_translation_set_for_object( ContentType::TERM, (string) $term_id );

		if ( $existing instanceof TranslationGroup ) {
			return $existing;
		}

		return $this->relations->create_group_from_source_object(
			ContentType::TERM,
			(string) $term_id,
			(string) $source_language
		);
	}
}
