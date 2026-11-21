# Isolated package tests

The package keeps its existing BrowserKit behavioral assertions on a dedicated Laravel application created by `tests/Support/PackageTestHost.php`. It uses the installed locked framework without booting or writing the application skeleton in `vendor/laravel/laravel`. No additional Testbench dependency is required. The runtime Composer graph now targets Laravel 13; the immutable Laravel 12 baseline manifest/lock and local vendor backup are preserved for the forward-upgrade fixture.

Run from the repository root with Docker Compose v2:

```sh
scripts/test-container pgsql
scripts/test-container mysql
```

Each invocation builds the digest-pinned PHP 8.5 / Composer 2 image, creates a unique Compose project/network, starts only its selected database, waits for its TCP endpoint, runs the suite, and removes its containers/network/volumes even on failure. PostgreSQL and MySQL data are in tmpfs; no ports or persistent/shared database volumes are exposed. The checkout, including vendor, is mounted read-only; only `.scratch/php85-laravel13-upgrade/artifacts` is writable for results. Compose explicitly uses `/dev/null` as its environment file instead of loading the repository `.env`.

Tests fail before application boot unless the explicit disposable flag, image marker and exact test service/database/user match. Never bypass that guard to point tests at another database. Each PHP process owns a marked random temporary application root for published controllers/config/assets, views, sessions, caches and uploads. Every test resets only the dedicated database schema; the host root is removed at shutdown. A run-scoped root permits repeated named migration loading without class redeclaration. Use a separate runner invocation for independent/concurrent runs; never share one database service between simultaneous test processes.

Faker uses a stable seed. Form attribute assertions compare values as data rather than interpolating generated names into CSS selectors, so apostrophes remain valid fixture input. The existing test admin configuration is preserved exactly, including legacy grid action rendering; this is separate from testing the package's default dropdown actions in later feature acceptance.

The candidate uses tagged BrowserKit 7.3 and PHPUnit 12.5 with its 12.5 configuration schema. At issue06, current and lowest independent Laravel13 graphs passed the then-retained96 tests on both engines without reported warnings/deprecations; final issue20 expands this to225 tests in each of four lanes. The two Laravel 12 PDO constant deprecations remain part of historical baseline evidence.

Run a focused check or collect deprecations by passing the PHP command after the engine:

```sh
scripts/test-container pgsql vendor/bin/phpunit --filter AuthTest --display-deprecations
scripts/test-container mysql vendor/bin/phpunit --display-deprecations --log-junit /results/baseline-mysql.xml
```

Synthetic performance characterization uses the same safety guard and disposable host:

```sh
scripts/test-container pgsql scripts/benchmark-baseline.php
scripts/test-container mysql scripts/benchmark-baseline.php
```

The benchmark records the real grid and form routes, an image transform/thumbnail and a 10,000-row exporter query/chunk path. Its CSV stream is deliberately owned by the benchmark, replacing the package exporter's `send/exit` transport; it does not certify selected/current/all export scopes, response headers or browser downloads. Final export acceptance belongs to issue 15. Timings are characterization samples, not equivalent to a completed performance regression gate; final candidate comparison uses repeated warm measurements under the documented dataset and budgets.

Standalone install/cache/generator/forward-upgrade fixtures can reuse `PackageTestHost::create()` and the guarded container environment. Fresh-install success does not replace the existing-host upgrade and rollback acceptance in issue 20. No real consumer application is changed by these fixtures.

## Independent Laravel 13 dependency hosts

Versioned complete current and lowest graphs live under `tests/Fixtures/dependencies/{current,lowest}/composer.{json,lock}`. Versioned `host-*` pairs in the same directory are the clean-checkout CI inputs; `scripts/ci-prepare-dependencies current|lowest` materializes and installs their applications under ignored `.scratch`. These are development acceptance fixtures; the package does not ship a consumer lock. The root ignored lock mirrors `current/composer.lock`. The original Laravel 12 lock stays under `baseline/`, and the local untracked `baseline-vendor/` backup remains available for issue 20.

Run `php scripts/prepare-dependency-host.php current` (or `lowest`) to create a native disposable host manifest, bootstrap, config, route and Blade view. Install it in the pinned image with the checkout mounted read-only at `/application` and only its `dependencies/host-current` directory writable at `/host`, working directory `/host`, using `composer install --no-interaction --no-progress`. The committed fixture lock freezes the graph; `composer update` deliberately refreshes it and requires a new audit. The manifest installs this checkout as a Composer path dependency with its own `/host/vendor`, and loads package config using the host's native config loader. Tests derive the package manifest vendor directory from the active Composer graph.

