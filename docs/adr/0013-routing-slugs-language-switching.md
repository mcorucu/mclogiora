# ADR 0013: Routing, Slugs & Language Switching

## Status

Accepted

## Context

Phases 10 and 11 built translation storage for posts, terms, strings, media, menus, and widgets. Every one of those services deliberately takes an **explicitly named language**, because nothing had yet earned the right to decide what language a request is in.

Phase 12 answers that question, and connects the stored translations to what a visitor actually sees.

## Decision

### One language context, and only one

`LanguageContextInterface` is the single authoritative answer to "what language is this request?". Every other subsystem — permalinks, the switcher, gettext, media, widgets, menus — receives it rather than working it out.

This is the most important constraint in the phase. Two resolvers would eventually disagree on some edge case, and the result is a page whose content is in one language, navigation in another, and interface strings in a third. Nothing else in the codebase parses the request for a language.

The resolved language is memoised per request, so it cannot change halfway through rendering.

### Resolution order, and what is deliberately absent

1. The language prefix mcLogiora itself put in the URL, if it names an active configured language.
2. The site default language.

That is the entire list. There is no IP geolocation, no external lookup, and no silent `Accept-Language` redirect. A visitor who requested a URL gets that URL. Guessing a visitor's language from their address and redirecting them is hostile: it strands people who deliberately chose a language, breaks shared links, and confuses crawlers.

### Directory URLs, default language unprefixed

```
example.com/about/          default (English)
example.com/tr/hakkimizda/  Turkish
```

The default language has **no** prefix by default. An existing single-language site keeps every URL it already has; turning the prefix on would change every published permalink at once. The option exists (`default_language_prefix`) for sites that want symmetry, and it is off unless chosen.

Only directory routing is implemented. Domain and subdomain strategies are named in `UrlStrategy` so the option has a stable shape, but they are not offered in the UI, because a control that silently does nothing is worse than an absent one.

### Language codes are untrusted input

Only active, configured languages become prefixes. `LanguageContext` normalises a candidate against a strict pattern and then checks it against the language repository. Anything else — an unknown code, an inactive language, a traversal attempt, a script fragment — is discarded and the request falls back to the default. Arbitrary request text can never become a language.

### Rewrite architecture and flushing

All rewrite handling lives in `RoutingModule`. One rule per language forwards everything after the prefix back through WordPress's own parser, so core, themes, and other plugins keep their rules and mcLogiora only adds the language segment. `.htaccess` and server configuration are never touched.

**Rewrite rules are flushed only when the routable prefix set actually changes.** The prefixes are fingerprinted and compared against a stored hash; an ordinary request does one option read and nothing else. Flushing per request would be a serious performance regression, and it is a common enough mistake that there is an integration test asserting five consecutive requests trigger zero rebuilds. Changing a switcher's display style never invalidates rules; changing the URL shape does, once.

### Missing translations return 404

If a translated URL is requested and no translation exists, the result is a genuine 404.

The tempting alternative — quietly serving the source-language content under the translated URL — is worse in every direction. The reader is shown a language they did not ask for, and search engines see the same content at two URLs, which is exactly what gets multilingual sites penalised for duplication.

**Menus are the deliberate exception.** A menu with no translation falls back to the source menu, because navigation is a wayfinding aid: an untranslated menu still works, whereas a vanished menu strands the visitor. This distinction is intentional and worth remembering when reading the code.

**Amended in Phase 13.1.** This rule was stated here from the beginning but nothing enforced it once the path resolved. It appeared to hold only because the verbose-page-rule defect below made most such paths fail to resolve at all; with that fixed, `/tr/<untranslated-slug>/` began serving source content exactly as this section forbids. The rule is now enforced explicitly: an object reached under a language prefix that has no relation in that language is turned into a 404 by `ObjectLanguageRedirect`.

### Path resolution follows WordPress core, including its verbose page rules

Under a language prefix, mcLogiora re-parses the remaining path against WordPress's own rewrite rules rather than inventing a second routing model.

