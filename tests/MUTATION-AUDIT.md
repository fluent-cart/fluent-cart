# Mutation audit — Phase 19 baseline through Phase 30 closure

These audits ask whether the local suite asserts the production behavior it
executes. They are deliberately small, risk-shaped samples, not claims of
repository-wide mutation coverage and not targets of 100%.

## Phase 28 re-run

Phase 28 sampled 16 fresh, non-equivalent mutations across the Phase 22/23
checkout, tax, coupon, refund, stock, and gateway-mapping surface, then
re-applied the four Phase 24 closure mutants. Mutants were applied one at a
time to `ee5b70efe` and restored immediately after their classification run.

| Sample | Mutants | Killed | Survived | Kill rate |
|---|---:|---:|---:|---:|
| Phase 19 historical baseline | 17 | 13 | 4 | 76.5% |
| Phase 28 fresh surface only | 16 | 11 | 5 | 68.8% |
| Phase 28 measured round, including four closure reconfirmations | 20 | 15 | 5 | 75.0% |

The narrowest owning case ran first. Every apparent survivor then ran against
the complete default PHP suite and the full three-flow Phase 27 E2E money tier
before classification. Those five runs remained green at pure `24/24` with
one documented known failure, smoke `283/283` with 34 documented skips,
permissions `318/318`, integration `87/87` with 15 documented known failures,
and E2E `3/3` with no skips. The JavaScript and browser tiers do not execute
these PHP branches and therefore were not mutation classifiers.

Every survivor run retained protected Orders `5,140`, Customers `2,320`, zero
owned residue, and zero outbound HTTP, mail, cron, Action Scheduler, and
provider requests. The four Phase 24 mutants all reproduced their original
focused red signature.

### Phase 28 survivor list, ranked by risk

#### 1. Coupon maximum discount below line subtotal — high

Mutant: remove `$maxAmount` from the three-way `min()` at
`app/Services/Coupon/Concerns/CanCalculateLineTotal.php:178`.

Impact: a coupon can discount more than its configured maximum when both the
coupon face value and eligible line subtotal exceed the cap.

Why it survived: the Phase 23 cap fixture sets the maximum to `1001` cents on
a `1001`-cent line. The line-total guard therefore produces the same `1001`
cents even when the explicit maximum is ignored.

Recommended kill: use a line subtotal above the coupon maximum and a coupon
amount above both, then assert the smaller independent maximum on the line and
discount ledger.

#### 2. Local refund reconciliation amount mismatch — high

Mutant: invert the local refund amount comparison from `==` to `!=` at
`app/Services/Payments/Refund.php:130`.

Impact: an asynchronous provider refund can attach its vendor ID to a local
refund with the wrong amount, while the matching local refund is ignored and a
duplicate refund row may be recorded.

Why it survived: the Phase 23 idempotency case creates a refund with a vendor
charge ID immediately. Its duplicate is resolved by the earlier vendor-ID
branch, so the local refund-without-vendor-ID reconciliation branch is never
entered.

Recommended kill: create one exact local refund without a vendor ID plus a
same-parent different-amount decoy, reconcile the matching provider payload,
and assert one row, the correct attached vendor ID, and unchanged money/stock.

#### 3. Stripe ordinary cancellation status — medium

Mutant: map the base `canceled` status to `expired` instead of `canceled` at
`app/Modules/PaymentMethods/StripeGateway/StripeHelper.php:68`.

Impact: a customer-initiated or otherwise ordinary Stripe cancellation is
reported as an expired subscription, changing lifecycle semantics and any
status-sensitive customer/admin display.

Why it survived: the Phase 22 Stripe case constructs only a canceled
subscription whose cancellation reason is `payment_failed`; the next branch
correctly overrides either base value to `expired`.

Recommended kill: add a normal canceled payload without
`cancellation_details.reason=payment_failed` alongside the existing failed
cancellation and assert the two distinct local statuses.

#### 4. Tax postcode range upper endpoint — medium