```sh
scripts/test-dependency-container current pgsql
scripts/test-dependency-container current mysql
scripts/test-dependency-container lowest pgsql
scripts/test-dependency-container lowest mysql
```

This runner reuses the existing database guard, creates a unique disposable Compose project, and mounts both checkout and host read-only. Only generated host caches/views use tmpfs. Its default command explicitly bootstraps `/host/vendor/autoload.php`; it cannot silently fall back to root vendor. `scripts/probe-dependency-host.php` separately checks native app discovery/config/routing/Blade and reports the actual framework/vendor paths. Complete commands, tested version floors, graph hashes, platform/audit results and remaining downstream acceptance are in the issue 06 validation report. These results establish dependency installability and retained harness compatibility; the final release behavior/browser/optional integration matrix remains required.

## Native install lifecycle acceptance

` scripts/test-dependency-container current pgsql /application/scripts/probe-install-lifecycle.php ` runs the native Composer-discovered provider/install/publish/cache/uninstall lifecycle in an additional temporary host using the selected independent vendor graph. Repeat with `current mysql` or `lowest pgsql`. It checks unpublished defaults, custom config/routes/guards/connection/table prefix, both route macros and builders, HTTPS/domain URL isolation, scoped host/admin Blade escaping/extensions, all five publish tags, normal/forced publishing, repeat/partial-install preservation, real migration failure, caching equivalence and conservative uninstall boundaries, including an external ancestor symlink pointing inward in cached and uncached configuration. Every exception/unexpected child exit fails the process. It never uninstalls the checked-in independent host itself. Full behavior and exact commands/results are in `.scratch/php85-laravel13-upgrade/references/issue07-validation.md`.

Installation still requires publishing migrations before `admin:install`. Repeat installation preserves an existing admin directory and seeds only if all nine admin tables are empty. `admin:publish --force` deliberately overwrites published resources, including supported view overrides. Uninstall removes only unchanged current generated/published config/controller/bootstrap/routes/asset files inside the host; custom files, symlinks, directories, migrations, translations, views and all tables/data remain.

## Authentication and session acceptance

`scripts/test-dependency-container current pgsql /application/scripts/probe-authentication.php` exercises the real HTTP kernel with the independent vendor graph, file-backed PHP sessions, configured identity/provider/connection, remember-cookie reauthentication, guard coexistence and request-forgery middleware outside its unit-test bypass. Repeat with `current mysql`, `lowest pgsql`, or append `json` for explicit disposable JSON serialization. This is a kernel/cookie fixture, not a real-browser/TLS-server check. Evidence and limitations: `.scratch/php85-laravel13-upgrade/references/issue08-validation.md`.

Logout now requires POST with CSRF/origin protection. The bundled navbar contains a CSRF form; customized headers/clients must make the same transport change. GET and HEAD logout are harmless redirects; only POST can mutate identity. Existing consumer session/cookie/cache defaults are preserved. If explicitly using `admin.session` middleware, optional `admin.session.path`/`domain` overrides are scoped around web cookie middleware and restored afterward, including when listed after `web`. Bundled toastr/alert views accept both PHP MessageBag and JSON array representations; customized published views must adopt that compatibility if their host selects JSON. No PHP-to-JSON session migration is performed or approved by these checks.

## Authorization and operation audit boundaries

`scripts/test-dependency-container current pgsql /application/scripts/probe-authorization.php` runs the real HTTP kernel with CSRF unit bypass disabled, custom guard/domain/nested prefix, connection/table names and compiled routes. Repeat with `current mysql` and `lowest pgsql`. It checks actual action/picker/widget context producers, allowed/denied/forged handlers, revocation, administrator bypass, grant rollback and redacted raw audit storage. Full boundary regressions are in `AuthorizationBoundaryTest`. [Issue09 evidence](../.scratch/php85-laravel13-upgrade/references/issue09-validation.md) defines the migration contract: all generic requests need `_context`, nonadministrators need explicit boolean authorization/model policy or a class-specific `admin.handler_abilities` slug, and every batch record is checked. Built-in credential/context redaction applies before logging and when reading historical inputs; `operation_log.sensitive_keys` adds alternate keys and `failure_policy` chooses best-effort `ignore` (default) or generic503 `abort`. This kernel fixture does not certify browser/UI integrations.

