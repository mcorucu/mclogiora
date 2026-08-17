<?php
/**
 * Import dry-run planner.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Relations\TranslationGroup;
use McLogiora\Relations\TranslationItem;
use McLogiora\Relations\TranslationRelationRepositoryInterface;
use McLogiora\Relations\TranslationStatus;
use McLogiora\Workflows\TranslationStatusTransitions;

defined( 'ABSPATH' ) || exit;

/**
 * Works out what importing a package would do, and does none of it.
 *
 * This class is the dry run and it is also the apply. A later slice executes
 * the operations this planner produces; it does not re-read the package and
 * decide again. Two independent implementations of "what should happen" would
 * drift, and the operator would have approved the older one.
 *
 * ### Nothing here writes
 *
 * Every call this planner makes is a read: repository finders, and the locator
 * gateway, which itself only queries. No repository mutator, no `wp_insert_*`,
 * no `update_option`, no cache invalidation. Planning a package on a site
 * leaves that site byte-identical, which is the property that makes it safe to
 * hand a stranger's package to an administrator and let them look at it first.
 *
 * ### The policy the plan encodes
 *
 * Import is additive. It creates languages the destination does not have,
 * creates translation groups it does not have, and links objects that are not
 * yet linked. It never overwrites: not a language's metadata, not a
 * translation's status, not an occupied language slot. Wherever the package and
 * the destination disagree about something that already exists, the plan
 * reports a conflict and plans nothing for it.
 *
 * That is a deliberate choice and not a limitation waiting to be lifted.
 * Overwriting needs a policy -- which side wins, per field, per domain -- and
 * no authoritative source has one yet. Inventing one inside a planner would
 * mean the first site to import a package discovers the policy by losing work
 * to it.
 */
final class ImportPlanner {
	/**
	 * Package validator.
	 *
	 * @var PackageValidator
	 */
	private $validator;

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
	 * Status transition policy.
	 *
	 * @var TranslationStatusTransitions
	 */
	private $transitions;

	/**
	 * Constructor.
	 *
	 * @param PackageValidator                       $validator Package validator.
	 * @param LanguageServiceInterface               $languages Language service.
	 * @param TranslationRelationRepositoryInterface $relations Relation repository.
	 * @param ObjectLocatorGatewayInterface          $objects Object locator gateway.
	 * @param TranslationStatusTransitions           $transitions Status transition policy.
	 */
	public function __construct(
		PackageValidator $validator,
		LanguageServiceInterface $languages,
		TranslationRelationRepositoryInterface $relations,
		ObjectLocatorGatewayInterface $objects,
		TranslationStatusTransitions $transitions
	) {
		$this->validator   = $validator;
		$this->languages   = $languages;
		$this->relations   = $relations;
		$this->objects     = $objects;
		$this->transitions = $transitions;
	}

	/**
	 * Builds the plan for a parsed package.
	 *
	 * @param TranslationPackage $package Parsed package.
	 * @return ImportPlan
	 */
	public function plan( TranslationPackage $package ) {
		$issues = $this->validator->validate( $package );

		foreach ( $issues as $issue ) {
			if ( PlanIssue::LEVEL_ERROR === $issue->level() ) {
				/*
				 * A package that cannot be applied gets no operations at all.
				 * Publishing a partial plan beside a blocking error invites
				 * someone to read the operations and ignore the reason they
				 * will never run.
				 */
				return new ImportPlan( array(), $issues );
			}
		}

		$operations = array();
		$site       = $this->site_languages();

		$this->plan_languages( $package, $site, $operations, $issues );
		$this->plan_relations( $package, $site, $operations, $issues );

		return new ImportPlan( $operations, $issues );
	}

