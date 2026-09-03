# AGENT.md — rules for the local WP-CLI tests

Read this completely before writing a test. `README.md` explains how to run the
installed tiers; this file explains how to extend them.

This suite is adapted from the `wp-plugin-test-suite` skill and runs locally
through WP-CLI against the real WordPress/MySQL install. Do not redesign it
around PHPUnit, Docker, `wp-env`, CI, database mocks, or loopback HTTP.

The repository's normal `dev/wp-browser` testing guidance remains in force for
that suite. This separately requested `tests/` suite is a task-specific
exception for its location and its `KNOWN-FAILURE` vocabulary. Do not modify
`dev/wp-browser` while working here.

## 0. Ground truth

All commands run from the plugin root. Phase 16 extends the exact-ID integration
foundation with five-row versus twenty-five-row query-count and batch guards
alongside the Phase 18 customer-portal UUID matrix, Phase 14 background
automation, Phase 10 read-only validation, and all earlier tiers:

```bash
bash tests/bin/run-all.sh static
bash tests/bin/run-all.sh smoke
bash tests/bin/run-all.sh permissions
bash tests/bin/run-all.sh integration
bash tests/bin/run-all.sh all

# Opt-in Phase 20 hostile environments; never part of the default gate.
bash tests/bin/run-all.sh all --axis=strict-sql
bash tests/bin/run-all.sh all --axis=non-utc
bash tests/bin/run-all.sh all --axis=pro-absent

# Standalone source-to-manifest route coverage.
php tests/lint/route-coverage.php

# Standalone source-derived mutating-route permission inventory.
php tests/lint/permission-inventory.php

# Proof-of-catch: this must be red and exit 1.
php tests/lint/raw-sql-prefix.php tests/lint/fixtures

# Real plugin scan: this must be green and exit 0.
php tests/lint/raw-sql-prefix.php
```

The plugin directory and WordPress root are auto-detected. Set
`WP_PLUGIN_TEST_ROOT` only when the resolver cannot find `wp-load.php`.
Plugin-specific values belong only in `tests/suite.config.php`.

Every runner must exit non-zero on failure. Before and after every test or lint
runner invocation, count every table listed under `protected_tables` using the
actual WordPress prefix. Any count change is a hard failure.

Phase 20 axes must remain opt-in and process-local. Never persist a site
timezone or global SQL-mode change. The Pro-absent axis must prevent Pro from
bootstrapping, not deactivate the stored plugin configuration. An axis failure
is a finding: preserve the existing assertion, record its production mechanism
in `FIX-PLAN.md`, and never loosen or skip the assertion to make the axis green.

## 1. Proof-of-catch

Never keep a test you have not watched fail.

For every test:

1. Break the behavior it covers in the narrowest reversible way.
2. Run the focused test and observe red.
3. Confirm the failure describes the intended defect, not incidental setup.
4. Restore the code.
5. Run again and observe green.
6. Preserve the red output in the commit message.

If a test cannot be made to fail, delete it. The intentional fixture in
`lint/fixtures/` is the Phase 0 proof-of-catch.

## 2. Absolute prohibitions

- Never mock `$wpdb`, the query builder, or models.
- Never call a live payment gateway endpoint. Provider-facing code may run
  only through the explicit Phase 22 fixture-backed transport.
- Never create, capture, refund, or cancel a payment.
- Never allow a webhook to reach a live endpoint.
- Never send real mail, make real loopback HTTP, run cron or Action Scheduler,
  or call `sleep()`.
- Never invoke a route marked dangerous in `suite.config.php`.
- Never depend on, delete, or mutate pre-existing site data.
- Never assert on log text or exact generated SQL strings.
- Never suppress a warning or weaken a test to make it pass.
- Never fix production code in a test-writing change.
- Never commit a red real suite.

When a later phase finds a genuine production defect, keep the full failing
case executable, mark it `KNOWN-FAILURE`, and record the mechanism with a
`file:line` cite in `FIX-PLAN.md`. This vocabulary is specific to this local
suite.

