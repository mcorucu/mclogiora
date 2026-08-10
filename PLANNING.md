# mcLogiora Planning

Current phase: Phase 13 complete (SEO, hreflang, Canonical & Sitemap Integration, v0.12.0). Next planned phase: Phase 14.

This document is the product and engineering plan for mcLogiora, a free and open-source multilingual platform for WordPress. It contains planning guidance only; implementation lives in `src/`, and architectural decisions are recorded in `docs/adr/`.

Product model: mcLogiora is permanently free and fully open source under a GPL-compatible licence. There is no premium edition, no paid tier, no licence-key system, and no feature paywall. See `docs/adr/0009-fully-open-source-product-model.md`.

Canonical repository: <https://github.com/mcorucu/mclogiora>. Local development paths and the WordPress environment symlink convention are documented in `docs/development/git-workflow.md` rather than hard-coded here.

Official admin UI design system: Skylearn. The original `skylearn-DESIGN.md` authority file is currently unavailable; see `docs/design/README.md` for the current status and the freeze policy that applies until it is restored.

## 1. Project Vision

mcLogiora should become a modern multilingual platform for WordPress, not a narrow translation utility and not a clone of WPML. The long-term goal is a cleaner, faster, more maintainable multilingual layer that feels native to WordPress while giving site owners, editors, translators, and developers clear tools for managing language relationships, translated content, translated strings, translated URLs, SEO metadata, and language switchers.

mcLogiora should support the essential multilingual needs of content-driven WordPress sites: posts, pages, custom post types, taxonomies, menus, widgets, media, strings, URLs, slugs, SEO metadata, hreflang, canonical URLs, OpenGraph compatibility, JSON-LD compatibility, REST API access, AJAX workflows, import/export, setup guidance, system status, translation suggestions, translation manager screens, language manager screens, translation status, and multiple switcher surfaces.

The platform should remain modular enough to add further integrations later without turning the core into a dependency web. Modularity here is an architectural concern only, never a commercial one: every module mcLogiora ships is free and open source. Optional and third-party integrations must be adapters around stable internal contracts.

## 2. Plugin Philosophy

mcLogiora should prioritize:

- Performance before feature volume.
- Explicit user review before publication.
- Native WordPress data structures where they are reliable.
- Small, testable modules instead of large service classes.
- Clear extension points for builders, SEO plugins, importers, exporters, suggestion providers, and switcher renderers.
- WordPress.org compliance from day one.
- Accessibility and translation readiness as baseline requirements.
- No telemetry, no tracking, no hidden external calls, and no required cloud account.
- External services only when a site owner explicitly configures a provider and brings their own API key.

The user experience should be friendly, legible, and confidence-building. The admin UI must follow Skylearn: bright sky blue primary actions, sun yellow only for achievements/rewards, leaf green for progress and success, gentle coral for recoverable errors, white and cool-tinted surfaces, generous spacing, rounded components, clear copy, large tap targets, visible focus states, and no harsh red or corporate-gray sterility.

## 3. Architecture

The proposed architecture is a modular WordPress plugin with a small kernel and separate domain modules. The kernel should own bootstrapping, module registration, shared service access, configuration, capabilities, hooks, asset registration, and lifecycle operations. Each domain module should register itself through a module interface.

Recommended layers:

- Kernel: plugin lifecycle, module loading, shared container, feature flags, environment checks.
- Domain: languages, translation relations, content translations, taxonomy translations, media translations, string translations, URL and slug translations.
- Integration: editor adapters, builder adapters, SEO adapters, REST controllers, AJAX handlers, CLI commands.
- Admin: screens, setup wizard, status dashboard, translation manager, settings, import/export.
- Presentation: language switcher renderers, admin components, block assets.
- Infrastructure: repositories, caches, schema management, import/export serializers, suggestion providers.

Core services should depend on interfaces rather than concrete integrations. Builder support, SEO support, translation suggestion providers, and switcher views should be adapter-based and loaded only when needed.

The code should be prepared for Composer autoloading during development, but the WordPress.org distribution must not depend on runtime package installation. Any bundled dependencies must be GPL-compatible and documented.

## 4. Folder Structure

Proposed future folder structure:

