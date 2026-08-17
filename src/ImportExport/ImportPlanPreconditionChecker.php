<?php
/**
 * Import plan precondition checker.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Relations\ContentType;
use McLogiora\Relations\TranslationRelationRepositoryInterface;
use McLogiora\Relations\TranslationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the exact state observed by planning before any import write.
 *
 * The checker deliberately reads the resolved IDs carried by operations. It
 * never calls the planner or resolves a locator again, so a changed site is a
 * stale-plan failure rather than an opportunity to silently choose new data.
 */
final class ImportPlanPreconditionChecker {
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
	 * Returns stale or structurally invalid preconditions.
	 *
	 * @param ImportPlan $plan Immutable plan.
	 * @return PlanIssue[]
	 */
	public function check( ImportPlan $plan ) {
		$issues    = array();
		$languages = array();
		$groups    = array();
		$assigned  = array();

		foreach ( $this->languages->get_languages() as $language ) {
			$languages[ $language->code() ] = $this->language_state( $language );
		}

		foreach ( $plan->operations() as $operation ) {
			$type   = $operation->type();
			$detail = $operation->detail();

			if ( PlannedOperation::SKIP === $type && isset( $detail['kind'] ) && 'language' === $detail['kind'] ) {
				$code     = $operation->subject();
				$current  = $this->languages->get_language_by_code( $code );
				$expected = isset( $detail['language'] ) && is_array( $detail['language'] ) ? $detail['language'] : null;

				if ( null === $current || null === $expected || $this->language_state( $current ) !== $this->language_state_from_detail( $expected ) ) {
					$issues[] = $this->stale( $operation, 'language_changed', array( 'language' => $code ) );
				}

				continue;
			}

			if ( PlannedOperation::CREATE_LANGUAGE === $type ) {
				$code = isset( $detail['code'] ) ? (string) $detail['code'] : '';

				if ( '' === $code || isset( $languages[ $code ] ) ) {
					$issues[] = $this->stale( $operation, 'language_exists_or_changed', array( 'language' => $code ) );
					continue;
				}

				if ( ! empty( $detail['is_default'] ) && null !== $this->languages->get_default_language() ) {
					$issues[] = $this->stale( $operation, 'default_language_changed' );
					continue;
				}

				if ( ! empty( $detail['is_default'] ) && $this->has_virtual_default( $languages ) ) {
					$issues[] = $this->invalid( $operation, 'multiple_default_languages' );
					continue;
				}

				$languages[ $code ] = $detail;
				continue;
			}

			if ( PlannedOperation::CREATE_GROUP === $type ) {
				$group_key = isset( $detail['group_key'] ) ? (string) $detail['group_key'] : '';
				$source    = $this->item_from_detail( $detail, true );

				if ( '' === $group_key || null === $source ) {
					$issues[] = $this->invalid( $operation, 'create_group_detail_invalid' );
					continue;
				}

				if ( isset( $groups[ $group_key ] ) || null !== $this->relations->find_group( $group_key ) ) {
					$issues[] = $this->stale( $operation, 'group_exists', array( 'group_key' => $group_key ) );
					continue;
				}

				$identity_issue = $this->check_object_identity( $operation, $detail );
				if ( null !== $identity_issue ) {
					$issues[] = $identity_issue;
					continue;
				}

				if ( ! isset( $languages[ $source->language_code() ] ) ) {
					$issues[] = $this->stale( $operation, 'source_language_missing', array( 'language' => $source->language_code() ) );
					continue;
				}

				$object_key = $this->object_key( $source->object_type(), $source->object_id() );
				if ( isset( $assigned[ $object_key ] ) || $this->relations->object_is_assigned( $source->object_type(), $source->object_id() ) ) {
					$issues[] = $this->stale( $operation, 'source_object_assigned', array( 'object_id' => (int) $source->object_id() ) );
					continue;
				}

				$groups[ $group_key ]    = array( $source );
				$assigned[ $object_key ] = true;
				continue;
			}

			if ( PlannedOperation::LINK_ITEM === $type || PlannedOperation::SKIP === $type ) {
				$group_key = isset( $detail['group_key'] ) ? (string) $detail['group_key'] : '';
				$group     = $this->virtual_group( $group_key, $groups );
				$is_source = PlannedOperation::SKIP === $type && isset( $detail['reason'] ) && 'source_present' === $detail['reason'];
				$item      = $this->item_from_detail( $detail, $is_source );

				if ( null === $item || '' === $group_key ) {
					$issues[] = $this->invalid( $operation, 'relation_detail_invalid' );
					continue;
				}

				if ( null === $group ) {
					$existing = $this->relations->find_group( $group_key );
					if ( null === $existing ) {
						$issues[] = $this->stale( $operation, 'group_missing', array( 'group_key' => $group_key ) );
						continue;
					}

					$group                = $existing->items();
					$groups[ $group_key ] = $group;
				}

				$identity_issue = $this->check_object_identity( $operation, $detail );
				if ( null !== $identity_issue ) {
					$issues[] = $identity_issue;
					continue;
				}

				if ( ! isset( $languages[ $item->language_code() ] ) ) {
					$issues[] = $this->stale( $operation, 'language_missing', array( 'language' => $item->language_code() ) );
					continue;
				}

				$matching = $this->find_slot( $group, $item->language_code() );
				if ( PlannedOperation::SKIP === $type ) {
					if ( null === $matching || ! $this->same_item( $matching, $item ) ) {
						$issues[] = $this->stale( $operation, 'skip_target_changed', array( 'group_key' => $group_key ) );
					}
					continue;
				}

				if ( null !== $matching ) {
					$issues[] = $this->stale(
						$operation,
						'language_slot_changed',
						array(
							'group_key' => $group_key,
							'language'  => $item->language_code(),
						)
					);
					continue;
				}

				$object_key = $this->object_key( $item->object_type(), $item->object_id() );
				if ( isset( $assigned[ $object_key ] ) || $this->relations->object_is_assigned( $item->object_type(), $item->object_id() ) ) {
					$issues[] = $this->stale( $operation, 'object_grouping_changed', array( 'object_id' => (int) $item->object_id() ) );
					continue;
				}

				$groups[ $group_key ][]  = $item;
				$assigned[ $object_key ] = true;
			}
		}

		return $issues;
	}