## Grid query/filter and log viewer acceptance

`GridQueryTest` uses equivalent PostgreSQL/MySQL data for custom/zero keys, soft deletes, scopes, eager relations and scalar relation sorting, JSON numeric sorting, all 19 registered filters and all six presenters, named pagination, normal/PJAX redirects, totals, search/selectors, malformed inputs and historical query qualification. `scripts/test-dependency-container lowest pgsql /host/vendor/bin/phpunit -c /application/phpunit.xml.dist --bootstrap /host/vendor/autoload.php --filter GridQueryTest` repeats these cases against the independent minimum vendor graph; replace `pgsql` with `mysql` for its dialect lane.

`scripts/test-dependency-container current pgsql /application/scripts/probe-grid-logs.php` checks the actual LogController through native middleware with request-forgery bypass disabled and a configured connection/table/guard/domain/prefix. It verifies stored values, historical display masking, all log filters, normal/PJAX pagination/empty results/redirects, method-specific view/delete grants, malformed deletion, single/batch deletion, compiled routing and logging enable/exclusion/failure policies. Repeat `current mysql` and `lowest pgsql`. These are HTTP-kernel checks; browser acceptance remains issue 19.

Request sorting now requires a server-declared `sortable()` column. `sortable('SIGNED')`, `sortable('DECIMAL(10,2)')` and deprecated `cast()` configure SQL conversion on the server; `_sort[cast]` is ignored. JSON columns use the existing `column('payload->rank')->sortable('SIGNED')` API and generated dotted sort key. PostgreSQL/MySQL grammars quote JSON selectors; registered names with punctuation or Unicode are allowed. `NUMERIC(p,s)` casts compile as DECIMAL(p,s) on MySQL. Explicit `payload->[0]` array selection is exercised on both engines; dotted numeric `.0` follows native grammar semantics (PostgreSQL array index versus MySQL literal object key). Relation retrieval and chunking clone the original relation before applying recorded queries, so later-page totals/export builders retain original scopes without pagination limits or offsets. HasOne/BelongsTo sorting uses scoped scalar subqueries, keeps unmatched rows and selects a deterministic related key when no relation order exists; nested/HasMany sorting remains explicitly unsupported. Database NULL placement and uncast JSON text ordering retain their engine semantics. Unsupported server cast types throw a configuration error; no raw request SQL is executed.

Positive requested sizes up to `admin.grid.max_per_page` (default 1000) are accepted; server `perPages` choices can raise that limit. Malformed/zero/negative/oversized requests fall back to the configured grid size. The selector displays the actual paginator size. Scalar filters ignore absent/null/empty/malformed arrays; zero/false remain meaningful (false string patterns are empty patterns). Set filters accept scalar/flat arrays and discard malformed nested members; empty `in` matches none and empty `notIn` matches all. Ranges accept named scalar endpoints and ignore malformed/date-invalid ranges. Custom where callbacks still own their input/query semantics. Log deletion requires positive decimal IDs separated by commas, with invalid requests returning422 before mutation.

For repeated performance comparison, prepare an ignored baseline source snapshot with `mkdir -p .scratch/php85-laravel13-upgrade/issue10-baseline-root` and `git archive 261a97aa | tar -x -C .scratch/php85-laravel13-upgrade/issue10-baseline-root`, then create its empty `vendor` and `scripts` directories. `scripts/test-grid-benchmark baseline pgsql` mounts that source and preserved `baseline-vendor` at the expected container paths, with the current guarded test harness read-only; `scripts/test-grid-benchmark candidate pgsql` measures the candidate. Repeat both with `mysql`. Each uses1000 users/profiles/tags, one warmup plus five measured runs and verifies rows/sort IDs. JSON artifacts include actual source paths/hashes/framework version, timings/counts/memory and first-run SQL without bindings. Original issue 02 measurements/locks/vendor remain unchanged. The measured nonadministrator live-grant overhead is constant+4 queries at 20 and 100 rows; the coordinator accepted that narrow budget adjustment pending independent review. Detailed evidence is in `.scratch/php85-laravel13-upgrade/references/issue10-validation.md`.

