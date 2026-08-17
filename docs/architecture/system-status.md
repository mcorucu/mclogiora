# System Status and Diagnostics

## Scope

Workstream E provides one read-only diagnostic projection for the mcLogiora
System Status screen and WordPress Site Health. It does not add a REST status
route, a CLI command, a repair action, a cache flush, a provider connection
test, or any background work.

The historical `/mclogiora/v1/status` route in `PLANNING.md` is a route-family
sketch. The authoritative Workstream E deliverable is the System Status admin
surface and Site Health integration, so the route remains unregistered.

## Contract note

| Diagnostic | Source | Read cost | Mutation/network | Sensitive data | Surfaces | Operator action |
| --- | --- | --- | --- | --- | --- | --- |
| Environment | WordPress version/locale/multisite, PHP, database server, plugin constants, permalink option | Cheap scalar reads | None; no network | Versions and booleans only; no paths | Admin, debug info | Compare prerequisites and site configuration |
| Language configuration | `LanguageRepositoryInterface` and `Language` projections | One cached repository read | None | Counts, codes, and default state only | Admin, debug info, Site Health test | Configure or correct a default/active language |
| Schema and persistence | `TableNames`, `SchemaBuilder`, migration/version services, relation count methods | Schema probes and cheap counts | None; no repair | Table labels, readiness, counts, engine-safe metadata only | Admin, debug info, Site Health test | Reactivate/re-run the normal installation path outside diagnostics |
| Relation integrity | Existing relation counts; no full-table orphan scan | Cheap counts | None | No object IDs or hashes | Admin, debug info as summary | Use translation management workflows; no automatic repair |
| Routing | `RoutingSettings` and WordPress permalink option | Cheap option reads | None | Strategy and pretty-permalink state | Admin, debug info, Site Health test | Review permalink settings and flush through WordPress if needed |
| Object cache | WordPress object-cache capability functions | One runtime check | No cache reset | Boolean state only | Admin, debug info | Treat absent persistent cache as supported informational state |
| Suggestions/providers | `SuggestionSettings`, `ProviderRegistry`, `ProviderReadiness` | Local options and provider metadata | No provider call | Configured/model-selected booleans only; never keys or masks | Admin, debug info, conditional Site Health test | Configure the selected provider locally, or leave suggestions disabled |
| Builders/editors | Existing compatibility/editor detection | Existing detection only | No builder loading or network | IDs, labels, qualification metadata | Admin, debug info summary | Treat deferred commercial qualification as non-runtime backlog |
| Import/export | `PackageFormat` constants | Constant reads | No package generation or apply | Format identity/version only | Admin, debug info | No action; transport-neutral design is intentional |

## Surface contract

- `DiagnosticsService::collect()` returns scalar/array projections only. No
  domain object, credential, source hash, translated hash, filesystem path,
  database secret, private content, nonce, or preview token crosses the
  projection.
- Collection catches isolated subsystem failures and records a degraded
  finding instead of making the whole page fatal.
- The System Status page is a read-only submenu protected by the existing
  resolved `CapabilityRegistry::MANAGE` capability.
- Site Health receives one sanitized `mcLogiora` debug-information section and
  only actionable direct tests: default language, schema readiness, pretty
  permalinks, and an enabled-but-incomplete provider. Disabled suggestions do
  not create a failure.
- Site Health callbacks reuse the same service and never contact a provider or
  another external endpoint. Diagnostics do not cache their own result.