	/**
	 * Plans the language section.
	 *
	 * @param TranslationPackage     $package Parsed package.
	 * @param array<string,Language> $site Destination languages keyed by code.
	 * @param PlannedOperation[]     $operations Operations, by reference.
	 * @param PlanIssue[]            $issues Issues, by reference.
	 * @return void
	 */
	private function plan_languages( TranslationPackage $package, array $site, array &$operations, array &$issues ) {
		foreach ( $package->languages() as $language ) {
			$code = $language->code();

			if ( ! isset( $site[ $code ] ) ) {
				$operations[] = new PlannedOperation(
					PlannedOperation::CREATE_LANGUAGE,
					$code,
					$language->to_array()
				);

				continue;
			}

			$differences = $this->language_differences( $language, $site[ $code ] );

			if ( array() === $differences ) {
				$operations[] = new PlannedOperation(
					PlannedOperation::SKIP,
					$code,
					array(
						'kind'     => 'language',
						'reason'   => 'identical',
						'language' => $language->to_array(),
					)
				);

				continue;
			}

			$issues[] = new PlanIssue(
				PlanIssue::LEVEL_CONFLICT,
				'language_differs',
				sprintf(
					/* translators: 1: language code, 2: comma-separated field names. */
					__( 'The language %1$s already exists here with a different %2$s. Import never overwrites an existing language.', 'mclogiora' ),
					$code,
					implode( ', ', array_keys( $differences ) )
				),
				array(
					'language'    => $code,
					'differences' => $differences,
				)
			);
		}

		$this->plan_default_language( $package, $site, $issues );
	}

	/**
	 * Reports a disagreement about which language is the site default.
	 *
	 * Reported separately from the per-language comparison because it is a
	 * property of the site rather than of a language: two languages each
	 * claiming a different answer to "which one is default" is one fact, and an
	 * operator reading only the per-language conflicts could miss it.
	 *
	 * @param TranslationPackage     $package Parsed package.
	 * @param array<string,Language> $site Destination languages keyed by code.
	 * @param PlanIssue[]            $issues Issues, by reference.
	 * @return void
	 */
	private function plan_default_language( TranslationPackage $package, array $site, array &$issues ) {
		$wanted = $package->default_language();

		if ( null === $wanted ) {
			return;
		}

		$current = null;

		foreach ( $site as $language ) {
			if ( $language->is_default() ) {
				$current = $language;
				break;
			}
		}

		if ( null === $current || $current->code() === $wanted->code() ) {
			return;
		}

		$issues[] = new PlanIssue(
			PlanIssue::LEVEL_CONFLICT,
			'default_language_differs',
			sprintf(
				/* translators: 1: default language code in the package, 2: default language code on this site. */
				__( 'The package treats %1$s as the default language and this site treats %2$s as the default. Import does not change the default language.', 'mclogiora' ),
				$wanted->code(),
				$current->code()
			),
			array(
				'package_default' => $wanted->code(),
				'site_default'    => $current->code(),
			)
		);
	}

	/**
	 * Plans the relation section.
	 *
	 * @param TranslationPackage     $package Parsed package.
	 * @param array<string,Language> $site Destination languages keyed by code.
	 * @param PlannedOperation[]     $operations Operations, by reference.
	 * @param PlanIssue[]            $issues Issues, by reference.
	 * @return void
	 */
	private function plan_relations( TranslationPackage $package, array $site, array &$operations, array &$issues ) {
		$resolver = new LocatorResolver( $this->objects );

		foreach ( $package->relations() as $group ) {
			$this->plan_group( $package, $group, $site, $resolver, $operations, $issues );
		}
	}