## 3. Requirements for every test

- One behavior per case; the name states the behavior.
- Arrange, act, and assert in visible order.
- Create unique fixtures with `FcTest::uniq()`.
- Delete exactly what the test created in a `finally` block.
- Assert behavior and stored values, not only a status code.
- Clear only configured plugin caches before a suite.
- Keep plugin-specific identifiers in `suite.config.php`.

Read-only smoke may skip when a safe fixture does not exist. It must never
manufacture a reason to call a destructive or payment-related route.

Every configured route file must remain represented in
`smoke/routes.manifest.php`, including files with zero REST declarations.
Every declaration has exact source metadata. Every query variation has
consumer `file:line` metadata. Web and faker transports are statically listed
with precise skips and are never dispatched.

`lint/route-coverage.php` is the Phase 2 enforcement for that contract. It is
purely static: it tokenizes every configured source file, checks exact
declaration IDs and cases, and must never load WordPress or invoke route code.

Every configured mutating declaration must remain represented in
`permissions/routes.manifest.php` by exact `file:line:VERB`. Protected admin
routes get anonymous and fresh-subscriber denial cases. Public and
customer-owned mutations remain explicit non-executable exemptions. A
post-permission REST fuse must block controller dispatch, and every created
subscriber must be deleted even on failure.

Do not derive worker, accountant, manager, or other FluentCart role
expectations from route metadata. That matrix requires an independently
approved human contract in `tests/Cases/permissions-contract.md`.

Integration cases create fixtures only inside the owning case. The
reusable helper records the inserted primary ID immediately, verifies an exact
identity before deleting through the real model, and proves both ID and
identity absent. Every case cleans up in `finally`; the runner retains outer
and shutdown backstops. Integration never mocks `$wpdb`, the query builder, or
models.

Phase 5 Order fixtures keep `payment_status=pending`, `total_paid=0`, and an
empty payment method. Order state changes use
`OrderResource::updateStatuses()`, not direct assignment. The fixture helper
cleans configured order-linked rows by captured Order ID, verifies zero
residue, and refuses any unreviewed status-hook callback. Canceled fixtures are
created already canceled so the terminal rejection cannot dispatch canceled
integrations.

Phase 6 shared fixtures use real Coupon, Activity, Label, LabelRelationship,
Meta, and ProductMeta models. Every row is recorded by primary ID immediately.
Tests deliberately collide the same foreign/object ID and key across different
discriminator values. Cleanup verifies exact ownership values, deletes captured
children before parents, and reports per-table exact-ID residue. Scheduled
actions, subscriptions, payments, gateways, and background jobs remain
inventory-only and uninvoked.

Phase 7 report fixtures first prove that the configured historical and future
ranges are empty. Report children use real OrderItem, OrderAddress, and
OrderOperation models and are captured by primary ID immediately. An Order is
created pending through the real model, then only inert report scalar fields
are changed by exact owned ID; never invoke a payment, transaction, receipt,
gateway, stock, webhook, scheduler, or Product lifecycle.

Global/current-period reports may use a read-only baseline followed immediately
by one exact fixture delta. Document the no-concurrent-write assumption and
fail on any unexpected delta; never compensate by selecting or changing a real
row. Every route in `tests/reports/inventory.php` must have exact-value coverage,
an executable/documented `KNOWN-FAILURE`, or a precise safety skip. Shape and
status smoke do not count as Phase 7 value coverage.

Phase 8 domain fixtures create Product, ProductDetail, ProductVariation,
OrderItem, ScheduledAction, Subscription, SubscriptionMeta, Activity, and Pro
License rows only by exact captured IDs. Before automatic expiry, the complete
production candidate predicate must return zero pre-existing rows; after
fixture creation it must return exactly the owned IDs. Subscription integration
feeds are disabled at their documented discovery filter so a lifecycle event
cannot invoke an installed external integration. HTTP, mail, cron, and Action
Scheduler guards remain fail-closed.

