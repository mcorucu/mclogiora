# ADR 0001: Foundation Architecture

## Status

Accepted

## Context

mcLogiora needs a production-ready foundation before multilingual features are implemented. The plugin must remain modular, WordPress.org compliant, and lightweight on normal requests.

## Decision

Use a thin root plugin file that defines constants, loads Composer or a fallback PSR-4 autoloader, registers lifecycle hooks, and defers work to `plugins_loaded`.

Use a core `Application` to register services and foundation modules. Use a small `Container` for shared services. Use `ModuleLoader` and `ModuleInterface` so future domains can register through stable contracts.

Do not create database tables, options, scheduled events, REST routes, AJAX handlers, or external service clients in Phase 02.

## Consequences

- Future features can attach through modules without expanding the bootstrap file.
- Foundation pages can exist without storing settings.
- Activation remains safe and reversible.
- Later phases must preserve conditional loading and avoid global side effects.
