# Changelog

## 0.15.0

- Added optional Translation Suggestions. The feature is off by default, needs no account with the plugin author, and does nothing until a site owner enables it, chooses a provider and supplies their own API key. A site that never opens the settings screen behaves exactly as it did in 0.14.0.
- Added four provider adapters — OpenAI, Anthropic, Google Gemini and DeepL — behind one provider-neutral interface, using the WordPress HTTP API rather than vendor SDKs. Nothing outside an adapter knows a provider's request or response shape.
- Language-model providers require an explicit model choice. The model list is fetched only when asked for, and suggestions stay unavailable until a model is selected. No model is chosen automatically, including a "sensible default", because that choice decides what the owner is billed. DeepL is a translation service rather than a model and has no selector.
- Added the per-field review workflow used on every surface: Generate produces a preview and writes nothing, then Apply, Regenerate or Discard. There is no translate-on-save, no bulk translation and no path by which generating text can publish it.
- Added suggestions to post, page and custom-post-type title and excerpt in the block editor, using the editor data layer rather than DOM access, and without marking the post dirty or issuing a second write.
- Added the same two fields to the Classic editor. The section creates no form and every control is an explicit plain button, because Classic wraps the screen in its own form and a control that submitted it would have saved the post instead of asking for a translation.
- Added suggestions for interface string translations, respecting a string's identity: two identical source strings registered under different text domains are different strings and translate independently.
- Added suggestions for taxonomy term name and description in the Translation Manager. A term's slug is never sent to `wp_update_term()`, because a machine-translated slug would silently change every URL the term owns.
- Added suggestions for media title, alternative text, caption and description. Media metadata is stored as one row per language, so every apply rewrites all four values with only the requested one changed.
- Recorded review state honestly per storage model. Posts, pages, custom post types and terms move to `machine_suggested` and need an explicit human transition to `translated`. Interface strings carry their own machine-suggested status. Media metadata is stored as `translated`, because that storage has no machine-suggested state and claiming one would make the screen disagree with the database.
- Added `PlaceholderShield`, which protects placeholders such as `%s` and `%1$s` across a provider round trip and refuses a suggestion that came back missing one. A broken placeholder is a fatal `sprintf` in production, not a typo, so refusing is better than applying.
- Added server-authoritative source resolution. The browser sends an object id, a field name and a target language, never the text to translate, so the endpoints cannot be used as a translation proxy funded by the owner's provider quota.
- Added short-lived preview tokens bound to user, object, field and language. Apply uses the stored preview rather than text from the browser, consumes the token on success, and refuses a token belonging to another field, another object, another user, or one already discarded.
- Added credential handling that prefers `wp-config.php` constants over database storage, never redisplays a saved key, and states plainly that keys entered in the admin are stored as entered rather than claiming an encryption a plugin cannot honestly provide.
- OpenAI requests use the Responses API and always send `store: false` as a policy constant with a strict-boolean regression test. No stateful OpenAI features are used: no conversations, no response chaining, no background jobs, no tools and no file uploads.
- No telemetry, no usage reporting and no external request at boot, on activation, on an editor load or on a visitor request. The only outbound traffic is a provider call the owner explicitly triggered.
- Post bodies, whole block documents and page-builder payloads are deliberately not machine-translated. Phase 15 proved those structures survive translation because mcLogiora copies them untouched; handing one to a language model and reassembling the answer fails silently when it fails, so it is documented as deferred rather than shipped partially.
- Fixed the media translation fields being adopted by WordPress's own attachment form. `edit_form_after_editor` fires inside `<form id="post">`, and the parser discarded the nested per-language forms, which meant a translation could not be saved for the affected language and WordPress's own Update button posted the wrong action. The fields now declare their owning form explicitly and those forms are printed outside the post form.
- Fixed keyboard focus being lost after applying or discarding a suggestion. Both actions remove the button that was just activated, which dropped focus to the document body; focus now returns to the field's Generate button.
- Fixed the Translation Suggestions settings screen completing actions without reporting them. Test connection, Save key, Remove key, Refresh model list, Save model and Save Settings all redirected with a result the screen never displayed, so success and failure looked identical.
- Fixed only one suggestion being applicable per translation. Applying a second field asked the workflow for a status it already held, and the refusal was treated as a failure and rolled the field back.