Mutant: change the postcode range upper comparison from `<=` to `<` at
`app/Modules/Tax/TaxCalculator.php:805`.

Impact: a customer whose numeric postcode equals the configured range endpoint
receives no matching tax rate.

Why it survived: the Phase 23 rate spans `1000-1999`, but the positive fixture
uses midpoint `1500`; the negative fixture uses `9999`. Neither instantiates
the exact upper boundary.

Recommended kill: calculate the same owned rate at `1999` and `2000`, asserting
inclusion at the endpoint and exclusion one step after it.

#### 5. Stripe HTTP 300 response boundary — low

Mutant: change the provider error boundary from `>= 300` to `> 300` at
`app/Modules/PaymentMethods/StripeGateway/API/API.php:97`.

Impact: an exact HTTP 300 response is returned as a success-shaped decoded body
instead of a `WP_Error`.

Why it survived: the Phase 22 Stripe error fixtures exercise HTTP `402` and
`429`, both of which remain errors under the mutant.

Recommended kill: add a canned HTTP 300 response and assert `api_error` plus
the preserved provider payload. This is lower risk because Stripe normally
uses 4xx/5xx API failures rather than redirects.

### Phase 28 complete mutant inventory

| ID | Production mutation | Focused/full evidence | Outcome |
|---|---|---|---|
| R28-01 | Checkout adjusted-item `line_total` subtraction becomes addition at `app/Helpers/CheckoutProcessor.php:332`. | Phase 23 produced line totals `1002/1000` and sum `2002` instead of `1000/998` and `1998`. | Killed |
| R28-02 | Subtract exclusive tax instead of adding it at `app/Helpers/CheckoutProcessor.php:1093`. | Phase 23 stored and transacted `1998` instead of `2004` cents. | Killed |
| R28-03 | Exclude the tax postcode range upper endpoint at `app/Modules/Tax/TaxCalculator.php:805`. | Focused tax, full default PHP, and E2E `3/3` all remained green. | Survived |
| R28-04 | Invert item/subtotal behavior in `TaxCalculator::roundTax()` at `app/Modules/Tax/TaxCalculator.php:1025`. | Item rounding returned `1` instead of `2`; subtotal rounding returned `2` instead of `1`. | Killed |
| R28-05 | Change coupon minimum eligibility from `<=` to `<` at `app/Services/Coupon/Concerns/CanValidateCoupon.php:131`. | Exact-minimum coupon discount disappeared (`0` instead of `1001`) and the missing calculated line raised diagnostics. | Killed |
| R28-06 | Ignore coupon `max_discount_amount` at `app/Services/Coupon/Concerns/CanCalculateLineTotal.php:178`. | Focused coupon, full default PHP, and E2E `3/3` all remained green. | Survived |
| R28-07 | Invert vendor refund ID equality at `app/Services/Payments/Refund.php:121`. | Refund rows and refunded cents doubled to `2`/`2002`; stock moved again to `9/1/0`. | Killed |
| R28-08 | Invert local refund amount equality at `app/Services/Payments/Refund.php:130`. | Focused refund, full default PHP, and E2E `3/3` all remained green. | Survived |
| R28-09 | Decrement rather than increment `on_hold` on legacy stock reservation at `app/Listeners/UpdateStock.php:215`. | Created-order stock became `7/0/-3` instead of `7/0/3`. | Killed |
| R28-10 | Subtract rather than restore available stock on refund at `app/Listeners/UpdateStock.php:253`. | Available stock became `6` instead of `8`. | Killed |
| R28-11 | Stop converting internal JPY cents to Stripe zero-decimal units at `app/Modules/PaymentMethods/StripeGateway/Plan.php:93`. | Captured Stripe plan amount was `12300` instead of `123`. | Killed |
| R28-12 | Map Stripe half-yearly cadence to five months at `app/Modules/PaymentMethods/StripeGateway/Plan.php:48`. | Fail-closed provider harness rejected the `_5_` plan URL while `_6_` was expected. | Killed |
| R28-13 | Map ordinary Stripe cancellation to expired at `app/Modules/PaymentMethods/StripeGateway/StripeHelper.php:68`. | Focused Stripe mapping, full default PHP, and E2E `3/3` all remained green. | Survived |
| R28-14 | Map PayPal quarterly cadence to four months at the effective branch in `app/Modules/PaymentMethods/PayPalGateway/PayPalHelper.php:233`. | Captured PayPal plan interval count was `4` instead of `3`. | Killed |
| R28-15 | Map PayPal suspended status to expiring at `app/Modules/PaymentMethods/PayPalGateway/SubscriptionManager.php:375`. | Status mapping returned `expiring` instead of `paused`. | Killed |
| R28-16 | Treat exact Stripe HTTP 300 as success at `app/Modules/PaymentMethods/StripeGateway/API/API.php:97`. | Focused Stripe errors, full default PHP, and E2E `3/3` all remained green. | Survived |
| R28-17 | Reconfirm Phase 24 trial-signup `&& → ||`. | Coupon total `60` vs `200`, line discounts `30/30` vs `100/100`, totals `270/270` vs `900/900`. | Killed |
| R28-18 | Reconfirm Phase 24 removal of `pending` from `NON_COLLECTING`. | Collected totals rose to `888` from `222` cents. | Killed |
| R28-19 | Reconfirm Phase 24 projection horizon `<= → <`. | Exact-horizon 2401-cent bucket disappeared. | Killed |
| R28-20 | Reconfirm Phase 24 cart cleanup cutoff `<= → <`. | Exact-cutoff owned Cart remained while the one-second-newer Cart was retained. | Killed |

