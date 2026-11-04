# WP-CLI gate

The gate runs through WP-CLI inside your real WordPress install. It counts the rows in the tables listed under `protected_tables` in `suite.config.php` (`fct_orders`, `fct_customers`) before and after every tier, and any change fails the run. `tests/README.md` explains why the gate is separate from `tests/codeception/` and where a new test goes. This file explains how to run and extend the gate.

## Run it

```bash
npm test -- wp-cli                         # every tier, about 23 seconds
npm test -- wp-cli smoke                   # one tier
npm test -- wp-cli integration coupon      # one tier, cases whose name contains "coupon"
```

| Tier | What it proves |
|---|---|
| `static` | `php -l` on changed files, then the lints in `lint/`: no unprefixed table in raw SQL, every route in a manifest, every mutating route in the permission inventory |
| `pure` | money, date and validator boundaries and provider mappings, with no database row created |
| `smoke` | every GET route answers, with the query shapes the admin sends |
| `permissions` | every mutating admin route refuses an anonymous caller and a new subscriber |
| `integration` | behaviour and exact stored values against the real database |

Good filters: `shared-`, `domain-`, `crud-`, `background-`, `public-uuid`, `throughput`, `reports-`, `provider`, `webhook`, `input-validation`.

`npm test -- e2e` runs the three money flows in `e2e/money-flows.php` through a fake gateway, each in its own WordPress process. The default gate never runs them.

### Options

- `--full-php-lint` lints every PHP file instead of the changed ones.
- `WP_PLUGIN_TEST_PROFILE=1` prints the time and query count of each case.
- `WP_PLUGIN_TEST_ROOT=/path/to/wordpress` is needed only when the runner cannot find `wp-load.php`.
- `--axis=strict-sql`, `--axis=non-utc` and `--axis=pro-absent` rerun the gate under MySQL `ONLY_FULL_GROUP_BY`, a non-zero `gmt_offset`, or with Pro kept from loading. They change nothing stored, and only one axis runs at a time:

```bash
bash tests/wp-cli/bin/run-all.sh all --axis=strict-sql
```

## Check that the gate can still fail

These commands must fail. A gate that cannot fail proves nothing.

```bash
php tests/wp-cli/lint/raw-sql-prefix.php tests/wp-cli/lint/fixtures   # exit 1 on the bad fixtures
php tests/wp-cli/lint/raw-sql-prefix.php                              # exit 0 on the plugin
```

Run the next two from the WordPress root, with the path to this plugin.

```bash
wp eval-file wp-content/plugins/fluent-cart/tests/wp-cli/bin/prove-protected-count-guard.php   # exit 97
wp eval-file wp-content/plugins/fluent-cart/tests/wp-cli/bin/prove-provider-fail-closed.php    # exit 1
```

## Layout

```
tests/wp-cli/
  suite.config.php   the only file with plugin-specific values: route files, dangerous routes, protected tables
  bin/               run-all.sh, one runner per tier, run-e2e.sh, the prove-* scripts
  lib/               harness.php (FcTest), fixture classes, provider-harness.php
  lint/              static lints; lint/fixtures/ holds intentional bugs
  fixtures/          Stripe, PayPal and webhook JSON payloads
  pure/  smoke/  permissions/  integration/  e2e/  environment/
  inventories/       crud, public, reports, throughput and validation inventories
```

## Manifests pin routes by file:line

`smoke/routes.manifest.php` lists every route declaration in the route files that `suite.config.php` names, including files with no REST route. Each declaration carries its source `file:line`, and each query variation carries the `file:line` of the code that sends it. `permissions/routes.manifest.php` lists every mutating declaration as `file:line:VERB`. Public and customer-owned mutations are listed as exemptions and are never dispatched. `lint/route-coverage.php` and `lint/permission-inventory.php` only read source. They fail when a route moves, so if you change a route, update both manifests and `inventories/public.php` in the same PR.

The permission tier checks anonymous and subscriber denial only. Expectations for FluentCart roles such as worker, accountant or manager need a contract a person approved. Do not derive them from route metadata.

## Rules for a new case

- Watch the case fail before you keep it. Break the behaviour in the narrowest way you can undo, run the case, confirm the failure names the intended defect, restore the code, and run it again. Put the red output in the commit message. Delete a case you cannot make fail.
- Test one behaviour per case, and say it in the case name. Assert stored values, not only a status code.
- Create fixtures inside the case with `FcTest::uniq()`, record each primary ID as soon as the row exists, and delete exactly those rows in a `finally` block. Never read, change or delete a row the case did not create.
- Order fixtures stay `payment_status=pending` with `total_paid=0` and no payment method. Change an order's status through `OrderResource::updateStatuses()`.
- Never mock `$wpdb`, the query builder or a model.
- Never reach a live gateway, send mail, make a loopback request, run cron or Action Scheduler, or call `sleep()`. `FcTest::boot()` blocks HTTP and mail, and the integration runner also blocks cron writes and Action Scheduler. Provider code runs only through `FcProviderHarness`, which answers from `fixtures/providers/` and throws on any request it does not expect.
- Never call a route that `suite.config.php` lists as dangerous, and never create, capture, refund or cancel a payment.
- Never assert on log text, generated SQL or wall-clock time. `FcTest::profileQueries()` gives the query count to assert on.
- Skip a case only when the install lacks what it needs, with `FcTest::skip()` and a message that names the missing plugin or fixture. Read-only smoke skips a route when no safe fixture exists and never creates one through a destructive route. Never skip or tag a case to hide a failure. If a case exposes a production bug, report the bug with its `file:line` in the PR description.
- Never fix production code in the change that adds a test.

## Harness

`lib/harness.php` defines `FcTest`. A case file looks like this:

```php
FcTest::boot();
FcTest::case('coupon total is stored in cents', function () {
	$code = FcTest::uniq('coupon');
	// arrange, act, assert, then clean up in finally
	FcTest::assertSame(1250, $stored, 'amount');
});
FcTest::finish('COUPONS');
```

The common calls are `FcTest::rest($method, $route, $params)`, `assert()`, `assertSame()`, `assertHealthy()`, `fail()`, `skip()`, `countQueries()`, `profileQueries()`, `uniq()` and `clearCaches()`. Fixture helpers are in `lib/fixtures.php` (`FcFixture`), `lib/report-fixtures.php`, `lib/domain-fixtures.php`, `lib/crud-fixtures.php` and the other `lib/*-fixtures.php` files. Read the one you need before you write a new helper.

`FcTest::rest()` dispatches through `rest_do_request()` in the same process. Each case records WordPress's `$EZSQL_ERROR` list, so a failed query still fails the case after a later query clears `$wpdb->last_error`. A PHP notice, warning or deprecation during a case also fails it.