## Grid displayers, actions and real-browser acceptance

`GridInteractionTest` adds eight integrated scenarios for all 20 registered displayers, model-bound closure/class macros and global definitions, trusted/raw versus escaped content, all implicit inline-control aliases, actual inline/relation/file persistence, safe action-form validation/correction and repeat rendering. Run it with the existing disposable engine runners. Full issue 09 authorization tests remain unchanged; the independent minimum graph executes both suites together.

Real browser acceptance uses a scoped, locked CLI tool graph rather than adding Node dependencies to the package runtime:

```sh
npm ci --prefix tests/browser --ignore-scripts --no-audit --no-fund
scripts/start-grid-browser pgsql
# In another shell, substitute the ephemeral loopback URL printed above:
scripts/check-grid-browser.py http://127.0.0.1:PORT pgsql
```

Repeat with `mysql`. Keep the first command running until the browser check finishes; stopping its app container lets its trap remove the unique database/network. The server seeds exactly once through an explicit CLI-only `--seed` branch, then retains its temporary host, files and file-backed sessions across actual HTTP requests. It uses the independent current Composer vendor graph and disables the framework's CSRF unit bypass. No consumer host is changed. The browser runner uses existing bundled UI assets, genuine clicks/forms/network responses/downloads/clipboard behavior and actual disposable database state. Its field-name audit stores no submitted values or credentials. Fixture accounts/records and all provider payloads are synthetic.

Screenshots and CLI captures are under ignored `output/playwright/issue11/`; the report, JUnit/logs and checksum results are in `.scratch/php85-laravel13-upgrade/{references,artifacts}/issue11-*`. Both real-engine browser flows check modern/legacy inline correction, signed detail/pickers, two upload columns after repeated PJAX renders, tools/selection/hotkeys, confirmation/denied/mixed batches, fixed columns and actual sticky headers, a displayer gallery and denied-user failures. QR retains its pre-existing external provider; the gallery sends only a synthetic test string. Missing optional Spatie ordering has a controlled dependency check here; live optional ordering remains issue 18. Durable file failure/rollback lifetime remains issue 14 and the full field/asset matrices remain later acceptance gates.

Action validation preserves the established HTTP200/status:false/plugin envelope and adds fixed field guidance only for declared interactor fields. Custom handler exception messages and submitted credentials stay absent. Consumers relying on raw custom validation wording should use the controlled field guidance instead; arbitrary handler failures retain the generic failure response. `uploadMany()` now has a correctly spelled public name while retaining `uplaodMany()` as an alias. Text displayers escape direct stored text but preserve explicitly trusted callback/builtin HTML chains; attributes/URLs are handled independently.

Review-cycle regressions enforce failed validators independently from safe public error projection, including wildcard/foreign validator keys and reuse of an action interactor. Overlapping grids now retain their public CSS classes while internal template/modal/handler IDs belong to their grid instance, resource, column and row. The browser pipeline includes a two-resource/two-model case before and after PJAX, checking exact write counts and each grid's own picker/detail producers. Capture to a distinct directory when reviewing: `scripts/check-grid-browser.py http://127.0.0.1:PORT pgsql --output-prefix issue11-review-cycle2`. The final implementation capture uses `issue11-cycle2`.


## File, image and avatar storage acceptance

`UploadLifetimeTest` covers all four upload fields with real disposable storage and database writes, pixel/orientation/format assertions, naming/visibility/root contracts, malformed input, replace/remove/retain, multiple-file ordering, soft/hard deletion, enclosing transaction commit/rollback, nested-child rollback and owned-path failure cleanup. The synthetic remote adapter forwards to disposable local storage and proves only the adapter contract. Run the current full suite with `scripts/test-container pgsql` / `mysql`; use `scripts/test-dependency-container lowest pgsql /host/vendor/bin/phpunit -c /application/phpunit.xml.dist --bootstrap /host/vendor/autoload.php --filter 'UploadLifetimeTest|FormLifecycleTest|FormFieldRegistryTest|NestedRelationshipsTest'` for the focused minimum lane (repeat `mysql`).

`scripts/test-dependency-container current pgsql /application/scripts/probe-uploads.php` runs actual profile and administrator controller avatar upload/replace/remove and failed validation/storage/database changes through native middleware, real CSRF/cookies, configured identity model/connection/disk and configured thumbnails. Repeat current MySQL and minimum lanes. Subclassed fixture controllers add thumbnails to the real parent form; no production thumbnail policy is introduced. This is HTTP-kernel evidence; C18.03 still owns the real browser integration.

