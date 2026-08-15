# 0016 — Editor Translation UX

## Status

Accepted. Implemented in Phase 14 (v0.13.0).

## Context

Phase 09 established an editor boundary and stopped there. `BlockEditorAdapter`, `ClassicEditorAdapter` and `ElementorAdapter` each answered `get_placeholder_areas()` with `status: planned`. Nothing was rendered anywhere, and a translator's only view of translation state was the Languages column on the posts list.

That meant leaving the editing screen to find out whether a translation existed, and leaving it again to create one. The information a translator needs is about the thing they are editing, so it belongs where they are editing it.

## Decision

### One model, three surfaces

`EditorTranslationModel` answers every question an editor UI has about one object: its language, its source, the status of each active language, the URLs to edit or view each translation, and whether this user may act.

The Block Editor panel, the Classic metabox and anything Phase 15 adds all render that one answer. They do not read relations themselves.

This is the whole architectural point. Three surfaces deriving translation state separately would drift — one showing a language another hides, one offering an action another refuses — and the drift would appear as bug reports about "the editor" that reproduce in one editor only. Capability is resolved in the model for the same reason: *may this person create a translation* must not have three answers.

### The editor renders; the server decides

No editor surface creates content. The one write action posts a form to `admin-post.php?action=mclogiora_create_translation` — the same endpoint the Translation Manager has used since Phase 10, with the same nonce, the same capability check, the same validation, the same rollback.

JavaScript therefore holds no authority it could be tricked out of, and there is exactly one implementation of what creating a translation means. A REST endpoint was considered and rejected: it would have duplicated an existing authenticated path to serve a UI that does not need it, and Phase 17 owns the public API question.

Actions are only offered where the server would accept them. A button that leads to a refusal is a promise the UI cannot keep, so `canCreate` is false for a language that already has a translation, for the source language, and for a user without the capability.

### WordPress 7.1 and the iframed canvas

WordPress 7.1 renders the editing canvas in an iframe. Nothing in this phase reaches into it.

The panel is registered with `@wordpress/plugins` and rendered by `PluginDocumentSettingPanel` from `@wordpress/editor`, both of which live in editor chrome outside the canvas. There is no cross-frame DOM access, no selector into editor internals, and no injected markup. Verified in the qualification environment: mcLogiora's stylesheet is present in the outer document and **absent from the canvas iframe**.

`wp.editor.PluginDocumentSettingPanel` is used rather than the identically named `wp.editPost` export. The latter has been deprecated since WordPress 6.6 and logs a console deprecation on every editor load — confirmed by reading the 7.1-RC3 build rather than assuming.

### No build pipeline

The panel is plain JavaScript against the `wp.*` globals WordPress already registers, with no JSX and no bundler.

A build step was considered. It would have bought JSX and nothing else here: the packages come from WordPress's own script handles either way, so there is no React duplication and no remote dependency in either design. What a bundler would have cost is a committed build artefact that can drift from its source, a `node_modules` tree, and a reviewer having to diff generated code. For a panel this size that is a bad trade. The file shipped is the file written.

This is a decision about *this* panel, not a rule. Phase 15 may well need a build; the enqueue is ordinary `wp_enqueue_script` and nothing about adding one later is blocked.

### Status has one vocabulary

`TranslationStatusPresenter` is the only place a status becomes something a person reads. Before it, the list-table column carried its own switch statement and each editor surface would have grown another.

Every status carries a label and a sentence explaining it, because "Needs update" is only useful if the reader knows it means the source moved on. Tone is a styling hint layered on top of text that is always rendered — status is never communicated by colour alone.

`machine_suggested` renders correctly if a relation somehow carries it, and no UI produces it. Phase 16 owns suggestions; a non-functional "translate" button would be worse than its absence.

### Source changes report what is known

A `needs_update` row states that the source changed and shows the modification timestamps the relation already records.

No diff is computed. A real side-by-side comparison is a feature, not a detail, and a partial one would mislead more than the plain sentence does.

### Elementor: copy structure through Elementor's own API

Phase 10 deliberately copied no Elementor metadata, which left a translator facing an empty canvas — asked to rebuild a design rather than translate one.

`ElementorPayloadAdapter` copies the layout through `Plugin::$instance->documents`: read `get_elements_data()` from the source, mark the target with `set_is_built_with_elementor()`, and `save()` the elements onto it.

