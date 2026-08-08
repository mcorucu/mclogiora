=== mcLogiora ===
Contributors: mcorucu
Tags: multilingual, translation, localization, language
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modern multilingual platform foundation for WordPress.

== Description ==

mcLogiora is planned as a modular multilingual platform for WordPress.

This early foundation release contains the technical platform skeleton, initial persistence layer, persistence-backed language management, and database-backed relation records:

* Thin plugin bootstrap.
* PSR-4 autoloading architecture.
* Core application and service container.
* Module loader.
* Environment validation.
* Foundation contracts.
* Conditional admin assets.
* Functional language manager for plugin language data.
* Database-backed translation group and item relation records.
* Editor-independent Classic Editor, Block Editor, and Elementor adapter foundations.
* Read-only compatibility detection for editors, builders, known plugins, and the active theme.
* Setup wizard welcome and default language steps.
* Localization and project metadata structure.
* Migration-based database schema for language and relation foundations.

It does not include multilingual content translation, URL rewriting, SEO output, switchers, REST endpoints, AJAX handlers, or external service integrations yet.

== Privacy ==

mcLogiora does not track users, does not collect telemetry, does not send beacon requests, and does not contact external services in this release.

== External Services ==

This release does not connect to any external services.

Future optional translation suggestion providers must be explicitly configured by a site administrator and documented before release.

== Installation ==

1. Upload the `mclogiora` folder to `/wp-content/plugins/`.
2. Activate the plugin from the WordPress Plugins screen.
3. Open the mcLogiora admin menu to confirm the foundation screen loads.

== Frequently Asked Questions ==

= Does this version translate content? =

No. Phase 05 intentionally includes content and taxonomy foundations and placeholders only.
Phase 07 adds language management persistence only.
Phase 08 adds relation persistence only. It does not create translated WordPress objects.
Phase 09 adds dormant editor adapter and compatibility foundations only. It does not register editor UI or load editor assets.

= Does this version create database tables or options? =

Yes. On activation, mcLogiora creates its initial language and translation relation tables through the migration runner and stores a separate database schema version. It does not create scheduled events or settings.

= Does this version use external services? =

No. There are no HTTP requests or external service integrations in this release.

== Changelog ==

= 0.8.0 =
* Added editor-independent adapter contracts for Classic Editor, Block Editor, and Elementor.
* Added read-only compatibility detection and the Compatibility dashboard.
* Added editor, compatibility, detection, and ADR documentation.

= 0.7.0 =
* Added persistence-backed translation group and item operations, relation integrity checks, cache invalidation, Translation Manager read updates, and relation health data.

= 0.6.0 =
* Added persistence-backed language CRUD, validation, admin language management, setup default-language persistence, cache invalidation, and language health data.

= 0.5.0 =
* Added migration runner, installer architecture, database version management, initial schema, database-backed repositories, cache foundation, and database health foundation.

= 0.4.0 =
* Added Content and Taxonomy Translation foundations, support registries, exclusion rules, dashboard cards, and Translation Manager support previews.

= 0.3.0 =
* Added Translation Relation foundation, mock repository/service layer, source-change concepts, and Translation Manager placeholder.

= 0.2.0 =
* Added core kernel refinements, capability foundation, Language Manager foundation, and Setup Wizard placeholder.

= 0.1.0 =
* Initial foundation infrastructure.