```text
mclogiora/
  mclogiora.php
  readme.txt
  uninstall.php
  composer.json
  phpcs.xml.dist
  phpstan.neon.dist
  PLANNING.md
  assets/
    css/
      admin.css
      editor.css
      switcher.css
    js/
      admin/
      editor/
      blocks/
      switcher/
    icons/
  languages/
  src/
    Core/
    Admin/
    Setup/
    Languages/
    Relations/
    Content/
    Taxonomies/
    Media/
    Strings/
    Urls/
    Seo/
    Rest/
    Ajax/
    Editors/
    Builders/
    Switchers/
    Suggestions/
    ImportExport/
    SystemStatus/
    Capabilities/
    Security/
    Support/
  templates/
    admin/
    switchers/
  tests/
    unit/
    integration/
```

This is a proposal only. The folder has not been scaffolded in this phase beyond the location required to store this planning document.

## 5. Database Model Proposal

Use custom tables only where WordPress post meta, term meta, and options would create slow queries or ambiguous relationships. A multilingual plugin needs fast relation lookups, status queries, source lookup, and reverse lookup by translated object.

Proposed tables:

- `wp_mclogiora_languages`: enabled languages, locale, slug, native name, text direction, status, fallback language, ordering.
- `wp_mclogiora_translation_groups`: canonical translation group records for related objects.
- `wp_mclogiora_translations`: object-level mapping between a translation group, object type, object ID, language code, source flag, status, and timestamps.
- `wp_mclogiora_string_groups`: logical grouping for strings by domain, source, plugin/theme, or context.
- `wp_mclogiora_strings`: original strings and metadata.
- `wp_mclogiora_string_translations`: translated strings per language with status and reviewer metadata.
- `wp_mclogiora_url_translations`: translated URL paths, slugs, redirects, conflict state, and canonical target metadata.
- `wp_mclogiora_jobs`: optional translation workflow records for assignments, suggestions, review state, and import/export batches.

Use WordPress APIs for schema creation through `dbDelta()` where appropriate. Store plugin options in a single versioned option array, with selected high-read values cached through transients or object cache groups.

Indexes should be planned before implementation:

- Unique object/language mapping on object type, object ID, and language.
- Translation group and language lookup.
- Status lookup for manager screens.
- URL path and language lookup.
- String hash, domain, and language lookup.

## 6. Translation Relation Model

The recommended relation model is group-based. Every translatable entity belongs to a translation group. A group can contain one source item and zero or more translated items.

Example:

```text
group_id: 123
  post:42   en_US source published
  post:87   tr_TR translated draft
  post:103  de_DE translated needs_review
```

Supported object types should include:

- `post`
- `term`
- `menu_item`
- `media`
- `widget`
- `template`
- `template_part`
- `string`
- future custom object types through filters

Statuses should be explicit:

- `source`
- `draft`
- `in_progress`
- `needs_review`
- `reviewed`
- `published`
- `outdated`
- `missing`

The source object should not be assumed to be the default language forever. Sites may need to change source language per group or migrate content. The model should support source reassignment with guarded admin workflows.

## 7. Module List

Core modules (all free and open source):

- Core Loader
- Language Manager
- Translation Relations
- Content Translation
- Taxonomy Translation
- Menu Translation
- Widget Translation
- Media Translation
- String Translation
- URL Translation
- Slug Translation
- Translation Manager
- Translation Status
- Translation Suggestions
- SEO Integration
- hreflang and Canonical
- OpenGraph Compatibility
- JSON-LD Compatibility
- REST API
- AJAX Actions
- Setup Wizard
- Import/Export
- System Status
- Language Switchers
- Block Theme and FSE Support
- Gutenberg Adapter
- Classic Editor Adapter
- Elementor Adapter
- ACF Adapter
- Developer API
- Security and Capabilities
- Cache and Performance
- Uninstall and Data Retention

Prepared adapter modules:

- Bricks
- Beaver Builder
- Divi
- Oxygen
- WPBakery
- Kadence
- GenerateBlocks
- Spectra
- SeedProd
- Avada

Later integration modules (WooCommerce, LMS platforms, and similar) are deferred for scope and stability reasons only. When they are implemented they will be free and open source, and they must be registered through the same module contracts as everything else.

## 8. Admin Screen Map

