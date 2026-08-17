# Developer API

This file is the published contract. Anything not listed under **Public API**
below is internal, whatever its PHP visibility, and may change in any release
without notice.

The decision record is [ADR 0019](../adr/0019-developer-and-operations-layer.md).

## Stability policy

- Public API is `mclogiora_`-prefixed, returns plain arrays and scalars, and
  never hands out a domain object. A `Language` or a `TranslationGroup` would
  promote every method on it, and every field behind it, into something that
  cannot change.
- A published array shape only ever gains keys. Removing or renaming one is a
  breaking change and gets a major-version note in `CHANGELOG.md`.
- `@since x.x.x` in the source means "the next release"; the placeholder is
  replaced when the release version is chosen. A hook's `@since` records when the
  hook was introduced, not when it was documented, so several supported hooks
  carry earlier versions than the read API.
- A supported hook carries an `@since` tag at its invocation. An unsupported one
  deliberately does not, so the source itself distinguishes them.
- Public API is covered by tests. A helper or hook without a contract test is not
  public.

## Public API

### Reads

All readers are safe to call before the plugin has booted, on a site with no
languages configured, and on a site whose schema has not been installed. They
return an empty array or `null` rather than raising.

| Function | Returns |
| --- | --- |
| `mclogiora_get_languages( array $args = array() )` | `array<int,array>` of language records. `$args['status']` accepts `active` (default) or `all`. |
| `mclogiora_get_default_language()` | Language record, or `null`. |
| `mclogiora_get_current_language()` | Language record for the current request, or `null`. |
| `mclogiora_get_translation( int $object_id, string $object_type, string $language )` | Translated object ID, or `null`. |
| `mclogiora_get_translation_group( int $object_id, string $object_type )` | Group record, or `null`. |
| `mclogiora_get_language_url( string $language, ?int $object_id = null, string $object_type = 'post', string $taxonomy = '' )` | URL, or `null`. |

### Template tags

Shipped in 0.11.0 and supported.

| Function | Returns |
| --- | --- |
| `mclogiora_language_switcher( array $args = array() )` | Switcher markup as a string. |
| `mclogiora_the_language_switcher( array $args = array() )` | Prints the switcher. |
| `mclogiora_current_language()` | Current language code, or `''`. |

`mclogiora_current_language()` and `mclogiora_get_current_language()` always
answer the same question; the first returns the code, the second the record.

`mclogiora_language_switcher()` is the supported name. The planning document
sketched `mclogiora_render_language_switcher()`; adding a second name for a
function that already ships would have been API sprawl for no gain.

## Record shapes

### Language record

```php
array(
    'code'         => 'tr',       // string, language code
    'locale'       => 'tr_TR',    // string, WordPress locale
    'tag'          => 'tr-TR',    // string, BCP 47 tag
    'native_name'  => 'Türkçe',   // string
    'english_name' => 'Turkish',  // string
    'direction'    => 'ltr',      // 'ltr' or 'rtl'
    'is_active'    => true,       // bool
    'is_default'   => false,      // bool
    'order'        => 1,          // int, display order
)
```

The internal status vocabulary is deliberately absent: `is_active` is the
question callers actually ask, and publishing the raw constants would freeze
them.

### Group record

```php
array(
    'group_key'    => 'a1b2…',    // string, opaque group identifier
    'object_type'  => 'post',     // string
    'source'       => array(…),   // one item record, or null
    'translations' => array(      // item records keyed by language code
        'en' => array(…),
        'tr' => array(…),
    ),
)
```

### Item record

```php
array(
    'object_id'   => 77,          // int
    'object_type' => 'post',      // string
    'language'    => 'tr',        // string
    'status'      => 'translated',// string, see below
    'is_source'   => false,       // bool
)
```

`status` is one of `original`, `missing`, `draft`, `translated`,
`needs_review`, `needs_update`, `machine_suggested`, `disabled`. The source
hashes and modified timestamps behind the change detector are not published.

### Object types

`post` and `term` are the types a theme normally asks about. `string`, `media`,
`menu` and `widget` records exist and are returned when asked for, but their
object identifiers are meaningful only to the subsystem that owns them.

## Security notes

- These functions read. None of them writes, so none of them takes a nonce or
  performs a capability check.
- **A returned object ID is a relation record, not permission to display that
  object.** The relation layer does not filter by post status or by the current
  user. Apply your own checks before rendering, exactly as you must after
  `get_post_meta()`.
- `mclogiora_get_language_url()` returns `null` when a translation does not
  exist. It never invents a plausible-looking URL, because serving
  source-language content under a translated URL is worse than offering
  nothing.
- Nothing here exposes provider credentials, provider responses, suggestion
  previews, or settings.

## Examples

Link the current post into every other language:

```php
foreach ( mclogiora_get_languages() as $language ) {
    $url = mclogiora_get_language_url( $language['code'], get_the_ID() );

    if ( null === $url ) {
        continue; // No translation in this language.
    }

    printf(
        '<a href="%s" hreflang="%s">%s</a>',
        esc_url( $url ),
        esc_attr( $language['tag'] ),
        esc_html( $language['native_name'] )
    );
}
```

