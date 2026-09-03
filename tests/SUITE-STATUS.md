# Test suite status

Snapshot: 2026-07-31, local WordPress/MySQL installation at `https://cart.lab`.
Protected baseline and final counts are Orders `5,140` / Customers `2,320`.
Unless a limit is stated below, exact fixture residue and outbound HTTP, mail,
cron, Action Scheduler, and provider-request captures are zero.

## Required and opt-in tiers

| Tier | Command | Latest result | Observed wall/runtime | Gate status |
|---|---|---|---:|---|
| Default PHP gate | `bash tests/bin/run-all.sh` | 9/9 gates; pure 26/26 (1 skip), REST 283/283 (34), permissions 318/318, integration 103/103 (16) | 21.51s wall; integration 13.42s | Required, green |
| Static | `bash tests/bin/run-all.sh static` | PHP lint + four inventory/lint gates pass | Included above | Required, green |
| Pure PHP | `bash tests/bin/run-all.sh pure` | 26/26, 1 executable known failure | <0.1s | Required, green |
| REST GET smoke | `bash tests/bin/run-all.sh smoke` | 283/283, 34 documented skips | ~1.9s | Required, green |
| Permission denial | `bash tests/bin/run-all.sh permissions` | 318/318 | ~2.0s | Required, green |
| Real-DB integration | `bash tests/bin/run-all.sh integration` | 103/103, 16 executable known failures | 13.42s | Required, green |
| JavaScript unit | `npm run test:js` | 4 files / 18 tests | 1.16s wall; 560ms Vitest | Opt-in, green |
| Admin screen smoke | `npm run test:browser -- admin/smoke` | 108 passed / 0 failed / 1 documented skip; 99/99 routes accounted | 48.42s | Opt-in, green |
| E2E money | `bash tests/bin/run-e2e.sh` | 3/3 flows, no skips | 3.24s | Opt-in, green |
| Full legacy browser | `npm run test:browser` | Diagnostic completion: 391 passed / 30 failed / 1 skipped across 422 assertions | 662.42s runner | Opt-in, partial/red |

The Phase 32 performance gate improved from 41.83s wall / 33.03s integration
to 21.51s wall / 13.42s integration by batching exact captured-ID residue
checks. This is a 48.6% wall-time reduction and meets the under-30-second gate
without reducing cases or safety assertions. Set `WP_PLUGIN_TEST_PROFILE=1`
to print per-integration-case elapsed time and query counts.

## Hostile environment profiles

| Profile | Command | Latest result | Wall | Status / open finding |
|---|---|---|---:|---|
| Strict SQL | `bash tests/bin/run-all.sh all --axis=strict-sql` | REST 261/283; integration 99/103 | 22.06s | Expected red: FIX-PLAN #17-#21 |
| Non-UTC | `bash tests/bin/run-all.sh all --axis=non-utc` | REST 283/283; permissions 318/318; integration 102/103 | 22.07s | Expected red: FIX-PLAN #22 |
| Pro absent | `bash tests/bin/run-all.sh all --axis=pro-absent` | 9/9 gates; integration 103/103 (18 skips) | 20.77s | Green |

All three profiles retained protected `5,140` / `2,320` counts, exact cleanup,
and zero transport/scheduler captures. They are opt-in production-compatibility
diagnostics and do not alter the green default gate.

**Why the non-UTC axis exists.** At `gmt_offset=0`, `current_time()` and
`gmdate()` are byte-identical, so any site-local-vs-UTC assertion is silently
vacuous — it passes whether the code is right or wrong. This site currently runs
at `+6`, so no assertion is vacuous today; at offset zero exactly one would lose
its discriminating power: the Order completion case bounding `completed_at` with
`gmdate()` in `tests/integration/order-status-state-machine.php`. The axis
preflight proves a fixed site-local timestamp is byte-identical to UTC at zero
and differs at `-4.5`. The pure `DateTime::gmtNow()` boundary stays meaningful at
zero because it also asserts a UTC timezone object and an exact epoch window.

## Coverage and mutation status

- Coverage inventory: 448 PHP files over 100 lines, 285 touched, 163 remaining
  zero-hit gaps. Phase 29 closed five gateway processor/webhook entries.
- Phase 29 gateway sample: 12/12 targeted behavior mutants killed; no
  actionable survivor. Stripe signature authentication is executable
  FIX-PLAN #30.
- Phase 30: all five Phase 28 survivors are killed by direct boundaries.
- Phase 31: 12 added JavaScript cases each went red under an owning drift or
  directly execute FIX-PLAN #31/#32.
- `tests/FIX-PLAN.md` contains 32 executable or source-pinned known findings.

## Known limits and tooling

- Phase 26 shared smoke behavior did not regress: its final standalone run is
  108/0/1 and byte-stable. The one skip is the explicit Element Plus
  `offsetHeight` park (FIX-PLAN #28); five routes remain explicitly parked.
- The committed full browser runner still stops after Phase 26 on this site's
  custom `/account/` redirect because shared `login()` waits only for
  `/wp-admin/` (FIX-PLAN #29). A temporary, fully restored login adapter allowed
  the diagnostic full run. Its 30 legacy failures are in Product edit,
  Customer search, and Tax suites; Orders, Products list, and Turnstile pass.
- The full legacy browser suite is mutating and is not byte-stable. Phase 32
  removed its exact orphan `_edit_lock` and cleared its exact `DE999888777` VAT
  marker; product edit flows may still advance ordinary modified timestamps.
- `package-lock.json` is authoritative: CI uses `npm ci`, documented workflows
  use `npm`, and release packaging includes it. The unused `yarn.lock` was
  restored to its pre-Vitest committed state.