	/**
	 * Checks that a plan's resolved ID still names its recorded locator.
	 *
	 * @param PlannedOperation $operation Operation.
	 * @param array            $detail Operation detail.
	 * @return PlanIssue|null
	 */
	private function check_object_identity( PlannedOperation $operation, array $detail ) {
		$locator = ObjectLocator::from_array( isset( $detail['locator'] ) ? $detail['locator'] : null );
		$id      = isset( $detail['object_id'] ) ? (int) $detail['object_id'] : 0;
		$type    = isset( $detail['object_type'] ) ? (string) $detail['object_type'] : '';

		if ( null === $locator || $id <= 0 || ! in_array( $type, array( ContentType::POST, ContentType::TERM ), true ) ) {
			return $this->invalid( $operation, 'object_identity_invalid' );
		}

		if ( ContentType::POST === $type && ObjectLocator::KIND_POST === $locator->kind() ) {
			$current = $this->objects->describe_post( $id );
			$matches = is_array( $current )
				&& (string) $current['post_type'] === $locator->post_type()
				&& (string) $current['slug'] === $locator->slug()
				&& $current['ancestors'] === $locator->ancestors();
		} elseif ( ContentType::TERM === $type && ObjectLocator::KIND_TERM === $locator->kind() ) {
			$current = $this->objects->describe_term( $id );
			$matches = is_array( $current )
				&& (string) $current['taxonomy'] === $locator->taxonomy()
				&& (string) $current['slug'] === $locator->slug();
		} else {
			$matches = false;
		}

		return $matches ? null : $this->stale( $operation, 'locator_drift', array( 'object_id' => $id ) );
	}

	/**
	 * Creates a relation item from an operation detail.
	 *
	 * @param array $detail Operation detail.
	 * @param bool  $original Whether the item is a source.
	 * @return \McLogiora\Relations\TranslationItem|null
	 */
	private function item_from_detail( array $detail, $original ) {
		if ( ! isset( $detail['object_type'], $detail['object_id'], $detail['language'] ) ) {
			return null;
		}

		$status = isset( $detail['status'] ) ? (string) $detail['status'] : ( $original ? TranslationStatus::ORIGINAL : '' );
		if ( ! TranslationStatus::is_valid( $status ) || ( ! $original && in_array( $status, array( TranslationStatus::ORIGINAL, TranslationStatus::MISSING ), true ) ) ) {
			return null;
		}

		return new \McLogiora\Relations\TranslationItem(
			(string) $detail['object_type'],
			(string) (int) $detail['object_id'],
			(string) $detail['language'],
			$status,
			(bool) $original
		);
	}