`scripts/test-dependency-container current pgsql /application/scripts/probe-uploads-optional.php` performs an actual disposable Composer `install --no-dev --no-scripts`, excludes Intervention from installed metadata and autoload, then uses a fresh PHP process to prove plain validation/upload works and transforms/thumbnails report useful dependency errors. Only temporary package links/metadata are changed. Existing root and independent vendor graphs remain read-only. PHP GD codecs are required for the processed formats, and EXIF for metadata orientation. See `.scratch/php85-laravel13-upgrade/references/issue14-validation.md` for exact results and filesystem/DB atomicity limits.

## CSV and optional Excel acceptance

`GridExportTest` checks query/scope/page validation, deterministic100-row chunks, tenant/soft-delete constraints, repeated access, disabled exports and per-grid callback ownership on each database. `scripts/test-dependency-container current pgsql /application/scripts/probe-exports.php` runs a loopback native PHP HTTP server inside the guarded disposable container and exercises the package's actual response `send()`/`exit`; repeat for `current mysql`, `lowest pgsql` and `lowest mysql`. It parses downloaded CSVs with explicit empty escape, verifies headers/BOM/empty results, special values/callbacks/column visibility, denied/disabled/malformed requests and complete1000/30000-row streams. Measured producer peak must remain below96MiB, with at most8MiB growth between those sizes. Its injected101st-row exception must produce an incomplete export, and must appear in the server error log. The HTTP200 status may already have been sent: that alone is never counted as successful completion.

The independent optional hosts are generated under `.scratch/php85-laravel13-upgrade/dependencies/host-excel` and `host-excel-lowest`; their versioned manifests/locks are in `tests/Fixtures/dependencies/`. Install in the pinned image using the same `/application:ro` and `/host` mounts described above; no platform/advisory bypass is used. Run `scripts/test-export-container pgsql` and `scripts/test-export-container mysql`; append `excel-lowest` for the minimum graph. Both fixtures use actual Maatwebsite4.0.7/PhpSpreadsheet5.11.0, download real XLSX responses, reopen them and compare scoped IDs, headings, raw values and cell types, including special values and empty results. Default binder numeric-string/blank behavior and XML CRLF normalization are explicit expectations. With the dependency absent, the core native probe checks the controlled optional-dependency error and explicitly reports workbooks unexecuted in that lane.

`docker run --rm -v "$PWD:/application:ro" laravel-admin-php85-tests:local php /application/scripts/probe-csv-wire.php` characterizes both original two-argument `fputcsv` calls on PHP8.5, captures their two deprecations and records the deliberate standard doubled-quote wire correction. Full evidence, dependency provenance and command outcomes are in [issue15 validation](../.scratch/php85-laravel13-upgrade/references/issue15-validation.md).

Issue16 tree acceptance: `scripts/test-container pgsql vendor/bin/phpunit --filter TreeOrderingTest` (also `mysql`) tests empty/custom-key trees, retained labels/order, atomic repeated reorder, malformed/missing/foreign/cyclic references, saving-hook cancellation, hard/soft direct-child deletion, menu role-pivot cleanup and explicit connections with an unusable default. The complete current suites include these tests. The minimum dependency command uses `scripts/test-dependency-container lowest pgsql /host/vendor/bin/phpunit -c /application/phpunit.xml.dist --bootstrap /host/vendor/autoload.php --filter 'TreeOrderingTest|AuthorizationBoundaryTest|FormLifecycleTest|NestedRelationshipsTest|UploadLifetimeTest|ManagementCommandsTest'` (also `mysql`).

Run `scripts/test-dependency-container current pgsql /application/scripts/probe-trees.php` with `current|lowest` and `pgsql|mysql`: actual MenuController and generic HasResourceActions/Form reorder routes run with native CSRF and permission middleware, custom guard/domain/admin connection, invalid-reference rollback and menu deletion/pivot cleanup. `scripts/probe-tree-baseline.php` reproduces original boot/listener/partial-write/default-connection behavior from the actual pre16 capture; only its namespace is isolated, and its source hash is reported. It runs only through the guarded disposable dependency wrapper. See [issue16 validation](../.scratch/php85-laravel13-upgrade/references/issue16-validation.md); browser asset/drag execution remains issue19.