	/**
	 * Plans one translation group.
	 *
	 * @param TranslationPackage     $package Parsed package.
	 * @param PackageRelationGroup   $group Package group.
	 * @param array<string,Language> $site Destination languages keyed by code.
	 * @param LocatorResolver        $resolver Locator resolver.
	 * @param PlannedOperation[]     $operations Operations, by reference.
	 * @param PlanIssue[]            $issues Issues, by reference.
	 * @return void
	 */
	private function plan_group(
		TranslationPackage $package,
		PackageRelationGroup $group,
		array $site,
		LocatorResolver $resolver,
		array &$operations,
		array &$issues
	) {
		$key    = $group->group_key();
		$source = $group->source();

		if ( null === $source ) {
			return;
		}

		$destination     = $this->relations->find_group( $key );
		$source_solution = $resolver->resolve( $source->locator() );
		$claimed         = array();

		if ( ! $source_solution->is_resolved() ) {
			$issues[] = $this->unresolved_issue( $key, $source, $source_solution, true );

			/*
			 * A group that does not exist here cannot be created around a
			 * source nobody can find, and none of its translations have
			 * anything to attach to. A group that does exist is a different
			 * case: its targets are still plannable, and refusing to look at
			 * them because the source moved would hide work the operator can
			 * act on.
			 */
			if ( ! $destination instanceof TranslationGroup ) {
				return;
			}
		} else {
			$source_id = $source_solution->object_id();
			$claimed[ $source->object_type() . ':' . $source_id ] = $source->language();

			if ( ! $destination instanceof TranslationGroup ) {
				$this->plan_new_group( $group, $source, $source_id, $operations, $issues );
			} else {
				$this->plan_existing_source( $group, $destination, $source, $source_id, $operations, $issues );
			}
		}

		foreach ( $group->targets() as $target ) {
			$this->plan_target(
				$package,
				$group,
				$destination,
				$source,
				$target,
				$site,
				$resolver,
				$claimed,
				$operations,
				$issues
			);
		}
	}

	/**
	 * Plans the creation of a group the destination does not have.
	 *
	 * @param PackageRelationGroup $group Package group.
	 * @param PackageRelationItem  $source Package source item.
	 * @param int                  $source_id Resolved source object identifier.
	 * @param PlannedOperation[]   $operations Operations, by reference.
	 * @param PlanIssue[]          $issues Issues, by reference.
	 * @return void
	 */
	private function plan_new_group(
		PackageRelationGroup $group,
		PackageRelationItem $source,
		$source_id,
		array &$operations,
		array &$issues
	) {
		if ( $this->relations->object_is_assigned( $source->object_type(), (string) $source_id ) ) {
			$issues[] = new PlanIssue(
				PlanIssue::LEVEL_CONFLICT,
				'object_already_grouped',
				sprintf(
					/* translators: 1: object description, 2: translation group key. */
					__( '%1$s already belongs to a different translation group here, so the group %2$s cannot be created around it.', 'mclogiora' ),
					$this->describe_item( $source ),
					$group->group_key()
				),
				array(
					'group_key'   => $group->group_key(),
					'language'    => $source->language(),
					'object_type' => $source->object_type(),
					'object_id'   => (int) $source_id,
					'role'        => 'source',
				)
			);

			return;
		}

		$operations[] = new PlannedOperation(
			PlannedOperation::CREATE_GROUP,
			$group->group_key(),
			array(
				'group_key'   => $group->group_key(),
				'object_type' => $source->object_type(),
				'object_id'   => (int) $source_id,
				'language'    => $source->language(),
				'status'      => TranslationStatus::ORIGINAL,
				'locator'     => null === $source->locator() ? null : $source->locator()->to_array(),
			)
		);
	}

