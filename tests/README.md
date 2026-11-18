# Tests

Every test in this plugin lives under `tests/`. `npm test` shows a menu in a terminal. `npm test -- <module>` runs one module without the menu, and `npm test -- --help` lists every module and argument.

## Run the gate after every code change

```bash
npm test -- wp-cli
```

The gate takes about 23 seconds and must exit 0 before you report the work done. Run it when you finish the change, not when you open the PR. Every committed case passes on `develop`, so a red gate after your change means your change broke it.

## Two databases decide what a suite may do

The PHP tests are split by the database they run against.

- `tests/wp-cli/` runs inside your real dev store through WP-CLI. It counts the rows in `fct_orders` and `fct_customers` before and after every tier and fails if a count changed. Because the data is real, it catches what only real data shows: a route that stopped answering, a raw-SQL table name without the prefix, a query that strict SQL mode rejects. It may not pay, refund, or delete anything it did not create.
- `tests/codeception/` runs against a throwaway database named in `tests/codeception/.env`. The Integration, Functional and IntegrationPro suites wipe that database on every run, so they can check out, pay, refund, and run a subscription through its whole life.

The two suites check different things. The gate proves that every mutating route refuses an anonymous caller. A Functional Cest proves that one resource's permission model is right. Before you delete a test because the other suite covers it, read both tests.

## Modules

| Module | Folder | Runs against | Command |
|---|---|---|---|
| `wp-cli` (tiers `static`, `pure`, `smoke`, `permissions`, `integration`) | `tests/wp-cli/` | your real dev store, row counts protected | `npm test -- wp-cli [tier] [filter]` |
| `e2e` | `tests/wp-cli/e2e/` | your real dev store, through a fake gateway | `npm test -- e2e` |
| `codeception unit` | `tests/codeception/Unit/` | nothing, WordPress is not loaded | `npm test -- codeception unit` |
| `codeception integration`, `codeception functional`, `codeception integration-pro` | `tests/codeception/Integration/`, `Functional/`, `IntegrationPro/` | the throwaway database, wiped on every run | `npm test -- codeception functional [path]` |
| `js` | `tests/js/` | nothing (Vitest in Node) | `npm test -- js` |

Two names run several modules. `npm test -- codeception` runs the four Codeception suites in order. `npm test -- safe` runs `wp-cli`, `codeception unit` and `js`, which leave the throwaway database alone.

## Where a new test goes

| You are testing | Folder |
|---|---|
| Pure PHP logic with no WordPress call | `tests/codeception/Unit/` |
| A model, service, event or migration that needs WordPress and the database | `tests/codeception/Integration/`, or `tests/codeception/IntegrationPro/` when it needs Pro loaded |
| A REST route: status, response shape, permissions, side effects | `tests/codeception/Functional/` |
| That a whole surface still answers on real data | `tests/wp-cli/`. Read `tests/wp-cli/README.md` first. |
| Admin or storefront JavaScript that Node can import | `tests/js/` |

The `testing` skill in `.claude/skills/testing/` picks between Unit, Integration and Functional and holds the Codeception conventions. Specs for the PHP suites are in `tests/codeception/Cases/{domain}.md`.

## A changed route needs its manifests updated

`tests/wp-cli/smoke/routes.manifest.php` and `tests/wp-cli/permissions/routes.manifest.php` pin each declared route by `file:line`. If you move or remove a declared route, even by one line, the `route-coverage` and `permission-inventory` lints fail until you update both manifests. A new mutating route also fails `permission-inventory` until you add it to that manifest. `tests/wp-cli/inventories/public.php` pins the public routes the same way. Update the manifests in the same PR as the route change, so a route cannot lose its coverage without a reviewer seeing it.

## Rules for every suite

- Never weaken, narrow or delete a test to make it pass. The test is the specification. If it looks wrong, say so and leave it red.
- Never skip a test to hide a failure. A test that cannot pass is fixed or deleted. Neither the wp-cli gate nor the Codeception suites skip a test, and the `no-skip` lint fails the gate when a file under `tests/` contains a skip call. A gate case that needs a store record creates it and deletes it by exact ID. A gate case that needs FluentCart Pro runs only where Pro loads, and fails when Pro is missing. If a test exposes a production bug, report the bug with its `file:line`. The owner decides whether the test stays red until the fix lands or is deleted.
- A test is vague when breaking the code it names would not fail it. Delete it or give it a real assertion. Typical cases: a lone `assertTrue(true)`, a lone `class_exists()`, a check that only reads status 200, and a grep of stylesheet text for colour or spacing tokens.
- Never mock the database, the query builder or a model. Mock only outbound third parties.
- For a bug fix, write the failing test first and watch it fail. Then fix the code.
- Report an unrelated bug with its `file:line` instead of fixing it inside another change.

## Setup

- Codeception: `cd tests/codeception && composer install`. Copy `.env.example` to `.env`, point `TEST_DB_*` at a database you can lose and `WORDPRESS_ROOT_DIR` at your WordPress install, then run `vendor/bin/codecept build`. The runner refuses to start a destructive suite until `FCT_TEST_DB_ALLOW="host:db"` names that database. In a terminal, the menu asks for it.
- The gate: `wp` must be on your `PATH`, and the WordPress install must load this plugin. The `php -l` step reads changed files from `git`, so `git` on your `PATH` must work.

If Codeception fails before any test runs, check these first.

| Error | Fix |
|---|---|
| `Suite 'integration' could not be found` | Suite names are case-sensitive. Use `Integration`. |
| `tests/_wordpress does not exist` | Set `WORDPRESS_ROOT_DIR` in `tests/codeception/.env` to your WordPress install. |
| `Trait _generated\IntegrationTesterActions not found` | Run `vendor/bin/codecept build` in `tests/codeception/`. |
| `Access denied for user` | Check `TEST_DB_USER` and `TEST_DB_PASSWORD` in `tests/codeception/.env`. |
| `register_argc_argv` error | Set `register_argc_argv = On` in `php.ini`. |
