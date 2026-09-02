=== mcLogiora ===
Contributors: mcorucu
Tags: multilingual, translation, localization, language
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A multilingual translation and language management plugin for WordPress.

== Description ==

mcLogiora is a modular multilingual platform for WordPress.

mcLogiora is fully free and fully open source. There is no premium edition, no paid tier, no licence key, and no feature paywall. Every feature it ships is available to every user.

This release brings translation into the editing experience itself:

* Thin plugin bootstrap.
* PSR-4 autoloading architecture.
* Core application and service container.
* Module loader.
* Environment validation.
* Stable plugin contracts.
* Conditional admin assets.
* Functional language manager for plugin language data.
* Database-backed translation group and item relation records.
* Editor-independent Classic Editor, Block Editor, and Elementor compatibility adapters.
* Read-only compatibility detection for editors, builders, known plugins, and the active theme.
* Setup wizard welcome and default language steps.
* Localization and project metadata structure.
* Migration-based database schema for language and relation data.

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

This release adds the developer and operations layer as well as optional Translation Suggestions. If you switch suggestions on and configure a WordPress AI connection or DeepL, mcLogiora can draft a translation of one field at a time, which you review before anything changes. The feature is off until you configure it, and translating by hand is unaffected. See "External Services" below for exactly what is sent and when.

Developer and operations surfaces:

* Read-only `mclogiora_` functions and documented extension hooks for themes and other plugins.
* Authenticated REST routes for translation reads, status changes, relation membership, and translation creation.
* `wp mclogiora` commands for language, relation, and translation reads and workflow mutations.
* Portable translation packages with deterministic export, dry-run planning, additive atomic apply, rollback, and stale-plan protection. No import/export transport is exposed.
* A read-only System Status screen and native Site Health diagnostics with redacted output and no provider network calls.

The REST layer does not expose strings, suggestions, switcher, import/export, or status routes, and it does not machine-translate post bodies or page-builder layouts. See "What Translation Suggestions do not translate" below.

== Privacy ==

mcLogiora does not track users, does not collect telemetry, does not send beacon requests, and contains no analytics of any kind.

mcLogiora contacts an external service only if you switch on Translation Suggestions, choose a provider, configure its connection, and then click a button that asks for a suggestion. Nothing is sent when a page loads, when the plugin is activated, when an editor opens, or when a visitor views your site. There is no mcLogiora server in the path: WordPress AI Client or the dedicated translation service sends the request from your site.

== Screenshots ==

1. Review pages, posts, and translation status in one place.
2. Create missing translations directly from WordPress content lists.
3. Choose your site language and translation languages from a built-in catalog.
4. Manage active languages with native names and locale details.
5. Keep translation context close to the content you are editing.
6. Learn the workflow with a searchable guide inside WordPress.
7. Set language URL structure and switcher presentation with confidence.

== External Services ==

mcLogiora's Translation Suggestions feature can connect to one external translation
provider that you choose and configure. It is switched off by default. If you never
enable it, mcLogiora makes no external requests at all.

For every service below, the following is true:

* The service is optional and is not used unless you enable suggestions, select that provider, and configure its connection.
* WordPress manages AI provider credentials in Settings → Connectors. DeepL uses an API key you supply in mcLogiora settings, and the provider bills you directly. mcLogiora ships no keys and no credits.
* Requests go from your site through WordPress AI Client or straight to DeepL. mcLogiora does not proxy your content through any mcLogiora service, and no data is sent to the plugin author.
* Only the single field you asked about is sent, and only at the moment you click a button. Generating a suggestion for a post title sends that title; it does not send the post body, the rest of your site, or any other field.
* Any required credential is handled by WordPress AI Client or sent to DeepL so the selected service can authenticate the request.
* Translating by hand needs none of this and works exactly the same whether the feature is on or off.

= WordPress AI Client =