Show which languages a post is still missing:

```php
$group = mclogiora_get_translation_group( get_the_ID(), 'post' );
$have  = null === $group ? array() : array_keys( $group['translations'] );

foreach ( mclogiora_get_languages() as $language ) {
    if ( ! in_array( $language['code'], $have, true ) ) {
        echo esc_html( $language['native_name'] ) . ' is untranslated.';
    }
}
```

## Hooks

mcLogiora fires fourteen hooks. Nine are supported contracts and are documented
below. Five are not, and are listed under **Unsupported hooks** so that finding
one in the source does not read as a promise.

> **Presence in source does not imply a compatibility guarantee.** Only the
> hooks under *Public actions* and *Public filters* are covered by the stability
> policy at the top of this file. Every supported hook carries an `@since` tag at
> its invocation; an unsupported one deliberately does not.

Each supported hook has a lifecycle contract test in
`tests/Integration/PublicHookContractTest.php`.

### Public actions

#### `mclogiora_activated`

*Since 0.1.0. Fired in `Core\Activation::activate()`.*

Fires once, last in the activation routine. The environment has already
validated, the schema install has been attempted, and any failure has been
recorded. It does not fire when validation fails, because activation aborts
before it.

| Parameter | Type | Description |
| --- | --- | --- |
| `$installed` | `true\|WP_Error` | Whether the schema install succeeded |

A `WP_Error` means the tables are not there. Anything that seeds data must check
it rather than assume a successful activation.

```php
add_action( 'mclogiora_activated', function ( $installed ) {
    if ( is_wp_error( $installed ) ) {
        return; // No schema; nothing to seed.
    }

    // Safe: mcLogiora's tables exist.
} );
```

#### `mclogiora_deactivated`

*Since 0.1.0. Fired in `Core\Deactivation::deactivate()`.* No parameters.

Deactivation deletes nothing: no table is dropped, no option is removed, and
every translation relation survives. Data removal is the uninstall routine's job
and is user-controlled. Consumers should follow the same rule.

### Public filters

#### `mclogiora_widget_adapters`

*Since 0.10.0. Filtered in `Widgets\WidgetAdapterRegistry::with_core_adapters()`.*

An adapter declares which keys of a widget's option array hold human-readable
text. A widget with no adapter is reported as unsupported and left completely
untouched, so this is the only way to make a third-party widget translatable.

| Parameter | Type | Description |
| --- | --- | --- |
| `$adapters` | `WidgetAdapterInterface[]` | Registered adapters, core set included |

Return the array. The core Text, Custom HTML and Block adapters are present when
it arrives, so a consumer can remove them as well as add its own. Entries that
do not implement `WidgetAdapterInterface` are ignored, and a non-array return
leaves the core set in place.

```php
add_filter( 'mclogiora_widget_adapters', function ( $adapters ) {
    $adapters[] = new My_Widget_Adapter();

    return $adapters;
} );
```

#### `mclogiora_register_payload_adapters`

*Since 0.13.0. Filtered in `Editors\Payload\PayloadAdapterRegistry::with_core_adapters()`.*

A payload adapter gives a newly created translation the starting state its
builder expects. It copies structure, never meaning; nothing here translates
text.

| Parameter | Type | Description |
| --- | --- | --- |
| `$extra` | `TranslationPayloadAdapterInterface[]` | Adapters to add. Always empty on arrival |
| `$registry` | `PayloadAdapterRegistry` | The registry the adapters are added to |

Additive only. The filtered value always starts as an empty array and the core
adapters are registered before it runs, so this hook cannot remove them. Entries
that do not implement `TranslationPayloadAdapterInterface` are ignored, as is a
non-array return.

Prefer returning your adapters over calling `$registry->add()` directly; the
return value is the contract.

#### `mclogiora_switcher_flag`

*Since 0.11.0. Filtered in `Switcher\SwitcherRenderer`.*

| Parameter | Type | Description |
| --- | --- | --- |
| `$flag` | `string` | Flag text. Empty by default |
| `$code` | `string` | Language code the flag is for |

**Return plain text, not HTML.** The value is placed in the switcher label and
escaped with `esc_html()` before output, so markup is displayed literally rather
than rendered. Escaping is mcLogiora's responsibility, and this filter is
deliberately not an HTML injection point.

Returning an empty string — the default — shows no flag. That default is a
decision, not an omission: a language is not a country, and shipping a mapping
would make a political claim on a site owner's behalf. Only consulted when the
switcher instance has flags switched on.

```php
add_filter( 'mclogiora_switcher_flag', function ( $flag, $code ) {
    $flags = array( 'tr' => '🇹🇷', 'de' => '🇩🇪' );

    return isset( $flags[ $code ] ) ? $flags[ $code ] : $flag;
}, 10, 2 );
```

#### `mclogiora_seo_owns_concern`

