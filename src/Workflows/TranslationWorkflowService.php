<?php
/**
 * Translation workflow facade.
 *
 * @package McLogiora
 */

namespace McLogiora\Workflows;

use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationRelationRepositoryInterface;
use McLogiora\Relations\TranslationRelationServiceInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates the content and taxonomy workflows and owns status changes.
 *
 * Admin controllers talk to this class. It holds no WordPress request state
 * and performs no output, so the same calls work from any future entry point.
 */
final class TranslationWorkflowService {
	/**
	 * Content workflow.
	 *
	 * @var ContentTranslationWorkflow
	 */
	private $content;

	/**
	 * Taxonomy workflow.
	 *
	 * @var TaxonomyTranslationWorkflow
	 */
	private $taxonomy;

	/**
	 * Status transition policy.
	 *
	 * @var TranslationStatusTransitions
	 */
	private $transitions;

	/**
	 * Relation repository.
	 *
	 * @var TranslationRelationRepositoryInterface
	 */
	private $repository;

	/**
	 * Relation service.
	 *
	 * @var TranslationRelationServiceInterface
	 */
	private $relations;

	/**
	 * Validator.
	 *
	 * @var TranslationWorkflowValidator
	 */
	private $validator;

	/**
	 * Constructor.
	 *
	 * @param ContentTranslationWorkflow             $content Content workflow.
	 * @param TaxonomyTranslationWorkflow            $taxonomy Taxonomy workflow.
	 * @param TranslationStatusTransitions           $transitions Status transitions.
	 * @param TranslationRelationRepositoryInterface $repository Relation repository.
	 * @param TranslationRelationServiceInterface    $relations Relation service.
	 * @param TranslationWorkflowValidator           $validator Validator.
	 */
	public function __construct(
		ContentTranslationWorkflow $content,
		TaxonomyTranslationWorkflow $taxonomy,
		TranslationStatusTransitions $transitions,
		TranslationRelationRepositoryInterface $repository,
		TranslationRelationServiceInterface $relations,
		TranslationWorkflowValidator $validator
	) {
		$this->content     = $content;
		$this->taxonomy    = $taxonomy;
		$this->transitions = $transitions;
		$this->repository  = $repository;
		$this->relations   = $relations;
		$this->validator   = $validator;
	}

	/**
	 * Returns the content workflow.
	 *
	 * @return ContentTranslationWorkflow
	 */
	public function content() {
		return $this->content;
	}

	/**
	 * Returns the taxonomy workflow.
	 *
	 * @return TaxonomyTranslationWorkflow
	 */
	public function taxonomy() {
		return $this->taxonomy;
	}

	/**
	 * Returns the status transition policy.
	 *
	 * @return TranslationStatusTransitions
	 */
	public function transitions() {
		return $this->transitions;
	}

	/**
	 * Changes the status of a translation item.
	 *
	 * The transition is validated against the current stored status, so a
	 * stale form cannot force an illegal state change.
	 *
	 * @param string $object_type Relation content type.
	 * @param int    $object_id Object ID.
	 * @param string $language_code Language code.
	 * @param string $new_status Requested status.
	 * @return TranslationItem|\WP_Error
	 */
	public function change_status( $object_type, $object_id, $language_code, $new_status ) {
		$allowed = $this->validator->validate_manage_capability();

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$item = $this->repository->find_item( (string) $object_type, (string) $object_id, (string) $language_code );

		if ( ! $item instanceof TranslationItem ) {
			return new \WP_Error(
				'mclogiora_translation_item_not_found',
				__( 'The translation could not be found.', 'mclogiora' )
			);
		}

		$transition = $this->transitions->validate( $item->status(), $new_status );

		if ( is_wp_error( $transition ) ) {
			return $transition;
		}

		return $this->relations->mark_status(
			(string) $object_type,
			(string) $object_id,
			(string) $language_code,
			(string) $new_status
		);
	}
}