Used when you select WordPress AI Client as your suggestion provider. The selected source text and translation instructions are passed to WordPress Core's provider-agnostic AI Client when you click Generate. WordPress selects the configured compatible provider and model, and manages its credentials in Settings → Connectors. mcLogiora does not receive or store those credentials and does not choose a vendor or model on your behalf.

WordPress AI Client: https://developer.wordpress.org/reference/functions/wp_ai_client_prompt/
WordPress AI overview: https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/

When updating from a version that stored an OpenAI, Anthropic or Google Gemini
mcLogiora credential, 1.0.2 removes those retired mcLogiora-owned credential
and model options once. It does not remove WordPress Connector settings or the
DeepL credential. The retired PHP constants are no longer read; PHP constants
cannot be removed by a plugin, so remove those old lines from `wp-config.php`
manually if you still have them.

= DeepL =

Used when you select DeepL as your suggestion provider. Your selected source text and your API key are sent to the DeepL Translate API when you click Generate, so it can return a translation. Testing the connection contacts DeepL's usage endpoint, which reports your quota and sends none of your content.

DeepL Free and Pro keys use different hosts (`https://api-free.deepl.com` and `https://api.deepl.com`); mcLogiora selects the correct one from your key. DeepL is a dedicated translation service rather than a language model, so it has no model selection.

Terms: https://www.deepl.com/en/pro-license
Privacy: https://www.deepl.com/en/privacy

= What is stored on your site =

If you paste an API key into the mcLogiora settings screen it is stored in your site's options table as you entered it. mcLogiora does not claim to encrypt it: any encryption a plugin can perform has to keep the key material on the same site, which would obstruct a casual reader while misleading a careful one.

If you prefer not to store a key in the database, define it in `wp-config.php` instead and mcLogiora will use that and tell you it is doing so:

`define( 'MCLOGIORA_DEEPL_API_KEY', '...' );`

A saved key is never shown again in the admin. The screen displays only a masked suffix so you can tell which key is installed.

== Translation Suggestions ==

Translation Suggestions let you ask a provider you already pay for to draft a
translation of one field, which you then review before anything changes.

= Turning it on =

1. Open **mcLogiora → Translation Suggestions**.
2. Tick **Allow translation suggestions on this site**.
3. Choose WordPress AI Client or DeepL. Nothing is chosen for you.
4. For WordPress AI Client, configure an AI provider in **Settings → Connectors**. For DeepL, save your API key here or define it in `wp-config.php`.
5. Use **Test connection** to check the connection. This sends none of your content.
6. WordPress AI Client chooses a compatible provider and model; DeepL has no model selection.

The WordPress AI Client status distinguishes no registered AI provider, a
registered provider that still needs its Connector configuration, and AI
support disabled for the site. These checks are local and do not send content.

= Using it =

Wherever suggestions are available you get a **Generate suggestion** button per
field. Generating shows you a preview and changes nothing. From there you can:

* **Apply suggestion** — write the suggested text to that field, and only that field.
* **Regenerate** — ask again. One click, one request.
* **Discard** — throw the preview away. Nothing is saved and no request is made.

Nothing is ever translated automatically, on save, in bulk, or on publish. No
suggestion is applied without you clicking Apply.

= Where suggestions are available =

* Posts, pages and custom post types, in both the block editor and the Classic editor: **title** and **excerpt**.
* Interface strings, in String Translation: the **translation for your target language**.
* Taxonomy terms, in the Translation Manager: **term name** and **term description**.
* Media, on the attachment edit screen: **title**, **alternative text**, **caption** and **description**.

= What Translation Suggestions do not translate =

Phase 16 translates named fields only. It deliberately does not machine-translate:

* the raw post body (`post_content`), or a block document as a whole
* Elementor layouts, Beaver Builder layouts, or other page-builder payloads
* arbitrary custom fields or post meta
* a term's slug, or a media file, filename, URL or dimensions

This is a safety decision rather than a missing feature. mcLogiora carries builder
and block structures through translation by copying them untouched, which is why
they survive. Sending a serialized block document to a language model and
reassembling the answer fails quietly when it fails — a damaged block delimiter does
not raise an error, it renders your page as visible HTML comments. That work needs a
proven extraction layer first.

