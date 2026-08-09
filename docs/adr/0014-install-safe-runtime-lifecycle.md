# ADR 0014: Install-Safe Runtime Lifecycle

## Status

Accepted

## Context

Phase 12 (see `0013-routing-slugs-language-switching.md`) hooked `gettext` for the first time. With that filter in place, WordPress could no longer finish booting: the integration job died roughly forty seconds after the test suite announced `Running as single site...`, before PHPUnit printed a banner or a single test name. The runner was killed by the operating system, not by a job timeout — the CI workflow sets none.

The cause was a mutual recursion between the translation filter and the guard that decides whether the filter applies:

```
__( 'anything' )                                  during plugins_loaded
  → apply_filters( 'gettext' )
  → FrontendTranslationModule::filter_gettext()
  → RequestContextGuard::applies()
  → is_preview()
      $wp_query does not exist yet, so WordPress calls
      _doing_it_wrong( …, __( 'Conditional query tags do not work…' ) )
  → __()  →  apply_filters( 'gettext' )  →  …forever…
```

`wp-settings.php` creates `$GLOBALS['wp_query']` **after** `do_action('plugins_loaded')`. Every module registered on `plugins_loaded` therefore runs inside a window where conditional query tags are unsafe, and `RoutingSettingsScreen::register()` calls `__()` to name its admin screen. The recursion allocated stack frames until the machine ran out of memory.

The module's existing re-entry flag could not stop it: the flag was set *after* the guard ran, so it protected the lookup but not the decision.

This was never a test artefact. The same window exists on every real WordPress request, and the same recursion would fire the first time any plugin or theme translated a string before the main query was built.

Two further problems were visible in the same code. Each module answered lifecycle questions for itself — `RoutingModule` had its own `wp_installing()` and schema check, `SwitcherModule` had a third copy — and nothing in the codebase said what mcLogiora is allowed to do while WordPress is installing.

## Decision

### One lifecycle authority

`McLogiora\Core\RuntimeReadiness` is the single place that answers:

| Question | Method |
|---|---|
| Is WordPress installing or upgrading? | `is_installing()` |
| Has WordPress created the main query? | `is_query_available()` |
| Does mcLogiora's schema exist? | `is_schema_ready()` |
| Is this admin / REST / ajax / cron / CLI / autosave? | `is_admin_request()` and friends |
| May multilingual front-end behaviour run at all? | `is_frontend_runtime()` |

`RequestContextGuard` is gone; its checks moved here, minus the defect. Routing, permalinks, front-end translation, and the switcher all ask this one object. Duplicated checks are how two modules end up disagreeing about whether it is safe to touch the database.

### Conditional query tags are never called before the query exists

`is_frontend_runtime()` checks `is_query_available()` **before** `is_preview()`, and the ordering is load-bearing. This is the specific fix for the recursion, and the ordering is documented in the class so it is not "tidied" later.

### The gettext re-entry flag covers the decision, not just the lookup

`FrontendTranslationModule::translate()` sets its re-entry flag as its first statement and clears it in a `finally`. Deciding whether to translate calls WordPress, and WordPress answers plenty of things with a translated string: a `_doing_it_wrong()` notice, a `WP_Error` message, a database failure page. Any of those re-enters the filter. Guarding only the lookup leaves the larger half unguarded.

This is defence in depth. Either half alone stops the observed hang; both together stop the whole class of failure.

### Installation-safe boot policy

While WordPress is installing, mcLogiora may load its bootstrap, its contracts, and its container. It may **not**:

- read or require configured languages,
- read or require its own database tables,
- resolve a `LanguageContext`,
- generate a translated URL,
- look up a translated string, media item, widget, or menu,
- flush rewrite rules.

`PermalinkModule`, `FrontendTranslationModule`, and `SwitcherModule` register **no hooks at all** during installation. Registering filters that would only ever decline is weaker than registering nothing: there is no code path left to get wrong.

### Schema-not-ready behaviour: fail open

Installed-but-not-migrated is a different state from installing, and it is a state a real site can sit in — after a manual file upload, or between activation and migration. When the schema is absent, the front end must behave as an ordinary monolingual WordPress site:

- original strings, menus, media, and widget content,
- ordinary WordPress permalinks,
- no rewrite rules registered,
- no fatal, no loop, and no fabricated translated route.

### Readiness is cheap

`is_frontend_runtime()` sits in front of every `gettext` call on the site, so it must cost nothing. Every check is a constant lookup, a `function_exists`, or an `isset`. The one option read — the stored schema version — is memoised for the request once it answers affirmatively. Only the affirmative answer is cached: a request can install the schema, but nothing removes it mid-request, so the negative is the only direction that could serve a stale answer.

### REST is deliberately excluded

A REST response is the block editor's own data at least as often as it is a visitor-facing payload, and rewriting it would change what editors see while they work. Multilingual REST output is a deliberate non-goal of Phase 12, not an oversight. Cron, WP-CLI, admin-ajax, autosaves, wp-admin, and previews are excluded for the reasons already given in ADR 0013.

## Consequences

- One class must be updated when a new lifecycle question appears, and modules cannot drift apart.
- `RequestContextGuard` no longer exists. Code referring to it must use `RuntimeReadiness`.
- A site whose schema is missing renders as ordinary WordPress rather than erroring, which is quieter but correct: a visitor is not the right audience for a migration problem.
- Multilingual REST output stays unavailable until a phase decides deliberately what it should mean.

## Related fix

Once the boot blocker was cleared and the Phase 12 integration tests could execute for the first time, they exposed a second defect that had been hidden behind it. `RoutingModule` emitted `mclogiora_path` from its rewrite rules but never registered it as a query var, and WordPress discards unregistered query vars during `parse_request`. Everything after a language prefix was therefore thrown away, and every translated URL resolved to the site home. Both vars are now registered, and the path is honoured only when a language prefix actually matched — otherwise a hand-written query string could re-route any URL to any other.

## Regression tests

- `tests/Integration/InstallationSafetyTest.php` — WordPress installs and reaches PHPUnit with the plugin present; `gettext` cannot nest inside itself when the main query is absent; installation and a missing schema perform **zero** string-store lookups, proven with a spy; front-end modules register no hooks during installation; switcher registration runs no query; the readiness gate costs no query once warm.
- `tests/Unit/RuntimeReadinessTest.php` — the full lifecycle matrix, including CLI, REST, and autosave in isolated processes.
- `tests/Integration/FrontendTranslationIntegrationTest.php` — the Phase 11 stores reaching real WordPress output.
- `tests/Integration/RoutingIntegrationTest.php` — directory routes resolving to the translated object, unprefixed default routes, missing translations 404ing, and inactive languages staying unroutable.

The recursion test fails against the unfixed code by assertion rather than by exhausting the machine, which is the difference between a regression test and a reproduction.