*Since 0.12.0. Filtered in `Seo\SeoCompatibilityManager::owns()`.*

Two plugins must never write the same tag. mcLogiora stands down automatically
for the SEO plugins it recognises; this filter is how a site settles ownership
for one it does not.

| Parameter | Type | Description |
| --- | --- | --- |
| `$owns` | `bool` | Whether mcLogiora owns the concern |
| `$concern` | `string` | One of `canonical`, `hreflang`, `og_locale`, `sitemap` |

Returning `false` removes that output entirely. For `hreflang` that usually
means the site has no language annotation at all, since none of the common SEO
plugins produces one.

#### `mclogiora_seo_output_open_graph_locale`

*Since 0.12.0. Filtered in `Seo\SeoModule`.* One `bool` parameter, `true` by
default. Return `false` for a theme that emits its own OpenGraph block, rather
than ending up with two `og:locale` tags. Only reached once mcLogiora already
owns the `og_locale` concern, so it cannot switch output back on for a site
where a recognised SEO plugin has taken it.

#### `mclogiora_seo_canonical_url`

*Since 0.12.0. Filtered in `Seo\CanonicalService::canonical_url()`.*

| Parameter | Type | Description |
| --- | --- | --- |
| `$url` | `string` | Canonical URL, or an empty string |
| `$subject` | `SeoSubject` | Request subject — see below |
| `$language_code` | `string` | Current language code |

Return a URL, or an empty string to suppress the tag. A non-string return is
treated as an empty string rather than printed. Escaping is mcLogiora's
responsibility; return a raw URL.

Singular requests never reach this filter: WordPress core prints their canonical
and mcLogiora does not compete with it.

#### `mclogiora_seo_x_default_url`

*Since 0.12.0. Filtered in `Seo\AlternateUrlService::x_default_url()`.*

| Parameter | Type | Description |
| --- | --- | --- |
| `$url` | `string` | Default-language URL, or an empty string |
| `$subject` | `SeoSubject` | Request subject — see below |

Return an empty string to omit the annotation. A non-string return is treated as
empty. The value arrives already empty when the default language has no
equivalent for this subject, because there is nothing honest to point at;
filling it in aims visitors at a guess.

#### The `SeoSubject` argument

`SeoSubject` is passed to the two filters above for context. **The class is
internal; only four of its methods are contract**:

| Method | Returns |
| --- | --- |
| `kind()` | `post`, `term`, or `home` |
| `object_id()` | `int`, zero for the home subject |
| `taxonomy()` | `string`, empty unless `kind()` is `term` |
| `is_home()` | `bool` |

Anything else on the class may be added, renamed, or removed. Do not type-hint
it in a signature you cannot change.

### Unsupported hooks

These exist, and removing them would break sites that already use them, so they
stay. They are **not** part of the public API, they carry no `@since`, and they
may change or disappear without a major-version note.

| Hook | Why it is not supported |
| --- | --- |
| `mclogiora_register_modules` | Hands out `Core\Container`. Supporting it would turn every service inside into a permanent compatibility contract, and a consumer can return a module list with the core modules missing, silently disabling the plugin. |
| `mclogiora_resolved_capability` | **Security boundary.** Every admin screen and every write path — translations, menus, widgets, media, strings, languages, suggestions — checks whatever this returns. A callback returning `read` opens all of it to any logged-in subscriber. It is not narrowed to "equal or stronger" because WordPress has no capability ordering to compare against: `current_user_can()` is a boolean per capability, and role plugins add capabilities no lattice here could rank. Inventing one would be a guess enforcing a security rule. |
| `mclogiora_feature_enabled` | Nothing in the plugin calls `FeatureFlags::is_enabled()`, and the flag table has fallen out of step with what shipped — switchers, SEO, builders and external services are all listed `false` and all exist. Publishing a filter over a table nobody reads would document a promise that is already untrue. |
| `mclogiora_register_editors` | The concept is sound, but supporting it would freeze `EditorInterface`, which still carries `get_placeholder_areas()` from the Phase 09 foundation and takes an internal `EditorContext`. Publishing an interface with a method named "placeholder" commits to keeping the placeholder. Deferred until that interface is reviewed. |
| `mclogiora_register_settings` | A reserved no-op. It passes no registry and mcLogiora registers no setting through it, so today it is a private alias for `admin_init`, which consumers already have. Deferred until a settings registry exists to hand out. |

To add a builder, use `mclogiora_register_payload_adapters` rather than
`mclogiora_register_editors`: payload adapters are supported and are what
actually prepares a translation's content.

## Not public API

Named here so the boundary is explicit:

