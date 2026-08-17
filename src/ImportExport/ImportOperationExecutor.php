<?php
/**
 * Domain-backed import operation executor.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationRelationServiceInterface;
use McLogiora\Relations\TranslationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Turns planned operations into calls to existing language and relation
 * domain services. It does not resolve package locators or re-plan.
 */
class ImportOperationExecutor implements ImportOperationExecutorInterface {
	/**
	 * Language domain service.
	 *
	 * @var LanguageServiceInterface
	 */
	private $languages;

	/**
	 * Relation domain service.
	 *
	 * @var TranslationRelationServiceInterface
	 */
	private $relations;

	/**
	 * Constructor.
	 *
	 * @param LanguageServiceInterface            $languages Language service.
	 * @param TranslationRelationServiceInterface $relations Relation service.
	 */
	public function __construct( LanguageServiceInterface $languages, TranslationRelationServiceInterface $relations ) {
		$this->languages = $languages;
		$this->relations = $relations;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param PlannedOperation $operation Operation.
	 * @return array<string,mixed>|\WP_Error Result or failure.
	 */
	public function execute( PlannedOperation $operation ) {
		$detail = $operation->detail();

		if ( PlannedOperation::SKIP === $operation->type() ) {
			return array(
				'type'    => $operation->type(),
				'subject' => $operation->subject(),
				'action'  => 'skipped',
			);
		}

		if ( PlannedOperation::CREATE_LANGUAGE === $operation->type() ) {
			$language_data = $detail;
			if ( isset( $detail['is_active'] ) ) {
				$language_data['status'] = $detail['is_active'] ? \McLogiora\Languages\LanguageStatus::ACTIVE : \McLogiora\Languages\LanguageStatus::INACTIVE;
			}
			if ( isset( $detail['is_default'] ) ) {
				$language_data['default'] = (bool) $detail['is_default'];
			}

			$result = $this->languages->create_language( $language_data );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'type'    => $operation->type(),
				'subject' => $operation->subject(),
				'action'  => 'created',
			);
		}

		if ( PlannedOperation::CREATE_GROUP === $operation->type() ) {
			if ( ! isset( $detail['group_key'], $detail['object_type'], $detail['object_id'], $detail['language'] ) ) {
				return new \WP_Error( 'import_plan_invalid', 'The group operation is incomplete.' );
			}

			$result = $this->relations->create_group_placeholder_with_key(
				(string) $detail['group_key'],
				new TranslationItem(
					(string) $detail['object_type'],
					(string) (int) $detail['object_id'],
					(string) $detail['language'],
					TranslationStatus::ORIGINAL,
					true
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'type'      => $operation->type(),
				'subject'   => $operation->subject(),
				'action'    => 'created',
				'group_key' => (string) $detail['group_key'],
			);
		}

		if ( PlannedOperation::LINK_ITEM === $operation->type() ) {
			$status = isset( $detail['status'] ) ? (string) $detail['status'] : '';
			if ( ! TranslationStatus::is_valid( $status ) || in_array( $status, array( TranslationStatus::ORIGINAL, TranslationStatus::MISSING ), true ) ) {
				return new \WP_Error( 'import_plan_invalid', 'The link operation has an invalid target status.' );
			}

			$result = $this->relations->attach_existing_object_as_translation(
				(string) $detail['group_key'],
				(string) $detail['object_type'],
				(string) (int) $detail['object_id'],
				(string) $detail['language'],
				$status
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'type'      => $operation->type(),
				'subject'   => $operation->subject(),
				'action'    => 'linked',
				'group_key' => (string) $detail['group_key'],
			);
		}

		return new \WP_Error( 'import_plan_invalid', 'The import operation type is not supported.' );
	}
}
