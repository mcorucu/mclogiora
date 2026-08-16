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
  replaced when the release version is chosen.
- Public API is covered by tests. A helper without a contract test is not
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

mcLogiora fires the actions and filters below. **None of them is part of the
public API yet.** They exist because internal subsystems needed them, their
argument lists have not been reviewed as contracts, and several of them sit on
top of security invariants. Promoting a hook means fixing its arguments,
proving its lifecycle position with a test, and writing down what a consumer may
and may not do with it; that work is its own tranche and has not been done.

Recorded here so the inventory is honest, not so it is used.

### Actions

| Hook | Fired in | Arguments |
| --- | --- | --- |
| `mclogiora_activated` | `Core\Activation` | `$installed` (`true` or `WP_Error`) |
| `mclogiora_deactivated` | `Core\Deactivation` | none |
| `mclogiora_register_settings` | `Admin\Settings\SettingsManager` | none |

### Filters

| Hook | Filtered in | Value |
| --- | --- | --- |
| `mclogiora_register_modules` | `Core\ModuleLoader` | Module list, plus the service container |
| `mclogiora_register_editors` | `Editors\EditorManager` | Editor adapter list |
| `mclogiora_register_payload_adapters` | `Core\Application` | Builder payload adapter list |
| `mclogiora_widget_adapters` | `Widgets\WidgetAdapterRegistry` | Widget adapter list |
| `mclogiora_feature_enabled` | `Core\FeatureFlags` | Whether a feature is on |
| `mclogiora_resolved_capability` | `Capabilities\CapabilityRegistry` | Effective WordPress capability |
| `mclogiora_switcher_flag` | `Switcher\SwitcherRenderer` | Optional flag character for a language |
| `mclogiora_seo_owns_concern` | `Seo\SeoCompatibilityManager` | Whether mcLogiora owns an SEO concern |
| `mclogiora_seo_output_open_graph_locale` | `Seo\SeoModule` | Whether to emit the OpenGraph locale |
| `mclogiora_seo_canonical_url` | `Seo\CanonicalService` | Canonical URL |
| `mclogiora_seo_x_default_url` | `Seo\AlternateUrlService` | `x-default` URL |

Two of these deserve a specific warning if you use them anyway.
`mclogiora_register_modules` hands out the service container, so consuming it
promotes every service inside it. `mclogiora_resolved_capability` decides which
WordPress capability an mcLogiora permission maps to, and a filter that returns
a weaker capability weakens every admin screen and every write behind it.

## Not public API

Named here so the boundary is explicit:

- Every class under `McLogiora\`, including the value objects the readers above
  project from, the repositories, `Core\Container`, and `Core\Application`.
- `Api\PublicApi` itself. The supported entry points are the functions.
- The `*_placeholder` methods on `TranslationRelationServiceInterface`. They are
  foundation-phase seams that outlived their phase.
- Test doubles under `McLogiora\Tests\Support`.
- The database schema and table names. Read through the API, not through SQL.
- Suggestion provider credentials, transports, previews, and settings storage.

## Not yet built

REST routes, WP-CLI commands, import/export, and the System Status and Site
Health surfaces are the remaining Phase 17 workstreams. See ADR 0019.
