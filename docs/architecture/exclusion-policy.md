# Exclusion Policy

Phase 05 defines calm scope boundaries for content and taxonomy support.

These are scope boundaries, not commercial ones. mcLogiora has no paid tier; everything it ships is free and open source. Content types listed as excluded are postponed for stability and maintenance reasons, and will be supported by free integration modules when their phases arrive. See `docs/adr/0009-fully-open-source-product-model.md`.

## Included In Current Foundation

Prepared for future workflows:

- Posts.
- Pages.
- Public custom post types.
- Categories.
- Tags.
- Public custom taxonomies.

## Excluded From Current Foundation

Not yet supported:

- WooCommerce products.
- WooCommerce orders.
- WooCommerce coupons.
- WooCommerce-specific post types.
- WooCommerce product categories, tags, attributes, and other WooCommerce taxonomies.
- LMS course, lesson, quiz post types where detected.
- LMS-specific taxonomies where detected.

## User-Facing Tone

The admin UI may state:

> WooCommerce and LMS support are planned as future free compatibility modules.

This must remain informational. No purchase links, upsells, blocking nags, tracking, telemetry, or remote checks are allowed. Copy must never imply that support is available behind a payment, because it is not and never will be.
