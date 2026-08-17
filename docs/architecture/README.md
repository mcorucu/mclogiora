# Architecture

mcLogiora uses a thin plugin bootstrap, PSR-4 autoloading, a core application, a service container, and a module loader.

The Phase 17 operations layer is documented in
[`system-status.md`](system-status.md): diagnostics are shared across the
read-only System Status screen and native WordPress Site Health integration.

The kernel owns lifecycle, environment validation, module registration, admin foundation routing, conditional assets, capabilities, feature flags, and localization. Future multilingual domains must be added as modules behind contracts rather than as direct bootstrap logic.

Phase 09 adds dormant editor and compatibility foundations while still intentionally excluding:

- Translation CRUD.
- Translated WordPress object creation.
- String, media, menu, or widget translation.
- URL rewriting and slug translation.
- SEO output, hreflang, and canonical tags.
- REST endpoints.
- AJAX handlers.
- Content writes.
- Post and term creation.
- Post meta and term meta copying.
- Builder integrations.
- Editor hooks, editor assets, metaboxes, sidebars, and builder UI.
- External services.
