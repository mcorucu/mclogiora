# mcLogiora Documentation

This directory contains planning, architecture, and decision records for mcLogiora.

Current phase: Phase 18, Slice 1, Correctness, Security & Internal Hardening, complete. Phases 02 through 17 are complete; the version line remains v0.16.0. Phase 18 Slice 2 and the final release gates remain open.

Language management and relation records persist. Translation workflows cover posts, pages, public custom post types, public taxonomies, interface strings, media metadata, navigation menus, and supported widgets. Phase 12 decides which of them a given front-end request renders, Phase 13 describes that decision to search engines, Phases 14 and 15 provide the editor and builder surfaces, and Phase 16 adds review-only translation suggestions over bring-your-own provider credentials. Phase 17 adds the developer and operations layer: the public read API, hook contracts, REST endpoints, WP-CLI, import/export, and read-only System Status/Site Health diagnostics.

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

The reconciled development roadmap, including verified phase history and the Phase 18 scope, lives in `../PLANNING.md` section 20. The published developer contract is `architecture/developer-api.md`.
