# Local WP-CLI test suite

The fast regression gate. Runs against the **real** WordPress/MySQL install with
protected row counts, so it is read-mostly by design.

For the map of all three suites and where a new test belongs, see `TESTING.md`
at the plugin root, or run `npm run test:help`. This file is the gate's own
reference.

```bash
npm test        # the whole gate, ~20s, must exit 0
```

## Why this suite is separate from `dev/wp-browser`

They differ by **which database they run against**, and that decides what each
can do:

- **This suite** runs on your real store. It can therefore catch things only real
  data exposes — route drift, raw-SQL prefix bugs, strict-SQL failures, real query
  shapes — but it must never mutate protected tables.
- **`dev/wp-browser`** runs on a disposable database (`resetFluentCartTables()`),
  so it can do destructive, payment, and full-lifecycle work this gate cannot.

Neither replaces the other. A full comparison found exactly **one** true duplicate
across ~4,700 cases. Do not delete a test here because "wp-browser covers it"
without checking.

## Tiers

| Tier | Command | What it proves |
|---|---|---|
| static | `npm run test:gate -- static` | no unprefixed raw SQL, no route drift, every mutating route inventoried |
| pure | `npm run test:gate -- pure` | money/date/validator boundaries, provider mappings — no DB |
| smoke | `npm run test:gate -- smoke` | every GET route answers, with the query shapes the app really sends |
| permissions | `npm run test:gate -- permissions` | every mutating route rejects anonymous and low-privilege callers |
| integration | `npm run test:gate -- integration` | behaviour and exact stored values against a real DB |

`run-all.sh` defaults to `all` = static + pure + smoke + permissions + integration.
JS, browser, and E2E are deliberately **opt-in** and never run in that gate.

```bash
npm run test:js                        # Vitest
npm run test:browser -- admin/smoke    # read-only admin screen mount
npm run test:e2e                       # E2E money flows (fake gateway)
```

Any tier takes a substring filter:

```bash
npm run test:gate -- integration coupon
npm run test:gate -- smoke orders
npm run test:gate -- pure money-to-cent
```

Useful filters: `shared-` (discriminators), `domain-` (stock/scheduler/
subscription invariants), `crud-` (route round-trips), `background-`,
`public-uuid`, `throughput`, `reports-`, `provider`, `webhook`,
`input-validation`.

## Hostile environment axes

Opt-in. They reproduce failures a dev machine hides — see `SUITE-STATUS.md` for
current results and why the non-UTC axis matters.

```bash
bash tests/bin/run-all.sh all --axis=strict-sql   # MySQL ONLY_FULL_GROUP_BY
bash tests/bin/run-all.sh all --axis=non-utc      # non-zero gmt_offset
bash tests/bin/run-all.sh all --axis=pro-absent   # FluentCart Pro deactivated
```

## Proving the suite still bites

A gate that cannot fail is worse than none. These are intentional red:

```bash
# raw-SQL lint must fire on the known-bad fixtures — exit 1
php tests/lint/raw-sql-prefix.php tests/lint/fixtures

# ...and stay clean on the real plugin — exit 0
php tests/lint/raw-sql-prefix.php

# the protected-count guard must hard-fail on a mutation — exit 97
cd /path/to/wordpress
wp eval-file /path/to/fluent-cart/tests/bin/prove-protected-count-guard.php
```

Standalone lints: `php tests/lint/route-coverage.php`,
`php tests/lint/permission-inventory.php`.

Extra flags: `--full-php-lint` scans every PHP file instead of changed ones;
`WP_PLUGIN_TEST_PROFILE=1` prints per-case timing and query counts.

## Layout

```
tests/
  suite.config.php   the only file with plugin-specific values
  bin/               runners
  lib/               harness, fixtures, provider transport
  lint/              static gates (+ fixtures/ = intentional bugs)
  smoke/  permissions/  public/  reports/  throughput/  validation/
                     manifests and inventories, pinned by file:line
  integration/       the behaviour cases
  js/                Vitest
```

**Manifests pin source locations by `file:line`.** A shifted line breaks the
lint. That is the feature — it is what stops a route from silently losing
coverage. If you change a route, update `smoke/routes.manifest.php`,
`permissions/routes.manifest.php`, and `public/inventory.php`.

## Rules

`AGENT.md` has the full set. The ones that matter most:

- **Never weaken a test to make it pass.** The test is the specification.
- **Never mock the database.** Every bug that has shipped here was a runtime or
  SQL bug, and a mocked-DB test passes on all of them.
- **Never mutate real store data.** Fixtures are created in-case and deleted in a
  `finally`; protected counts are compared before and after every tier.
- **Findings are parked, not fixed.** A failing case gets a `KNOWN-FAILURE` tag
  and a `FIX-PLAN.md` entry with `file:line`. Fixes happen in a separate pass so
  they get reviewed.

## Other documents

| File | What |
|---|---|
| `SUITE-STATUS.md` | every tier: command, latest result, runtime, known limits |
| `AGENT.md` | rules for extending this suite |
| `FIX-PLAN.md` | open findings, each with a parked failing case |
| `FIX-AGENT.md` | rules for the separate production-fix run |
| `COVERAGE-GAPS.md` | risk-ranked list of files no test touches |
| `MUTATION-AUDIT.md` | whether the suite asserts what it executes; survivor list |
| `../dev-docs/bugs/test-suite-findings/kanban.md` | the findings as developer tickets |
