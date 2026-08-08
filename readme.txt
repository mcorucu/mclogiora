=== mcLogiora ===
Contributors: mcorucu
Tags: multilingual, translation, localization, language
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modern multilingual platform foundation for WordPress.

== Description ==

mcLogiora is planned as a modular multilingual platform for WordPress.

mcLogiora is fully free and fully open source. There is no premium edition, no paid tier, no licence key, and no feature paywall. Every feature it ships is available to every user.

This release adds the first real translation workflows on top of the platform foundation:

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

Translation workflows:

* Create a draft translation of a post, page, or supported public custom post type.
* Link content that is already translated to its source, without copying or changing it.
* Unlink a translation. This removes the relation only and never deletes, trashes, or edits the content.
* Create translated categories, tags, and supported public custom taxonomy terms, using a translated name that you provide.
* Move translations through an explicit status lifecycle: draft, needs review, translated, and needs update.
* See translations marked as needing an update when the source title, content, or excerpt changes.
* Review language status for each post directly in the posts, pages, and custom post type list tables.

Every translation action is explicit. Nothing is translated automatically, nothing is published automatically, and no content is ever deleted by a translation action.

It does not include URL rewriting, translated slugs, SEO output, language switchers, string translation, media translation, REST endpoints, AJAX handlers, or external service integrations yet.

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

It creates and links translations, but it does not translate text for you. Creating a translation makes a draft that starts from the source content, which you then translate yourself. There is no automatic or machine translation in this release.

= Does unlinking a translation delete anything? =

No. Unlinking removes only mcLogiora's relation record. The post or term keeps its content, metadata, status, and revisions.

= Does it change my published content? =

No. New translations are always created as drafts, and linking existing content does not modify it.

= Does this version create database tables or options? =

Yes. On activation, mcLogiora creates its initial language and translation relation tables through the migration runner and stores a separate database schema version. It does not create scheduled events or settings.

= Does this version use external services? =

No. There are no HTTP requests or external service integrations in this release.

== Changelog ==

= 0.9.0 =
* Added translation workflows for posts, pages, public custom post types, categories, tags, and public custom taxonomies.
* Added create, link, unlink, and status actions with capability checks, nonces, validation, and safe redirects.
* Added source change tracking that marks translations as needing an update.
* Added a language status column to supported list tables.
* Fixed a language repository contract defect and added a test suite.

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