Matching the first rule that fits is not what core does, and the difference is not cosmetic. When the permalink structure starts with `%postname%`, core sets `use_verbose_page_rules` and the page rule becomes a catch-all that matches any single-segment path — posts included. `WP::parse_request()` does not trust that match: it resolves the captured slug with `get_page_by_path()`, checks the resulting post status, and `continue`s to the next rule when either check fails, so the post rule further down gets its turn.

mcLogiora stopped at the first match. Every translated **post** under a language prefix therefore resolved to a page that does not exist and returned a 404, while translated pages worked, and the sitemap went on advertising the broken URLs to search engines. `RoutingModule::verbose_page_rule_matches()` now mirrors both of core's conditions.

The lesson worth keeping: when re-parsing a path, the reference implementation is core's, not a reasonable-looking approximation of it.

### An object's language is its own, not the request's

A stored object's language comes from its translation relation. The request's language comes from the URL. They are related but never interchangeable, and conflating them was the root of a second family of defects.

Two consequences follow:

- **Permalinks.** Asking for a translated object's URL returns that object's own language route, whoever is browsing. Previously the prefix came from the current request, so a Turkish translation linked from an English page produced an English-route URL — and Phase 13 had to correct the sitemap specifically for this, treating a symptom rather than the cause.
- **Routes.** WordPress resolves a slug regardless of language, so a Turkish translation also answered at its unprefixed English URL, declaring `lang="en-US"` and a canonical that appeared in neither of its own hreflang alternates. One address wins: the object's own language route is authoritative, and any other route that reaches the object is redirected there with a 301.

`TranslatedUrlGenerator::retarget_prefix()` exists for this. `apply_prefix()` stays additive and idempotent, which is correct while a URL is being assembled but cannot repair a URL that already carries the wrong language; re-targeting strips a routable language prefix before applying the intended one. Only a prefix that is genuinely a routable language is stripped, so a page slugged like a language code is left alone.

An object with no relation belongs to the default language and is never moved — it is ordinary monolingual WordPress content, and not mcLogiora's to redirect.

### Post slugs

Translations are separate WordPress posts, so each one uses its own `post_name`. There is no parallel slug store, no machine-generated slug, and no external service. Editors change a translated slug the normal way, and WordPress's own `wp_unique_post_slug()` uniqueness rules are respected rather than bypassed.

Hierarchical pages build their URL from the **translated** hierarchy: a translated child sits under its translated parent. When a parent has no translation, WordPress's own path is used rather than inventing a parent translation.

### Taxonomy slugs

Phase 11 created translated terms with a deterministic provisional slug (`name-languagecode`) purely to avoid colliding with the source. Phase 12 makes those replaceable: the translated term is a real WordPress term, so its slug is edited normally, and the URL generator uses whatever slug the term currently has. Taxonomy rewrite bases are respected. WooCommerce and LMS taxonomies remain excluded.

### One URL generator

`TranslatedUrlGenerator` is the only place that decides what a translated URL looks like. Switchers, template tags, and the future SEO phase call into it rather than assembling paths, because a second implementation would drift and start emitting URLs that do not resolve.

It never fabricates a URL. When a translation does not exist it returns `null`, and callers decide how to present that absence.

Resolving one language memoises the **whole** translation group, so rendering a switcher with five languages is one relation lookup rather than five.

### Permalink filters and their guards

Filters attach to `post_link`, `page_link`, `post_type_link`, `term_link`, and `home_url`. Two guards keep them safe:

- **Recursion.** The filters ask the generator for a URL, and the generator asks WordPress for a permalink, which would re-enter the filters. `PermalinkFilters::without_filters()` suspends them for the inner call.
- **Context.** `RuntimeReadiness` disables multilingual behaviour in wp-admin, cron, WP-CLI, AJAX, REST, autosave, and previews, and while WordPress is installing or the schema is absent. Rewriting links inside the admin would show editors URLs that do not match what they are editing; doing it during cron or CLI would silently change what those processes write. This originally lived in `RequestContextGuard`, which was replaced -- see `0014-install-safe-runtime-lifecycle.md`.