## 0.14.0

- Added Phase 15 extended builder compatibility. Ten builders were assessed; the honest result is two adapters, three builders that need no code at all, and five that nobody here could legally install and so cannot be claimed.
- Added Beaver Builder support. Beaver keeps its layout in post meta rather than in the post, so a translation used to open as an empty page. The layout is now copied through Beaver's own `FLBuilderModel` API, following the same sequence Beaver's own multilingual duplication uses, including the draft layout and the builder-enabled flag. Page-level custom CSS is not copied, matching Beaver's own behaviour, and the translation's asset cache is cleared so it rebuilds.
- Confirmed Kadence Blocks, GenerateBlocks and Spectra need no builder-specific code. All three store their layout as ordinary block content, which mcLogiora already copies exactly. Writing adapters for them would have added three classes that copy nothing and three more things to break; a test asserting the markup survives keeps it true instead.
- Fixed two builders never being detected. mcLogiora looked for Beaver Builder at `beaver-builder/beaver-builder.php` and SeedProd at `seedprod/seedprod.php`; the free Beaver edition ships as `beaver-builder-lite-version/fl-builder.php` and SeedProd has always shipped as `coming-soon`. Both names were plausible and neither was real. Detection now prefers a class or function the builder itself defines, which survives an edition change and a directory rename.
- Added nuanced compatibility reporting. A builder whose layout is ordinary block content is fully compatible and needs nothing; a builder nobody could install is unproven rather than broken. Those are different facts and are now reported differently. No builder is ever described as unsupported.
- Confirmed SeedProd's own landing pages are correctly outside mcLogiora's scope: they use a non-public post type, translation is refused cleanly, and ordinary pages still translate normally with SeedProd active.
- Added a `WordPress builder compatibility` CI job running the adapters against the current free editions of Elementor, ACF, Kadence Blocks and Beaver Builder, so a builder release that breaks an adapter fails a build instead of a site. Commercial packages are never downloaded.
- Bricks, Divi, WPBakery, Oxygen and Avada are recorded as not yet verified. No adapter was written for any of them, because writing one from assumptions about its storage would be a compatibility badge with no evidence behind it.
- Elementor's global site settings remain uncopied, now as a recorded decision rather than an open question: they belong to the site, not to a translated post.
- No post meta is copied into a translation, by any adapter, ever. That single property is why the block builders work without code, and it is now asserted as a test.
- WordPress 7.1 support is still not claimed. `Tested up to` remains 7.0 until a final 7.1 build ships and passes a compatibility smoke.

## 0.13.0

- Added Phase 14 editor translation UX. Translation state now lives where the editing happens, in both the Block Editor and the Classic Editor.
- Added one shared editor translation model. The Block Editor panel, the Classic metabox and anything a later phase adds all render the same answer about an object's language, its source, the status of every active language, and what this user may do. Three surfaces deriving that separately would have drifted into bugs that reproduce in one editor only.
- Added a Block Editor panel in the document sidebar, registered through `@wordpress/plugins` and rendered by `@wordpress/editor`. WordPress 7.1 renders the editing canvas in an iframe; nothing here reaches into it, and mcLogiora's stylesheet is absent from the canvas entirely. `wp.editor.PluginDocumentSettingPanel` is used rather than the `wp.editPost` export of the same name, which has been deprecated since WordPress 6.6 and logs a console deprecation on every editor load.
- Added a Classic Editor metabox with feature parity: the same languages, statuses and actions, drawn the way the Classic Editor draws things. Its create button posts a form printed outside `<form id="post">`, because HTML discards a nested form and the button would otherwise submit the post form instead.
- Added one status vocabulary shared by the editor surfaces and the posts-list column. Every status carries a label and a sentence explaining it, and status is never communicated by colour alone.
- Added a clear outdated-translation notice. A `needs_update` row says the source changed and shows the timestamps the relation already records. No diff is computed, because a partial comparison would mislead more than the plain sentence does.
- Added Elementor layout copying through Elementor's own document API, so a translator translates the existing design instead of rebuilding it. Elementor's `save()` records the template type, stamps the current version, and drops the stale generated CSS so it rebuilds for the translation. No `_elementor_*` meta is copied by hand, and the generated CSS never travels.
- Added ACF detection with native editing on translated objects. Field groups render on a translation, values stay per-object, and editing a translation never touches the source. Seeding a translation with the source's field values is deliberately not implemented: repeaters, flexible content and clone fields each store values in shapes that would have to be proven safe first, and a partial implementation would appear to work while corrupting the harder types.
- Added a payload adapter contract kept separate from the editor UI contract. Presenting state on a screen and preparing stored content are different jobs; a failure during preparation rolls back the draft the operation created, and never touches pre-existing content.
- The editor never creates content itself. Its one write action posts to the same `admin-post` endpoint the Translation Manager has used since Phase 10, with the same nonce, capability, validation and rollback, so JavaScript holds no authority of its own and no REST endpoint was added.
- Language is displayed and not editable. Reassigning an object's language is a relation operation with group-integrity consequences, not a dropdown, and no such workflow exists yet.
- No build pipeline was introduced. The panel is plain JavaScript against the packages WordPress already registers, so the file shipped is the file written and no build artefact can drift from its source.
- WordPress 7.1 support is still not claimed. `Tested up to` remains 7.0 until a final 7.1 build is released and passes a compatibility smoke.