Going through the document API rather than copying `_elementor_*` meta is the decision. Elementor's own `save()` writes the elements, records the template type, stamps the *current* Elementor version, and drops the stale generated CSS so it rebuilds for the translation. A hand-maintained allowlist of meta keys would have had to reproduce all of that and would rot at the next Elementor release. Verified in the qualification environment: the translation receives the layout and `_elementor_page_assets`, and **`_elementor_css` is not copied**.

Marking the target matters more than it looks. Without `set_is_built_with_elementor()` the translation stores the layout but is not flagged as an Elementor page, so the theme template renders and the translator sees an empty page with their layout invisibly present. That was a real defect in the first implementation, caught by qualifying against a live Elementor.

No Elementor Pro API is used, and every entry point is guarded so a site without Elementor loads the class without fataling.

### ACF: native editing, no value seeding

A translation is a separate WordPress post, so ACF already stores its values separately and already renders its field groups on the translated object. Language-specific values are what a multilingual site wants and they work with no mcLogiora code at all. Verified live: field groups render on the translation, values are per-object, and editing the translation leaves the source untouched.

Seeding a translation with the source's field values is **deliberately not implemented**. It looks small and is not: repeaters and flexible content store a count key plus generated per-row keys, clone and group fields rewrite names, relationship fields hold IDs that may themselves need translating. Each shape would have to be proven across the free and Pro field sets before the copy could be called safe, and a partial implementation would appear to work while quietly corrupting the harder types — worse for a translator than an empty field they can see is empty.

`AcfPayloadAdapter` therefore holds the seam rather than filling it: it reports ACF's presence and copies nothing. When value seeding is designed properly it replaces `copy()` and nothing else moves. mcLogiora stores no ACF values of its own and never will — a second store for data ACF owns would be two sources of truth for one field.

### Payload copying is not editor UI

`TranslationPayloadAdapterInterface` is separate from `EditorInterface` on purpose. Presenting state on an admin screen and preparing a translation's stored content are different jobs with different lifetimes — the first runs on a screen, the second runs once inside a workflow that may have to roll back. Fusing them would have produced one interface where every implementation ignored half the methods.

Adapters run after the relation is recorded, and a failure rolls back the same way the workflow already rolls back a failed relation: the relation is detached and the draft this operation created is removed. Pre-existing content is never touched. A draft whose layout failed to copy is not a usable translation, and leaving it attached would present a half-prepared page as a real one.

### Language is displayed, not edited

The panel shows the current object's language and does not offer to change it. Reassigning an object's language is a relation operation with group-integrity consequences, not a UI dropdown, and no such workflow exists yet. Showing a control that could corrupt a translation group would be worse than showing none.

### The panel does not disturb the editor

Opening the panel does not mark the post dirty, create an autosave, touch the block store, or intercept save. Verified in the qualification environment: `isEditedPostDirty()` is false after opening, saving works, content is preserved and blocks stay valid.

### Classic: a form that cannot nest

The Classic Editor wraps the whole screen in `<form id="post">`, and HTML does not allow a nested form — the parser discards it silently and the button is left submitting the post form instead.

So the create button is rendered in the metabox and its form is printed on `admin_footer`, outside the post form, tied together by the HTML `form` attribute. This keeps the action a POST to the shared endpoint. The alternative, a nonced GET link, would have been less code and a mutation over GET.

This was found by clicking the button in a real browser. It is invisible in server-rendered markup, which is worth remembering when reviewing the next editor integration.

## Consequences

- A translator sees language, source, per-language status and safe actions without leaving the editor, in both the Block Editor and the Classic Editor.
- An Elementor translation starts as the source's layout rather than an empty canvas.
- ACF works natively on translations, with value seeding honestly deferred.
- One status vocabulary is shared by the editor surfaces and the list-table column.
- No REST endpoint, no build pipeline, no editor DOM manipulation, and no new authority in JavaScript.

## Phase 15 extension points

| Need | Existing seam |
| --- | --- |
| A new builder's editor UI | Render `EditorTranslationModel::for_post()`; do not read relations |
| A new builder's layout copying | Implement `TranslationPayloadAdapterInterface`, register via `mclogiora_register_payload_adapters` |
| A new status | Add it to `TranslationStatus` and `TranslationStatusPresenter`; every surface picks it up |
| ACF value seeding | Replace `AcfPayloadAdapter::copy()`; the workflow already calls it and already rolls back on failure |
| Richer source-change reporting | `sourceChange` in the model already carries the timestamps a diff view would start from |
