# Design Authority Status

## Current status: authority unavailable

mcLogiora's admin UI was built against a design system referred to throughout the planning documents as **Skylearn**, originally held in a file named `skylearn-DESIGN.md`.

**That file is currently unavailable.** It was not present anywhere on the development machine during the repository rebaseline, and it was never committed to version control — it lived outside the plugin tree and was lost before the repository existed.

## What this means

The design system is not lost in the sense that it cannot be observed. It is embodied in the shipped implementation:

- `assets/css/admin.css` contains the working token and component layer.
- `PLANNING.md` section 2 records the intent in prose: bright sky blue primary actions, sun yellow reserved for achievements and rewards, leaf green for progress and success, gentle coral for recoverable errors, white and cool-tinted surfaces, generous spacing, rounded components, clear copy, large tap targets, visible focus states, and no harsh red or corporate-grey sterility.
- Every admin screen built in Phases 03 through 09 follows it.

What is missing is the authoritative specification: exact token values, the full component inventory, state definitions, spacing scale, and the rules that governed decisions the implementation happens not to exercise.

## Policy until the authority is restored

1. **The existing admin UI is frozen as the reference implementation.** Where the specification is ambiguous or absent, the shipped CSS and existing screens are authoritative.
2. **Do not reconstruct `skylearn-DESIGN.md` from memory.** A confidently-written but inaccurate specification is worse than an acknowledged gap, because later work would treat invented values as authoritative.
3. **Do not invent a replacement design system** and do not redesign existing screens.
4. **New admin surfaces should extend existing patterns**, reusing established classes and tokens rather than introducing new visual language.
5. **Do not generate visual or image assets** to fill the gap.

## Recovery task

Restoring the design authority is a separate maintenance task, deliberately not attempted during the repository rebaseline. When undertaken, it should:

- Search for surviving copies of the original file in backups, cloud storage, or older machine images before anything else.
- If no original survives, derive a specification **from the shipped CSS**, documenting observed values rather than remembered ones, and mark it explicitly as reconstructed.
- Commit the result into this directory so the authority is version-controlled from then on — the root cause of this gap was that the design file lived outside the repository.

Until that task completes, treat any statement about Skylearn not evidenced by `assets/css/admin.css` or `PLANNING.md` as unverified.