Supplemental `scripts/test-dependency-container current pgsql /application/scripts/probe-tree-contracts.php` (also lowest/MySQL) checks arbitrary string-key rendering/options/reorder, trusted custom branch markup and explicit int0/string"0" rejection. Original key0 traversal runs in separate timeout5s/memory64M PHP subprocesses with a17-visit guard; it never runs uncontrolled recursion. Parent0 is the existing fixed root sentinel, not a usable node key.

Issue16 review cancellation regression additionally uses a real Menu parent/child with role pivots on `tree_selected`: a later deleting listener returns false, preserving exact node/pivot snapshots; nested cancellation preserves earlier outer-transaction work and transaction depth; disabling that listener allows successful node/pivot cleanup. This runs in both full current suites and both minimum focused suites. The reviewed cycle1 candidate and its provenance are preserved; cycle2 artifacts use the `issue16-cycle2-` prefix.


Issue17 detail/layout/widget/PJAX acceptance: `DetailLayoutPjaxTest` renders real Show relations/nested grids/custom fields, built-in and trusted formatting, tools, layouts/widgets/directives, localized views and a published RTL override. It checks response types/status/Unicode/fallbacks, Throwable recovery and explicit HTTP response/status propagation, independent step state and nested/repeated presentation scopes. Run with the disposable current full suites. The focused minimum command is `scripts/test-dependency-container lowest pgsql /host/vendor/bin/phpunit -c /application/phpunit.xml.dist --bootstrap /host/vendor/autoload.php --filter 'DetailLayoutPjaxTest|PhpCompatibilityTest|ProviderLifecycleTest|FormLifecycleTest|FormFieldRegistryTest|GridQueryTest|GridInteractionTest|AuthorizationBoundaryTest'` (also MySQL).

`scripts/test-dependency-container current pgsql /application/scripts/probe-details.php php` performs actual native kernel/cookie/CSRF/validation/progression/completion requests. Use `current|lowest`, `pgsql|mysql` and `php|json` for all eight lanes. It checks two independent workflows, host session preservation, server-owned navigation despite tampering, earlier approved values after validation failure, debug-disabled host TypeError500/HTTP418, failed503 without an exception, JSON and Unicode PJAX, and same-process callback/script lifetime. It uses generated file sessions and a temporary host only. This is kernel/render evidence; actual browser asset execution remains issue19.

`scripts/test-dependency-container current pgsql /application/scripts/probe-detail-baseline.php` executes captured pre17 sources in isolated namespaces/processes and records actual original failures. See [issue17 evidence](../.scratch/php85-laravel13-upgrade/references/issue17-validation.md) for approved behavior corrections, exact frozen commands and limits.


Issue17 cycle2 adds `scripts/test-dependency-container current pgsql /application/scripts/probe-step-workflows.php php` (current/lowest, PostgreSQL/MySQL, PHP/JSON). Two different two-step workflows render on the same page with identical step keys; actual signed handlers check independent saved values, failed completion feedback/state, cross-step sharing, own cleanup and continued usability of the other workflow's earlier contexts. Append `frozen` to execute the captured cycle1 candidate and reproduce overwrite/cleanup before the fix. DetailLayoutPjaxTest separately checks independent copies of the identical class map via stable explicit third-argument workflow IDs. See the cycle2 section of the issue17 report; original evidence remains preserved.


## Extension and optional tool acceptance

Run `scripts/test-optional-container pgsql` or `mysql` for the current real optional
host; add `optional-lowest` for the minimum framework graph. These independent
hosts freeze mews/captcha3.5.1, Spatie eloquent-sortable5.0.1 and Matthias Mullie
minify1.3.76, with Intervention3.11.10/current and3.11.0/minimum. They are never
runtime requirements of the core package. Manifests/locks/vendor live only under
`.scratch/php85-laravel13-upgrade/dependencies/host-optional{,-lowest}`. Install
with the pinned image, checkout `/application:ro` and only that host `/host:rw`,
working directory `/host`, `composer install --no-interaction --no-progress`.

