# Code Standards & Static Analysis

mcLogiora enforces WordPress Coding Standards through PHPCS and static analysis through PHPStan. Both run locally and in CI, using the same configuration.

## Running the checks

```bash
composer install
composer check
```

`composer check` is the local quality gate and runs, in order:

| Step | Command | What it does |
| --- | --- | --- |
| 1 | `composer validate --strict` | Validates `composer.json` |
| 2 | `composer lint` | `php -l` across every PHP file |
| 3 | `composer phpcs` | WordPress Coding Standards |
| 4 | `composer phpstan` | Static analysis at level 5 |

Individual steps can be run on their own (`composer phpcs`, `composer phpstan`, …). `composer phpcbf` fixes the violations that can be fixed automatically.

CI runs the same four checks, plus a repository-hygiene job. If `composer check` passes locally, CI should pass too.

## PHPUnit

Phase 10 established the test suite. Run it with `composer test`, or as part of `composer check`.

The workflow and domain layers contain no direct WordPress calls, so they are tested without a WordPress installation. `tests/bootstrap.php` stubs the few primitives they use — `WP_Error`, the translation functions, and a handful of sanitizers. These stubs are minimal and faithful; they are not a WordPress emulator, and tests must not rely on behaviour beyond what the bootstrap defines.

Test doubles live in `tests/Support/`:

| Double | Replaces |
| --- | --- |
| `FakeContentGateway` | The WordPress post and term APIs, with injectable failures |
| `FakeRelationRepository` | Relation persistence, enforcing the same integrity rules as the database repository |
| `FakeLanguageService` | A fixed language set |
| `FakeWpdb` | Enough of `wpdb` to test repository contracts, including read-back failure |
| `WorkflowTestFactory` | Assembles the real workflow classes over those doubles |

Only the WordPress and database edges are replaced; the classes under test are the production ones, wired as production wires them.

## PHPCS sniff scoping

WPCS is written for procedural WordPress plugins: the global `$wpdb`, direct `wp_verify_nonce()` calls, and `class-*.php` file names. mcLogiora is PSR-4 and dependency-injected, which produces systematic false positives.

The baseline audit on 2026-08-08 found 458 violations. 359 of them came from four sniff families that cannot recognise the patterns this codebase uses. Each is excluded in `phpcs.xml.dist` and **scoped to the specific files that use the pattern**, so the sniffs remain fully active everywhere else, including files added by future phases.

| Sniff | Count | Scope | Why |
| --- | --- | --- | --- |
| `WordPress.Files.FileName` | 163 | Whole project | PSR-4 names class files after their class. Mutually exclusive with `class-*.php`; PSR-4 is the decision on record (ADR 0001, `composer.json`). |
| `WordPress.DB.PreparedSQL` + `PreparedSQLPlaceholders` | 141 | 3 files | `wpdb` is constructor-injected, so the sniff cannot see `$this->wpdb->prepare()`. |
| `WordPress.Security.NonceVerification` | 42 | 2 files | Nonces are verified via `Security::verify_nonce()`; the sniff does not follow static wrappers. |
| `WordPress.Security.ValidatedSanitizedInput` | 13 | 2 files | Input is sanitized via `Validation::*`; same wrapper limitation. |

### Manual audit of the excluded security sniffs

Because these exclusions cover security-relevant sniffs, the affected files were read line by line before the exclusions were added:

**SQL** — `SchemaBuilder`, `DatabaseLanguageRepository`, `DatabaseTranslationRelationRepository`. Every user-supplied value is bound with a `%s` or `%d` placeholder through `prepare()`. The only interpolated token is the table name, which comes from `McLogiora\Database\TableNames` as `$wpdb->prefix` plus a hard-coded suffix, contains no user input, and cannot be parameterized in MySQL. No query builds SQL from request data.

**Nonces and input** — `LanguageManager`, `SetupWizard`. Both verify a nonce and check a capability before acting on request data, and route input through the `Validation` helpers.

### Phase 10 attempt and why it was reverted