	/**
	 * Compares the source of a group the destination already has.
	 *
	 * @param PackageRelationGroup $group Package group.
	 * @param TranslationGroup     $destination Destination group.
	 * @param PackageRelationItem  $source Package source item.
	 * @param int                  $source_id Resolved source object identifier.
	 * @param PlannedOperation[]   $operations Operations, by reference.
	 * @param PlanIssue[]          $issues Issues, by reference.
	 * @return void
	 */
	private function plan_existing_source(
		PackageRelationGroup $group,
		TranslationGroup $destination,
		PackageRelationItem $source,
		$source_id,
		array &$operations,
		array &$issues
	) {
		$current = $destination->original();

		if ( ! $current instanceof TranslationItem ) {
			$issues[] = new PlanIssue(
				PlanIssue::LEVEL_CONFLICT,
				'group_without_source',
				sprintf(
					/* translators: %s: translation group key. */
					__( 'The translation group %s exists here with no source item, which the package cannot be reconciled against.', 'mclogiora' ),
					$group->group_key()
				),
				array( 'group_key' => $group->group_key() )
			);

			return;
		}

		if ( (int) $current->object_key() === (int) $source_id && $current->content_type() === $source->object_type() ) {
			$operations[] = new PlannedOperation(
				PlannedOperation::SKIP,
				$group->group_key() . ':' . $source->language(),
				array(
					'kind'        => 'relation_item',
					'reason'      => 'source_present',
					'group_key'   => $group->group_key(),
					'object_type' => $source->object_type(),
					'language'    => $source->language(),
					'object_id'   => (int) $source_id,
					'status'      => TranslationStatus::ORIGINAL,
					'locator'     => null === $source->locator() ? null : $source->locator()->to_array(),
				)
			);

			return;
		}

		$issues[] = new PlanIssue(
			PlanIssue::LEVEL_CONFLICT,
			'group_source_differs',
			sprintf(
				/* translators: 1: translation group key, 2: object description in the package. */
				__( 'The translation group %1$s exists here with a different source object than %2$s.', 'mclogiora' ),
				$group->group_key(),
				$this->describe_item( $source )
			),
			array(
				'group_key'           => $group->group_key(),
				'site_object_type'    => $current->content_type(),
				'site_object_id'      => (int) $current->object_key(),
				'package_object_type' => $source->object_type(),
				'package_object_id'   => (int) $source_id,
			)
		);
	}

