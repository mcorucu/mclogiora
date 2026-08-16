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

The three interfaces a supported filter requires — `WidgetAdapterInterface`,
`TranslationPayloadAdapterInterface`, and the `SeoSubject` methods above — are
the exception. Implementing them is how those filters are used, so they are
covered by the stability policy.

## Not yet built

REST routes, WP-CLI commands, import/export, and the System Status and Site
Health surfaces are the remaining Phase 17 workstreams. See ADR 0019.
