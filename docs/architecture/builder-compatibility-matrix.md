# Builder compatibility matrix

What mcLogiora has actually demonstrated, and against which version.

Every "live" row below was exercised against a running copy of that builder:
a source page was built, translated through the normal workflow, and the
translation inspected. Rows marked deferred were not tested, because no
legitimate copy of the product was available — not because anything is known
to be wrong with them.

Last qualified: Phase 15 (v0.14.0), WordPress 7.1-RC3, PHP 8.3.

## Summary

| Builder | Version tested | Layout storage | Strategy | Layout preserved | mcLogiora panel in builder | Qualification |
| --- | --- | --- | --- | --- | --- | --- |
| Elementor | 4.2.2 | Post meta, via document API | Payload adapter | Yes | No | Live |
| Beaver Builder | 2.10.3.2 (Lite) | Post meta (`_fl_builder_data`) | Payload adapter | Yes | No | Live + CI |
| Kadence Blocks | 3.7.9.1 | `post_content` (blocks) | Native | Yes | n/a — WordPress editor | Live + CI |
| GenerateBlocks | 2.4.0 | `post_content` (blocks) | Native | Yes | n/a — WordPress editor | Live |
| Spectra | 2.20.1 | `post_content` (blocks) | Native | Yes | n/a — WordPress editor | Live |
| SeedProd | 6.20.8 | Own non-public post type | None required | n/a | No | Live |
| Bricks | — | Not established | — | Not claimed | No | Deferred |
| Divi | — | Not established | — | Not claimed | No | Deferred |
| WPBakery | — | Not established | — | Not claimed | No | Deferred |
| Oxygen | — | Not established | — | Not claimed | No | Deferred |
| Avada | — | Not established | — | Not claimed | No | Deferred |

## What the strategies mean

**Native** — the builder stores its layout as ordinary block content in
`post_content`, which mcLogiora already copies when it creates a translation.
No builder-specific code exists, and none is needed. This is full
compatibility, not a lesser form of it.

**Payload adapter** — the builder stores its authoritative layout outside
`post_content`, so a copied post would open empty. mcLogiora copies the layout
through the builder's own public API when the translation is created.

**None required** — the builder is detected and coexists correctly, but has no
content mcLogiora needs to carry.

**Deferred** — no legitimate copy was available to test against. mcLogiora
makes no claim in either direction. A translation of a page built with one of
these may or may not carry its layout; nobody here has checked.

## Notes per builder

### Elementor

Layout copied through `Plugin::$instance->documents`. The translation is marked
with `set_is_built_with_elementor()`, without which it would hold a layout
Elementor never renders. Generated CSS (`_elementor_css`) is not copied and
regenerates for the translation; the version stamp is Elementor's current one,
not the source's.

Elementor's global site settings (its Kit) are deliberately **not** copied.
They belong to the site, not to a post.

### Beaver Builder

Tested against the free Lite edition. Published and draft layouts are both
copied, and the builder-enabled flag travels, following the sequence Beaver's
own `duplicate_wpml_layout()` uses. Page-level custom CSS and JavaScript from
`get_layout_settings()` are not copied, matching Beaver's own multilingual
behaviour. The translation's asset cache is cleared so it rebuilds.

The paid edition ships in a different directory (`bb-plugin`) and was not
tested, but detection is by class name and is unaffected by that.

### Kadence Blocks, GenerateBlocks, Spectra

Verified by translating markup produced by each builder's own block serializer
and confirming the translation parses into the same named blocks, with no
invalid fragments and no editor recovery prompt.

Hand-written markup that merely resembles these builders' output does **not**
validate — on the source page either. If you are writing fixtures, generate
them with `wp.blocks.serialize()` rather than by hand.

### SeedProd

SeedProd's landing pages live in a `seedprod` post type registered with
`public => false`. mcLogiora only translates public content types, so those
pages are correctly outside its scope and a translation request for one is
refused cleanly. Ordinary pages continue to translate normally with SeedProd
active.

### Bricks, Divi, WPBakery, Oxygen, Avada

Commercial products, none distributed through wordpress.org and none present in
any development environment available during qualification. No storage model
was established and no adapter was written.

To qualify any of them, install a licensed copy, build a source page, translate
it, and check whether the layout arrives. If it does not, the builder needs a
payload adapter written against its current public API — not against
assumptions about its meta keys.

## Continuous coverage

The `WordPress builder compatibility` CI job installs the current free
editions of Elementor, ACF, Kadence Blocks and Beaver Builder and runs the
adapters against them on every build, so a builder release that breaks an
adapter fails a build rather than a site.

Elementor's layout test skips in that job: Elementor builds its runtime during
activation, which a bare WordPress test install never performs, so saving a
document fails inside Elementor's own code. It is qualified live instead.
