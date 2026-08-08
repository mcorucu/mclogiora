# Changelog

## Unreleased

- Canonicalized the project into a standalone Git repository published at <https://github.com/mcorucu/mclogiora>, with the development WordPress installation referencing it through a symlink.
- Recorded the permanent fully free and open-source product model in ADR 0009, covering the absence of licence keys, feature gates, upgrade nags, default tracking or telemetry, remote kill switches, and SaaS dependencies for core functionality.
- Removed obsolete premium and paid add-on terminology from planning, architecture, and admin copy. WooCommerce and LMS support are now described as future free compatibility modules rather than premium add-ons. No feature gate, licence check, or scope boundary changed.
- Reconciled the development roadmap with actual executed history. Phases 01 through 09 now reflect what was built, and Phases 10 through 18 are defined.
- Added development workflow documentation covering the repository layout, branch model, validation steps, and prohibited operations.
- Documented that the original Skylearn design authority file is unavailable, froze the existing admin UI as the reference implementation, and recorded design-system recovery as a separate future task.

No functional, schema, migration, or runtime behavior changes are included.

## 0.8.0

- Added the Phase 09 editor integration foundation with editor contracts, context, registry, factory, detector, and manager.
- Added dormant Classic Editor, Block Editor, and Elementor adapters with no editor hooks, scripts, panels, or content writes.
- Added read-only compatibility detection for editors, builders, known plugins, and the active theme.
- Added the Skylearn-aligned Compatibility dashboard and editor surface placeholders.
- Added editor architecture, compatibility, detection strategy, and ADR documentation.

## 0.7.0

- Added Phase 08 Translation Relation Persistence.
- Added database-backed translation group operations for empty groups, source groups, UUID lookup, internal ID lookup, metadata updates, and soft archive.
- Added database-backed translation item operations for attaching existing objects, status changes, language changes, metadata updates, group/status/object lookups, source lookup, safe detach, and assignment checks.
- Added relation integrity rules for one source per group, one active item per group language, active language requirements, disabled-language protection, and one active group assignment per object.
- Added relation cache invalidation after successful group and item writes.
- Updated Translation Manager to read database-backed relation records while keeping write actions as placeholders.
- Extended database health data with relation counts and integrity-check placeholders.
- Added relation persistence architecture and ADR documentation.

## 0.6.0

- Added Phase 07 Language Persistence and Management.
- Added controlled language create, update, enable, disable, delete guard, default-language, reorder, lookup-by-code, and lookup-by-locale repository operations.
- Added persistence-backed Languages admin screen actions with nonces, capability checks, validation feedback, and Skylearn-aligned forms.
- Added setup wizard Welcome and Default Language steps with default language persistence.
- Added language cache invalidation after successful writes.
- Added health data for total languages, active languages, and default-language configuration.
- Added language persistence architecture, CRUD lifecycle, validation, cache strategy, and ADR documentation.

## 0.5.0

- Added Phase 06 Database Architecture and Persistence Layer.
- Added installer, migration runner, migration interface, schema builder, version checker, and database version manager.
- Added initial migration for languages, translation groups, and translation items.
- Added UUID-based translation group identifiers.
- Added database-backed language and relation repositories while preserving repository interfaces.
- Added object-cache decorators and internal database health check foundation.
- Added database schema, ERD, index, migration strategy, and persistence ADR documentation.

## 0.4.0

- Added Phase 05 Content and Taxonomy Translation foundations.
- Added content type model, registry contract, registry implementation, support detectors, exclusion rules, and placeholder service.
- Added taxonomy model, registry contract, registry implementation, support detector, exclusion rules, and placeholder service.
- Added dashboard placeholder cards for translatable content types, translatable taxonomies, excluded integrations, and future editor support.
- Added Translation Manager support overview placeholders for content and taxonomy readiness.
- Added documentation for content, taxonomy, exclusion policy, developer API notes, and free vs add-on boundaries.

## 0.3.0

- Added Phase 04 Translation Relation foundation.
- Added translation group and translation item value objects.
- Added relation content type and status constants.
- Added source-change metadata concepts and a needs-update detector interface.
- Added mock in-memory relation repository and relation service contracts.
- Added the Translation Manager placeholder with Skylearn-aligned filters, status table, and disabled future actions.
- Added relation architecture documentation and ADR for relation model decisions.

## 0.2.0

- Added Phase 03 core kernel refinements for admin screen registration, capability resolution, and feature flags.
- Added language domain foundations: language entity, status constants, repository and service contracts, in-memory repository, locale validation, and RTL detection.
- Added the Languages admin screen with Skylearn-aligned placeholder UI and mock data only.
- Added the Setup Wizard placeholder with six planned steps.
- Added architecture documentation and ADR for the language model and Phase 03 persistence boundary.

## 0.1.0

- Added the Phase 02 foundation bootstrap.
- Added PSR-4 autoload architecture.
- Added core application, service container, module loader, lifecycle classes, and environment validation.
- Added foundation contracts for modules, logging, builders, and translation providers.
- Added conditional admin assets and placeholder admin pages.
- Added localization, docs, tests, and WordPress.org compliance scaffolding.