Stock and scheduler tests call services directly, never hooks, cron, or a
background runner. A recording JobRunner may override only the processor to
observe real database selection; it must not mock the query builder or model.
License issuance may call the installed Pro handler only for an inert,
pending-payment owned Order. It must never change payment state or invoke a
gateway. Domain assertions use conservation, exact ownership, cursor order,
and idempotency rather than wall-clock timing.

Phase 9 keeps a source-checked CRUD inventory for Orders, Customers, Products
and Variants, Coupons, and Labels. Route-created rows are captured immediately
from the success envelope and cleanup verifies exact primary ID plus immutable
ownership fields. One-field updates compare raw `SELECT *` snapshots so every
physical column is accounted for; `updated_at` is the only permitted automatic
bookkeeping change. Product and Order creation routes remain excluded because
they can reach cron and gateway boundaries respectively. Missing Label entity
update/delete routes and unrelated Variant metadata rewrites stay executable
`KNOWN-FAILURE` cases.

Phase 10 is read-only. `tests/validation/inventory.php` pins the Phase 9 admin
CRUD request-guard wiring, shared list-filter readers, explicit unsafe-reader
exclusions, and direct stored/row-derived `v-html` sinks. SELECT-shaped ordering
probes, literal wildcard comparisons, pagination boundaries, policy discovery,
and customer ownership checks may read pre-existing rows but never update them.
The Coupon list is source-only because its GET controller updates status on
returned rows. Customer-profile subscription/payment routes remain outside this
round. If installed data raises an unrelated diagnostic, document the exclusion;
never suppress it or rewrite real rows to make a read probe pass.

Phase 14 may drive only the real background entry points covered by its exact
fixtures. Before every case, prove both `wp_mail()` and a local
`wp_remote_post()` are intercepted, then clear the sentinel captures. Any
unfiltered production scanner must first return zero pre-existing candidates
and, after fixture creation, only exact owned IDs. Due dates are past or
deterministically near their reminder threshold; never wait with `sleep()`.

Action Scheduler reads may inspect queue state, but creation remains preempted.
An explicitly expected enqueue may return a synthetic positive ID only through
the harness capture mode, and the case must consume the recorded operation
before it ends. Queue tests never execute the mail callback. Store digest
settings may be disabled only in the request-local static cache, never by
updating an option. Pro license expiry is allowed only after the complete
production candidate predicate proves exact ownership. A genuine automation
defect stays executable as `KNOWN-FAILURE` with a `FIX-PLAN.md` entry; do not
fix production in this phase.

Phase 18 dynamically dispatches only negative customer-portal UUID paths.
Create two exact WordPress-user/Customer actors and inert pending records; log
in as A and use B's UUID. Snapshot both actors before dispatch and require
physical equality afterward. A wrong, malformed, or other-customer UUID must
expose no foreign identifier and must reach no gateway, remote lifecycle
method, mail, HTTP, cron, or Action Scheduler path.

Never dispatch a correct UUID when the post-guard path can resolve a gateway.
Pin the ownership predicate, denial branch, and first protected operation by
exact source line instead. Missing UUIDs are a constrained route-binding
assertion because the shortened URL may name a different list route. Every
UUID-bearing declaration needs its own negative dynamic case. Reconcile the
complete 21-entry `PUBLIC-EXEMPT` permission inventory and identify the nine
UUID mutations within it. Deleted/cancelled handles and non-sequential UUID
shape are explicit phase invariants. A real guard defect remains executable as
`KNOWN-FAILURE`; never repair production in the test-writing phase.

Phase 16 profiles database work only through `FcTest::profileQueries()`. It
captures the query counter immediately around the production filter/service
call and relation materialization, returns the behavior result, and records
elapsed milliseconds for informational output only. Tests never retain or
assert generated SQL text and never assert wall-clock time.