The bare home URL is deliberately left unprefixed, since it is also the base for assets and API endpoints that have nothing to do with content language.

### Applying the Phase 11 translations

| Domain | Hook | Notes |
| --- | --- | --- |
| Strings | `gettext`, `gettext_with_context` | Returns the original when untranslated; a re-entry flag prevents recursion |
| Media | `get_post_metadata` (alt), `wp_get_attachment_caption` | Read-time only; stored attachment values are never rewritten |
| Widgets | `widget_text`, `widget_custom_html_content` | Supported adapters only; unknown widgets untouched; nothing persisted |
| Menus | `wp_nav_menu_args` | Swaps the rendered menu only; stored location assignments unchanged |

**Plurals are not claimed.** Phase 11 stores one translated string per source string, which cannot represent the plural forms a language may need. Hooking `ngettext` would therefore return a singular translation for plural contexts and quietly corrupt the output. `ngettext` is deliberately left alone until the storage model supports plural forms properly. Supporting a safe subset honestly is better than appearing to support plurals and being wrong.

No filesystem scanning and no remote call happens during a front-end request. Scanning remains an explicit admin action, as established in Phase 11.

### Switcher architecture

`LanguageSwitcher` builds a view model; `SwitcherRenderer` turns it into markup. Templates never query repositories, so every surface shows the same languages and the same URLs.

Surfaces: shortcode `[mclogiora_switcher]`, a Gutenberg block, a classic widget, and the template tags `mclogiora_language_switcher()` / `mclogiora_the_language_switcher()`. Styles: inline list, dropdown, compact, and code pills.

Per-instance attributes override global settings without changing them, and every attribute is whitelisted — a shortcode can never inject an arbitrary URL, class, or redirect target.

### Flags are not languages

Flags are **off by default and never the semantic default**.

A language is not a country. Spanish is not Spain, Arabic belongs to no single state, English has no obvious flag, and picking one for any of these makes a political claim on a site owner's behalf. mcLogiora therefore ships **no** flag mapping at all: a site that wants flags supplies them through the `mclogiora_switcher_flag` filter, per language, deliberately.

The readable label never depends on a flag, so a flag-only inaccessible mode is not possible.

### Accessibility and direction

Every mode renders real links or a real `<select>` — never a div with a click handler — so keyboard and screen-reader users get working navigation with no JavaScript. The dropdown is enhanced by the external switcher script when available; its `<noscript>` fallback renders real language links.

Links carry `lang`, `hreflang`, and `dir`. The current language is marked `aria-current` with screen-reader text, and an unavailable language is announced as such rather than rendered as a dead link. The switcher is wrapped in a labelled `<nav>`.

Direction comes from each language's own metadata, so an RTL language listed on an LTR page is isolated correctly.

`hreflang` on switcher links is link semantics, not SEO output. **No `<link rel="alternate">` tag is emitted** — that belongs to Phase 13.

### Styling

One small stylesheet, namespaced, low specificity, no `!important`, no images, no web fonts. It is registered but only enqueued when a switcher has actually rendered, so pages without one load nothing extra.

## Consequences

- Visitors reach translated content at predictable, honest URLs.
- Untranslated content is reported as missing rather than disguised.
- Existing default-language URLs are unchanged by installing the phase.
- Rewrite flushing is bounded and observable.

### Phase 13 extension points

Phase 13 consumes this layer instead of rebuilding it:

| Need | Existing seam |
| --- | --- |
| hreflang alternates | `LanguageSwitcher::items()` already yields every language with a real URL or `null` |
| Canonical URL | `TranslatedUrlGenerator::post_url()` / `term_url()` |
| Sitemap alternates | The same generator, per object |
| OpenGraph locale | `LanguageContextInterface::current()` exposes the locale |
| SEO plugin integration | Read the context; never resolve the language independently |
