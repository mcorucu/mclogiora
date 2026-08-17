<?php
/**
 * Package validator.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

use McLogiora\Core\RuntimeReadiness;
use McLogiora\Relations\TranslationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Asks whether a parsed package means anything on this site.
 *
 * The parser has already established that the file is a package. That is a
 * different question from this one, and keeping them apart is what lets a
 * package be checked for shape before a destination has been chosen, and lets
 * the destination be examined without re-reading a byte of JSON.
 *
 * What this class decides is whole-package: does mcLogiora's schema exist here
 * at all, does the package speak the domain's own vocabulary, and is there
 * anything about its provenance the operator should read before the plan. Per
 * item resolution -- which post a locator names, whether a language slot is
 * taken -- belongs to the planner, which is the only place that walks the
 * relation graph, so that a plan and a validation cannot reach two different
 * conclusions about the same item.
 */
final class PackageValidator {
	/**
	 * Runtime readiness.
	 *
	 * @var RuntimeReadiness
	 */
	private $readiness;

	/**
	 * This build's plugin version.
	 *
	 * @var string
	 */
	private $plugin_version;

	/**
	 * Constructor.
	 *
	 * @param RuntimeReadiness $readiness Runtime readiness.
	 * @param string           $plugin_version This build's plugin version.
	 */
	public function __construct( RuntimeReadiness $readiness, $plugin_version ) {
		$this->readiness      = $readiness;
		$this->plugin_version = (string) $plugin_version;
	}

	/**
	 * Validates a package against this site.
	 *
	 * @param TranslationPackage $package Parsed package.
	 * @return PlanIssue[]
	 */
	public function validate( TranslationPackage $package ) {
		$issues = array();

		if ( ! $this->readiness->is_schema_ready() ) {
			/*
			 * Stated as an error rather than planned around. With no schema
			 * every lookup reports "absent", and a plan built on that would
			 * propose creating the entire package on a site that cannot store
			 * any of it -- the most confident wrong answer available.
			 */
			$issues[] = new PlanIssue(
				PlanIssue::LEVEL_ERROR,
				'schema_not_installed',
				__( 'mcLogiora has not finished installing its database tables on this site, so a package cannot be considered against it.', 'mclogiora' )
			);
		}

		$issues = array_merge( $issues, $this->validate_statuses( $package ) );
		$issues = array_merge( $issues, $this->validate_provenance( $package ) );

		return $issues;
	}

	/**
	 * Checks every item status against the canonical vocabulary.
	 *
	 * A status outside the domain's own list is an error rather than a per-item
	 * problem. The vocabulary is part of format version 1, so a package using a
	 * word mcLogiora does not have was not produced by mcLogiora, or was edited
	 * afterwards; either way the rest of its statuses are no longer worth
	 * trusting item by item.
	 *
	 * @param TranslationPackage $package Parsed package.
	 * @return PlanIssue[]
	 */
	private function validate_statuses( TranslationPackage $package ) {
		$issues = array();

		foreach ( $package->relations() as $group ) {
			foreach ( $group->items() as $item ) {
				if ( TranslationStatus::is_valid( $item->status() ) ) {
					continue;
				}

				$issues[] = new PlanIssue(
					PlanIssue::LEVEL_ERROR,
					'unknown_status',
					sprintf(
						/* translators: 1: status found in the package, 2: language code, 3: translation group key. */
						__( 'The status %1$s on the %2$s item of translation group %3$s is not a translation status mcLogiora recognises.', 'mclogiora' ),
						$item->status(),
						$item->language(),
						$group->group_key()
					),
					array(
						'group_key' => $group->group_key(),
						'language'  => $item->language(),
						'status'    => $item->status(),
					)
				);
			}
		}

		return $issues;
	}

	/**
	 * Reports what produced the package, without judging it.
	 *
	 * A plugin version difference is never an error. Format compatibility is
	 * the authority on whether a package can be read, and it has already been
	 * settled by the parser; refusing a package because the site has since been
	 * updated would make every release invalidate every package taken before
	 * it. The operator is told, and that is all.
	 *
	 * @param TranslationPackage $package Parsed package.
	 * @return PlanIssue[]
	 */
	private function validate_provenance( TranslationPackage $package ) {
		$issues   = array();
		$manifest = $package->manifest();

		if ( PackageFormat::GENERATOR !== $manifest->generator() ) {
			$issues[] = new PlanIssue(
				PlanIssue::LEVEL_WARNING,
				'foreign_generator',
				sprintf(
					/* translators: %s: generator name declared in the package. */
					__( 'The package was written by %s rather than by mcLogiora. Its format version is supported, so it will be read.', 'mclogiora' ),
					$manifest->generator()
				),
				array( 'generator' => $manifest->generator() )
			);
		}

		if ( '' !== $this->plugin_version && $manifest->generator_version() !== $this->plugin_version ) {
			$issues[] = new PlanIssue(
				PlanIssue::LEVEL_WARNING,
				'plugin_version_differs',
				sprintf(
					/* translators: 1: plugin version that produced the package, 2: plugin version reading it, 3: package format version. */
					__( 'The package was produced by mcLogiora %1$s and is being read by %2$s. Format version %3$d is supported by both.', 'mclogiora' ),
					$manifest->generator_version(),
					$this->plugin_version,
					$manifest->format_version()
				),
				array(
					'package_plugin_version' => $manifest->generator_version(),
					'site_plugin_version'    => $this->plugin_version,
				)
			);
		}

		return $issues;
	}
}