Order, Customer, Product/Variant, and Activity list profiles first create five
exact owned rows, then add twenty and repeat at twenty-five. They assert exact
paginator batch/total values, materialize the source-inventoried relations,
and require the query count to remain equal. Every list guard must be proven
red by temporarily disabling `BaseFilter::applyWith()`. Report aggregates use
the same staged row counts, assert exact aggregate values, and require a stable
query count. Throughput fixtures capture every model/post ID immediately,
delete children before parents, and report exact-ID plus marker residue.

Phase 12 is a fixture-free pure-behavior tier. It may load the real plugin
through WP-CLI because currency settings, filters, and framework value objects
are part of several named public helpers, but it must not create or update any
WordPress or FluentCart row. Every case declares the exact production helper
targets and at least one boundary in its metadata; the runner rejects missing
coverage metadata. Assert exact values and types for zero, signed, large,
rounding, timezone, empty, malformed, and eligibility edges as applicable.

Use unsaved value/model objects only. Test-only subclasses may expose a
protected calculation boundary when the public orchestration path would add
database or lifecycle work. Mail, HTTP, cron, and Action Scheduler remain
fail-closed. A genuine arithmetic or parsing defect stays executable as a
`KNOWN-FAILURE` with a `FIX-PLAN.md` entry; never repair production in this
phase.

Phase 13 may add fixture-free cases to the same pure runner when coverage
triage finds cheap deterministic production logic. Such cases use
`'phase' => 13`, retain the Phase 12 metadata and transport-safety rules, and
must have their own proof-of-catch. Coverage diagnostics must not change the
default runner or PHP configuration. `COVERAGE-GAPS.md` is the authoritative
risk-ranked local-gate inventory; it must describe its measurement scope
without claiming repository-wide absence of ad hoc tests.

Phase 22 provider tests install `FcProviderHarness` only inside the owning
case. The shared terminal `pre_http_request` blocker delegates to that fake
only while explicitly active; every unmatched or out-of-order request throws
before transport. Responses come from JSON files under
`tests/fixtures/providers/`, and every matched request records its exact URL,
method, and normalized body. Provider captures are expected fake traffic and
must leave the ordinary `outbound_http` guard count at zero.

The standalone `tests/bin/prove-provider-fail-closed.php` is intentional red:
it sends one unmatched request through WordPress HTTP and must exit 1 with the
provider rejection message. Mapping cases may use unsaved values or exact
owned Product/Variation fixtures, but no Phase 22 case creates an Order or
persists a provider token, key, gateway setting, remote ID, or Product meta.

Phase 23 commerce cases use only disposable exact-ID Customers, Orders,
Products, addresses, Carts, TaxRates, Coupons, OrderItems, and Transactions.
Every Order is pending or an inert report-prepared value fixture; no case
completes a payment. Checkout runs as an anonymous request through the
test-only inert gateway, and refund notifications are suppressed before the
mail boundary. Assert integer cents and stock quantities exactly, clean owned
rows in `finally`, and restore any temporary option, request, user, lock,
filter, or gateway state before the case returns. The multi-line coupon
remainder remains an executable `KNOWN-FAILURE` under FIX-PLAN #23.

Phase 24 mutation-closure cases must instantiate the exact sibling status or
equality boundary described in `MUTATION-AUDIT.md`. Pure cases remain
fixture-free. The DailyScheduler case may freeze only the test-only namespaced
clock, must reset it in `finally`, and may delete only exact-ID owned Carts.
Re-applied production mutants are temporary proof artifacts: observe the
focused failure, restore the original production line immediately, and require
an empty production diff before the phase commit.

Phase 25 JavaScript unit cases run only through the opt-in `npm run test:js`
entry point. Keep the environment Node-only and use local in-memory browser
boundary fakes; no WordPress, database, browser runner, network, or persistent
storage is available. Do not modify existing `test:*` script bodies,
`dev/browser/`, or `dev/wp-browser/`. Assert complete request data, response
errors, state transitions, integer cents, dates, schema output, and validator
messages exactly.