`ExtensionToolsTest` executes boot/disabled/config, actual custom-field aliases,
fill/default/prepare/validation/render/assets/database persistence, custom
column/exporter callbacks, import reruns/grant preservation/conflict rollback and
configured-model connections. Generator invalid names/options/labels are checked
before writes. `OptionalIntegrationTest` uses the installed packages for actual
captcha image/cache validation, Spatie moves and CSS/JS minification.

Native fixtures use the same disposable wrappers:

```sh
scripts/test-dependency-container current pgsql /application/scripts/probe-generators.php
scripts/test-dependency-container current pgsql /application/scripts/probe-extension-install.php
scripts/test-optional-container pgsql optional /application/scripts/probe-captcha.php
```

Repeat `current|lowest`, `pgsql|mysql`, or `optional|optional-lowest`. Generated
operations retain signed contexts and real CSRF middleware; all9 action variants
and ordinary/step forms execute their generated handlers. The extension probe
performs real Composer installation, strict generated metadata validation,
platform/advisory checks and fresh-process provider discovery, then checks
routes/views/assets/migrations and disabled behavior. Captcha's native image route
refreshes the cookie-session challenge; incorrect, expired, correct and replayed
inputs traverse the actual validator and database Form. Controlled time expires
the real cache entry; no validator answer is stubbed. These are HTTP-kernel and
render checks; actual browser JavaScript execution belongs to issue19.

Identical extension imports preserve rows and grants. A conflicting host menu or
permission rolls the import back rather than overwriting it. Disablement gates
future provider boot/import/routes, and preserves existing data/published assets
and registrations made earlier in the same process. Refresh configuration caches
and restart long-running processes when changing enablement. Generated metadata
now requires PHP~8.5.13/admin^2.0/PHPUnit^12.5.8. Nonadministrator generated handlers
need their explicit authorization/ability configuration as described in issue09;
there is no compatibility authorization bypass.

Image and Excel acceptance reuses the unchanged source paths and prior executed
issue14/15 fixtures, including no-dev Image absence and20 reopened XLSX workbooks.
`issue18-reused-paths.json` compares their hashes to accepted prior results. No live
cloud provider or README third-party extension is certified: each remains an
explicit unresolved consumer/integration gate in the issue18 validation report.
The historical Jenssegers MongoDB reference is retained and unresolved; modern
stable MongoDB\Laravel uses another namespace and requires ext-mongodb. The
PostgreSQL/MySQL fixture does not promise MongoDB support.

Issue18 review cycle2 replaces parser-only generator checking with a bounded real
PHP compiler preflight. Form/action/extension generation streams source to
`PHP_BINARY -l` with a10second process timeout before destination creation; source
is compiled without executing it. This matches the issue04 compiler-check pattern
and catches reserved class/type names case-insensitively, including extension
classes derived from package names. ExtensionToolsTest also lints and autoloads
valid neighboring names. `scripts/probe-generator-reserved.php` replays the frozen
cycle1 commands and hash-verified templates; append `fixed` to check current
nonzero/no-output behavior. See `issue18-cycle2-results.json` for the final review
correction results; the original cycle1 artifacts remain historical evidence.


## Bundled asset and full browser parity acceptance

Issue19 retains the existing Bootstrap3/AdminLTE2 UI and ships patched ready-to-publish frontend bytes. The isolated Node development graph, exact source/patch provenance, licensing, deterministic build/check commands, retained snapshot policy and cache invalidation are documented in `tools/assets/README.md`. Consumers do not require Node. `AssetDistributionTest` checks every source/published distribution SHA, jQuery compatibility alias/load order, actual published override URL invalidation and external/host URL preservation.

The full browser fixture extends the existing guarded persistent host with real profile/admin/grant/log/menu routes and synthetic scalar/choice/date/container/nested/upload/custom-field/captcha/widget data. It uses real CSRF/file sessions and real optional packages from the already locked `host-optional` graph. Captcha uses actual JPEG/cache/session verification with a synthetic one-character alphabet, not a validator stub. File-backed cache is required across PHP HTTP requests. Distinct physical columns reconcile the exact `dateTimeRange`/`datetimeRange` aliases on case-insensitive MySQL identifiers.

```sh
ISSUE19_BROWSER=1 BROWSER_HOST=optional scripts/start-grid-browser pgsql
# Substitute its ephemeral loopback URL in another shell:
scripts/check-parity-browser.py http://127.0.0.1:PORT pgsql --output-prefix issue19-final
scripts/check-grid-browser.py http://127.0.0.1:PORT pgsql --output-prefix issue19-grid-final
```

