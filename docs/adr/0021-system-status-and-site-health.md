# 0021 — Read-Only System Status and Site Health

## Status

Accepted and implemented in Phase 17, Workstream E. The shared diagnostics
projection, System Status admin screen, and native WordPress Site Health
integration are complete. The historical `/mclogiora/v1/status` sketch is not
registered because no authoritative source requires a REST transport; the
admin and Site Health surfaces answer the Workstream E requirement.

## Decision

`DiagnosticsService::collect()` is the single transport-neutral source for
operator diagnostics. It returns scalar and array projections only and
isolates subsystem failures as findings. It does not cache its own result,
write state, repair schema, reset caches, scan all relation rows, or generate a
package.

The projection includes useful environment metadata, language/default state,
schema and cheap persistence counts, routing prerequisites, object-cache
state, local suggestion/provider readiness, compatibility metadata, and the
portable package format identity. Relation-integrity reporting is explicitly
limited to existing cheap counts; full orphan scans are not run on page load.

The System Status submenu uses the existing resolved
`CapabilityRegistry::MANAGE` capability and is rendered with semantic
headings, table markup, text status labels, and no state-changing controls.

WordPress Site Health receives one sanitized `mcLogiora` debug-information
section with private fields marked appropriately. Direct tests are limited to
actionable conditions: a valid default language, schema readiness, suitable
permalinks, and an enabled-but-incomplete suggestions provider. Disabled
suggestions are informational, not a failure. No provider connectivity test
or other external request is performed.

Credentials are represented only as configuration booleans. Keys, masks,
prefixes, suffixes, selected model values, filesystem paths, database
credentials, object IDs, hashes, private content, nonces, and preview tokens
never enter the diagnostic projection or either WordPress output surface.

## Qualification boundary

The collector and both native integrations were qualified against real
WordPress lifecycles on 7.0.4 and 7.1-RC3. Repeated collection and Site Health
filter evaluation produce no writes and no outbound HTTP requests. A narrow
table-probe seam tests missing-table degradation without damaging the shared
qualification database.