## 0.13.0 — Phase 13.1 stabilization

Merged during the 0.13.0 cycle, before the Phase 14 entries above.

Phase 13.1 stabilization. Three defects found while qualifying the plugin against WordPress 7.1-RC3. None of them is caused by WordPress 7.1: all three predate it and were exposed rather than introduced by the qualification.

- Fixed translated posts returning a 404 under `/%postname%/` permalinks, the most common permalink structure on content sites. Re-parsing the path after a language prefix stopped at the first matching rewrite rule; WordPress core does not. When the structure starts with `%postname%` core turns on verbose page rules and the page rule becomes a catch-all matching any single-segment path, posts included. Core resolves the captured slug with `get_page_by_path()` and moves to the next rule when no usable page comes back. mcLogiora now mirrors both of core's conditions, including the post-status check. Translated pages always worked, which is why this survived two phases of tests.
- Fixed the sitemap advertising URLs that returned a 404. It listed `/tr/<slug>/` for every translated post while that address did not resolve, so the plugin was submitting broken URLs to search engines. Listing and serving are now asserted together, with a post fixture rather than a page.
- Fixed translated content being served under another language's route. WordPress resolves a slug regardless of language, so a Turkish translation also answered at its unprefixed English URL, declaring `lang="en-US"` and a canonical that appeared in neither of its own hreflang alternates. A stored object's language belongs to the object, established by its translation relation; it is not a property of whoever happens to be browsing. Any route reaching an object in a different language now redirects permanently to the object's own URL, so one address is authoritative instead of two competing ones.
- Fixed permalink generation using the request's language instead of the object's. A translation linked from a default-language page produced a default-language URL. Phase 13 corrected this for the sitemap specifically; the cause is now fixed for every caller.
- Fixed source content being served beneath a translated URL. Phase 12 documented this as a 404 and nothing enforced it — it only appeared to hold because the verbose page rule defect made most such paths fail to resolve at all. An object with no translation in the requested language is now a genuine 404.
- Fixed unlinking a translation permanently consuming that language. ADR-0010 says unlink removes the relation record; the implementation instead marked it `disabled` and kept the row, contradicting the documented contract and overloading a status that means administrative language disabling. Because the language-slot check matches on language alone, the abandoned row blocked that language forever: after unlinking, the same language could not be linked or translated again without editing the database directly. The relation record is now removed. Content, meta, revisions, and the source object remain untouched, exactly as before.
- Added a `WordPress 7.1 compatibility` CI job, pinned to a specific build rather than following trunk or nightlies, running the full integration suite on PHP 8.3. It fails if the build under test is not the intended one, or if the tree is missing built assets. The existing integration job continues to cover current stable WordPress; nothing was removed or relaxed.
- Fixed the test-suite installer resolving pre-release versions to trunk. A version such as `7.1-RC3` has no SVN tag, so the fallback reached trunk — which was already 7.2-alpha during 7.1's release candidate period, meaning a 7.1 run would have quietly tested against the next major's suite. Release branches are now preferred over trunk.
- WordPress 7.1 support is **not** claimed yet. `Tested up to` deliberately remains 7.0 until a final 7.1 build is released and passes a compatibility smoke.