	/**
	 * Plans one target item of a group.
	 *
	 * @param TranslationPackage     $package Parsed package.
	 * @param PackageRelationGroup   $group Package group.
	 * @param TranslationGroup|null  $destination Destination group.
	 * @param PackageRelationItem    $source Package source item.
	 * @param PackageRelationItem    $target Package target item.
	 * @param array<string,Language> $site Destination languages keyed by code.
	 * @param LocatorResolver        $resolver Locator resolver.
	 * @param array<string,string>   $claimed Objects already claimed within this group.
	 * @param PlannedOperation[]     $operations Operations, by reference.
	 * @param PlanIssue[]            $issues Issues, by reference.
	 * @return void
	 */
	private function plan_target(
		TranslationPackage $package,
		PackageRelationGroup $group,
		$destination,
		PackageRelationItem $source,
		PackageRelationItem $target,
		array $site,
		LocatorResolver $resolver,
		array &$claimed,
		array &$operations,
		array &$issues
	) {
		$key = $group->group_key();

		if ( ! $this->language_is_available( $package, $site, $target->language() ) ) {
			$issues[] = new PlanIssue(
				PlanIssue::LEVEL_CONFLICT,
				'language_missing',
				sprintf(
					/* translators: 1: language code, 2: translation group key. */
					__( 'The language %1$s is configured neither on this site nor in the package, so the %2$s item cannot be linked.', 'mclogiora' ),
					$target->language(),
					$key
				),
				array(
					'group_key' => $key,
					'language'  => $target->language(),
				)
			);

			return;
		}

		if ( $target->object_type() !== $source->object_type() ) {
			$issues[] = new PlanIssue(
				PlanIssue::LEVEL_CONFLICT,
				'group_object_type_mismatch',
				sprintf(
					/* translators: 1: translation group key, 2: source object type, 3: target object type. */
					__( 'Translation group %1$s relates a %2$s to a %3$s, which mcLogiora does not treat as a translation.', 'mclogiora' ),
					$key,
					$source->object_type(),
					$target->object_type()
				),
				array(
					'group_key'          => $key,
					'language'           => $target->language(),
					'source_object_type' => $source->object_type(),
					'target_object_type' => $target->object_type(),
				)
			);

			return;
		}

		if ( $this->taxonomies_disagree( $source, $target ) ) {
			$issues[] = new PlanIssue(
				PlanIssue::LEVEL_CONFLICT,
				'group_taxonomy_mismatch',
				sprintf(
					/* translators: 1: translation group key, 2: source taxonomy, 3: target taxonomy. */
					__( 'Translation group %1$s relates a term in %2$s to a term in %3$s. A term can only be the translation of a term in the same taxonomy.', 'mclogiora' ),
					$key,
					$source->locator()->taxonomy(),
					$target->locator()->taxonomy()
				),
				array(
					'group_key'       => $key,
					'language'        => $target->language(),
					'source_taxonomy' => $source->locator()->taxonomy(),
					'target_taxonomy' => $target->locator()->taxonomy(),
				)
			);

			return;
		}

		$resolution = $resolver->resolve( $target->locator() );

		if ( ! $resolution->is_resolved() ) {
			$issues[] = $this->unresolved_issue( $key, $target, $resolution, false );

			return;
		}

		$object_id = $resolution->object_id();
		$claim     = $target->object_type() . ':' . $object_id;

		if ( isset( $claimed[ $claim ] ) ) {
			$issues[] = new PlanIssue(
				PlanIssue::LEVEL_CONFLICT,
				'duplicate_object_in_group',
				sprintf(
					/* translators: 1: translation group key, 2: language already claiming the object, 3: language claiming it again. */
					__( 'Translation group %1$s names the same object for both %2$s and %3$s.', 'mclogiora' ),
					$key,
					$claimed[ $claim ],
					$target->language()
				),
				array(
					'group_key'      => $key,
					'language'       => $target->language(),
					'other_language' => $claimed[ $claim ],
					'object_id'      => $object_id,
				)
			);

			return;
		}

		$claimed[ $claim ] = $target->language();
		$existing          = $destination instanceof TranslationGroup
			? $this->item_in_language( $destination, $target->language() )
			: null;

		if ( $existing instanceof TranslationItem ) {
			$this->plan_occupied_slot( $group, $target, $existing, $object_id, $operations, $issues );

			return;
		}

		if ( $this->relations->object_is_assigned( $target->object_type(), (string) $object_id ) ) {
			$issues[] = new PlanIssue(
				PlanIssue::LEVEL_CONFLICT,
				'object_already_grouped',
				sprintf(
					/* translators: 1: object description, 2: translation group key. */
					__( '%1$s already belongs to a different translation group here, so it cannot also be linked into %2$s.', 'mclogiora' ),
					$this->describe_item( $target ),
					$key
				),
				array(
					'group_key'   => $key,
					'language'    => $target->language(),
					'object_type' => $target->object_type(),
					'object_id'   => $object_id,
					'role'        => 'target',
				)
			);

			return;
		}

		$operations[] = new PlannedOperation(
			PlannedOperation::LINK_ITEM,
			$key . ':' . $target->language(),
			array(
				'group_key'   => $key,
				'object_type' => $target->object_type(),
				'object_id'   => $object_id,
				'language'    => $target->language(),
				'status'      => $target->status(),
				'locator'     => null === $target->locator() ? null : $target->locator()->to_array(),
			)
		);
	}