= A suggestion is not an approval =

Applying a suggestion records that a machine produced the text, so you can find it
again and review it:

* Posts, pages, custom post types and taxonomy terms move to **Machine suggested**. Moving them to **Translated** is a separate, explicit step that only a person can take.
* Interface strings are stored with a machine-suggested status of their own.
* Media metadata is stored as **Translated**, because mcLogiora's media translation storage has no machine-suggested state. Review machine output on media before relying on it — the storage cannot flag it for you.

Machine translation is a draft. Read it before you publish it.

== Installation ==

1. Upload the `mclogiora` folder to `/wp-content/plugins/`.
2. Activate the plugin from the WordPress Plugins screen.
3. Open the mcLogiora admin menu to confirm the overview screen loads.

== Frequently Asked Questions ==

= Does this version translate content? =

It creates and links translations, and it can now draft individual fields for you if you switch on Translation Suggestions and supply your own provider key. Creating a translation still makes a draft that starts from the source content. Suggestions are per-field, always previewed before they are applied, and never automatic: there is no translate-on-save, no bulk translation, and no machine text is published without you applying it. Post bodies and page-builder layouts are not machine-translated at all.

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

Only if you ask it to. Translation Suggestions are off by default; with them off, mcLogiora makes no external requests at all. If you enable them, configure WordPress AI Client in Settings → Connectors or save a DeepL API key, then the single field you click Generate on is sent through that selected service. Nothing is sent on page loads, editor loads, activation, or visitor requests, and nothing is ever sent to the plugin author. The SEO features still work entirely through WordPress hooks and send nothing anywhere. See "External Services" above for per-provider detail.

= Will this conflict with my SEO plugin? =

No. If Yoast SEO, Rank Math, All in One SEO, The SEO Framework, or Slim SEO is active, mcLogiora leaves that plugin's canonical, social, and sitemap output alone and only adds the language annotations it does not provide. The Compatibility screen shows exactly which parts are handled by which plugin.

= Does it change my canonical URLs? =

Each translation points at itself. Sending every language back to the default one would tell search engines your translations are duplicates to ignore, which is the opposite of what translating a site is for.

== Changelog ==

= 1.0.2 =

* Routed AI translation suggestions through the WordPress 7.0 AI Client and Connectors APIs.
* Removed vendor-specific AI endpoints, model catalogues, and AI credentials from mcLogiora.
* Kept DeepL as an explicitly configured dedicated translation service.
* Removed retired mcLogiora-owned AI credential and model options once during upgrade without touching WordPress Connector settings.

= 1.0.1 =

* Polished the first-run language setup, multilingual content inventory, and local manual experience.
* Added WordPress.org compatibility and security hardening, including prepared table identifiers and explicit nonce handling.
* Refreshed the WordPress.org visual presentation with a premium, restrained spacewave direction.

= 0.16.0 =

* Added the public read API and reviewed developer hook contracts.
* Added authenticated REST translation reads and workflow mutations, plus matching `wp mclogiora` language, relation, and translation commands.
* Added portable translation package export, parsing, validation, dry-run planning, additive atomic apply, stale-plan protection, rollback, and targeted cache invalidation. No operator transport is exposed.
* Added read-only System Status and native Site Health diagnostics with redacted output and no automatic provider network calls.
* Qualified the plugin against WordPress 7.1 Final, including the Block Editor, routing, multilingual SEO, REST, WP-CLI, import/export, diagnostics, and database-backed integration suite. `Tested up to` is 7.1.
* Live provider qualification was not performed because provider credentials were unavailable. The optional provider adapters and contracts remain statically and integration qualified; manual multilingual functionality is independent of those services.

= 0.15.0 =

