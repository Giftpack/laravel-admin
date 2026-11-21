# Package development checks

The PHPUnit/BrowserKit suite boots its own temporary Laravel application through
`tests/Support/PackageTestHost.php`. It does not modify a vendor application
skeleton. Tests use guarded, uniquely named PostgreSQL/MySQL services with tmpfs
storage and clean them up on exit. The repository `.env` is never loaded.

## Prepare the supported dependency sets

From the repository root, with Docker Compose v2 available:

```sh
scripts/ci-prepare-dependencies current
scripts/ci-prepare-dependencies lowest
```

These reusable local commands install, platform-check and audit the versioned
manifest/lock pairs in `tests/Fixtures/dependencies/`. Writable applications and
vendor trees are generated under `.scratch/tests/dependencies`; results go under
`.scratch/tests/results`. Neither directory is a source input. No existing
`.scratch` contents, root `vendor` or host PHP installation is required.

The `current` and `lowest` graphs exercise the supported PHP 8.5/Laravel 13 and
Symfony 7.4 line. Optional-tool and Excel hosts remain separate from the core
runtime requirements. See the dependency-fixture README for update instructions.

## Run the suite

```sh
scripts/test-dependency-container current pgsql
scripts/test-dependency-container current mysql
scripts/test-dependency-container lowest pgsql
scripts/test-dependency-container lowest mysql
```

Pass an explicit PHP command to select a regression or collect diagnostics:

```sh
scripts/test-dependency-container current pgsql /host/vendor/bin/phpunit -c /application/phpunit.xml.dist --bootstrap /host/vendor/autoload.php --filter PrecommitFormIntegrityTest --display-all-issues
```

`scripts/test-container pgsql|mysql` instead uses an already installed root
`vendor` directory. Both wrappers mount the checkout read-only, enforce exact
throwaway database coordinates and refuse to boot without the disposable marker.
Never bypass these checks or point them at a shared or real database.

The complete integration lane additionally runs optional packages, real captcha,
Intervention-absent installation, CSV streaming and reopened Excel workbooks:

```sh
scripts/ci-release-lane current pgsql
scripts/ci-release-lane current mysql
scripts/ci-release-lane lowest pgsql
scripts/ci-release-lane lowest mysql
```

These are local test commands; this project does not install a GitHub Actions
workflow. Every stage must succeed before a lane writes its completion marker.

## Native integration regressions

The `scripts/probe-*.php` files exercise current package behavior through real
middleware, cookies and CSRF rather than relying on unit-test bypasses. Run them
through `scripts/test-dependency-container`; for example:

```sh
scripts/test-dependency-container current pgsql /application/scripts/probe-authentication.php
scripts/test-dependency-container current pgsql /application/scripts/probe-uploads.php relative
scripts/test-dependency-container current pgsql /application/scripts/probe-step-workflows.php json
```

Available checks cover installation/cache/uninstall, configured identity and
sessions, authorization and audit redaction, forms/relationships, upload lifetime,
trees, details/layout/PJAX, generated handlers and extension installation.
They use synthetic data and temporary hosts. Optional feature checks also support
`scripts/test-optional-container` and `scripts/test-export-container`. Fake remote
storage exercises adapter contracts and does not certify a live provider.

## Browser regressions

The locked CLI tooling is development-only:

```sh
npm ci --prefix tests/browser --ignore-scripts --no-audit --no-fund
scripts/start-grid-browser pgsql
```

Keep the server runner alive, then use its printed ephemeral loopback origin in
another terminal:

```sh
scripts/check-grid-browser.py http://127.0.0.1:PORT pgsql
```

The broader parity fixture includes all fields, nested relationships, uploads,
captcha, administrator/profile forms, logs, trees, workflows, downloads,
localization/RTL, repeated PJAX and asset-security regressions:

```sh
PARITY_BROWSER=1 BROWSER_HOST=optional scripts/start-grid-browser pgsql
scripts/check-parity-browser.py http://127.0.0.1:PORT pgsql
```

Repeat with `mysql` for the other engine. Use `--output-prefix` to separate runs.
Fixtures seed once and retain their owned files/sessions between actual requests.
Captures are generated under ignored `output/playwright`; stop the server runner
to remove its containers/network/database. QR checks send synthetic data to the
existing external provider; provider availability and privacy policy remain host
integration concerns.

## Assets and maintenance

See `tools/assets/README.md` for locked asset rebuilding, deterministic checks,
license/provenance metadata and the current inventory generator. Preserve the
vendored patch/source inputs: they are needed to regenerate the shipped security
fixes and existing UI. Consumer applications do not need Node.

Upgrade/rollback operator guidance lives in `docs/en/upgrade.md` and
`docs/zh/upgrade.md`. One-time migration benchmarks, captured old implementations,
release audit scripts and private task reports are not part of the maintained test
suite.
