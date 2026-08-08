# ADR 0009: Fully Open-Source Product Model

## Status

Accepted

This ADR is a product-strategy decision. It supersedes the commercial assumptions embedded in earlier planning documents and amends ADR 0004.

## Context

Earlier planning material assumed a two-tier product: a free core distributed through WordPress.org, plus paid modules for WooCommerce, LMS platforms, translation memory, cloud synchronisation, and similar advanced integrations. That assumption shaped how documentation described boundaries. It produced admin copy such as "reserved for future add-ons" and a planning section titled "Future Premium Modules".

The paid tier was never implemented. There is no licence-key system, no activation server, no feature gate, no upgrade prompt, and no telemetry anywhere in the codebase. The commercial boundary existed only as a documented intention.

Retaining that intention had real costs:

- It made scope boundaries look like paywalls to anyone reading the source or the admin screens.
- It created pressure to hold back useful functionality in order to protect a future paid tier.
- It complicated the WordPress.org story, where upsell-oriented plugins face additional scrutiny and user distrust.
- It conflicted with the stated engineering priorities of performance, native WordPress behaviour, and honest user communication.

## Decision

mcLogiora is permanently free and fully open source.

1. The codebase is GPL-compatible and stays that way. All bundled assets and dependencies must be GPL-compatible.
2. There is no licence-key system and none will be added.
3. There are no paid feature gates.
4. There are no artificial feature restrictions used to differentiate editions.
5. There are no upgrade nags, upsells, or purchase prompts in the admin UI.
6. There is no tracking by default.
7. There is no telemetry by default.
8. There is no remote kill switch and no remote feature toggle.
9. Core multilingual functionality has no SaaS dependency.
10. Manual translation always works with no external service configured.
11. External translation providers are optional in every sense: optional to configure, optional to use, and never required for correctness.
12. External services require explicit user action and explicit configuration.
13. Where an external provider is supported, the user brings their own API credentials.
14. External-service usage is disclosed in accordance with WordPress.org requirements.
15. WooCommerce and LMS integrations are future free and open-source compatibility modules, postponed for scope and stability reasons only.
16. Performance and WordPress-native behaviour take priority over feature quantity.

There is no Pro edition, no Commerce edition, and no LMS edition. Any future packaging split, such as a companion plugin, is a technical decision about load cost and maintenance, never a commercial one, and every part remains free and open source.

## Consequences

### Retained

The modular architecture is retained in full. Module contracts, the adapter registry, editor and builder adapter interfaces, the translation provider interface, and the exclusion registries all stay exactly as designed. Modularity was always justified on engineering grounds — bounded load cost, testability, and stable extension points for third parties — and those grounds are unaffected by the pricing change.

Scope boundaries are also retained. WooCommerce and LMS content types remain excluded from the current foundation. What changes is the stated reason: not "reserved for paid modules" but "postponed to a later free phase".

### Removed

The commercial boundary itself. Documentation and user-facing copy no longer describe any functionality as premium, paid, or add-on-gated. The planning roadmap no longer contains a premium module section.

Bulk automatic publishing of machine translations, previously listed as a premium candidate, is dropped rather than moved. Machine output must always be reviewed by a human before publication, which is a product-safety position independent of pricing.

### Implications

- Third-party developers can build on the module contracts without wondering which extension points are commercially reserved.
- WordPress.org review is simpler: no upsell surfaces, no licensing code, no external activation calls to disclose.
- Contributions can be accepted without a contributor licence agreement negotiated around a proprietary tier.
- The project's sustainability model, if one is ever needed, must come from something other than feature restriction. That question is deliberately left open here rather than answered with a paywall.

### Verification

Any future change that introduces a licence check, an edition flag, a feature gate keyed to payment, an upgrade prompt, or default telemetry contradicts this ADR and must be rejected or must supersede this ADR explicitly.
