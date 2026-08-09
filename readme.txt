=== mcLogiora ===
Contributors: mcorucu
Tags: multilingual, translation, localization, language
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.11.0
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

Interface strings, media, menus, and widgets:

* Scan your theme or a plugin for translatable interface strings, on request only.
* Translate those strings from a searchable String Translation screen.
* Translate image and file metadata per language: title, alternative text, caption, and description. The file itself is shared, never duplicated.
* Create a translated navigation menu that keeps the item order and nesting of its source.
* Translate the text of Text, Custom HTML, and Block widgets. Widget types mcLogiora does not understand are listed as unsupported and are never modified.

Every translation action is explicit. Nothing is translated automatically, nothing is published automatically, no source file is ever modified, and no content is ever deleted by a translation action. Scanning only ever runs when you ask for it, never during normal site traffic.

Multilingual URLs and switching:

* Serve translated content under language directories such as /tr/hakkimizda/, while your default language keeps the URLs it already has.
* Give each translation its own slug, edited normally in WordPress.
* Add a language switcher with a shortcode, a block, a widget, or a template tag, in list, dropdown, compact, or pill styles.
* Show translated interface strings, image metadata, widget text, and menus automatically once a visitor is in that language.

A translated URL with no translation behind it returns a normal 404 rather than quietly showing you the original language. Menus are the one exception: an untranslated menu still appears, so navigation never disappears.

mcLogiora never guesses your visitors' language from their location and never redirects them automatically. Flags are off by default, because a language is not a country.

It does not include hreflang, canonical tags, sitemap integration, SEO plugin integrations, REST endpoints, AJAX handlers, or external service integrations yet.

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

= Will installing this change my existing URLs? =

No. Your default language keeps the URLs it already has. Only additional languages get a directory prefix, unless you explicitly turn that on for the default language too.

= What happens if a page is not translated yet? =

The translated URL returns a normal 404, and the language switcher does not offer that language. mcLogiora will not show you one language's content under another language's address.

= Does scanning modify my theme or plugins? =

No. Scanning reads source files and never writes to them. It is limited to a single theme or plugin directory that you choose, cannot be pointed at arbitrary paths, and requires a high-trust capability because it reads source code.

= Are my images duplicated when I translate them? =

No. One file serves every language. Only the text around it, such as the alternative text and caption, is stored per language.

= Does this version create database tables or options? =

Yes. On activation, mcLogiora creates its language, translation relation, string, media, and widget translation tables through the migration runner and stores a separate database schema version. It does not create scheduled events or settings.

= Does this version use external services? =

No. There are no HTTP requests or external service integrations in this release.

== Changelog ==

= 0.11.0 =
* Added multilingual URL routing with language directories and translated slugs.
* Added an accessible language switcher as a shortcode, block, widget, and template tag.
* Added front-end display of translated strings, media metadata, widgets, and menus.
* Added a Languages & URLs settings screen.

= 0.10.0 =
* Added string translation with an explicit, confined source scanner and a String Translation screen.
* Added per-language media metadata without duplicating any file.
* Added translated navigation menus that preserve order and nesting.
* Added widget translation for Text, Custom HTML, and Block widgets through an adapter model.
* Added a database migration for the new string, media, and widget tables.
* Added WordPress integration tests and a real translation catalogue.

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