## 0.12.0

- Added Phase 13 multilingual SEO integration.
- Added `hreflang` alternates for translated singular content, supported taxonomy archives, and the home page. A language is annotated only when a translation genuinely exists and a URL can be produced for it, because an alternate pointing at a URL that 404s is worse than no alternate at all.
- Added a self-referential `hreflang` entry built from the same source as every other alternate, so a page cannot declare one URL to be itself and a different one to be its own language's version.
- Added an x-default annotation pointing at the default language's equivalent, omitted entirely when no equivalent exists rather than aimed at a guess. Filterable through `mclogiora_seo_x_default_url`.
- Added self-referential canonical URLs for translated term archives, a blog-index front page, and a static posts page. Singular requests are left to WordPress core, which has been emitting the correct translated permalink since Phase 12, so no page can end up with two canonical tags.
- Added correct translated URLs in the WordPress core sitemap. Core builds entries from `get_permalink()`, which is prefixed with the language of the current request, so without this every translated entry would have been listed at an address that resolves to the wrong language.
- Added the correct `lang` and `dir` attributes on translated pages, replacing only those two attributes so anything a theme contributed survives.
- Added a request `locale` filter so themes and plugins loading translations just in time reach the current language's files.
- Added `og:locale` and `og:locale:alternate`, omitted for a language configured without a territory rather than given an invented one.
- Added SEO plugin compatibility for Yoast SEO, Rank Math, All in One SEO, The SEO Framework, and Slim SEO, with ownership decided per concern. `hreflang` never transfers, because none of those plugins produces it for a multilingual site.
- Changed the language switcher to use the same standards-compatible language tags as the document head. A link saying `hreflang="tr"` while the head said `hreflang="tr-TR"` was two different claims about one page.
- Added a multilingual SEO status panel reporting what is emitted, what is delegated, and which languages cannot produce a valid language tag. Read-only; nothing is repaired automatically.
- Added `LanguageTag`, which converts a WordPress locale to a BCP 47 tag. `hreflang="tr_TR"` looks correct in WordPress code and is silently ignored by search engines, which is the worst kind of bug.
- Fixed activation discarding the installer's result, so a failed migration read as a successful activation. Failures are now recorded, shown as an admin notice with a retry action, and reported in the health panel.
- Fixed the `_load_textdomain_just_in_time` notice WordPress 6.7 emits on every page load. Seven admin modules translated their screen titles while registering on `plugins_loaded`; titles are now resolved when the menu is actually built.
- Changed string translation lookups to be memoised for the request, including misses. Phase 12 wired this path to `gettext`, so a page view now asks about every string a theme renders and most have no translation.
- No external services, no telemetry, and no analytics were added. Multilingual SEO works entirely through WordPress hooks.

## 0.11.0

