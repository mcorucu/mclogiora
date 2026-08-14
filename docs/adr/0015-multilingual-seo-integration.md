# ADR 0015: Multilingual SEO Integration

## Status

Accepted

## Context

Phase 12 answered "what language is this request?" and made every URL on the site language-correct. What it did not do is tell anyone outside the browser. A translated page still announced the site's configured locale in its `lang` attribute, offered search engines no way to discover its other language versions, and appeared in the WordPress sitemap under a URL that resolved to the wrong language.

Those are three different problems with one common requirement: they must all describe the same page in the same way. A canonical tag that says one thing while `hreflang` says another is worse than either alone, because the contradiction is what gets acted upon.

## Decision

### Routing owns URLs; SEO consumes them

`TranslatedUrlGenerator` remains the only thing in the plugin that decides what a translated URL looks like, `LanguageContext` remains the only answer to what language a request is in, and `RuntimeReadiness` remains the only gate on whether multilingual behaviour may run at all. Nothing in `src/Seo/` parses `REQUEST_URI`, reconstructs a path, or forms a second opinion about the current language.

This is not tidiness. Canonical, `hreflang`, the switcher, and the sitemap all describe the same set of URLs, and the only way they cannot disagree is if there is one place that knows.

### One subject, resolved once

`SeoContext` turns the current query into a `SeoSubject` — a post, a term, or the site home — using WordPress's own conditional tags. Canonical and `hreflang` both consume it, so they cannot describe different things.

The subject is deliberately narrow. Search results, date archives, author archives, and feeds are not represented, because mcLogiora has no translated equivalent to point at for them and inventing one produces URLs that either 404 or serve the wrong content.

### Document language and request locale are separate decisions

Three values that are easy to confuse and are not interchangeable:

| Concept | Example | Used for |
|---|---|---|
| Language code | `tr` | mcLogiora's identifier, URL prefixes |
| WordPress locale | `tr_TR` | translation files, `get_locale()` |
| BCP 47 tag | `tr-TR` | `lang` attributes, `hreflang` |

`LanguageTag` converts the locale, falling back to the code. Underscores never survive: `hreflang="tr_TR"` looks right in WordPress code and is silently ignored by search engines, which is the worst possible failure mode — the annotation appears to work and does nothing.

WordPress locale variants such as `de_DE_formal` lose their trailing segment. That segment distinguishes translation files, not languages, and `hreflang="de-DE-formal"` communicates nothing while risking rejection of the whole set.

**Document language** is set through `language_attributes`, replacing only the `lang` and `dir` attributes and leaving anything a theme added intact. `dir` comes from the language's own configured direction rather than from `is_rtl()`, because `is_rtl()` reads a `WP_Locale` built before the request had a language.

**Request locale** is set through the `locale` filter, and this is deliberately the narrowest mechanism that works. `switch_to_locale()` would be more thorough — it reloads text domains that had already loaded — but it also rebuilds `WP_Locale`, and building `WP_Locale` calls `__()` around forty times for month and weekday names. On a site with front-end string translation active, every one of those becomes a lookup, on every page view, to retranslate text WordPress had already translated. Since WordPress 6.7 loads text domains just in time, filtering `locale` reaches the same files for anything not yet loaded, which is now the common case.

**What that does not cover, stated plainly:** a plugin that still calls `load_plugin_textdomain()` during `init` has chosen its files before any request can have a language, and no filter applied afterwards changes that. Such a plugin's strings stay in the site's configured locale.

### hreflang

Alternates are emitted for translated singular content and supported taxonomy archives, plus the site home. Every URL comes from `TranslatedUrlGenerator`. A language appears only when a translation genuinely exists and a URL can be produced for it; nothing is fabricated by pattern.

The self-referential entry is built from the same source as every other alternate. Deriving it separately is how a page ends up declaring one URL to be itself and a different one to be its own language's version.

A subject with fewer than two alternates emits nothing at all. A lone self-reference tells a search engine only what it already knew, and on a partially translated site that is noise on every page.

Order follows the configured language order, so rendered pages diff cleanly between requests.

### x-default

x-default points at the default language's equivalent of the current subject. When the default language has no equivalent, the annotation is **omitted** rather than aimed at a guess — pointing it at content that does not exist is the same failure as a fabricated alternate.

The policy is filterable through `mclogiora_seo_x_default_url` rather than configurable through an admin control, because a site with an opinion here has a specific one, and a select box could not express it.

### Canonical: every page canonicalizes to itself

Pointing every language back at the default one is the single most damaging thing a multilingual plugin can do: it tells search engines the translations are duplicates to ignore, and the work of translating the site disappears from results.

