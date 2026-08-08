# mcLogiora Documentation

This directory contains planning, architecture, and decision records for mcLogiora.

Current phase: Phase 11 string, media, menu, and widget translation (v0.10.0). Next planned phase: Phase 12, URL routing, slug translation, and language switching.

Language management and relation records persist. Translation workflows cover posts, pages, public custom post types, public taxonomies, interface strings, media metadata, navigation menus, and supported widgets. Which translated data is rendered for a given front-end request is deliberately not decided yet: URL routing, translated slugs, SEO output, switchers, editor translation UI, REST endpoints, AJAX handlers, and external providers remain future phases.

## Product model

mcLogiora is permanently free and fully open source under a GPL-compatible licence. There is no premium edition, no paid tier, no licence-key system, and no feature paywall. See `adr/0009-fully-open-source-product-model.md`.

## Contents

| Directory | Purpose |
| --- | --- |
| `adr/` | Architecture decision records, numbered and append-only |
| `architecture/` | Subsystem design documents |
| `database/` | Schema, ERD, indexes, and migration strategy |
| `development/` | Contributor workflow and code standards documentation |
| `design/` | Design system authority status |

The reconciled development roadmap, including verified phase history and planned Phases 10 through 18, lives in `../PLANNING.md` section 20.