- Added Phase 12 URL routing, translated slugs, and language switching.
- Fixed a boot failure that made WordPress unable to finish installing while mcLogiora was active. The `gettext` filter's guard called `is_preview()` before WordPress creates the main query; WordPress answers that with a `_doing_it_wrong()` notice whose message is built with `__()`, which re-entered the filter, which called the guard again. The recursion consumed all available memory. See `docs/adr/0014-install-safe-runtime-lifecycle.md`.
- Added `RuntimeReadiness` as the single authority on installation state, schema availability, and request context. Routing, permalinks, front-end translation, and the switcher all ask one object instead of each keeping its own copy of the checks.
- Added an installation-safe boot policy. While WordPress is installing, the front-end translation, permalink, and switcher modules register no hooks at all, read no languages, and touch no mcLogiora table.
- Added a schema-not-ready fallback. A site whose tables are missing renders as ordinary monolingual WordPress -- original strings, menus, media, and permalinks -- rather than erroring or fabricating a translated route.
- Fixed the `gettext` re-entry guard, which previously wrapped only the translation lookup and not the decision to translate. Any translated string produced while deciding now falls straight through.
- Fixed plugin activation installing only the Phase 10 schema. `InstallerFactory`, which is what activation actually calls, carried its own migration list and had fallen a migration behind, so a real site never got the Phase 11 string, media, and widget tables and those translations had nowhere to store anything. Both installer paths now share one `MigrationRegistry`, and a test asserts the registry reaches the current database version.
- Fixed permalinks carrying their language prefix twice on a prefixed-language request. WordPress builds an object permalink by calling `home_url()` with a path and then filtering the result through `post_link`, `page_link`, or `term_link`; mcLogiora filters both, so every link on a `/tr/` page pointed at `/tr/tr/`. Applying a prefix is now idempotent.
- Fixed `mclogiora_path` never being registered as a query var. WordPress discards unregistered query vars during `parse_request`, so everything after a language prefix was thrown away and every translated URL resolved to the site home. The path is now honoured only when a language prefix actually matched.
- Removed `RequestContextGuard`, whose responsibilities moved into `RuntimeReadiness`.
- Added WordPress integration coverage for directory language routes, unprefixed default routes, missing-translation 404s, inactive languages, the translated posts page, and the front-end application of Phase 11 string, media, widget, and menu translations.
- Added installation and boot regression tests, including a recursion guard that fails by assertion instead of exhausting the machine.
- Added a single authoritative language context. Everything that needs the current language now receives it from one place, so content, navigation, and interface strings can never disagree about what language a page is in.
- Added directory-based language URLs. The default language keeps its existing unprefixed URLs; secondary languages are served under a language directory such as `/tr/`.
- Added strict language validation. Only active configured languages become routing prefixes, and unknown, inactive, or hostile prefixes fall back to the default rather than becoming a language.
- Added rewrite rules registered through WordPress APIs, flushed only when the routable prefix set actually changes. Ordinary requests never rebuild them, and changing a switcher display setting never triggers a flush.
- Added a genuine 404 for a translated URL whose translation does not exist, rather than silently serving source-language content under it. Menus are the deliberate exception and fall back to the source menu, because navigation that disappears strands the visitor.
- Added translated post and page URLs built from each translation's own slug and its translated ancestors, respecting WordPress slug uniqueness rather than bypassing it.
- Added real translated taxonomy slugs, replacing the provisional language-scoped slugs Phase 11 created.
- Added one authoritative translated URL generator used by every switcher surface, which never fabricates a URL for a translation that does not exist and resolves a whole translation group in a single lookup.
- Added front-end application of the Phase 11 translations for strings, media metadata, supported widgets, and menus, all through the same language context and none of them writing to stored values.
- Added a language switcher available as a shortcode, block, classic widget, and template tag, in inline, dropdown, compact, and pill styles, with configurable behaviour when a translation is missing.
- Added switcher accessibility: real links and form controls, keyboard operation without JavaScript, `lang`, `hreflang`, and `dir` attributes, current-language announcement, and explicit unavailable-language wording.
- Added a Languages & URLs settings screen covering the URL structure and switcher presentation.
- Flags are off by default and never assumed. A language is not a country, so no flag is mapped to any language unless a site supplies one, and the readable label never depends on one.
- Plural translation is deliberately not hooked. The Phase 11 storage model holds one translated string per source string, so claiming plural support would return singular text in plural contexts.

## 0.10.0

