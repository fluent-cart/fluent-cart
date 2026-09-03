# FIX-AGENT.md — rules for a separate production-fix run

Read this instead of `AGENT.md`'s “never fix production code” rule only when a
maintainer explicitly starts a production-fix pass. Every other safety,
proof-of-catch, protected-data, and no-mocking rule remains active.

Do not use this file during test-writing or bootstrap work.

## 0. Preconditions

Work only from a reviewed item in `tests/FIX-PLAN.md`. The item must identify a
real failing case parked as `KNOWN-FAILURE`, cite the production `file:line`,
and state the failure mechanism.

The objective definition of done is:

> the parked case becomes a pass, every unrelated result stays unchanged, and
> every protected table count is unchanged.

## 1. Fix workflow

1. Count every configured protected table.
2. Run the focused suite and preserve the exact skip/failure text.
3. Make only the production fix authorized by the backlog item.
4. Count the protected tables again; stop on any change.
5. Run the focused suite and observe the parked case pass.
6. Run every installed real tier and confirm no unrelated state changed.
7. Count protected tables immediately after every invocation.
8. Update the backlog entry with before/after evidence.

If the fix does not flip its own case, it is not done.

## 2. Absolute prohibitions

- Never weaken, delete, narrow, skip, or “adjust” a test to make it pass.
- Never edit `vendor/`.
- Never change a route method, response contract, or public hook/filter
  signature without an explicitly versioned maintainer decision.
- Never call a payment gateway or live endpoint.
- Never create, capture, refund, or cancel a payment.
- Never allow a webhook to reach a live endpoint.
- Never send real mail, use real loopback HTTP, run cron, or call `sleep()`.
- Never touch pre-existing site data.
- Never commit a red real suite.
- Never bundle unrelated backlog items into one commit.

## 3. Stop and request a decision

Flag the item instead of fixing when:

- correct behavior is ambiguous;
- the change could break existing callers, add-ons, hooks, or response shapes;
- the fix requires `vendor/`, a migration, or a schema change not already
  authorized;
- the fix requires changing the test;
- the safety net cannot be proven locally.

## 4. Backlog update format

```markdown
## FIX N — <title> — <date/time>

**Status:** fixed | partial | flagged (needs decision)
**Files changed:** <paths>
**Commit:** <sha>

### Case flipped
- before: `<exact skip/failure text>`
- after: `<exact pass line>`

### What was wrong
<root-cause mechanism with production file:line>

### What changed
<narrow implementation and rationale>

### Regression risk
<what was checked and what could not be checked>

### Follow-ups / decisions needed
<remaining work>
```