	/**
	 * Compares a language slot the destination group already fills.
	 *
	 * @param PackageRelationGroup $group Package group.
	 * @param PackageRelationItem  $target Package target item.
	 * @param TranslationItem      $existing Destination item in that language.
	 * @param int                  $object_id Resolved object identifier.
	 * @param PlannedOperation[]   $operations Operations, by reference.
	 * @param PlanIssue[]          $issues Issues, by reference.
	 * @return void
	 */
	private function plan_occupied_slot(
		PackageRelationGroup $group,
		PackageRelationItem $target,
		TranslationItem $existing,
		$object_id,
		array &$operations,
		array &$issues
	) {
		$key = $group->group_key();

		if ( (int) $existing->object_key() !== (int) $object_id || $existing->content_type() !== $target->object_type() ) {
			$issues[] = new PlanIssue(
				PlanIssue::LEVEL_CONFLICT,
				'language_slot_occupied',
				sprintf(
					/* translators: 1: language code, 2: translation group key, 3: object description in the package. */
					__( 'The %1$s slot of translation group %2$s is already filled here by a different object than %3$s.', 'mclogiora' ),
					$target->language(),
					$key,
					$this->describe_item( $target )
				),
				array(
					'group_key'         => $key,
					'language'          => $target->language(),
					'site_object_type'  => $existing->content_type(),
					'site_object_id'    => (int) $existing->object_key(),
					'package_object_id' => (int) $object_id,
				)
			);

			return;
		}

		if ( $existing->status() === $target->status() ) {
			$operations[] = new PlannedOperation(
				PlannedOperation::SKIP,
				$key . ':' . $target->language(),
				array(
					'kind'        => 'relation_item',
					'reason'      => 'item_present',
					'group_key'   => $key,
					'object_type' => $target->object_type(),
					'language'    => $target->language(),
					'object_id'   => (int) $object_id,
					'status'      => $target->status(),
					'locator'     => null === $target->locator() ? null : $target->locator()->to_array(),
				)
			);

			return;
		}

		/*
		 * The same object in the same slot with a different status. Import does
		 * not write it, so the plan says what the two sides hold and whether the
		 * domain would even permit the move. `TranslationStatusTransitions` is
		 * the only authority on that question; asking it here rather than
		 * restating its matrix is what stops the import path becoming a second,
		 * quieter status lifecycle.
		 */
		$permitted = $this->transitions->validate( $existing->status(), $target->status() );

		$issues[] = new PlanIssue(
			PlanIssue::LEVEL_CONFLICT,
			'item_status_differs',
			sprintf(
				/* translators: 1: language code, 2: translation group key, 3: status on this site, 4: status in the package. */
				__( 'The %1$s item of translation group %2$s has the status %3$s here and %4$s in the package. Import does not change a translation status.', 'mclogiora' ),
				$target->language(),
				$key,
				$existing->status(),
				$target->status()
			),
			array(
				'group_key'          => $key,
				'language'           => $target->language(),
				'object_id'          => (int) $object_id,
				'site_status'        => $existing->status(),
				'package_status'     => $target->status(),
				'transition_allowed' => true === $permitted,
				'transition_error'   => is_wp_error( $permitted ) ? $permitted->get_error_code() : null,
			)
		);
	}

	/**
	 * Builds the issue describing a locator that could not be followed.
	 *
	 * @param string              $group_key Group key.
	 * @param PackageRelationItem $item Package item.
	 * @param LocatorResolution   $resolution Resolution outcome.
	 * @param bool                $is_source Whether the item is the group source.
	 * @return PlanIssue
	 */
	private function unresolved_issue( $group_key, PackageRelationItem $item, LocatorResolution $resolution, $is_source ) {
		$messages = array(
			/* translators: 1: object locator, 2: language code, 3: translation group key. */
			LocatorResolution::NOT_FOUND    => __( '%1$s names no object on this site, so the %2$s item of translation group %3$s cannot be placed.', 'mclogiora' ),
			/* translators: 1: object locator, 2: language code, 3: translation group key. */
			LocatorResolution::AMBIGUOUS    => __( '%1$s names more than one object on this site, so the %2$s item of translation group %3$s is ambiguous. mcLogiora will not choose between them.', 'mclogiora' ),
			/* translators: 1: object locator, unused here because the locator has no slug to print, 2: language code, 3: translation group key. */
			LocatorResolution::INCOMPLETE   => __( 'The %2$s item of translation group %3$s has no slug to look up, so it cannot be placed. WordPress leaves a draft without a slug until it is published.', 'mclogiora' ),
			/* translators: 1: object locator, 2: language code, 3: translation group key. */
			LocatorResolution::ABSENT       => __( '%1$s carries no locator, so the %2$s item of translation group %3$s cannot be placed. The exporting site could not address the object.', 'mclogiora' ),
			/* translators: 1: object locator, 2: language code, 3: translation group key. */
			LocatorResolution::TYPE_UNKNOWN => __( '%1$s names a post type or taxonomy that is not registered on this site, so the %2$s item of translation group %3$s cannot be placed.', 'mclogiora' ),
		);

		$template = isset( $messages[ $resolution->status() ] )
			? $messages[ $resolution->status() ]
			: $messages[ LocatorResolution::NOT_FOUND ];

		return new PlanIssue(
			PlanIssue::LEVEL_UNRESOLVED,
			'locator_' . $resolution->status(),
			sprintf( $template, $this->describe_item( $item ), $item->language(), $group_key ),
			array(
				'group_key'   => $group_key,
				'language'    => $item->language(),
				'object_type' => $item->object_type(),
				'role'        => $is_source ? 'source' : 'target',
				'locator'     => null === $item->locator() ? null : $item->locator()->to_array(),
				'matches'     => $resolution->ids(),
			)
		);
	}