All admin screens must follow the Skylearn design system while adapting it to professional WordPress admin use. The UI should be warm, clear, spacious, accessible, and efficient.

Global admin shell:

- Use Atkinson Hyperlegible or a locally bundled GPL-compatible equivalent if licensing permits; otherwise provide a close system fallback.
- Use Sky `#3B82F6` for primary actions, active navigation, and focus states.
- Use Leaf `#22C55E` for progress, complete status, and success.
- Use Sun `#FBBF24` only for achievements, setup completion, or positive milestone badges.
- Use Coral `#F87171` for gentle recoverable errors.
- Use minimum 16px text and 56px primary tap targets where practical.
- Use rounded cards, clear outlines, and conditional motion respecting `prefers-reduced-motion`.

Proposed screens:

- Setup Wizard: language selection, default language, URL mode, SEO compatibility check, string scan option, external services disclosure.
- Dashboard: language coverage, translation progress, missing translations, outdated translations, recent work.
- Languages: add/edit languages, locale, native name, slug, direction, fallback, ordering, enabled state.
- Translation Manager: filterable table by object type, language, status, assigned user, outdated state.
- Content Translation Detail: source/target comparison, status, notes, suggestions, save draft/review actions.
- String Translation: domains, string search, context, translations, status, import/export.
- Media Translation: attachment metadata, alt text, caption, localized file replacement if enabled.
- URL and Slug Translation: path conflicts, slug editor, redirect hints, permalink diagnostics.
- Switchers: shortcode, block, widget, menu item, PHP/template tag examples, preview, style options.
- SEO: hreflang, canonical, OpenGraph, JSON-LD, plugin compatibility settings.
- Suggestions: provider list, BYO API key settings, per-provider disclosure, test connection, usage limits.
- Import/Export: portable JSON/CSV packages, language filters, dry-run validation.
- System Status: environment, table status, cache status, REST routes, active adapters, conflicts.
- Settings: data retention, capabilities, modules, performance, debug logging.

## 9. Editor Integration Plan

Gutenberg support should be first-class.

Block Editor:

- Sidebar panel showing current language, translation group, status, source relation, and available translations.
- "Create translation" flow from the source post.
- "Open source" and "Open translation" links.
- Outdated source detection based on source modified time and optional content hash.
- REST-backed status updates with nonce and capability checks.
- Block-level metadata should not be altered unless a specific block adapter owns that behavior.

Classic Editor:

- Meta box with language, relation, status, source/target links, and create translation actions.
- Submit box status summary.
- Compatibility with Quick Edit and Bulk Edit where safe.

Elementor:

- Adapter that detects Elementor data, duplicates source layout into target content only when user requests it, and allows translated text review.
- No hard dependency on Elementor classes during core load.

ACF:

- Field group compatibility layer.
- Per-field translation behavior: copy, translate, ignore, synchronize.
- Avoid mutating ACF storage directly except through supported APIs when available.

## 10. Builder Adapter Architecture

Builder integrations must use adapters and must never be tightly coupled into Core.

Recommended contract:

```text
BuilderAdapterInterface
  get_id()
  get_label()
  is_available()
  supports_object_type($object_type)
  detect_translatable_fields($object_id)
  copy_source_structure($source_id, $target_id, $context)
  extract_segments($object_id, $context)
  apply_translated_segments($object_id, $segments, $context)
  get_status($object_id)
```

Adapter registry:

- Loaded by the Builders module after plugins are loaded.
- Each adapter checks availability without fatal errors.
- Core receives normalized segments, not builder-specific data.
- Adapters may enqueue admin assets only on relevant screens.
- Third parties can register adapters through a documented filter or action.

Prepared adapters:

- Gutenberg Adapter
- Classic Editor Adapter
- Elementor Adapter
- ACF Adapter
- Bricks Adapter
- Beaver Builder Adapter
- Divi Adapter
- Oxygen Adapter
- WPBakery Adapter
- Kadence Adapter
- GenerateBlocks Adapter
- Spectra Adapter
- SeedProd Adapter
- Avada Adapter

## 11. Language Switcher Architecture

Switcher support should be renderer-based. The same switcher data provider should feed all presentation surfaces.

Surfaces:

- Shortcode
- Widget
- Block
- PHP function
- Template tag
- Menu item
- Floating switcher