Phase 10 tried to remove `src/Languages/DatabaseLanguageRepository.php` from the SQL exclusion lists. Assigning a local `$wpdb` before each query does work for PHPCS: the sniff then sees `prepare()` normally, and the remaining table-name interpolation needs only a per-query `phpcs:ignore`. That took the file from 33 errors to zero.

It was reverted because the alias changes how PHPStan resolves those queries and reintroduces static-analysis errors. Trading one tool's correctness for another's is not an improvement, and forcing it through inside a feature phase would have meant baselining new PHPStan errors to fix PHPCS ones.

Converting these files needs a change that satisfies both tools, verified against both, in its own commit. That is the recommended shape of the follow-up below.

### Risk and follow-up

These exclusions mean the affected files are not machine-checked for SQL injection, nonce verification, or input sanitization. Phase 10 adds write workflows to exactly this area, so the risk is real and increases.

Before or during Phase 10, replace the file-scoped exclusions with something that restores machine checking. Options, roughly in order of preference: add `phpcs:ignore` annotations with justifications at the specific call sites; introduce a thin `wpdb` accessor the sniff recognises; or contribute wrapper-aware configuration upstream. Whichever is chosen, no new file should be added to these exclusion lists without the same line-by-line audit.

## PHPCS warnings

Errors fail the build; warnings are reported but do not (`ignore_warnings_on_exit`). Six warnings are currently outstanding, all of them reserved-keyword parameter names in value-object constructors:

| File | Parameter |
| --- | --- |
| `src/Relations/TranslationManager.php` (×2) | `$empty` |
| `src/Relations/InMemoryTranslationRelationRepository.php` | `$new` |
| `src/Content/TranslatableContentType.php` | `$public` |
| `src/Taxonomies/TranslatableTaxonomy.php` | `$public` |
| `src/Languages/Language.php` | `$default` |

Renaming them would change public method signatures, which is an API change rather than a standards fix, so they were left alone. They are safe to fix in any phase that already touches those signatures.

## PHPStan baseline

`phpstan-baseline.neon` records one pre-existing finding so CI can block **new** ones. The baseline exists to make debt visible, not to hide it. Do not regenerate it to make an error disappear — fix the error, or discuss it first.

### Resolved in Phase 10: `DatabaseLanguageRepository::create()` may return `null`

```
Method McLogiora\Languages\DatabaseLanguageRepository::create()
should return McLogiora\Languages\Language|WP_Error but returns null.
```

`create()` ends with `return $this->find_by_code( $language->code() );`, and `find_by_code()` returns `Language|null`. The declared contract is `Language|WP_Error`.

In practice the row was just inserted, so the lookup normally succeeds. If it does not — a failed read, replication lag, or an unexpected database state — `create()` returns `null`. A caller written against the contract will test `instanceof WP_Error`, take the success path, and fatal on a method call against `null`.

**Fixed in Phase 10.** `create()` now returns a `WP_Error` with the code `mclogiora_language_created_but_unreadable` when the post-insert lookup fails, and the baseline entry was removed. `DatabaseLanguageRepositoryCreateTest` covers the unreadable, failed-insert, and success paths.

The read-back is performed through a small private `read_back_created_language()` method rather than calling `find_by_code()` a second time directly. The two lookups run against different database states, and separate call sites make that explicit to readers and keep static analysis from treating them as one memoized expression.

### Remaining: `InMemoryTranslationRelationRepository::create_empty_group()` never returns `WP_Error`

```
Method ...::create_empty_group() never returns WP_Error
so it can be removed from the return type.
```

Not a defect. The in-memory implementation cannot fail, but its return type follows the interface, which database-backed implementations need. Narrowing it would break interface conformance. Left as-is deliberately.

## Integration tests

Phase 11 added a WordPress integration suite alongside the unit tests. Unit tests remain the primary suite; integration tests cover only behaviour that doubles cannot prove.

```bash
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest
composer test:integration
```

`bin/install-wp-tests.sh` downloads WordPress core and the official WordPress PHPUnit test library, and creates the test database. It needs `svn` and a reachable MySQL server. `tests/bootstrap-integration.php` then loads mcLogiora inside that environment, and `phpunit-integration.xml.dist` runs `tests/Integration`.