WordPress already prints a canonical tag for singular requests, and because Phase 12 made `get_permalink()` language-correct, **that tag is already self-referential on a translated page**. So nothing is filtered there. A second opinion on singular canonical would only be an opportunity to disagree with core, and would fight any SEO plugin legitimately customising it.

mcLogiora prints a canonical only for the surfaces core leaves uncovered — term archives, a blog-index front page, and a static posts page. Duplication is therefore impossible by construction rather than by a de-duplication pass.

**Corrected in Phase 13.1.** The reasoning above holds only if `get_permalink()` is language-correct for the *object*, and Phase 12 had made it language-correct for the *request*. On its own prefixed route the two agree, which is why this looked right. On any other route they do not: a Turkish translation reached at its unprefixed English URL got a canonical pointing at that English URL — a URL absent from its own hreflang set, and a second address serving the same content.

Two changes restore the claim. Permalinks are now derived from the object's language rather than the request's, so delegating singular canonical to core is sound for the reason this section always gave. And a translated object requested through any other language's route is 301'd to its own, so the wrong-route canonical has no opportunity to be printed.

**The invariant, stated plainly:** for multilingual singular content, the canonical URL must be one of the page's own hreflang alternates. A page that names one URL as itself and a different URL as its own language's version has made two contradictory claims. `MultilingualSeoIntegrationTest::test_canonical_url_belongs_to_the_hreflang_set()` asserts it on both the translated and the source route.

### WordPress core sitemaps

Core builds sitemap entries with `get_permalink()`, and Phase 12's permalink filter prefixed URLs with the language of *the current request*. A sitemap request carries no prefix, so without intervention every translated entry would be listed at its unprefixed URL — a list of addresses that resolve to the wrong language or to nothing.

Each entry is therefore rewritten to the language the object itself belongs to, through `TranslatedUrlGenerator::own_post_url()` and `own_term_url()`. No parallel sitemap is generated and no second index is created; translated content is ordinary WordPress content and the core providers already list it.

**Phase 13.1 note.** Since permalinks are now object-language-aware everywhere, this filter no longer *corrects* anything on its own — it agrees with what `get_permalink()` already returns. It is kept because it states the guarantee at the point it matters and costs nothing, and because the sitemap is where a regression here would be most expensive and least visible.

Producing a correct-looking URL was in any case only half the job. Throughout Phase 13 the sitemap listed `/tr/<slug>/` for every translated post while that exact URL returned a 404, so the plugin was submitting broken addresses to search engines. `SitemapIntegrationTest::test_listed_translated_post_url_resolves()` now asserts listing and serving together, using a post rather than a page — under `/%postname%/` a page resolves through the catch-all rule and would have passed throughout the defect.

**Alternates are deliberately not added to the sitemap.** `xhtml:link` annotations require an `xmlns:xhtml` declaration on the `<urlset>` element, and WordPress core offers no supported way to add one. Emitting the links without the namespace would produce invalid XML, and an invalid sitemap can be rejected as a whole. The document-head annotations carry the same information and are documented by search engines as sufficient. This is a real limitation of the core sitemap API, not an oversight.

### OpenGraph locale

`og:locale` and `og:locale:alternate` only, from the same language facts. OpenGraph wants `language_TERRITORY`, so a language configured without a territory cannot be expressed and is omitted rather than given an invented one — turning `en` into `en_US` asserts a territory the site never chose.

mcLogiora emits no `og:title`, `og:image`, or `og:description`. It contributes the language facts it uniquely knows and nothing else.

### mcLogiora is not becoming an SEO plugin

No titles, no meta descriptions, no keyword analysis, no breadcrumbs, no schema builders, no redirects, and no robots directives beyond what multilingual routing requires. Only multilingual concerns belong here.

### SEO plugin ownership

Ownership is per concern, not all-or-nothing, because that is how the plugins actually divide up.

| Plugin | canonical | og:locale | sitemap | hreflang |
|---|---|---|---|---|
| None | mcLogiora | mcLogiora | mcLogiora | mcLogiora |
| Yoast SEO | Yoast | Yoast | Yoast | **mcLogiora** |
| Rank Math | Rank Math | Rank Math | Rank Math | **mcLogiora** |
| All in One SEO | AIOSEO | AIOSEO | AIOSEO | **mcLogiora** |
| The SEO Framework | TSF | TSF | TSF | **mcLogiora** |
| Slim SEO | Slim SEO | Slim SEO | **mcLogiora** | **mcLogiora** |

`hreflang` never transfers, because none of these plugins produces it for a multilingual site. Standing down from it because an SEO plugin happens to be installed would simply delete the one piece of output only mcLogiora can supply.