	/**
	 * Returns whether a language exists here or arrives with the package.
	 *
	 * @param TranslationPackage     $package Parsed package.
	 * @param array<string,Language> $site Destination languages keyed by code.
	 * @param string                 $code Language code.
	 * @return bool
	 */
	private function language_is_available( TranslationPackage $package, array $site, $code ) {
		return isset( $site[ $code ] ) || $package->has_language( $code );
	}

	/**
	 * Returns whether two term items name different taxonomies.
	 *
	 * @param PackageRelationItem $source Source item.
	 * @param PackageRelationItem $target Target item.
	 * @return bool
	 */
	private function taxonomies_disagree( PackageRelationItem $source, PackageRelationItem $target ) {
		$source_locator = $source->locator();
		$target_locator = $target->locator();

		if ( null === $source_locator || null === $target_locator ) {
			return false;
		}

		if ( ObjectLocator::KIND_TERM !== $source_locator->kind() || ObjectLocator::KIND_TERM !== $target_locator->kind() ) {
			return false;
		}

		return $source_locator->taxonomy() !== $target_locator->taxonomy();
	}

	/**
	 * Returns the item a destination group holds in one language.
	 *
	 * @param TranslationGroup $group Destination group.
	 * @param string           $language Language code.
	 * @return TranslationItem|null
	 */
	private function item_in_language( TranslationGroup $group, $language ) {
		foreach ( $group->items() as $item ) {
			if ( $item instanceof TranslationItem && $item->language_code() === (string) $language ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Returns the destination languages keyed by code.
	 *
	 * @return array<string,Language>
	 */
	private function site_languages() {
		$languages = array();

		foreach ( $this->languages->get_languages() as $language ) {
			if ( $language instanceof Language ) {
				$languages[ $language->code() ] = $language;
			}
		}

		return $languages;
	}

	/**
	 * Returns the portable fields on which two languages disagree.
	 *
	 * @param PackageLanguage $package Package language.
	 * @param Language        $site Destination language.
	 * @return array<string,array{package:mixed,site:mixed}>
	 */
	private function language_differences( PackageLanguage $package, Language $site ) {
		$pairs = array(
			'locale'       => array( $package->locale(), $site->locale() ),
			'native_name'  => array( $package->native_name(), $site->native_name() ),
			'english_name' => array( $package->english_name(), $site->english_name() ),
			'direction'    => array( $package->direction(), $site->direction() ),
			'is_active'    => array( $package->is_active(), $site->is_active() ),
			'is_default'   => array( $package->is_default(), $site->is_default() ),
			'order'        => array( $package->order(), $site->order() ),
		);

		$differences = array();

		foreach ( $pairs as $field => $pair ) {
			if ( $pair[0] !== $pair[1] ) {
				$differences[ $field ] = array(
					'package' => $pair[0],
					'site'    => $pair[1],
				);
			}
		}

		return $differences;
	}

	/**
	 * Describes a package item for a plan message.
	 *
	 * @param PackageRelationItem $item Package item.
	 * @return string
	 */
	private function describe_item( PackageRelationItem $item ) {
		$locator = $item->locator();

		return null === $locator ? $item->object_type() : $locator->describe();
	}
}
