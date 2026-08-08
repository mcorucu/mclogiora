# Language Manager Architecture

Phase 07 converts language management from placeholder data to controlled plugin-data persistence.

## Domain Objects

- `Language` remains an immutable value object for code, locale, native name, English name, direction, status, order, and default-language state.
- `LanguageStatus` continues to support `active` and `inactive`.
- `LocaleValidator` validates locale-like strings structurally.
- `RtlDetector` detects right-to-left language codes and locales from a known RTL code list.

## Contracts

- `LanguageRepositoryInterface` preserves the existing read methods: `all()`, `active()`, and `default_language()`.
- Phase 07 adds language write and lookup methods to the same repository contract: create, update, enable, disable, safe delete, set default, reorder, find by code, and find by locale.
- `LanguageServiceInterface` exposes the same operations to admin modules so UI classes do not talk to SQL.

## Repository

`DatabaseLanguageRepository` is the production repository. It writes to `wp_mclogiora_languages` and checks relation tables before destructive language deletes.

`CachedLanguageRepository` wraps the database repository. It caches only the ordered language list and derives active/default/code/locale reads from that list. Successful writes invalidate the single language cache key.

`InMemoryLanguageRepository` remains available for tests and fixtures and implements the expanded contract.

## Admin Screen

The `mcLogiora -> Languages` screen supports:

- Add language.
- Edit language fields except the stable language code.
- Enable and disable non-default languages.
- Set default language.
- Numeric reorder.
- Disabled delete control with integrity copy.

All actions use capability checks and nonces. Delete support exists in the repository but remains disabled in the UI until a later phase can display integrity details clearly.

## Boundary

Language records are plugin data only. Phase 07 does not create translations, translation relations, translated posts, translated terms, URL rules, switchers, REST endpoints, AJAX handlers, SEO output, role changes, scheduled events, or external service calls.
