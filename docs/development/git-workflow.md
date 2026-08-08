# Git Workflow

This document describes the canonical development protocol for mcLogiora. It applies to human contributors and to AI coding assistants equally.

## Repository layout

The repository is standalone. It is not stored inside a WordPress installation.

| Role | Path |
| --- | --- |
| Canonical projects root | `/Volumes/MCORUCU_WORK/Development/Projects` |
| WordPress environments root | `/Volumes/MCORUCU_WORK/Development/Environments` |
| This repository | `/Volumes/MCORUCU_WORK/Development/Projects/mclogiora` |
| Remote | <https://github.com/mcorucu/mclogiora> |

Paths above are specific to the primary maintainer's machine. Contributors should substitute their own equivalents; only the structural rule matters, which is that the repository lives outside the WordPress tree.

### WordPress environment symlink

The plugin directory inside the development WordPress installation is a **symlink** to this repository, not a copy:

```
wordpress/wp-content/plugins/mclogiora -> /Volumes/MCORUCU_WORK/Development/Projects/mclogiora
```

Create it with:

```bash
ln -s /Volumes/MCORUCU_WORK/Development/Projects/mclogiora \
      /path/to/wordpress/wp-content/plugins/mclogiora
```

This guarantees one source of truth. Never keep a second physical copy of the plugin inside `wp-content/plugins` — divergence between an environment copy and the repository is exactly the failure this layout exists to prevent.

If the WordPress installation runs inside a container, ensure the projects root is bind-mounted so the symlink resolves inside the container as well.

## Branch model

`main` is the stable, synchronised baseline. It always matches `origin/main`.

Every task gets a dedicated branch. Feature work is never committed directly to `main`.

Branch naming:

| Prefix | Use |
| --- | --- |
| `feat/` | New functionality |
| `fix/` | Bug fixes |
| `chore/` | Tooling, repository, and housekeeping work |
| `docs/` | Documentation-only changes |
| `refactor/` | Behaviour-preserving restructuring |

## Before starting work

Inspect the repository before changing anything:

```bash
pwd
git rev-parse --show-toplevel
git status --short --branch
git remote -v
git branch --show-current
git fetch --prune
git log --oneline --decorate -n 15
```

Then confirm:

- The working tree is clean, or any existing changes are understood and intentionally preserved.
- Local `main` and `origin/main` are synchronised.
- You are on the correct branch before editing.

If the working tree is unexpectedly dirty, stop and report what is dirty. Do not proceed on top of changes you did not make and do not understand.

## During work

- Stay inside the scope of the task.
- Never overwrite or discard work you did not create.
- No unrelated refactors. If you notice an unrelated problem, note it and leave it alone.
- Keep source changes and documentation changes coherent: if behaviour changes, its documentation changes in the same branch.

## Before committing

Run whatever validation applies to the change:

```bash
find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \;
composer validate --strict
composer exec -- phpcs      # when dev dependencies are installed
composer exec -- phpstan    # when dev dependencies are installed
```

Then review the change properly:

```bash
git status
git diff
git add <intended paths>
git diff --cached
```

Stage only intended files. Never `git add -A` without reading the resulting status first.

## Committing

Use focused commits with conventional messages:

```
type: short imperative summary

Optional body explaining why, not what.
```

Types: `feat`, `fix`, `chore`, `docs`, `refactor`, `test`, `perf`, `build`.

One logical change per commit. A commit that needs the word "and" in its summary is usually two commits.

## Pushing

Push only when the current task explicitly authorises it. Pushing publishes work to a public repository; it is not a routine save operation.

```bash
git push -u origin <branch>
```

## Integration

Integration is pull-request based. Open a PR from the task branch into `main`, let validation run, review the diff, and merge deliberately.

Direct feature commits to `main` are not permitted. The only commit made directly on `main` was the initial v0.8.0 baseline import.

## Prohibited

These are never acceptable, regardless of how convenient they seem:

- `git push --force` and `git push --force-with-lease` to shared branches
- Rewriting published history
- `git reset --hard` over changes you did not create
- `git clean` against a working tree containing untracked user files
- Silent `git stash` of someone else's changes
- Committing to unrelated repositories
- Automatic releases, tags, or version bumps as a side effect of another task
- Automatic deployment
- Committing secrets, credentials, `.env` files, database dumps, or vendor directories

## Release and distribution

Releases, tags, and WordPress.org SVN publication are separate, deliberate, explicitly authorised operations. They never happen as a by-product of feature or documentation work.