- Every class under `McLogiora\`, including the value objects the readers above
  project from, the repositories, `Core\Container`, and `Core\Application`.
- `Api\PublicApi` itself. The supported entry points are the functions.
- The `*_placeholder` methods on `TranslationRelationServiceInterface`. They are
  foundation-phase seams that outlived their phase.
- `Contracts\BuilderAdapterInterface`, which nothing implements or consumes.
- `Seo\SeoSubject` beyond the four methods named above.
- Test doubles under `McLogiora\Tests\Support`.
- The database schema and table names. Read through the API, not through SQL.
- Suggestion provider credentials, transports, previews, and settings storage.

Three things are the exception, because using a supported filter requires them.
`WidgetAdapterInterface` and `TranslationPayloadAdapterInterface` are interfaces
you implement to register an adapter. The four `SeoSubject` methods listed above
are not implemented but read, on an object the filter hands you. All three are
covered by the stability policy; the rest of `SeoSubject` is not.

## REST API

Namespace `mclogiora/v1`. Reads on every resource, plus writes for translation
creation, relation membership and translation status. `/languages` is read-only.
Exactly one route creates a WordPress object (`POST /translations`), and the
only `DELETE` in the namespace removes a relation membership — never a post or a
term.

Every read response is projected from the same readers documented above, and the
write returns the same projection, so HTTP and PHP always answer the same
question the same way.

### Authentication

Ordinary WordPress REST authentication applies: cookies plus a nonce for
same-origin requests, and whatever else the site has configured. mcLogiora ships
no token system, no API key, and no CORS policy of its own, and it never will —
authentication is WordPress's job.

**Writes require no extra mcLogiora nonce.** A cookie-authenticated REST request
already needs `X-WP-Nonce`, enforced by WordPress before any route runs; without
it WordPress does not establish the user and the permission check refuses.
Layering an admin-form nonce on top would add nothing and would make Application
Password and other WordPress-native clients unable to call the write route at
all.

Permission is resolved through mcLogiora's capability boundary, the same one
every admin screen checks.

> **REST does not bypass WordPress object permissions.** A returned object ID is
> a relation record. Before fetching and rendering that object, apply the same
> checks you would after `get_post_meta()`.

### `GET /mclogiora/v1/languages`

Returns the configured languages, in display order.

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `status` | `active` \| `all` | `active` | `all` includes disabled languages and requires permission |

**Permission:** public for `status=active`. The active set is already published
on any page carrying a language switcher, and by the `hreflang` block on pages
that carry none, so withholding it over HTTP would protect nothing. `status=all`
adds languages that are configured but not enabled — unpublished site
configuration that nothing on the front end reveals — and requires permission to
manage translations.

```json
[
  {
    "code": "en", "locale": "en_US", "tag": "en-US",
    "native_name": "English", "english_name": "English",
    "direction": "ltr", "is_active": true, "is_default": true,
    "order": 0, "home_url": "https://example.com/"
  }
]
```

There is deliberately no "current language" field. The request language is
resolved from the routing prefix mcLogiora puts in front-end URLs, and a REST
request carries none, so "current" here would always be the site default.
Reporting it would be a confident answer to a question REST cannot ask.

### `GET /mclogiora/v1/relations`

Returns the translation group an object belongs to.

| Parameter | Type | Required | Notes |
| --- | --- | --- | --- |
| `object_type` | string | yes | One of `post`, `term`, `string`, `media`, `menu`, `widget`, `future` |
| `object_id` | integer | yes | Positive |
| `taxonomy` | string | no | Needed to resolve a URL for a term |

**Permission:** requires the capability to manage translations. See *Why the
relation routes are not public* below.

```json
{
  "group_key": "9f1c…",
  "object_type": "post",
  "source": {
    "object_id": 42, "object_type": "post", "language": "en",
    "status": "original", "is_source": true,
    "url": "https://example.com/about-us/"
  },
  "translations": {
    "en": { "…": "…" },
    "tr": {
      "object_id": 77, "object_type": "post", "language": "tr",
      "status": "translated", "is_source": false,
      "url": "https://example.com/tr/hakkimizda/"
    }
  }
}
```

`url` is `null` when no front-end URL can be resolved — a term whose taxonomy
the caller did not name, or an object type that has no URL at all.

### `GET /mclogiora/v1/translations`

Returns one translation of an object, with its source alongside.

Takes the same parameters plus a required `language`. Same permission.

```json
{
  "object_type": "post",
  "object_id": 42,
  "language": "tr",
  "source": { "object_id": 42, "language": "en", "status": "original", "…": "…" },
  "translation": { "object_id": 77, "language": "tr", "status": "translated", "…": "…" }
}
```

### Why the relation routes are not public

A relation record names object IDs whatever state those objects are in: a draft
translation, a private page, a scheduled post. The relation layer has never
filtered by post status or by the reader, and serving it anonymously would
announce that a private page exists and hand over its translation's ID.

A public projection is buildable — filter each item by what the reader may
actually see — but doing that correctly means correct read authorisation for
posts, terms, strings, media, menu items and widgets: six answers with six ways
to be subtly wrong. It is deferred rather than guessed at. `/languages` already
covers the public case.

### `POST /mclogiora/v1/relations`

Links an object that already exists into a translation group.

| Parameter | Type | Required | Notes |
| --- | --- | --- | --- |
| `object_type` | string | yes | `post` or `term` — the only types with a link workflow |
| `source_id` | integer | yes | The object whose group the target joins |
| `target_id` | integer | yes | The existing object to link as a translation |
| `language` | string | yes | Language the target represents |
| `taxonomy` | string | for terms | Required when `object_type` is `term` |

**Permission:** requires the capability to manage translations. The workflow
additionally requires `edit_post` on both objects for posts, so a caller who
passes the general check can still be refused for a specific object.

Returns the same shape as `GET /relations`, showing the group after the link.

**Linking creates nothing.** No post and no term is created, and neither the
source nor the target object is edited — not the title, content, excerpt, slug,
status, parent, author, dates, or revisions. Only a relation record is added.

Repeating a successful link returns 409 `mclogiora_object_already_related`. An
object belongs to at most one translation group, so a second link is a conflict
rather than a second success.

```
POST /wp-json/mclogiora/v1/relations
{ "object_type": "post", "source_id": 42, "target_id": 77, "language": "tr" }
```

### `DELETE /mclogiora/v1/relations`

Detaches an object from its translation group.

| Parameter | Type | Required | Notes |
| --- | --- | --- | --- |
| `object_type` | string | yes | `post` or `term` |
| `object_id` | integer | yes | The object to detach |
| `language` | string | yes | The language slot it occupies |

> **This removes translation relation membership. It does not delete the
> WordPress post or term.** The object survives with every field unchanged —
> content, meta, status, slug, parent, revisions, and for terms the name,
> description and taxonomy. It is never trashed. The resource this `DELETE`
> removes is the membership, which is why it lives under `/relations` and not
> under a content path. Deleting content remains WordPress's own job and is not
> reachable from this namespace at all.

```json
{ "object_type": "post", "object_id": 77, "language": "tr", "detached": true }
```

Repeating a successful unlink returns 404 `mclogiora_relation_item_not_found` —
the membership is already gone.

The **source item of a group cannot be detached**: 409
`mclogiora_relation_detach_original`. Removing it would orphan every
translation hanging off it.

The group itself survives losing its last translation, keeping its key and its
source. No group cleanup happens; that is the domain's existing behaviour, not
something REST adds.

### `POST /mclogiora/v1/translations`

Creates a translation of an existing post or term. **This is the only route in
the namespace that brings a WordPress object into existence.**

| Parameter | Type | Required | Notes |
| --- | --- | --- | --- |
| `object_type` | string | yes | `post` or `term` |
| `source_id` | integer | yes | The object to translate |
| `language` | string | yes | Language the new translation represents |
| `taxonomy` | string | terms | The source term's taxonomy |
| `translated_name` | string | terms | Name for the new term |
| `translated_description` | string | no | Description for the new term. Empty by default |

There is deliberately no `post_title`, `post_content`, `post_status`,
`post_author`, `post_parent`, `post_name`, `meta_input`, `tax_input`,
`featured_media`, `slug`, `parent`, `term_id` or term meta. This route is not a
`wp_insert_post` or `wp_insert_term` proxy with a translation record attached;
the workflow owns every creation default.

**Permission:** requires the capability to manage translations. The workflow
additionally requires `edit_post` on the source and `edit_posts` for its type
when translating a post, and `manage_categories` when translating a term.

> **Creation never adopts an object that already exists.** A term with the same
> name, or one already holding the slug the workflow wanted, is never handed
> back as the translation — a new term is created instead and the existing one
> is left untouched. To make an object that already exists the translation, use
> `POST /relations`. The caller has to choose that deliberately.

#### Posts

The new post is created with:

| Field | Value |
| --- | --- |
| `post_status` | **always `draft`** |
| `post_type` | the source's type |
| `post_title`, `post_content`, `post_excerpt`, `menu_order` | copied from the source |
| `post_author` | the source's author, or the current user |
| `post_parent`, `post_name`, meta, terms | not set |

#### Terms

The new term is created with:

| Field | Value |
| --- | --- |
| `name` | your `translated_name`, trimmed |
| `description` | your `translated_description`, or empty — never copied from the source |
| `taxonomy` | the source's taxonomy |
| `slug` | `sanitize_title( "{name}-{language}" )` — see below |
| `parent` | the translated parent, or `0` — see below |

**Slug.** The workflow derives a provisional, language-scoped slug rather than
letting WordPress derive one from the name alone. That stops a translation
colliding with its source when both names reduce to the same slug, and it is
recognisable so the slug manager can replace it later. **It is not a translated
slug and should not be treated as one.** If the derived slug is already taken,
WordPress makes it unique by suffixing; it never reuses the term already holding
it.

**Parent.** `0` when the source has no parent, and `0` when the source's parent
has no translation in the same language. Only when the source's parent *is*
already translated into the target language does the new term get that
translated parent. mcLogiora never builds a mixed-language hierarchy: a flat
translation is better than one hanging off a parent in another language. For
non-hierarchical taxonomies the parent is always `0`.

Relation status is `draft`.

> **Nothing is ever published.** A translation nobody has read does not go live
> because a client asked for one. The draft is a starting point for a person.

> **Nothing is machine-translated.** The draft begins as a copy of the source's
> text. No provider is contacted, by this route or any other.

Returns the same group projection as `GET /relations`; the new post is at
`translations[<language>].object_id`.

Repeating an identical request returns 409 `mclogiora_translation_exists` and
**creates no second post**. The language-slot check runs before the insert, so a
refused request costs nothing rather than creating a post that is then removed.

**Rollback.** If the relation write fails after the object exists — or, for
posts, the builder-payload step — the workflow deletes the object it just
created and leaves no orphan behind. That guarantee lives in the workflow, not
in REST, and both paths have regression tests against real WordPress. The
translation group itself survives holding only its source, the same state a
group reaches when its last translation is unlinked.

Repeating an identical term request behaves exactly as for posts: 409
`mclogiora_translation_exists`, and no term is created.

### `PUT|PATCH /mclogiora/v1/translations`

Moves an existing translation to a new status. Both verbs do the same thing.

`POST` is **not** accepted here: on a collection it means create, and one verb
meaning both "make a new draft" and "change a status" would be resolved by
guessing from which parameters arrived.

| Parameter | Type | Required | Notes |
| --- | --- | --- | --- |
| `object_type` | string | yes | Same allow-list as the reads |
| `object_id` | integer | yes | Positive |
| `language` | string | yes | Must be configured on this site |
| `status` | string | yes | One of the translation statuses |
| `taxonomy` | string | no | Only affects the URL in the response |

**Permission:** requires the capability to manage translations, checked before
any lookup. The workflow re-checks it independently, so authorisation does not
depend on the controller.

Returns the same shape as `GET /translations`, carrying the new status:

```json
{
  "object_type": "post", "object_id": 77, "language": "tr",
  "source":      { "object_id": 42, "language": "en", "status": "original",     "…": "…" },
  "translation": { "object_id": 77, "language": "tr", "status": "needs_review", "…": "…" }
}
```

**REST decides nothing about which transitions are legal.** Whether a move is
allowed, whether the source item may change status, and whether the caller may
manage translations are all answered by `TranslationWorkflowService`, the same
service the admin screens call. The route maps HTTP to one workflow call and
projects the result.

Repeating an identical request is **not** a no-op and **not** a second success.
The first call returns 200; an identical repeat returns 409
`mclogiora_status_unchanged` and changes nothing.

A status change is a bookkeeping change. It edits no post content, title, slug,
post status, modified time or revision, on either side of the relation, and it
contacts no translation provider — moving a status to `machine_suggested` does
not ask anyone to translate anything.

#### Every domain mutation is now reachable

The seven operations the translation domain supports — create, link and unlink
for posts and for terms, plus the status change — all have a route. No
translation mutation remains unexposed.

### Errors

Codes are the contract; messages are not. Every error carries an HTTP status.

REST-layer failures use the `mclogiora_rest_` prefix. A refusal that comes from
the domain carries the **workflow's own code**, unchanged, so the same refusal
reported by REST, by an admin screen and by a future CLI is identifiable as the
same thing.

| Code | Status | Meaning |
| --- | --- | --- |
| `mclogiora_rest_forbidden` | 401 / 403 | 401 when not logged in, 403 when logged in without permission |
| `mclogiora_rest_invalid_object_type` | 400 | `object_type` is not in the allow-list; the response `data.allowed` lists it |
| `mclogiora_rest_unknown_language` | 400 | The language is not configured on this site |
| `mclogiora_rest_relation_not_found` | 404 | The object belongs to no translation group |
| `mclogiora_rest_translation_not_found` | 404 | The group has no translation in that language |

Domain refusals from the status write:

| Code | Status | Meaning |
| --- | --- | --- |
| `mclogiora_translation_item_not_found` | 404 | No translation item for that object and language |
| `mclogiora_unknown_target_status` | 400 | Not a recognised status |
| `mclogiora_original_not_assignable` | 400 | `original` is a structural role, not a status you can set |
| `mclogiora_missing_not_assignable` | 400 | `missing` describes an absent translation |
| `mclogiora_status_unchanged` | 409 | Already at that status |
| `mclogiora_invalid_status_transition` | 409 | Not reachable from the current status |
| `mclogiora_original_status_immutable` | 409 | The source item of a group cannot change status |

Domain refusals from the membership routes:

| Code | Status | Meaning |
| --- | --- | --- |
| `mclogiora_cannot_link_to_self` | 400 | Source and target are the same object |
| `mclogiora_invalid_source_id`, `mclogiora_invalid_target_id` | 400 | Identifier is not usable |
| `mclogiora_rest_missing_taxonomy` | 400 | Linking terms without naming a taxonomy |
| `mclogiora_cannot_edit_source`, `mclogiora_cannot_edit_target` | 403 | You may manage translations, but not this particular object |
| `mclogiora_cannot_edit_terms` | 403 | You may not edit terms |
| `mclogiora_source_not_found`, `mclogiora_target_not_found` | 404 | Named object does not exist, or not in that taxonomy |
| `mclogiora_relation_item_not_found` | 404 | No membership to detach |
| `mclogiora_object_already_related` | 409 | The target already belongs to a translation group |
| `mclogiora_translation_exists` | 409 | That language slot is already filled |
| `mclogiora_post_type_mismatch` | 409 | A translation must use the same post type as its source |
| `mclogiora_taxonomy_mismatch` | 409 | A translated term must share its source's taxonomy |
| `mclogiora_same_language` | 409 | Target language equals the source language |
| `mclogiora_relation_detach_original` | 409 | The source item of a group cannot be detached |

Domain refusals specific to term creation:

| Code | Status | Meaning |
| --- | --- | --- |
| `mclogiora_rest_missing_taxonomy` | 400 | No taxonomy named for a term |
| `mclogiora_missing_translated_name` | 409 | The name was empty or only whitespace |
| `mclogiora_taxonomy_not_translatable` | 409 | That taxonomy is not available for translation |
| `mclogiora_source_not_found` | 404 | No such term in the taxonomy you named |

The 403s are worth noting: they arrive from the workflow *after* the permission
callback has already passed. Whether you may edit one particular post is not
knowable until the workflow has resolved which post that is, so a general
capability is never the whole check.

The 400/409 split answers two different questions. **400** means the request was
wrong whatever state the site is in — retry after fixing the request. **409**
means the request was well formed and conflicts with the state this translation
is actually in — retry after the state changes.

Invalid or missing parameters are rejected by WordPress's own argument
validation with `rest_invalid_param` / `rest_missing_callback_param` and a 400,
before any lookup runs. Errors never carry SQL, table names, class names, or
paths.

Permission is checked before the lookup, so an unauthorised caller cannot probe
which objects exist by telling a 403 from a 404.

### Not yet in REST

`/strings`, `/suggestions`, `/switcher`, `/import`, `/export` and `/status` are
sketched in the plan and are not registered. Nothing about suggestions is
reachable over REST: no provider credential, setting, preview or model cache is
exposed, and a REST read makes no outbound HTTP request at all.

## WP-CLI

Root command `wp mclogiora`. Reads go through the same functions documented
above; writes go through the same workflow services REST calls. CLI, REST and
PHP therefore answer the same question the same way and use the same field
names. Every mutation the translation domain supports is reachable as a
command.

Commands register only under WP-CLI. A web or admin request constructs no
command object and touches no WP-CLI symbol. mcLogiora takes no Composer
dependency on WP-CLI — the runtime provides it when it is the runtime — and the
command classes deliberately do not extend `WP_CLI_Command`, so a site without
WP-CLI can autoload them without fatalling.

Read commands support `--format` (`table` by default, plus `csv`, `json`,
`yaml`, `count`) and `--fields=<comma,separated>`, through WP-CLI's own
formatter. A field name outside the published set is an error, not an empty
column.

Mutation commands are human-first: a success line, no table and no `--format`.
Adding a formatter to them would either pollute machine output with the success
message or invent a mutation-only shape; an operator who wants the resulting
state runs `wp mclogiora relation get`, which is already machine-readable.

A successful command exits `0`. Anything refused — permission, invalid
argument, or a domain conflict — exits non-zero with a message on stderr. A
domain refusal carries the workflow's own code in parentheses, so the same
refusal stays identifiable whether it arrives from the CLI, from REST or from
an admin screen.

> ### Mutation commands need a WordPress user
>
> WP-CLI commands do **not** bypass WordPress or mcLogiora authorization.
> Running `wp` without `--user` leaves no current user at all, so every mutation
> is refused with `mclogiora_cannot_manage_translations`. That is correct, not a
> bug.
>
> Pass a sufficiently capable user with WP-CLI's own global flag:
> `--user=<login|id|email>`. There is deliberately no `--force`, `--as-admin` or
> `--skip-permissions`; shell access is not a WordPress capability.
>
> Read commands need no user because they perform no capability check — the
> read API never has.

### `wp mclogiora language list`

Lists configured languages.

| Option | Values | Default |
| --- | --- | --- |
| `--status` | `all`, `active` | **`all`** |

Fields: `code`, `locale`, `tag`, `native_name`, `english_name`, `direction`,
`is_active`, `is_default`, `order`, `home_url`.

> **`--status` defaults to `all` here, unlike the REST route.** REST defaults to
> active and gates the rest behind a capability because anonymous callers exist.
> Running `wp` means shell access to the server, which is already more
> privileged than any WordPress role, so that distinction buys nothing — and
> hiding configured-but-disabled languages from the person administering them
> would be the wrong default.

```
$ wp mclogiora language list --status=active --format=json
```

### `wp mclogiora relation get <object-type> <object-id>`

Shows the translation group an object belongs to, one row per language.

Fields: `language`, `object_id`, `object_type`, `status`, `is_source`, `url`.
`--taxonomy=<name>` is needed to resolve URLs for terms; without it the `url`
column is empty rather than wrong.

Errors with a non-zero exit when the object type is unknown, the identifier is
not a positive integer, or the object belongs to no group.

### `wp mclogiora translation get <object-type> <object-id> <language>`

Resolves one translation. Same fields and options.

A missing translation is an error with a non-zero exit — never an invitation to
create one. `wp mclogiora translation get` that quietly created a draft would be
a surprising thing for a `get` to do.

```
$ wp mclogiora translation get post 42 tr --fields=object_id --format=csv
```

### What the CLI shows that REST does not

Relation inspection returns object IDs whatever state those objects are in,
drafts and private posts included. That is deliberate and differs from the REST
routes, which are gated because anonymous HTTP callers exist. An operator with
shell access could read the database directly; withholding an ID from them would
be theatre. Secrets and internals stay out regardless — no credential, preview
token, source hash, table name or class name appears in any output.

### `wp mclogiora translation status <object-type> <object-id> <language> <status>`

Moves an existing translation to a new status by calling
`TranslationWorkflowService::change_status()`.

`<status>` is one of the canonical statuses — `draft`, `translated`,
`needs_review`, `needs_update`, `machine_suggested`, `disabled`. Friendly
aliases such as `approved` or `done` deliberately do not exist: a status that
works on one transport and not another means two vocabularies for one concept.

A valid status is not a legal transition. Which moves are allowed is the
workflow's answer, identically to REST — repeating the current status is
`mclogiora_status_unchanged`, and the source item of a group cannot change
status at all.

```
$ wp mclogiora translation status post 77 tr needs_review --user=admin
Success: post 77 in tr is now needs_review.
```

### `wp mclogiora relation link <object-type> <source-id> <target-id> <language>`

Links an object that already exists into a translation group. `--taxonomy` is
required for terms.

**Creates nothing.** Both objects must already exist; neither is edited. Posts
and terms dispatch to their own workflow, so the checks that differ between them
— post type against post type, taxonomy against taxonomy — still apply. The new
membership starts at `needs_review`, the same status REST produces.

```
$ wp mclogiora relation link post 42 77 tr --user=admin
$ wp mclogiora relation link term 5 9 tr --taxonomy=category --user=admin
```

### `wp mclogiora relation unlink <object-type> <object-id> <language>`

Detaches an object from its translation group.

> **This removes translation relation membership only. It does not delete the
> WordPress post or term.** The object survives with every field unchanged —
> content, status, slug, parent, revisions, and for terms the name, description
> and taxonomy. Deleting content is WordPress's own job and is not reachable
> from this namespace.

```
$ wp mclogiora relation unlink post 77 tr --user=admin
Success: Detached post 77 from its tr translation slot. The post itself was not deleted.
```

Repeating an unlink reports `mclogiora_relation_item_not_found`. The source item
of a group cannot be detached at all.

### `wp mclogiora translation create <object-type> <source-id> <language>`

Creates a new translation. **This is the only command that brings a WordPress
object into existence.**

| Option | Required | Notes |
| --- | --- | --- |
| `--taxonomy=<name>` | terms | The source term's taxonomy |
| `--name=<name>` | terms | Name for the new term |
| `--description=<text>` | no | Description for the new term. Empty by default |

There is deliberately no `--title`, `--content`, `--excerpt`, `--status`,
`--author`, `--parent`, `--slug` or `--meta`. The workflow owns every default,
and a flag for any of them would make this a clone command wearing a
translation label.

**Posts.** The new post is always a **draft**, carrying the source's post type,
title, content, excerpt, menu order and author, with no slug and no parent.

**Terms.** The new term takes your `--name` and `--description` — the
description is never copied from the source — in the source's taxonomy, with a
provisional language-scoped slug the workflow derives and a parent only when the
source's parent is already translated into the same language. WordPress
uniquifies the slug if it is taken.

The new relation starts at `draft` in both cases.

> **`create` creates. It never adopts an object that already exists.** A term
> with the same name, or one holding the slug the workflow wanted, results in a
> new term while the existing one is left untouched. To make an object that
> already exists the translation, use `wp mclogiora relation link` — a different
> operation, and the caller has to choose it.

**Nothing is machine-translated.** A created post starts as a copy of the
source's text and a created term takes the name you gave; no provider is
contacted.

```
$ wp mclogiora translation create post 42 tr --user=admin
Success: Created post 77 as the tr translation, in group 08dd6cb2-….

$ wp mclogiora translation create term 5 tr --taxonomy=category --name=Haberler --user=admin
```

Repeating an identical create returns `mclogiora_translation_exists` and
creates nothing. An occupied language slot is refused before anything is
inserted.

Creation is delegated to the same workflows REST and the admin screens use, and
those workflows own the compensation if a step fails after the object exists.
The CLI implements no rollback of its own.

### Not yet in the CLI

Nothing from the translation domain. Import/export and diagnostics belong to
later workstreams, and suggestions stay off every programmatic transport for the
reason given under REST.

## Not yet built

Import/export and the System Status and Site Health surfaces are the remaining
Phase 17 workstreams. See ADR 0019.
