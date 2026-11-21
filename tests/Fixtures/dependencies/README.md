# Frozen dependency graphs

These manifests and locks are versioned test inputs. The package itself does not
ship a consumer lock. `current` and `lowest` describe the root package graphs;
`host-*` pairs describe independent core, optional-tool and Excel applications.

Run `scripts/ci-prepare-dependencies current` and/or
`scripts/ci-prepare-dependencies lowest` from a clean checkout. Preparation copies
the corresponding `host-*` inputs into ignored `.scratch` working directories,
generates their application bootstrap/configuration, and installs, platform-checks
and audits them in the pinned Docker runtime. No existing `.scratch` data, host PHP
installation or root `vendor` directory is required.

`php scripts/prepare-dependency-host.php current` can materialize one host without
installing packages; it also accepts `lowest`, `optional`, `optional-lowest`,
`excel` and `excel-lowest`. CI invokes it in Docker with `/host` as its writable
output while keeping the checkout read-only.

Refresh a graph deliberately with Composer in the pinned runtime, retaining its
framework/Symfony policy, and copy both its manifest and lock back here. Run the
full matching CI lane and platform/advisory checks before accepting the update.
Generated applications, vendor trees, logs and historical release evidence remain
ignored under `.scratch`.