Display modes:

- Dropdown
- List
- Pills
- Compact current language
- Flags with text
- Native names
- Translated names
- Current language indicator
- Custom CSS class support

Architecture:

- `SwitcherDataProvider` resolves current language, available translations, URLs, flags, names, active state, and disabled/missing state.
- `SwitcherRendererInterface` renders specific layouts.
- Block and widget UIs store only presentation settings; URLs must be resolved dynamically.
- Accessibility is mandatory: keyboard support, labels, current page indication, `aria-current`, no color-only state, adequate target size.
- Conditional asset loading: switcher CSS/JS only when a switcher is present.

## 12. Translation Suggestions Architecture

Translation Suggestions are allowed in the free version, but they must never automatically publish translations.

Required flow:

```text
User presses "Suggest Translation"
  -> AJAX or REST request with nonce/capability validation
  -> configured provider
  -> suggested translation returned
  -> user reviews
  -> user saves
```

Provider rules:

- Bring Your Own API Key only.
- External services are optional and disabled by default.
- No background sending of site content.
- No telemetry or hidden usage tracking.
- Each provider requires clear External Services documentation for WordPress.org review.
- API keys stored securely with autoload disabled where possible.
- Failed provider calls should produce gentle, actionable errors.

Provider interface:

```text
SuggestionProviderInterface
  get_id()
  get_label()
  is_configured()
  supports_language_pair($source_language, $target_language)
  suggest($source_text, $source_language, $target_language, $context)
```

The UI should make suggestion provenance clear and require user action to apply and save.

## 13. SEO Architecture

SEO support should provide correct multilingual signals without forcing users into a single SEO plugin.

Core SEO responsibilities:

- hreflang tags for each available translation.
- `x-default` option.
- Language-specific canonical URLs.
- Avoid duplicate canonicals.
- OpenGraph locale and alternate locale compatibility.
- JSON-LD language-aware fields where safe.
- Sitemap integration through WordPress core and adapter hooks.
- Translated slugs and URLs.
- Redirect handling for changed translated slugs.

SEO adapters:

- WordPress core SEO primitives.
- Popular SEO plugins through optional adapters.
- Existing local SEO plugin awareness can be planned later, but mcLogiora should not depend on any one SEO plugin.

Conflict strategy:

- Detect active SEO plugins.
- Avoid double-rendering tags.
- Provide diagnostics in System Status.
- Allow developers to disable mcLogiora SEO output by filter.

## 14. REST API Architecture

REST routes should be namespaced and versioned:

```text
/mclogiora/v1/languages
/mclogiora/v1/translations
/mclogiora/v1/relations
/mclogiora/v1/strings
/mclogiora/v1/suggestions
/mclogiora/v1/switcher
/mclogiora/v1/import
/mclogiora/v1/export
/mclogiora/v1/status
```

Principles:

- Use WordPress REST API permission callbacks on every route.
- Validate and sanitize all input.
- Escape output in rendered contexts.
- Return structured errors with stable codes.
- Keep public switcher endpoints read-only and cacheable.
- Keep suggestion endpoints authenticated and nonce-protected.
- Do not expose API keys, raw provider responses, private post data, or unpublished translation content to unauthorized users.

REST should power Gutenberg panels, setup wizard interactions, suggestions, import/export dry runs, and selected admin table updates.

## 15. Developer API

mcLogiora should provide a stable developer API before encouraging third-party extensions.

Planned public functions:

- `mclogiora_get_current_language()`
- `mclogiora_get_default_language()`
- `mclogiora_get_languages()`
- `mclogiora_get_translation($object_id, $object_type, $language)`
- `mclogiora_get_translation_group($object_id, $object_type)`
- `mclogiora_get_language_url($language, $object_id = null)`
- `mclogiora_render_language_switcher($args = array())`

Planned filters/actions:

- `mclogiora_register_modules`
- `mclogiora_register_builder_adapters`
- `mclogiora_register_seo_adapters`
- `mclogiora_register_suggestion_providers`
- `mclogiora_supported_object_types`
- `mclogiora_language_switcher_args`
- `mclogiora_language_url`
- `mclogiora_translation_status`
- `mclogiora_should_render_hreflang`
- `mclogiora_external_service_disclosures`