`composer check` deliberately runs only the unit suite, so the everyday gate stays fast and needs no database. CI runs both.

Integration tests never contact an external service.

### Query budgets

`tests/Integration/SeoQueryBudgetTest.php` asserts ceilings, not exact counts. An exact count would fail the first time WordPress changed how it caches terms, which would say nothing about this plugin. Current measurements on a translated page with three languages: the full alternate set costs 11 queries once, and canonical, x-default, the switcher, and a second `wp_head` render cost nothing after that.

### The suite's own installation is a regression test

`composer test:integration` installs WordPress from scratch with mcLogiora active. That installation is not scaffolding, it is coverage: Phase 12 shipped a `gettext` filter whose guard recursed through `_doing_it_wrong()` and killed the runner before PHPUnit printed a banner. `tests/Integration/InstallationSafetyTest.php` makes the proof explicit, so a future boot failure names itself instead of appearing as a silent process death.

When a run dies before the PHPUnit banner with no test name, suspect plugin boot rather than a slow test, and re-run with a trace on `plugins_loaded` before reaching for a timeout.

## Multilingual SEO output

Three authorities are single by design, and Phase 13 does not add a fourth of anything:

| Question | Authority |
|---|---|
| What does a translated URL look like? | `TranslatedUrlGenerator` |
| What language is this request? | `LanguageContext` |
| May multilingual behaviour run? | `RuntimeReadiness` |

Nothing in `src/Seo/` parses `REQUEST_URI`, rebuilds a path, or forms its own opinion about the current language. Canonical, `hreflang`, the switcher, and the sitemap all describe the same URLs, and one source is the only thing that stops them disagreeing.

Two rules are easy to break by accident:

- **Never emit a URL for a translation that does not exist.** An `hreflang` annotation pointing at a 404 is worse than no annotation, because it asserts a translation exists and then fails to produce it.
- **Never canonicalize a translation to its source.** That tells search engines the translations are duplicates to ignore, which deletes the value of translating the site.

Language values reaching markup go through `LanguageTag`. A WordPress locale is not a BCP 47 tag, and `hreflang="tr_TR"` is silently ignored by search engines while looking perfectly correct in the code.

See `docs/adr/0015-multilingual-seo-integration.md`.

## Lifecycle checks

Ask `McLogiora\Core\RuntimeReadiness` whether mcLogiora may act. Do not re-implement `wp_installing()`, `is_admin()`, a schema-version read, or a conditional query tag inside a module: duplicated checks drift, and one module ends up doing work during installation that its neighbour correctly refuses.

Two rules are load-bearing:

- **Never call a conditional query tag before `is_query_available()` returns true.** Before WordPress creates the main query, `is_preview()` and its siblings answer with `_doing_it_wrong()`, whose message is built with `__()`. Inside anything that runs during translation, that is an unbounded recursion.
- **A `gettext` filter's re-entry guard must wrap the decision as well as the lookup.** Deciding whether to translate calls WordPress, and WordPress returns translated strings from error paths.

See `docs/adr/0014-install-safe-runtime-lifecycle.md`.

## Translation catalogue

`languages/mclogiora.pot` is generated from source, not maintained by hand:

```bash
composer pot
```

This runs the official WP-CLI i18n command, which is a development dependency. The generated catalogue is committed; the tooling that produces it lives in `vendor/` and is not.


## Schema checks in the integration suite

The WordPress test suite rewrites `CREATE TABLE` into `CREATE TEMPORARY TABLE` so each test stays isolated. MySQL temporary tables never appear in `SHOW TABLES`, so any existence check built on `SHOW TABLES` reports freshly created tables as missing.

`SchemaBuilder::table_exists()` therefore uses a suppressed `DESCRIBE`. When adding schema code, do not switch it back to `SHOW TABLES` or `information_schema`: neither can see temporary tables, and the integration suite would go quietly and misleadingly green-then-red again.

A migration is complete only when its declared tables exist. Never treat an empty `$wpdb->last_error` as success: a statement that was never executed leaves no error behind, and dbDelta suppresses errors while inspecting tables. See `docs/adr/0012-verified-migration-completion.md`.