Phase 27 E2E money flows run only through the opt-in
`tests/bin/run-e2e.sh` entry point. The full tier launches each of the three
flows in a fresh WordPress process, reuses one exact Phase 27 identity across
those processes, and compares protected counts before the first flow and after
the last. A focused flow argument is for proof/development only and is never
full-tier evidence. Every flow proves mail/loopback interception before work,
installs `FcProviderHarness` as a zero-fallthrough provider boundary, captures
new Order IDs immediately, deletes only exact-owned rows in `finally`, and
reports zero HTTP, mail, cron, Action Scheduler, provider-request, and residue
counts. The default `tests/bin/run-all.sh` gate must not invoke Phase 27.

## 4. Harness API

`tests/lib/harness.php` is the shared WP-CLI runtime:

```php
FcTest::boot();
FcTest::case('behavior name', function () {});
FcTest::finish('SUITE NAME');

FcTest::rest('GET', '/route', ['per_page' => 5]);
FcTest::assertHealthy($result, 'label');
FcTest::assert($condition, 'detail');
FcTest::assertSame($expected, $actual, 'label');
FcTest::fail('detail');
FcTest::skip('reason');

FcTest::countQueries(function () {});
FcTest::profileQueries(function () {});
FcTest::uniq('prefix');
FcTest::clearCaches();
FcTest::useProviderHttpTransport($callback);
FcTest::clearProviderHttpTransport();

FcFixture::initialize();
FcFixture::customer();
FcFixture::reloadCustomer();
FcFixture::cleanupCustomer();
FcFixture::residueCount();
FcFixture::order();
FcFixture::reloadOrder($id);
FcFixture::updateOrderStatus($order, $status);
FcFixture::coupon();
FcFixture::activity($moduleId, $moduleType, $suffix);
FcFixture::label($suffix);
FcFixture::labelRelationship($labelId, $labelableId, $labelableType);
FcFixture::meta($objectId, $objectType, $metaKey, $metaValue);
FcFixture::productMeta($objectId, $objectType, $metaKey, $metaValue);
FcFixture::cleanupAll();
FcFixture::residueCounts();
FcFixture::sharedResidueCounts();
FcFixture::reportResidueCounts();
FcFixture::reportMarkerResidueCounts();
FcReportFixture::createDataset();
FcReportFixture::createCurrentOrderDataset();
FcDomainFixture::product();
FcDomainFixture::orderWithItem();
FcDomainFixture::scheduledAction();
FcDomainFixture::subscription();
FcDomainFixture::expiryCandidateIds();
FcDomainFixture::captureLicensesForOrder();
FcDomainFixture::cleanupAll();
FcDomainFixture::residueCounts();
FcCrudFixture::capture();
FcCrudFixture::snapshot();
FcCrudFixture::assertOnlyFieldChanged();
FcCrudFixture::cleanupAll();
FcCrudFixture::residueCounts();

$provider = new FcProviderHarness();
$provider->expect('POST', $url, 'stripe/payment-intent-success.json');
$provider->install();
$provider->requests();
$provider->assertComplete();
$provider->uninstall();
```

REST dispatch is in-process through `rest_do_request()`. Every case snapshots
WordPress's append-only `$EZSQL_ERROR` ledger so one failed query remains
observable even when a later query clears `$wpdb->last_error`. The harness also
resets plugin request caches before dispatch. `FcTest::boot()` installs
fail-closed WordPress HTTP and mail guards. An attempted external request or
mail is blocked and fails the owning case. Permission smoke additionally calls
`FcTest::interceptCronMutations()` so any cron write is blocked and reported.
Integration also calls `FcTest::interceptActionScheduler()` so enqueue/schedule
attempts are preempted and reported.

## 5. Findings and handoff

Production findings go in `tests/FIX-PLAN.md` with symptom, mechanism,
production `file:line`, and the parked test. Work evidence goes in the commit
message:

```text
Cases added: <count>   Suite runtime: <seconds>

Proof-of-catch:
  <case> — broke <mechanism>, observed: <exact red output>

Deliberately skipped:
  <surface> — <reason>

Needs a human decision:
  <ambiguity>
```

Report partial or blocked work honestly. Never start a later roadmap phase
inside the current phase.