Developer documentation should include examples for custom post types, custom taxonomies, builder adapters, suggestion providers, SEO output overrides, and custom switcher rendering.

## 16. Performance Strategy

Performance must be designed into the data model and loading strategy.

Key strategies:

- Load only the kernel on every request; lazy-load modules by context.
- Register admin assets globally, enqueue conditionally.
- Register frontend assets only when switchers or frontend features are present.
- Use indexed relation tables for translation lookups.
- Cache language lists and translation relation maps.
- Avoid scanning all posts during normal requests.
- Use scheduled or manually triggered indexing for strings when needed.
- Use object cache groups for language and relation data.
- Keep REST responses paginated.
- Avoid autoloading large option arrays or API keys.
- Use batch processing for import/export and string scans.
- Design queries for large sites from the beginning.

Target behaviors:

- Frontend lookup of current language and alternate URLs should be constant or near-constant time.
- Admin Translation Manager should remain paginated and filter-indexed.
- No external provider calls should happen on page load.
- No builder parsing should run unless a translation workflow asks for it.

## 17. Security Strategy

Security must follow WordPress.org expectations and WordPress Coding Standards.

Requirements:

- Capability checks for every admin action.
- Nonce verification for all state-changing admin, AJAX, and REST requests.
- Strict sanitization for request data.
- Escaping at output based on context.
- Prepared SQL for all custom queries.
- No direct file execution; include guards in PHP files.
- API keys stored in protected options, never displayed after save.
- External service calls only after explicit configuration.
- No telemetry, tracking, beaconing, or hidden remote calls.
- Import validation with dry-run before write operations.
- Clean uninstall with user-controlled data removal policy.
- Principle of least privilege for custom capabilities.
- Avoid storing provider response logs unless debug mode is explicitly enabled.

Suggested capabilities:

- `mclogiora_manage_languages`
- `mclogiora_manage_translations`
- `mclogiora_translate_content`
- `mclogiora_review_translations`
- `mclogiora_manage_settings`
- `mclogiora_import_export`

Map defaults to administrators and editors conservatively during implementation.

## 18. WordPress.org Compliance Checklist

Planning checklist:

- GPL-compatible licensing.
- No tracking or telemetry.
- No obfuscated code.
- No required external service.
- External Services section in `readme.txt`.
- Clear BYO API key behavior.
- Proper sanitization.
- Proper escaping.
- Capability checks.
- Nonce verification.
- Translation-ready strings.
- Accessibility-ready admin UI.
- Conditional asset loading.
- Clean uninstall policy.
- Coding Standards via PHPCS.
- Static analysis via PHPStan.
- Unit and integration testing readiness.
- No bundled non-GPL assets.
- No minified-only source without originals.
- No remote code execution or loading executable code from third-party services.
- No automatic publishing of machine translations.
- No database writes on file include.
- No activation side effects beyond required setup.
- Clear privacy documentation.

## 19. Future Free Integration Modules

mcLogiora has no premium edition and no paid modules. The candidates below are postponed for scope, stability, and maintenance reasons only. When any of them is implemented it will be free and open source, GPL-compatible, and subject to the same review standards as the rest of the plugin.

Postponed integration candidates:

- WooCommerce products.
- WooCommerce orders.
- WooCommerce attributes.
- WooCommerce checkout.
- WooCommerce cart.
- WooCommerce emails.
- LearnDash.
- Tutor LMS.
- Lifter LMS.
- Sensei LMS.
- Membership integrations.
- Course system integrations.
- Cloud synchronization.
- Translation Memory.
- AI-assisted translation workflows.
- Team workflow automation.
- Advanced analytics.

These may ship inside mcLogiora or as separate companion plugins, depending on which is better for performance and maintenance. That packaging choice is a technical decision, never a commercial one.

Two items from the earlier plan are deliberately dropped rather than postponed:

- Bulk automatic publishing of machine translations remains out of scope in every edition. Machine output must always be reviewed by a human before publication.
- Any feature whose purpose would be to justify a paid tier is out of scope by definition.

The core should expose adapter contracts that make these integrations possible later without carrying their logic in the shipped WordPress.org package.

## 20. Development Phases