Repeat with MySQL. The complete parity runner covers all55 built-in fields/aliases plus a concrete custom field/alias, successful database/file mutations and invalid feedback, profile/avatar replacement, web administrator CRUD/grants, historical/new audit redaction/filter/delete, actual tree pointer dragging/save, signed multistep state, CSV download and repeated menu/PJAX renders. The existing grid runner separately verifies all14 retained stages including permissions, inline/legacy corrections, signed pickers/actions, repeat uploads, batches and the pre-existing external QR provider using synthetic data only. `--stage` can run a bounded diagnostic stage; `--output-prefix` preserves prior captures. CLI traces, screenshots, network/console records and CSV bytes are saved under `output/playwright/`.

The synthetic locale matrix renders actual English, Traditional Chinese and Arabic translations; it checks a published footer override, RTL CSS/local fonts and independently generated LTR/RTL minified manifests. Production hosts should publish languages and generate minified assets for their actual configured skin/direction. DateMultiple retains its existing hard-coded Chinese Flatpickr calendar locale; other date controls use the requested Moment locale (including Arabic digits). No other locale/browser/provider is certified by this bounded matrix. Both minified and ordinary assets use identical public published paths/contracts, with content hashes in URLs.


## Final release matrix (issue20 candidate)

The obsolete Travis matrix is replaced by `.github/workflows/php85.yml`: PHP8.5.13,
Laravel13.40.0/minimum or13.43.0/current, Symfony7.4 only, crossed with
PostgreSQL15.14/MySQL8.4.13. All four jobs execute the **entire225-test package
suite**, followed by real optional integration tests, native captcha, Image
absence, native CSV and actual reopened Excel workbooks. There is no
allow-failures lane. Other PHP/Laravel/Symfony/DB/browser/cloud/external-extension
combinations are not inferred from this matrix. Remote Actions has not run until
an authorized push; local results are workflow-equivalent executions.

```sh
scripts/ci-prepare-dependencies current
scripts/ci-prepare-dependencies lowest
scripts/ci-release-lane current pgsql
scripts/ci-release-lane current mysql
scripts/ci-release-lane lowest pgsql
scripts/ci-release-lane lowest mysql
```

Preparation installs frozen locks in independent core/optional/Excel hosts,
checks actual platform requirements and audits each complete graph. Explicit
development Mockery^1.6.15 excludes the broken transitive1.0 spy generator; the
real generated interface-spy call is separately verified. The package's consumer
runtime does not require dev tools. Original pre-floor results remain historical,
and final lane artifacts use `issue20-ci-*`; no old96-test claim substitutes for
these full feature runs. All prior browser runtime source/assets are unchanged;
the independently approved issue19 Chromium evidence is hash-reconciled, not
rerun solely for documentation/tool-floor changes.

Build `docker build -f tools/ci/Dockerfile.php84 -t
laravel-admin-php84-baseline:issue20 .` for the compatible original baseline
runtime, then run `scripts/test-upgrade-container pgsql` / `mysql`. These use one
persistent native installation per engine, real database/application/upload
backups, exact existing data/customization snapshots, actual Composer install,
selective assets/header merging and original-runtime backup restoration. Session
invalidation/relogin is an explicit rehearsal operator policy. Backups/results
are synthetic and local; no consumer or production restore is authorized.

Final comparable performance uses `scripts/test-release-benchmark baseline
pgsql` then `candidate pgsql` (repeat MySQL) while heavy jobs are idle. Each uses
one warmup plus five runs on the original1000-user/profile/tag, related-form,
80x40JPEG and10000-row datasets. It retains the explicitly named owned-stream
characterization and additionally checks complete real CSV send/exit over
loopback HTTP, reopening every row. This does not relabel the substitute as
transport proof. Original C01.06 budgets and the accepted constant+4 restricted
permission-query allowance are checked separately in the release report.

## Clean-checkout inputs and local evidence

`.scratch/` is intentionally ignored. CI requires only the versioned graphs in
`tests/Fixtures/dependencies/`; preparation generates every required core, optional
and Excel application before installing its lock. The local historical evidence
and pre-upgrade source/vendor captures referenced above are not CI inputs and are
not present in a fresh checkout. Historical reproduction/rehearsal commands need
those separately retained local captures; ordinary CI lanes do not.