- Added Phase 11 string, media, menu, and widget translation.
- Added a string registry whose identity is the source text, text domain, and gettext context together, so the same word in different contexts stays separately translatable.
- Added a token-based source scanner that runs only from an explicit admin action, never during normal site traffic. It reads only, never writes to theme or plugin files, is confined to a single chosen directory, and reports dynamic calls as unresolvable instead of guessing at them.
- Added the String Translation screen with search, text domain and origin filters, per-language editing, and scan controls.
- Added an explicit-language string lookup API. Nothing hooks `gettext`; runtime language selection stays with a later phase.
- Added per-language media metadata for title, alternative text, caption, and description. One attachment serves every language and no file is ever duplicated.
- Added the featured image policy deferred from Phase 10: a translated post references the same attachment as its source, and an image chosen explicitly for one language is respected.
- Added translated navigation menus as separate WordPress menus, preserving item order and nesting by remapping parents to the new item identifiers. Only whitelisted core menu item fields are copied, and theme locations are never reassigned.
- Added widget translation through an adapter model with Text, Custom HTML, and Block widget adapters. Widget types without an adapter are reported as unsupported and are never modified, and source widget options are never rewritten.
- Added `Migration002TranslationDomains` creating the string, string translation, media translation, and widget translation tables. Migration001 is unchanged.
- Added a WordPress integration test harness using the official WordPress PHPUnit suite, covering behaviour that test doubles cannot prove.
- Replaced the empty `languages/mclogiora.pot` scaffold with a real catalogue generated from source, and added `composer pot` to regenerate it.
- Added `composer test:integration` and extended CI with an integration job backed by a real database service.
- Fixed table detection, which used `SHOW TABLES` and therefore could not see temporary tables. Every repository gates on that check, so a working schema was reported as entirely absent. Detection now uses `DESCRIBE`, which sees permanent and temporary tables alike. See `docs/adr/0012-verified-migration-completion.md`.
- Changed the migration contract so the stored schema version only advances after a migration verifies that the tables it declared actually exist. Previously the version advanced simply because a migration had been called, which meant a plugin could believe it was upgraded when it was not and would never retry.
- Changed `MigrationRunner::run()` and `Installer::install()` to return `true` or a `WP_Error` naming the missing tables, stopping at the first failure.

## 0.9.0

- Added Phase 10 content and taxonomy translation workflows.
- Added Create Translation for posts, pages, and supported public custom post types. The new object is always a draft, copying only the source title, content, excerpt, menu order, and author.
- Added Link Existing Translation, which records a relation without copying or modifying any content.
- Added Unlink, which removes the relation record only. The WordPress post or term keeps its content, meta, status, and revisions, and is never trashed or deleted.
- Added translated term creation for categories, tags, and supported public custom taxonomies. The translated name is supplied by the user rather than duplicated from the source, and a translated parent is used only when one already exists in the target language.
- Added an explicit translation status state machine. Invalid transitions are rejected, the source item is immutable, and the machine suggested status stays reserved for a later phase.
- Added conservative source change tracking. Translations are marked as needing an update when the source title, content, or excerpt changes, while autosaves, revisions, bulk edits, and irrelevant status changes are ignored.
- Added real Translation Manager actions with capability checks, nonces, sanitization, validation, safe redirects, and admin notices. No AJAX or REST is used.
- Added a compact language status column to supported post list tables using standard WordPress column hooks.
- Added a narrow WordPress content gateway so workflow behaviour can be tested without a database.
- Added compensating rollback: if relation persistence fails after a draft or term was created by the same operation, that object is removed. Pre-existing content is never deleted.
- Fixed `DatabaseLanguageRepository::create()` returning null when an inserted language could not be read back, which broke its documented `Language|WP_Error` contract.
- Added a PHPUnit test suite covering workflow validation, relation integrity, status transitions, link and unlink semantics, taxonomy rules, source change tracking, and rollback behaviour.
- Added `composer test` and wired it into `composer check`, and extended CI to run the test suite on PHP 7.4 and 8.3.

## Unreleased

- Added Composer development dependencies for the quality configuration that already existed: PHP_CodeSniffer, WordPress Coding Standards, PHPStan, and the WordPress PHPStan extension.
- Added Composer scripts, with `composer check` as the local quality gate running validation, syntax checks, PHPCS, and PHPStan.
- Added a GitHub Actions CI workflow running syntax checks on PHP 7.4, 8.2, and 8.4, plus standards, static analysis, and repository-hygiene jobs on pull requests and pushes to main.
- Scoped four WPCS sniff families to the specific files whose object-oriented patterns they cannot interpret, after a manual security audit of those files, and documented the audit and its follow-up in `docs/development/code-standards.md`.
- Applied automatic coding-standard fixes and mechanical documentation-comment corrections, and replaced the deprecated `readonly()` call with `wp_readonly()`. All changes verified as behavior-neutral.
- Added a PHPStan baseline recording two pre-existing findings, one of which is a genuine defect in `DatabaseLanguageRepository::create()` documented for Phase 10.
- Updated contributor documentation and the pull request template with the quality gate.

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