### Phase 28 restored verification

An isolated worktree at the pre-Round-5 commit `d24c78d7d` reproduced the
unchanged starting baseline: pure `16/16` with one documented known failure,
smoke `283/283` with 34 documented skips, permissions `318/318`, and integration
`77/77` with 14 documented known failures in `22.3s`. Its protected counts,
transport guards, and fixture cleanup all remained exact, and the worktree was
removed after the run.

After the final mutant was restored, the default PHP gate passed at pure
`24/24` with one documented known failure, smoke `283/283` with 34 documented
skips, permissions `318/318`, and integration `87/87` with 15 documented known
failures in `31.2s`. Vitest passed three files and six tests. The Phase 27 E2E
tier passed `3/3` with no skips, residue, or transport captures.

The final browser code completed three consecutive registered-screen
traversals with the identical result: `108` passed, `0` failed, and one
documented skip across `109` assertions. Each run accounted for all 99 runtime
routes, rendered four data grids, restored the same whole-store fingerprint,
and removed one `_edit_lock` created by its exact disposable administrator.
FIX-PLAN #28 parks the intermittent Element Plus `offsetHeight` diagnostic as
`SKIP`; it was absent on these three runs but had been observed during the
audit. All other uncaught page or console errors remain fatal. FIX-PLAN #29
separately documents the custom-login handshake flake and its exact-owned
auth-cookie recovery; recovered attempts are also reported as skipped rather
than unqualified green.

All restored gates ended with Orders `5,140`, Customers `2,320`, no owned
residue, no permitted outbound transport, and no production-file diff.

## Phase 29 gateway surface

Phase 29 sampled the 12 passing processor, subscription-manager, and webhook
behaviors it added. Each owning case was run against a targeted temporary
production drift, and all 12 produced a direct red signature before the
original production bytes were restored.

| Surface | Passing cases | Targeted mutants killed | Actionable survivors |
|---|---:|---:|---:|
| Stripe processor and subscription manager | 3 | 3 | 0 |
| PayPal processor | 2 | 2 | 0 |
| Stripe webhook replay/order handling | 3 | 3 | 0 |
| PayPal signature/replay/order handling | 4 | 4 | 0 |
| **Total** | **12** | **12** | **0** |