The original twelve-phase sequence in this document drifted from what was actually built: two persistence phases were inserted during execution, which shifted every later number. The list below is the reconciled roadmap. Phases 01-09 are historical fact, verified against `CHANGELOG.md`, the plugin version header, the ADR set, and the source tree. Phases 10-18 are planned and not yet started.

### Completed phases (verified against repository evidence)

Phase 01: Discovery & Architecture Lock

- Architectural scope, risks, data model proposal, and implementation boundaries established.

Phase 02: Foundation & Plugin Infrastructure — v0.1.0

- Plugin bootstrap, PSR-4 autoloading, core application, service container, module loader, lifecycle classes, environment validation, foundation contracts, conditional admin assets, localization, and WordPress.org compliance scaffolding.

Phase 03: Core Kernel & Language Manager Foundation — v0.2.0

- Admin screen registration, capability resolution, feature flags, language entity and status model, repository and service contracts, in-memory repository, locale validation, RTL detection, Languages screen, and Setup Wizard placeholder.

Phase 04: Translation Relation Foundation — v0.3.0

- Translation group and item value objects, relation content type and status constants, source-change metadata concepts, needs-update detector interface, in-memory relation repository, relation service contracts, and the Translation Manager placeholder.

Phase 05: Content & Taxonomy Translation Foundation — v0.4.0

- Content type model, registries and contracts, support detectors, exclusion rules, taxonomy model and registry, placeholder services, and dashboard support cards.

Phase 06: Database Architecture & Persistence Layer — v0.5.0

- Installer, migration runner and interface, schema builder, version checker, database version manager, initial migration for languages/groups/items, UUID group identifiers, database-backed repositories, object-cache decorators, and database health foundation.

Phase 07: Language Persistence & Management — v0.6.0

- Language create, update, enable, disable, delete guard, default-language, reorder, and lookup operations; persistence-backed Languages admin actions with nonces, capability checks, and validation; setup wizard welcome and default-language steps; cache invalidation; language health data.

Phase 08: Translation Relation Persistence — v0.7.0

- Database-backed group and item operations, relation integrity rules, safe detach and soft archive, relation cache invalidation, Translation Manager database-backed reads, and relation health data. Write actions intentionally remain placeholders.

Phase 09: Editor Integration & Compatibility Foundation — v0.8.0

- Editor contracts, context, registry, factory, detector, and manager; dormant Classic Editor, Block Editor, and Elementor adapters with no hooks, scripts, panels, or content writes; read-only compatibility detection for editors, builders, known plugins, and the active theme; Compatibility dashboard.

Phase 10: Content & Taxonomy Translation Workflows - v0.9.0

- Create, link, unlink, and status workflows for posts, pages, public custom post types, categories, tags, and public custom taxonomies; an explicit status state machine; conservative source change tracking; a list table language column; compensating rollback; and the first real test suite. See `docs/adr/0010-content-taxonomy-translation-workflows.md`.

Phase 11: String, Media, Menu & Widget Translation - v0.10.0

- String registry with context-aware identity, an explicit token-based source scanner, the String Translation screen, per-language media metadata with no file duplication, the featured image reference policy, translated menus with hierarchy remapping, a widget adapter model, a new schema migration, a real POT catalogue, and the WordPress integration test harness. See `docs/adr/0011-string-media-menu-widget-translation.md`.

Phase 12: URL Routing, Slug Translation & Language Switching - v0.11.0

- One authoritative language context, directory URLs with an unprefixed default language, strict prefix validation, conservative rewrite flushing, 404 for missing translations, translated post and term slugs, a single translated URL generator, front-end application of the Phase 11 translations, and an accessible language switcher across four surfaces and four styles. See `docs/adr/0013-routing-slugs-language-switching.md`.
- An explicit install-safe runtime lifecycle. `RuntimeReadiness` is the single authority on installation state, schema availability, and request context; front-end modules register nothing while WordPress is installing, and a missing schema renders as ordinary monolingual WordPress. See `docs/adr/0014-install-safe-runtime-lifecycle.md`.
- Three defects the boot hang had been hiding: the routing path query var was never registered, plugin activation installed only the Phase 10 schema, and permalinks carried their language prefix twice. All three are covered by regression tests. Widget titles, the document `lang` attribute, and the `_load_textdomain_just_in_time` notice are documented as known gaps in ADR 0014.