* Added optional Translation Suggestions. Off by default; you bring your own API key and the provider bills you directly.
* Supported providers: OpenAI, Anthropic, Google Gemini and DeepL. Nothing is chosen for you.
* The three language-model providers require you to pick a model explicitly. No model is ever selected automatically, because that choice affects what you are billed. DeepL has no model selection.
* Per-field workflow everywhere it appears: Generate a preview, then Apply, Regenerate or Discard. Generating changes nothing.
* Available for post and page title and excerpt in both the block editor and the Classic editor, interface string translations, taxonomy term name and description, and media title, alternative text, caption and description.
* Applying a suggestion to a post, page, custom post type or term records it as Machine suggested, which a person must promote to Translated. Interface strings get their own machine-suggested status. Media metadata is stored as Translated because that storage has no machine-suggested state.
* Placeholders such as %s and %1$s are protected across translation, and a suggestion that loses one is refused rather than applied.
* Your site talks to the provider directly. There is no mcLogiora service in the path, no telemetry, and no external request until you click a button.
* API keys can be defined in wp-config.php instead of the database, and a saved key is never redisplayed.
* Post bodies, block documents and page-builder layouts are deliberately not machine-translated. See the readme for why.
* Fixed: the media translation fields could be adopted by WordPress's own attachment form, which prevented saving a translation for one language and could send the wrong action when using WordPress's Update button.
* Fixed: applying or discarding a suggestion left keyboard focus on nothing; it now returns to the field's Generate button.
* Fixed: the Translation Suggestions settings screen completed actions such as Test connection without reporting the result.
* Fixed: a second suggestion could not be applied to a translation that already had one.

= 0.14.0 =
* Added Beaver Builder support: a new translation starts with the source page's layout instead of an empty page.
* Confirmed Kadence Blocks, GenerateBlocks and Spectra work with no extra setup. Their layouts travel with the content already.
* Fixed Beaver Builder and SeedProd not being recognised on sites that had them installed.
* Added a clearer compatibility list that says what has actually been tested, and against which version, rather than a yes/no.
* SeedProd's own landing pages are left alone, and ordinary pages still translate normally alongside it.
* Bricks, Divi, WPBakery, Oxygen and Avada are not yet verified. They may work, but nothing has been tested, so nothing is claimed.

= 0.13.0 =
* Added a translation panel to the Block Editor document sidebar, showing the content's language, its source language, and the status of every active language without leaving the editor.
* Added a matching Classic Editor metabox with the same languages, statuses, and actions.
* Added Create Translation, Edit Translation, and View links directly in the editor. Creating a translation still goes through the same checks as everywhere else in the plugin.
* Added a clear notice when the source content changed after a translation was last updated, including when each was last modified.
* Added Elementor support for translations: a new translation starts with the source page's layout, so the design is there to be translated rather than rebuilt. Generated Elementor CSS is rebuilt for the translation rather than copied.
* Added Advanced Custom Fields support: field groups appear on translated content and each translation keeps its own values. Copying the source's field values into a new translation is not included yet.
* Added one set of status wording shared by the editor and the posts list, so a translation is described the same way everywhere.
* Translated content still opens in whichever editor the site already uses. No separate translation editor is introduced.

= 0.12.0 =
* Added hreflang alternates, a self-referential entry, and an x-default annotation for translated pages, terms, and the home page.
* Added self-referential canonical URLs for translated term archives, the front page, and a static posts page. A translation is never pointed back at its source language.
* Added correct translated URLs in the WordPress core sitemap, so every entry resolves in the language it belongs to.
* Added the correct `lang` and `dir` attributes on translated pages, and pointed WordPress at the current language's translation files.
* Added og:locale and og:locale:alternate where no SEO plugin already provides them.
* Added compatibility with Yoast SEO, Rank Math, All in One SEO, The SEO Framework, and Slim SEO. mcLogiora keeps hreflang, which none of them provides, and leaves their own output alone.
* Added a multilingual SEO status panel showing exactly what is being emitted and what would stop it working.
* Fixed activation silently succeeding when the database tables could not be created.
* Fixed the WordPress 6.7 notice about translations being loaded too early.

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