Deleting only PayPal's early already-cancelled handler guard remained green
because the downstream subscription service independently rejects the same
state. That redundant, path-equivalent deletion is excluded from the table;
removing both replay guards produced two cancellation events and two Activity
rows, so the observable idempotency contract is killed. The executable Stripe
signature case is a production finding (FIX-PLAN #30), not a surviving mutant.

The restored Phase 29 slice passed `14/14` with one documented known failure;
the then-current full integration tier passed `100/100` with 16 documented
known failures. Protected Orders stayed `5,140`, Customers `2,320`, and HTTP,
mail, cron, Action Scheduler, and provider-request captures stayed at zero.

## Phase 30 survivor closure

Phase 30 added one exact boundary case for each of the five Phase 28 survivors
and then reapplied the original mutations one at a time. Every mutant was
killed by its narrow owning case and restored immediately afterward.

| Phase 28 survivor | Phase 30 red signature | Outcome |
|---|---|---|
| R28-06 coupon maximum removed | The independent `1001`-cent cap became `4000` cents on both the line and coupon ledger, leaving `1000` instead of `3999` payable cents. | Killed |
| R28-08 local refund amount equality inverted | Provider ID `re_phase30_matching_1001` attached to the `2002`-cent decoy (ID `7292`) instead of the matching `1001`-cent local refund (ID `7291`). | Killed |
| R28-13 ordinary Stripe cancellation mapped to expired | The ordinary/payment-failed pair returned `expired/expired` instead of `canceled/expired`. | Killed |
| R28-03 postcode upper endpoint excluded | Postcode `1999` produced `0` instead of `10` tax cents; `2000` remained excluded. | Killed |
| R28-16 exact Stripe HTTP 300 accepted | HTTP 300 returned a decoded array instead of `WP_Error(api_error)` with the preserved provider payload. | Killed |

The restored focused gates passed pure `2/2` and integration `4/4`, with
protected Orders `5,140`, Customers `2,320`, exact fixture cleanup, and zero
outbound HTTP, mail, cron, or Action Scheduler captures.

## Phase 19 historical baseline

| Mutants | Killed | Survived | Kill rate |
|---:|---:|---:|---:|
| 17 | 13 | 4 | 76.5% |

No equivalent mutant is included in the denominator. Every mutation below
changes behavior for a constructible input. Mutants were applied one at a time
to the Phase 20 baseline commit `dcf3ad082` and restored immediately after the
run.

The first run was the narrowest relevant existing slice. Whenever that slice
was green, the mutant was run against the complete default `all` suite before
being classified. Two mutants that looked like survivors in a narrow slice
were killed only by the full suite:

- accepting `per_page=200` passed the Phase 16 throughput slice, then Phase
  10's four shared-list boundary cases killed it; and
- returning `true` from the base route policy passed the single
  `upload-editor-file` filter, then the full permission and integration tiers
  killed it.

All full-suite survivor runs retained the ordinary green result: pure `16/16`
with one known skip, smoke `283/283` with 34 skips, permissions `318/318`, and
integration `77/77` with 14 known skips. Protected Orders stayed at 5,140,
Customers at 2,320, and outbound HTTP, mail, cron, and Action Scheduler
captures stayed at zero.

### Phase 19 historical survivor list, ranked by risk

#### 1. Trial-signup coupon subtotal branch — high

Mutant: change `&&` to `||` between the subscription-payment and positive
trial-day checks at `app/Services/Coupon/DiscountService.php:512-514`.

Impact: a subscription without a trial can be treated as signup-fee-only, or a
non-subscription item carrying stray trial metadata can enter the same branch.
Either can calculate a coupon against the wrong subtotal on a checkout money
path.

Why it survived: the pure fixed-discount case uses ordinary one-time lines with
no trial metadata. The local integration tier deliberately does not create a
checkout or payment.

Recommended kill: add fixture-free line cases for a subscription with zero
trial days and a one-time item with nonzero stray trial days; assert the exact
coupon discount and resulting line total.

#### 2. Pending subscription counted as collected revenue — high

Mutant: remove `pending` from `ProductFinancialsCalculator::NON_COLLECTING` at
`app/Modules/MCP/Support/ProductFinancialsCalculator.php:35-36`.

Impact: a pending subscription with a nonzero bill count contributes money to
`collected_to_date`, overstating product financials and any MCP response built
from that rollup.

Why it survived: the Phase 13 financial case isolates currency and settlement
using only active subscriptions. It asserts status-sensitive forward metrics
but supplies no pending or intended row with money.

Recommended kill: include active, pending, and intended rows with distinct
nonzero collected amounts; assert the full status breakdown while requiring
both non-collecting statuses to contribute exactly zero lifetime revenue.

#### 3. Payment exactly on the projection horizon — medium

Mutant: change the inclusive horizon comparison from `<=` to `<` at
`app/Modules/MCP/Support/PaymentProjector.php:86`.

Impact: a finite installment or recurring renewal whose timestamp equals the
requested horizon disappears from its bucket and projected totals.

Why it survived: the Phase 13 projection ends at `2026-09-30 23:59:59`, while
its last monthly event is `2026-09-20`. The finite-cap and perpetual-horizon
assertions are strong but do not place an event on the exact endpoint.

Recommended kill: add events exactly at the horizon and one second after it;
assert inclusive admission for the first and exclusion for the second.

#### 4. Cart exactly on the cleanup cutoff — low

Mutant: change the stale-cart query from `updated_at <= cutoff` to
`updated_at < cutoff` at
`app/Hooks/Scheduler/AutoSchedules/DailyScheduler.php:34-36`.

Impact: a cart exactly on the configured age boundary remains until a later
daily pass. This is a one-cycle retention error rather than data corruption.

Why it survived: the Phase 14 case places its stale cart in 1901 and its
retained cart in 2099, proving broad selection and idempotency but not equality
at the cutoff.

Recommended kill: create owned carts exactly at the computed cutoff and one
second newer, then assert delete/retain respectively without sleeping.

### Phase 24 survivor closure

Phase 24 implemented the four recommended boundaries above and re-applied each
original mutant individually. All four now die in the narrowest owning case,
then pass again after the production line is restored:

| Survivor | Phase 24 focused evidence | Outcome |
|---|---|---|
| S1 trial-signup `&& → ||` | The two sibling lines produced `60` instead of `200` coupon cents, `30/30` instead of `100/100` line discounts, and `270/270` instead of `900/900` line totals. | Killed |
| S2 remove `pending` from `NON_COLLECTING` | Pending revenue leaked into both finite and headline collected totals: `888` instead of `222` cents. | Killed |
| S3 projection horizon `<= → <` | The `2026-07-31 00:00:00` exact-horizon event disappeared, leaving an empty bucket instead of one 2401-cent finite event; the `+1s` event remained excluded. | Killed |
| S4 stale-cart cutoff `<= → <` | With the DailyScheduler clock frozen, the exact-cutoff owned Cart remained present; the one-second-newer Cart was retained in both versions. | Killed |

The Phase 19 baseline remains historical (`13/17`, 76.5%). The Phase 28
re-run above reconfirmed all four closure kills.

### Phase 19 complete mutant inventory

| ID | Production mutation | Focused evidence | Outcome |
|---|---|---|---|
| M01 | Replace Activity `morphMany` with ID-only `hasMany` at `app/Models/Concerns/HasActivity.php:10-14`. | Shared Activity case returned its same-ID Coupon decoy and other same-ID rows. | Killed |
| M02 | Replace Order LabelRelationship `morphMany` with ID-only `hasMany` at `app/Models/Order.php:911-914`. | Shared Label case returned both the Order row and Customer decoy. | Killed |
| M03 | Remove Coupon Meta `object_type=coupon` at `app/Models/Coupon.php:179-184`. | Shared Coupon Meta read returned the first `phase6_decoy` value. | Killed |
| M04 | Remove ProductMeta `object_type` from `ProductMetaResource::find()` at `api/Resource/ProductMetaResource.php:36-38`. | Shared ProductMeta read returned the type decoy ID/value. | Killed |
| M05 | Return before `BaseFilter::applyWith()` eager loading at `app/Services/Filter/BaseFilter.php:597-612`. | Phase 16 saw relation coverage fall to zero and query counts grow `12→52` / `7→27`. | Killed |
| M06 | Accept `per_page=200` by changing `< 200` to `<= 200` at `app/Services/Filter/BaseFilter.php:278-284`. | Throughput passed; full suite observed `200` instead of clamped `10` on Products, Orders, Customers, and Activity. | Killed by full suite |
| M07 | Exclude events equal to the projection horizon at `app/Modules/MCP/Support/PaymentProjector.php:86`. | Phase 24 removed the exact-horizon 2401-cent bucket while the `+1s` decoy stayed excluded. | Killed in Phase 24 |
| M08 | Invert the `NON_COLLECTING` membership test at `app/Modules/MCP/Support/ProductFinancialsCalculator.php:200`. | Phase 13 observed collected total `0` instead of `49800`. | Killed |
| M09 | Remove `pending` from `NON_COLLECTING` at `app/Modules/MCP/Support/ProductFinancialsCalculator.php:35-36`. | Phase 24 observed collected totals rise from `222` to `888` cents. | Killed in Phase 24 |
| M10 | Change Coupon max-use rejection from `>=` to `>` at `app/Services/Coupon/DiscountService.php:627-630`. | Phase 12 received no `coupon_max_uses_exceeded` error at the exact limit. | Killed |
| M11 | Swap the trial-signup subtotal branch from `&&` to `||` at `app/Services/Coupon/DiscountService.php:512-514`. | Phase 24 observed the sibling-line coupon total fall from `200` to `60` cents. | Killed in Phase 24 |
| M12 | Change the first Order-value bucket from `<= 10000` to `< 10000` at `app/Services/Report/OrderReportService.php:55-68`. | Phase 7 counted `0` instead of `1` at the exact 100.00 boundary. | Killed |
| M13 | Remove the report `paymentStatus` query constraint at `app/Services/Report/ReportService.php:162-175`. | Phase 7 produced 25 exact-value failures, including gross `1199.99` instead of `200.00`. | Killed |
| M14 | Change expiry batch limit to `batchSize - 1` at `app/Models/Subscription.php:1379-1382`. | Phase 8 processed three owned rows in three batches instead of two. | Killed |
| M15 | Exclude carts equal to the daily cleanup cutoff at `app/Hooks/Scheduler/AutoSchedules/DailyScheduler.php:34-36`. | Phase 24's frozen-clock exact-ID case retained the equal-cutoff Cart. | Killed in Phase 24 |
| M16 | Remove the `renewal_processed` early return at `app/Modules/StoreManagedRenewal/Services/RenewalService.php:505-508`. | Phase 14 dispatched the renewal event twice instead of once. | Killed |
| M17 | Return `true` immediately from `Policy::hasRoutePermissions()` at `app/Http/Policies/Policy.php:40-57`. | One-route filter passed; full suite failed 266 permission denials and three ID-scoped subscriber GET denials. | Killed by full suite |

### Phase 19 interpretation

The suite is strongest where it uses adversarial decoys, exact cents, boundary
fixtures, full-row snapshots, idempotency counters, and staged query profiles.
All four shared-table discriminator mutants died immediately, as did report
filter deletion, subscription batch drift, coupon use-limit drift, and renewal
redelivery.

The survivor pattern is narrower: sibling status values and exact equality
boundaries that the existing examples do not instantiate. These are targeted
test opportunities, not evidence that the production behavior is wrong. The
ranked list is the deliverable; chasing a nominal 100% score would invite
incidental assertions and equivalent mutants.