Phase 13: SEO, hreflang, Canonical & Sitemap Integration - v0.12.0

- Document language and request locale, BCP 47 language tags, hreflang alternates with a self-referential entry and an x-default policy, self-referential canonical URLs, WordPress core sitemap URL correction, OpenGraph locale, and per-concern ownership adapters for the five common SEO plugins. Carries the two boot problems Phase 12.1 left open: activation now surfaces installer failures, and admin screens no longer translate while registering. See `docs/adr/0015-multilingual-seo-integration.md`.

### Planned phases

- hreflang output, canonical handling, OpenGraph and JSON-LD compatibility, sitemap integration, and coexistence with established SEO plugins.

Phase 14: Editor Translation UX

- Gutenberg, Classic Editor, Elementor, and ACF translation surfaces; translation status UI; create and edit translation actions built on the Phase 09 adapter foundation.

Phase 15: Extended Builder Compatibility

- Bricks, Divi, Beaver Builder, WPBakery, Oxygen, Kadence, GenerateBlocks, Spectra, SeedProd, and Avada, each only as far as it can be supported safely and maintained honestly. Breadth here is explicitly subordinate to correctness.

Phase 16: Translation Suggestions

- Suggestion provider interfaces, AJAX/REST suggestion workflow, optional external providers, bring-your-own API credentials, and a review-only UI. Suggestions never auto-publish, and manual translation must remain fully functional with no provider configured.

Phase 17: Developer & Operations Layer

- REST API, import/export with dry-run, WP-CLI commands, System Status, Site Health integration, and the public developer API.

Phase 18: Hardening, Performance, Accessibility & WordPress.org Release Preparation

- Plugin Check, PHPCS, PHPStan, unit and integration tests, accessibility audit, performance profiling, readme and privacy review, external-service disclosure review, and release preparation.

## Discovery Notes

These notes record the Phase 01 discovery environment. They are historical context; the current canonical paths and repository layout are documented in `docs/development/git-workflow.md`.

- Development uses a local WordPress installation kept outside this repository; the plugin directory is a symlink to the standalone repository checkout.
- Local stack uses Docker Compose with `wordpress:latest`, MySQL 8.0, WordPress exposed on port 8080, and phpMyAdmin exposed on port 8081.
- WordPress core reports `$wp_version = '7.0'` in `wp-includes/version.php` at discovery time.
- Existing plugins include WooCommerce, Akismet, Plugin Check, mcorucu SEO Tools, Hello Dolly, and Vacuum Image Optimizer.
- The requested `mclogiora` plugin folder did not exist before this planning phase.
- The `mclogiora` folder was created only to hold this required `PLANNING.md` document.

## Potential Risks

- The free feature scope is large. Posts, taxonomies, strings, media, URLs, SEO, editors, builders, REST, import/export, and suggestions should be phased carefully to avoid a brittle first release.
- Multilingual URL handling can conflict with permalink settings, redirects, canonical tags, sitemap output, and SEO plugins.
- Builder compatibility can become expensive if adapters are not isolated behind stable contracts.
- String translation can become slow if scanning and lookup are not indexed and cached.
- Translation suggestions require careful WordPress.org External Services documentation and strict opt-in behavior.
- Admin UI must adapt Skylearn thoughtfully to professional plugin workflows without abandoning the specified visual language.
- WooCommerce is installed in the local development environment, but WooCommerce support is out of scope until a dedicated later phase and should not influence current implementation work.

## Recommendations Before Implementation

- Start Prompt 02 with a narrow skeleton phase: bootstrap, standards, module loader, readme, uninstall policy, and test scaffolding only.
- Define public interfaces before implementing integrations.
- Implement languages and relation tables before editor UI.
- Write the WordPress.org compliance story early, especially External Services and privacy disclosures.
- Treat Skylearn as a token and component system for admin screens, not as loose inspiration.
- Use Plugin Check, PHPCS, PHPStan, and automated tests from the first implementation phase.
- Keep translation suggestions disabled by default until provider documentation, storage, and review-only UX are complete.

## Next Phase

Phases 02 through 13 are complete. The next implementation phase is **Phase 14: Editor Translation UX**, which brings the translation workflow into the editing experience.
