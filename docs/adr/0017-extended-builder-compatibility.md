# 0017 — Extended Builder Compatibility

## Status

Accepted. Implemented in Phase 15 (v0.14.0).

## Context

Phase 14 established a payload adapter seam and used it for Elementor. Ten more builders were named as candidates. The obvious reading — write ten adapters — is wrong, and acting on it would have produced worse software than writing two.

Builders do not store content the same way. Some keep their layout in `post_content` as ordinary serialized blocks, where mcLogiora's existing content copy already carries it perfectly. Some keep it in post meta and need an adapter. Some are commercial products that cannot be legally installed here, so nothing about them can be demonstrated at all.

An adapter written for the first group would be code that does nothing. An adapter written for the third group would be guesswork with a compatibility badge on it.

## Decision

### Classify first, implement second

Every candidate was classified from a running copy or not classified at all:

| Classification | Meaning | Result |
| --- | --- | --- |
| Native | Layout lives in `post_content`; already copied | No code |
| Adapter | Layout lives outside `post_content` | Payload adapter |
| None | Nothing required | Detection only |
| Deferred | No legitimate copy available | No claim |

### No compatibility claim without a running copy

This is the rule the phase turns on. Detection strings, meta keys and storage models were read out of the installed plugin, never recalled.

That rule immediately found two defects in mcLogiora's own existing detection. `BuilderDetector` looked for Beaver Builder at `beaver-builder/beaver-builder.php` and SeedProd at `seedprod/seedprod.php`. The free Beaver edition ships as `beaver-builder-lite-version/fl-builder.php` and SeedProd has always shipped as `coming-soon/coming-soon.php`, so **both were silently never detected** — written from plausible names rather than from a running install.

Detection now prefers a class or function the builder itself defines, with basenames as a fallback. A class name survives an edition change and a directory rename; a basename survives neither. All thirteen detection signals were verified against running builders.

### Native block builders get no adapter

Kadence Blocks, GenerateBlocks and Spectra all store their layout as serialized Gutenberg blocks in `post_content`. Phase 10 already copies that, byte for byte.

Writing adapters for them would have added three classes that copy nothing, three more failure paths, and three more things to keep working. The correct implementation was no implementation, and the way to keep it correct is a test asserting the markup survives — using markup produced by each builder's own serializer, not markup that merely looks right.

That distinction mattered. Hand-written "Kadence-shaped" fixtures reported as invalid in the editor, and the source page reported invalid identically — the fixture was wrong, not the copy. Real serialized output from `wp.blocks.serialize()` validates on both source and translation.

### Beaver Builder follows Beaver Builder's own sequence

Beaver keeps the authoritative layout in post meta, so a copied post opens as an empty page. It needs an adapter.

The adapter does not invent a copy procedure. Beaver ships `duplicate_wpml_layout()` for exactly this case — handing a layout to a translation of the same post — and the adapter performs the same steps through the same public `FLBuilderModel` methods. It cannot call that method directly: it is an AJAX handler that reads `$_POST`, checks its own nonce and can `wp_die()`.

Two choices follow Beaver's precedent rather than intuition. Page-level custom CSS and JavaScript from `get_layout_settings()` are **not** copied, because Beaver's own multilingual duplication does not copy them. The draft layout **is** copied, because a source with unpublished builder changes would otherwise hand the translator a layout that does not match what the source shows.

### Generated assets are never copied

No adapter copies generated CSS, compiled assets, or cache markers. Elementor's `save()` clears stale Post CSS itself; Beaver's cache is cleared through the public `delete_all_asset_cache()`.

The stronger guarantee sits underneath: **mcLogiora copies no post meta at all.** A sentinel written to `_generateblocks_dynamic_css_version` on a source does not appear on the translation. That single property is why every block builder works without code, and why no builder's cache or version marker can travel by accident. It is asserted as a test so a future "just copy the builder's meta" change fails loudly.

### Failure rolls the draft back

A payload adapter returning `WP_Error` causes the workflow to detach the relation and delete the draft it just created. Pre-existing content is never touched, and no relation is left claiming a translation whose layout never arrived. Verified with an adapter registered through the public filter that always fails: no orphan draft, source intact, only the original relation item remaining.

### Payload compatibility and editor UX are separate claims

A builder can preserve layout perfectly and still have no mcLogiora panel inside its editor. Those are different facts and the compatibility record reports them separately.

No builder-side editor UI was added in this phase. Elementor and Beaver both have extension surfaces, but translation is already manageable from the WordPress editor, the Translation Manager and the posts list, and an embedded panel is a convenience rather than a compatibility requirement. Adding one is a later decision, not a gap in this one.

### Commercial builders are deferred, not unsupported

Bricks, Divi, WPBakery, Oxygen and Avada are commercial. None is on wordpress.org and none exists in any environment here. They are recorded as **deferred**: nothing proven, nothing claimed, no adapter written.

The user's own SEO plugin happens to contain adapters for several of them. That is second-hand evidence about builder internals, written for text extraction rather than layout duplication and possibly stale, and it was deliberately not used. Copying another plugin's assumptions would produce exactly the unevidenced badge this phase exists to avoid.

A deferred builder is not described as incompatible. Nothing is known, and the wording says so.

### Wording never calls a builder unsupported

A block builder needing no code is fully compatible. Saying otherwise would be wrong about the builder and wrong about the plugin. Status wording distinguishes *layout travels with the content*, *layout copied for translations*, *nothing extra required*, *detected but not verified*, and *not verified*. A test asserts no status label ever contains "unsupported", "incompatible" or "premium".

### Elementor site settings stay with the site

Phase 14 left this open. Elementor's global settings live in its Kit document, which belongs to the site rather than to any post. A translation is a post. Copying site-wide settings onto one would create a second source of truth for something the site already owns, and diverge silently the moment the real settings changed.

They remain uncopied, and this is now a decision rather than an omission.

### CI covers what CI can honestly cover

Elementor and ACF were live-qualified by hand in Phase 14, which left both able to break silently on a new release. A `WordPress builder compatibility` job now installs the current free editions of Elementor, ACF, Kadence Blocks and Beaver Builder and runs the adapters against them.

The set is deliberately small: one adapter-backed builder, one native block builder, one field framework, and Beaver Builder because it is the only free builder storing layout outside `post_content`. A full matrix belongs to Phase 18. Commercial packages are never downloaded.

Tests skip when their plugin is absent, so a wordpress.org outage degrades the job to "nothing extra proven" rather than a false failure. Elementor's layout test skips in CI for a different and honest reason: Elementor builds its runtime during activation, which a bare WordPress test install never runs, so `save()` fails inside Elementor's own code. Passing anyway would misreport coverage; failing would report an Elementor bootstrap gap as an mcLogiora defect. It is qualified live instead, and the test resumes by itself if that ever changes.

## Consequences

- Three block builders are supported with no code, and a test keeps it true.
- Beaver Builder translations start with the source layout.
- Two long-standing detection bugs are fixed.
- Compatibility is reported with nuance instead of a boolean.
- Five commercial builders are honestly unproven rather than falsely claimed.

## Phase 18 requalification expectations

| Item | Why it needs revisiting |
| --- | --- |
| Commercial builders | Each needs a licensed copy before any claim |
| Tested versions | Recorded per builder; a major release invalidates the claim |
| Beaver layout settings | Revisit whether page-level CSS should travel |
| Builder matrix breadth | A wider CI matrix belongs to release hardening |
| Builder-side editor UI | Optional convenience, still unbuilt |
