<?php
/**
 * Final import plan verification.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Relations\TranslationRelationRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies the exact state promised by an applied plan before commit.
 */
final class ImportPlanVerifier {
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
	 * Object locator gateway.
	 *
	 * @var ObjectLocatorGatewayInterface
	 */
	private $objects;

	/**
	 * Constructor.
	 *
	 * @param LanguageServiceInterface               $languages Language service.
	 * @param TranslationRelationRepositoryInterface $relations Relation repository.
	 * @param ObjectLocatorGatewayInterface          $objects Object gateway.
	 */
	public function __construct( LanguageServiceInterface $languages, TranslationRelationRepositoryInterface $relations, ObjectLocatorGatewayInterface $objects ) {
		$this->languages = $languages;
		$this->relations = $relations;
		$this->objects   = $objects;
	}

	/**
	 * Verifies every operation's expected postcondition.
	 *
	 * @param ImportPlan $plan Applied plan.
	 * @return PlanIssue[]
	 */
	public function verify( ImportPlan $plan ) {
		$issues = array();

		foreach ( $plan->operations() as $operation ) {
			$detail = $operation->detail();
			$type   = $operation->type();

			if ( PlannedOperation::CREATE_LANGUAGE === $type ) {
				$language = isset( $detail['code'] ) ? $this->languages->get_language_by_code( $detail['code'] ) : null;
				if ( null === $language || ! $this->language_matches( $language, $detail ) ) {
					$issues[] = $this->failed( $operation, 'language_postcondition_failed' );
				}
				continue;
			}

			if ( PlannedOperation::SKIP === $type && isset( $detail['kind'] ) && 'language' === $detail['kind'] ) {
				$language = $this->languages->get_language_by_code( $operation->subject() );
				if ( null === $language || ! isset( $detail['language'] ) || ! $this->language_matches( $language, $detail['language'] ) ) {
					$issues[] = $this->failed( $operation, 'language_skip_postcondition_failed' );
				}
				continue;
			}

			if ( PlannedOperation::CREATE_GROUP === $type ) {
				$group = $this->relations->find_group( isset( $detail['group_key'] ) ? $detail['group_key'] : '' );
				if ( null === $group || ! $this->group_contains_detail( $group->original(), $detail, true ) ) {
					$issues[] = $this->failed( $operation, 'group_postcondition_failed' );
				}
				continue;
			}

			if ( PlannedOperation::LINK_ITEM === $type || PlannedOperation::SKIP === $type ) {
				$group     = $this->relations->find_group( isset( $detail['group_key'] ) ? $detail['group_key'] : '' );
				$is_source = PlannedOperation::SKIP === $type && isset( $detail['reason'] ) && 'source_present' === $detail['reason'];
				if ( null === $group || ! $this->group_contains_detail( $this->find_item( $group->items(), $detail ), $detail, $is_source ) ) {
					$issues[] = $this->failed( $operation, 'relation_postcondition_failed' );
				}
			}
		}

		return $issues;
	}

	/**
	 * Checks one relation item against planned detail.
	 *
	 * @param object              $item Current item.
	 * @param array<string,mixed> $detail Planned detail.
	 * @param bool                $source Whether item is the source.
	 * @return bool
	 */
	private function group_contains_detail( $item, array $detail, $source ) {
		if ( ! $item instanceof \McLogiora\Relations\TranslationItem || ! isset( $detail['object_type'], $detail['object_id'], $detail['language'], $detail['status'] ) ) {
			return false;
		}

		return $item->object_type() === (string) $detail['object_type']
			&& (int) $item->object_id() === (int) $detail['object_id']
			&& $item->language_code() === (string) $detail['language']
			&& $item->status() === (string) $detail['status']
			&& $item->is_original() === (bool) $source
			&& $this->locator_matches( $detail );
	}

	/**
	 * Finds a relation item by identity and language.
	 *
	 * @param array               $items Group items.
	 * @param array<string,mixed> $detail Planned detail.
	 * @return object|null
	 */
	private function find_item( array $items, array $detail ) {
		foreach ( $items as $item ) {
			if ( $item->object_type() === (string) ( isset( $detail['object_type'] ) ? $detail['object_type'] : '' )
				&& (int) $item->object_id() === (int) ( isset( $detail['object_id'] ) ? $detail['object_id'] : 0 )
				&& $item->language_code() === (string) ( isset( $detail['language'] ) ? $detail['language'] : '' ) ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Checks the resolved object still matches its locator.
	 *
	 * @param array<string,mixed> $detail Planned detail.
	 * @return bool
	 */
	private function locator_matches( array $detail ) {
		$locator = ObjectLocator::from_array( isset( $detail['locator'] ) ? $detail['locator'] : null );
		$id      = isset( $detail['object_id'] ) ? (int) $detail['object_id'] : 0;

		if ( null === $locator || $id <= 0 ) {
			return false;
		}

		if ( ObjectLocator::KIND_POST === $locator->kind() ) {
			$current = $this->objects->describe_post( $id );
			return is_array( $current ) && (string) $current['post_type'] === $locator->post_type() && (string) $current['slug'] === $locator->slug() && $current['ancestors'] === $locator->ancestors();
		}

		$current = $this->objects->describe_term( $id );
		return is_array( $current ) && (string) $current['taxonomy'] === $locator->taxonomy() && (string) $current['slug'] === $locator->slug();
	}

	/**
	 * Checks all portable language fields.
	 *
	 * @param object              $language Current language.
	 * @param array<string,mixed> $detail Planned language detail.
	 * @return bool
	 */
	private function language_matches( $language, array $detail ) {
		return $language->code() === (string) ( isset( $detail['code'] ) ? $detail['code'] : '' )
			&& $language->locale() === (string) ( isset( $detail['locale'] ) ? $detail['locale'] : '' )
			&& $language->native_name() === (string) ( isset( $detail['native_name'] ) ? $detail['native_name'] : '' )
			&& $language->english_name() === (string) ( isset( $detail['english_name'] ) ? $detail['english_name'] : '' )
			&& $language->direction() === (string) ( isset( $detail['direction'] ) ? $detail['direction'] : '' )
			&& $language->is_active() === (bool) ( isset( $detail['is_active'] ) ? $detail['is_active'] : false )
			&& $language->is_default() === (bool) ( isset( $detail['is_default'] ) ? $detail['is_default'] : false )
			&& $language->order() === (int) ( isset( $detail['order'] ) ? $detail['order'] : 0 );
	}

	/**
	 * Creates a final-verification failure issue.
	 *
	 * @param PlannedOperation $operation Operation.
	 * @param string           $reason Failure reason.
	 * @return PlanIssue
	 */
	private function failed( PlannedOperation $operation, $reason ) {
		return new PlanIssue(
			PlanIssue::LEVEL_ERROR,
			'import_apply_verification_failed',
			'The applied import did not produce the exact planned state.',
			array(
				'operation' => $operation->to_array(),
				'reason'    => (string) $reason,
			)
		);
	}
}