An important consequence of Phase 12 makes this safe: every one of these plugins derives its canonical from the WordPress permalink, which is already language-correct. The adapters therefore have very little to do, and adapters for plugins whose filter names could drift between major versions carry no filters at all rather than claiming a hook that might not exist. Suppressing mcLogiora's own correct output while failing to correct someone else's would be worse than doing nothing.

**Unknown SEO plugins do not silence anything.** Guessing that an unrecognised plugin owns canonical would break working sites to protect a hypothetical one. The health check reports the possibility; `mclogiora_seo_owns_concern` lets a site settle it explicitly.

### The switcher speaks the same language as the head

Phase 12's switcher emitted the internal language code as `hreflang` on each link. Both `hreflang="tr"` and `hreflang="tr-TR"` are well formed, so nothing complained, but the switcher and the document head were making two different claims about the same page. All switcher styles now use `LanguageTag`, so there is one representation of a language everywhere it appears in markup.

### Diagnostics

`SeoHealthCheck` reports what the front end is currently emitting and what would stop it working: languages whose locale cannot form a valid tag, two languages sharing one tag, which concerns are delegated, unrecognised SEO plugins, and an incomplete schema installation. Strictly read-only — every plausible repair is a decision with consequences the plugin is not entitled to make.

### Performance

`StringTranslationService` gained a request-level memo in front of the object cache. Phase 12 changed its usage profile completely: it went from being called deliberately by an admin screen to being called for every string a theme renders, most of which have no translation. Misses are memoised as well as hits, because a miss is the common case and the one worth not repeating.

Measured on a translated page with three languages: resolving the complete alternate set costs 11 queries once; canonical, x-default, and the switcher afterwards cost **zero**; a second `wp_head` render costs **zero**; fifty repeats of an untranslated string cost **zero** after the first.

## Consequences

- SEO output is only as correct as the routing layer, which is the intended coupling. A URL bug shows up in four places at once, and is fixed in one.
- A site using Yoast, Rank Math, AIOSEO, or The SEO Framework gets `hreflang` from mcLogiora and everything else from that plugin. No page carries two canonical tags.
- Sitemap alternates are unavailable until WordPress core supports namespace declarations on `<urlset>`.
- Text domains loaded before `init` stay in the site's configured locale.

## Observed in a real installation

Smoke-tested on WordPress 7.0 with a second, unrecognised SEO plugin active alongside.

**Canonical held up.** That plugin filters `get_canonical_url`, which is the same integration point mcLogiora deliberately leaves to WordPress core, so the page carried exactly one canonical tag and it was the translated URL. The decision not to filter singular canonical is what made two plugins coexist without either noticing.

**OpenGraph duplicated.** Both plugins emitted `og:locale`, because mcLogiora has no adapter for that plugin and therefore still owned the concern. This is the documented consequence of not standing down for unrecognised plugins, and the trade is deliberate: guessing that an unverified plugin owns a concern would break working sites to protect a hypothetical one. The health panel reports the conflict by name and points at `mclogiora_seo_output_open_graph_locale`.

## Carried-over hardening

Two problems Phase 12.1 documented and deferred are resolved here, because both are the same shape — something that should be loud being silent.

**Activation no longer discards installer errors.** A failed migration is recorded in `InstallationFailure` and surfaced as an admin notice with a retry action, and appears in the health report. It does not block activation: a database can be briefly unavailable for reasons unrelated to this plugin, and refusing to activate would turn a transient fault into a support ticket. Administrators see an actionable sentence; the table-level detail stays behind the capability that can act on it.

**Admin screens no longer translate while registering.** `AdminScreen` accepts a callable for its titles, and all seven modules pass one. WordPress 6.7 reports translation before `init` through `_load_textdomain_just_in_time()` on every page load; deferring the call keeps registration inert, which is the same principle ADR 0014 established for the routing layer.

## Phase 14 extension points

- `mclogiora_seo_owns_concern` — settle ownership explicitly for an unrecognised SEO plugin.
- `mclogiora_seo_x_default_url` — override or omit x-default per subject.
- `mclogiora_seo_canonical_url` — override the canonical for non-singular surfaces.
- `mclogiora_seo_output_open_graph_locale` — switch off locale metadata for a theme that emits its own OpenGraph block.
- `SeoAdapterInterface` — add a plugin without touching `SeoModule`.

## Deliberately deferred

A per-row SEO indicator in the translation list tables was considered and deferred. It would need its own column, its own status vocabulary, and its own explanation of how it differs from translation status, which is editor UX and belongs with Phase 14 rather than bolted onto this one.