	/**
	 * Returns a virtual group created during this preflight.
	 *
	 * @param string              $group_key Group key.
	 * @param array<string,array> $groups Virtual groups.
	 * @return array|null
	 */
	private function virtual_group( $group_key, array $groups ) {
		return isset( $groups[ $group_key ] ) ? $groups[ $group_key ] : null;
	}

	/**
	 * Finds an item in a language slot.
	 *
	 * @param array  $items Group items.
	 * @param string $language Language code.
	 * @return object|null
	 */
	private function find_slot( array $items, $language ) {
		foreach ( $items as $item ) {
			if ( $item->language_code() === (string) $language ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Compares the identity and status of two relation items.
	 *
	 * @param object $left First item.
	 * @param object $right Second item.
	 * @return bool
	 */
	private function same_item( $left, $right ) {
		return $left->object_type() === $right->object_type()
			&& $left->object_id() === $right->object_id()
			&& $left->language_code() === $right->language_code()
			&& $left->status() === $right->status();
	}

	/**
	 * Returns the virtual object-assignment key.
	 *
	 * @param string $type Object type.
	 * @param string $id Object ID.
	 * @return string
	 */
	private function object_key( $type, $id ) {
		return (string) $type . ':' . (string) $id;
	}

	/**
	 * Projects a language into comparable plan state.
	 *
	 * @param object $language Language entity.
	 * @return array<string,mixed>
	 */
	private function language_state( $language ) {
		return array(
			'code'         => $language->code(),
			'locale'       => $language->locale(),
			'native_name'  => $language->native_name(),
			'english_name' => $language->english_name(),
			'direction'    => $language->direction(),
			'is_active'    => $language->is_active(),
			'is_default'   => $language->is_default(),
			'order'        => $language->order(),
		);
	}

	/**
	 * Projects planned language detail into comparable state.
	 *
	 * @param array<string,mixed> $detail Language detail.
	 * @return array<string,mixed>
	 */
	private function language_state_from_detail( array $detail ) {
		return array(
			'code'         => isset( $detail['code'] ) ? (string) $detail['code'] : '',
			'locale'       => isset( $detail['locale'] ) ? (string) $detail['locale'] : '',
			'native_name'  => isset( $detail['native_name'] ) ? (string) $detail['native_name'] : '',
			'english_name' => isset( $detail['english_name'] ) ? (string) $detail['english_name'] : '',
			'direction'    => isset( $detail['direction'] ) ? (string) $detail['direction'] : '',
			'is_active'    => isset( $detail['is_active'] ) ? (bool) $detail['is_active'] : false,
			'is_default'   => isset( $detail['is_default'] ) ? (bool) $detail['is_default'] : false,
			'order'        => isset( $detail['order'] ) ? (int) $detail['order'] : 0,
		);
	}

	/**
	 * Returns whether virtual plan state already has a default language.
	 *
	 * @param array<string,array<string,mixed>> $languages Language state.
	 * @return bool
	 */
	private function has_virtual_default( array $languages ) {
		foreach ( $languages as $language ) {
			if ( ! empty( $language['is_default'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Creates a stable stale-plan issue.
	 *
	 * @param PlannedOperation    $operation Operation.
	 * @param string              $reason Drift reason.
	 * @param array<string,mixed> $context Context.
	 * @return PlanIssue
	 */
	private function stale( PlannedOperation $operation, $reason, array $context = array() ) {
		$context['operation'] = $operation->to_array();
		$context['reason']    = (string) $reason;

		return new PlanIssue( PlanIssue::LEVEL_ERROR, 'import_plan_stale', 'The import plan no longer matches the destination.', $context );
	}

	/**
	 * Creates a stable invalid-plan issue.
	 *
	 * @param PlannedOperation $operation Operation.
	 * @param string           $reason Invalid detail reason.
	 * @return PlanIssue
	 */
	private function invalid( PlannedOperation $operation, $reason ) {
		return new PlanIssue(
			PlanIssue::LEVEL_ERROR,
			'import_plan_invalid',
			'The import plan contains an invalid operation.',
			array(
				'operation' => $operation->to_array(),
				'reason'    => (string) $reason,
			)
		);
	}
}
