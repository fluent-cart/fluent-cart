# Customer Identity Binding — Broken Access Control

**Date:** 2026-09-11
**Severity:** CRITICAL
**Status:** NOT FIXED — audit only, no code in this repository has been changed
**Scope:** Storefront customer dashboard, `UserHandler`, `Customer` model, `CustomerResource`
**CWE:** CWE-639 (Authorization Bypass Through User-Controlled Key), CWE-287 (Improper Authentication)

This is the same vulnerability class that was reported against **Fluent Support 2.3.2**
by Patchstack as Broken Access Control. Fluent Cart has it too, on a wider surface.
This document records what the problem is, why it has to be fixed rather than
mitigated, and the design Fluent Support used to close it, so that work does not
have to be redone from scratch here.

---

## 1. Summary

Fluent Cart decides which customer record a signed-in visitor owns by **matching
email addresses**, and then rewrites the record's `user_id` to that visitor.

WordPress lets any user change their own account email with **no confirmation** on
most code paths. The click-through confirmation (`_new_email` meta + a hash mailed
to the new address) runs only on `wp-admin/profile.php`. The REST users endpoint,
WP-CLI, and virtually every front-end account form call `wp_update_user()`
directly and skip it entirely.

Put together: **a WordPress user can point their account at somebody else's email
address and take permanent ownership of that person's customer record**, including
their orders, addresses, downloads and subscriptions.

There is no email verification anywhere in the plugin. A search of `app/` and
`api/` for `_new_email`, `IS_PROFILE_PAGE`, `newuseremail`, and every spelling of
"verif" returns nothing.

---

## 2. Root cause

> An email address is treated as proof of identity. It is not. It is a mutable,
> self-service profile field.

Everything below is a consequence of that single assumption.

---

## 3. Affected code

### 3.1 The storefront identity resolver — primary issue

**`api/Resource/CustomerResource.php:402-432`**

```php
public static function getCurrentCustomer(bool $createIfNotExists = false): ?object
{
    ...
    $currentUser = get_user_by('ID', get_current_user_id());

    $query = Customer::query()->where('user_id', $currentUser->ID)
        ->orWhere('email', $currentUser->user_email)      // ⚠️ email reaches any record
        ->with(['billing_address', 'shipping_address']);

    $existingCustomer = $query->first();

    if ($existingCustomer) {
        if ($existingCustomer->user_id != $currentUser->ID) {
            $existingCustomer->user_id = $currentUser->ID; // ⚠️ and rebinds it
            $existingCustomer->save();                     // ⚠️ permanently
        }
        ...
    }
}
```

Two separate defects in one block:

1. `orWhere('email', ...)` lets an account reach a record it has no relationship to.
2. The rebind-and-save makes the takeover **permanent**. Changing the WordPress
   email back afterwards does not undo it — the record now carries the attacker's
   `user_id` and resolves to them on the first branch of the same query.

A third, smaller problem: `->first()` on an `OR` query with no `orderBy` means the
record that wins is whatever the storage engine returns first. With duplicate
emails this is nondeterministic.

**This block is the storefront's entire access control.** The route policy is:

**`app/Http/Policies/CustomerFrontendPolicy.php:8-12`**

```php
public function verifyRequest(Request $request): bool
{
    // check user logged in
    return is_user_logged_in();
}
```

Every controller under `customer-profile` (`app/Http/Routes/frontend_routes.php:62`)
delegates authorization entirely to `getCurrentCustomer()`. If that query returns the
wrong record, every endpoint in the group returns the wrong person's data.

### 3.2 Email change handler

**`app/Hooks/Handlers/UserHandler.php:30-67`**

```php
public function handleWpUserProfileUpdated($userId, $oldData, $newData = [])
{
    $emailChanged = $oldData->user_email !== $newData['user_email'];
    ...
    $attachToCustomer = Customer::query()->where('email', $newEmail)->first();

    if (empty($attachToCustomer)) {
        // moves the customer record onto the new address, unverified
        Customer::query()->where('email', $oldEmail)->update(['email' => $newEmail]);
    } else {
        $oldCustomer = Customer::query()->where('email', $oldEmail)->first();
        if (empty($oldCustomer)) {
            $attachToCustomer->update(['user_id' => $userId]);  // ⚠️ line 59
            return;
        }
        $this->moveCustomerResources($oldCustomer->id, $attachToCustomer->id);
        ...
    }
}
```

Line 59 is the takeover written out literally: a record found **only** by address
match is bound to the account that just claimed that address. Nothing here checks
whether the change was confirmed, because nothing in the plugin ever confirms one.

The `moveCustomerResources()` branch is also worth a look on its own: it moves
orders, coupons, carts, addresses, subscriptions and download permissions between
records on the strength of the same unverified signal.

### 3.3 Registration handler

**`app/Hooks/Handlers/UserHandler.php:123-136`**

```php
public function userRegistrationHandler($userId)
{
    $user = get_user_by('ID', $userId);

    $customer = Customer::query()
        ->where('email', $user->user_email)   // ⚠️ address match
        ->first();

    if ($customer) {
        $this->updateCustomer($customer, $user, $userId);   // writes user_id
        return;
    }
}
```

Registration is a slightly better signal than an email change — WordPress refuses
an address another *user* already holds, and core's own registration mails the
password to that inbox. But it is **not** proof on a store: any checkout or
membership flow that lets the visitor choose their own password (WooCommerce-style
registration, most membership plugins, Fluent Cart's own auto-registration at
`fluent_cart/cart_completed`) registers an unverified address and immediately
inherits every guest order placed under it.

There is no filter and no setting to turn this off.

### 3.4 Rebinding as a side effect of a read

**`app/Models/Customer.php:396-410`**

```php
public function getWpUserId($recheck = false)
{
    if ($recheck) {
        $user = get_user_by('email', $this->email);
        if ($user && $user->ID != $this->user_id) {
            $this->user_id = $user->ID;
            $this->save();          // ⚠️ a getter that rewrites identity
        }
    }
    return $this->user_id;
}
```

**`app/Models/Customer.php:470-489`** — `getWpUser()` does the same thing
unconditionally, with no opt-in flag at all.

Methods named `get*` that persist an identity change are hard to audit: any new
call site silently becomes another rebinding path.

---

## 4. Attack scenario

Preconditions: the site allows customer registration or already has the attacker as
any logged-in user (subscriber is enough). The target is a customer record whose
address is not currently held by an active WordPress account — **every guest
checkout customer**, which on a typical store is the majority.

1. Attacker holds any WordPress account.
2. Attacker changes their account email to the target's address via
   `PUT /wp-json/wp/v2/users/<own id>`, or any account form. No confirmation is
   sent, requested, or checked.
3. Attacker opens the customer dashboard.
4. `getCurrentCustomer()` matches the target's record on `orWhere('email', ...)`,
   sets `user_id` to the attacker, and saves.
5. The attacker now owns that record permanently.

A second route needs no email change at all: register a new account using a guest
customer's address (§3.3) and the same binding happens at `user_register`.

### What is exposed

From `app/Http/Routes/frontend_routes.php:62-99`, all behind `is_user_logged_in()`:

| Capability | Route |
|---|---|
| Order history and details | `GET orders`, `GET orders/{order_uuid}` |
| Billing addresses — read and **write** | `GET`/`PUT orders/{transaction_uuid}/billing-address` |
| Digital product downloads | `GET /downloads`, `downloadableProducts` |
| Customer profile and addresses | `POST /update`, `/edit-address`, `/delete-address` |
| Subscription list and detail | `GET subscriptions`, `GET subscriptions/{uuid}` |
| **Change payment method** | `POST subscriptions/{uuid}/update-payment-method` |
| **Switch payment method** | `POST subscriptions/{uuid}/switch-payment-method` |
| Cancel auto-renew | `POST subscriptions/{uuid}/cancel-auto-renew` |
| Pause / resume billing | `POST subscriptions/{uuid}/pause`, `/resume` |
| Trigger an early payment | `POST subscriptions/{uuid}/initiate-early-payment` |

This is a materially worse outcome than the Fluent Support report, which exposed
ticket history. Here it reaches purchase records, personal addresses, paid digital
goods, and live subscription billing controls.

### What limits it

Stated plainly so the risk is not overestimated:

- WordPress blocks changing an account email to one another **user** already holds.
  So a customer with an active account whose WordPress email still matches their
  customer record cannot be targeted through §3.2.
- The exposed population is guest-checkout customers, plus anyone whose WordPress
  email has since drifted away from the address on their orders.
- On a store, guest checkout customers are usually the largest group, and §3.3
  covers them through registration regardless.

---

## 5. Why this must be fixed rather than mitigated

- **It is an authentication bypass, not a data leak.** The attacker does not read
  something they should not; they *become* the customer. Everything downstream is
  correctly authorized against the wrong identity.
- **It is persistent.** The rebind is written to the database. Reverting the email
  does not restore the victim's ownership.
- **It reaches money.** Payment method changes and subscription controls sit behind
  it.
- **There is no configuration that turns it off**, so no advisory workaround exists.
- **It has an established precedent.** The same class was reported publicly against
  Fluent Support 2.3.2. A researcher who read that advisory has an obvious next
  place to look, and the affected code here is more valuable.

Filtering, escaping and capability checks cannot help — every component is behaving
as written. The identity decision itself is wrong.

---

## 6. How Fluent Support fixed it

Branch `fix/security-customer-identity-binding` in `fluent-support` (and the
matching branch in `fluent-support-pro`). The principle:

> A customer record's link to a WordPress account is written at record creation, at
> registration, or by somebody with authority over the account. **Never by an
> address match.** The contact address only moves when the change is proven.

### 6.1 Resolve by identity, never by address

`Customer::getCustomerFromData()` was changed so a `user_id` lookup and an `email`
lookup no longer fall through into each other. If a `user_id` is supplied, that is
the answer — no email fallback. Both lookups gained `orderBy('id', 'ASC')` so the
record that wins is the oldest match rather than whatever the engine returns.

**Fluent Cart equivalent:** delete `orWhere('email', ...)` from
`CustomerResource::getCurrentCustomer()` and remove the rebind-and-save.

### 6.2 An existing record is never claimed

`Customer::maybeCreateCustomer()` no longer writes `user_id` onto a record it is not
already linked to, and — added after review — no longer writes `email` onto a record
that belongs to an account at all. That second guard mattered: without it the
profile-update protection was worthless, because the next authenticated ticket
creation re-resolved the record and wrote the unverified address anyway.

**Fluent Cart equivalent:** audit every write of `user_id` and `email` on an
existing customer, including `getWpUser()` and `getWpUserId()` in the model.

### 6.3 The contact address only moves when the change is proven

`profile_update` now recognises exactly two proofs:

- **The account holder completed WordPress's own confirmation.** Detected by
  `IS_PROFILE_PAGE` being defined and truthy, the `_new_email` user meta matching
  the incoming address, and `hash_equals()` against `$_GET['newuseremail']`. The
  hash is the real proof — it only exists in the inbox the mail was delivered to.
- **An actor holding `edit_user` over the target account made an actual email
  change** (actor ≠ target).

Everything else — REST, WP-CLI, WooCommerce account forms, third-party profile
screens — syncs names and leaves the address alone.

Two details worth copying:

- Gate on whether the address **actually changed**, using `$old_user_data`. Without
  that, an administrator editing somebody's *name* counted as an authorised email
  change and moved the address onto whatever unverified value the account carried.
- `wp_insert_user()` takes **slashed** data and `profile_update` passes that same
  array through, and core separately slashes `$old_user_data->user_email` on
  purpose. Unslash **both sides** or an address containing an apostrophe reads as
  changed on every update.

### 6.4 Reconcile the leftovers with a confirmation link

Refusing unverified changes leaves a real gap: the account moves, the record does
not, and support mail keeps going to an abandoned inbox. `EmailClaimService` closes
it.

When the signed-in account's address and its record's address disagree, the
dashboard offers to confirm. Confirming mails a signed, expiring link **to the
address being claimed**. Opening it moves the address and absorbs any unlinked
duplicate record that had been collecting data under it.

**The security of the whole flow is which inbox that mail lands in.** Point an
account at an address you do not own and the link goes to the real owner, who
learns of the attempt, while you get nothing. Opening the link also requires being
signed in as the claiming account, so neither the inbox nor the account moves
anything alone.

The link carries its own state — an HMAC-SHA256 over
`record id | account id | current address | claimed address | expiry`, keyed on
`wp_salt('auth')` with a domain-separation prefix, base64url encoded. Binding the
record's *current* address is what retires a used link: confirming moves that
address, so a replay describes a record that no longer matches. Nothing is stored,
so nothing needs pruning.

Two implementation notes:
- Percent-encode the address fields before joining. WordPress's `is_email()`
  accepts `|` in the local part, so an unencoded separator makes the token
  unparseable for a legitimate address.
- Decode only **after** the signature verifies, so what is checked is exactly what
  was signed.

### 6.5 Rotate bearer credentials when an address moves

Fluent Support ticket hashes authorise a public signed view on their own. They are
now regenerated whenever the contact address moves **or** a ticket changes owner,
and are hidden from model serialization.

**Fluent Cart equivalent — please check:** every order/download/invoice link that
authorises by a token in the URL. If a record changes hands or an address moves,
those links must stop working. `OrderDownloadPermission` is the obvious one.

### 6.6 Merging must hand off everything before deleting

Absorbing a duplicate reparents orders/tickets one model at a time (so model events
fire), plus conversations, attachments, activity, person meta and internal
notifications, then deletes the emptied record **only after re-reading it as
empty**. An action lets the Pro plugin move its own tables.

`UserHandler::moveCustomerResources()` is the equivalent here and should be checked
against the full list of tables carrying `customer_id`.

### 6.7 Commits to read

`fluent-support`, branch `fix/security-customer-identity-binding`:

| Commit | Subject |
|---|---|
| `002ac2b6` | Bind customer records to accounts by identity, not by email |
| `260aa42d` | Read customer status through named predicates |
| `adceee36` | Add integration coverage for customer identity binding |
| `3549a5c0` | Let customers confirm a support address their account has moved to |
| `76b3262f` | Add integration coverage for the support address claim flow |
| `6a772550` | Hand off the rest of a customer record before deleting it |
| `acce83e1` | Stop paying for duplicate checks that were not needed |
| `b05deb41` | Close four gaps found reviewing this branch before merge |
| `bbda3c97` | Act on the external review, and drop a filter nobody asked for |

`fluent-support-pro`, same branch name: `42b3fd8`, `75fe079`, `2ab0b24`.

---

## 7. Suggested order of work for Fluent Cart

1. **Stop the bleeding.** Remove `orWhere('email', ...)` and the rebind-and-save
   from `CustomerResource::getCurrentCustomer()`. This alone closes the primary
   path.
2. **Remove the address-match binds.** `UserHandler.php:59`, and the rebinds in
   `Customer::getWpUser()` / `getWpUserId()`.
3. **Hold the address on unverified changes** in `handleWpUserProfileUpdated()`,
   applying §6.3.
4. **Add the claim flow** (§6.4) so customers can reconcile a diverged address.
5. **Gate registration binding** (§3.3) behind the claim flow plus a filter, then
   consider defaulting it off.
6. **Rotate order/download tokens** when a record changes hands (§6.5).

### The one genuine complication

Fluent Cart's dashboard **depends** on the email match today. A guest buyer who
later registers finds their orders only because of it. Removing the match without
shipping the claim flow orphans real customers' purchase history.

So steps 1 and 4 should land **together**, not in sequence. Fluent Support had the
same tension and the claim flow is what resolved it.

---

## 8. What not to build

Recorded because it came up during the Fluent Support review and cost time.

The merge and address-move paths have narrow concurrency windows: authorization is
checked before a sequence of separate writes, the address is saved before tokens
rotate, and transient-backed rate limits are read-modify-write. These are real and
they were **deliberately not fixed**. Closing them means locks or transactions that
neither plugin uses anywhere else, for windows measured in milliseconds that require
an attacker to already hold the proven address.

Do not add speculative extension points either. One filter was added to the Fluent
Support merge "in case" a listener could not move its rows, nothing used it, and it
was removed before merge — a public filter is hard to take back once shipped.

---

## 9. Verification checklist

Each of these should be observed **failing** against the current code before the
fix, and passing after. (Fluent Support's harness requires this for every test; see
`fluent-support/tests/AGENT.md`.)

- [ ] An unverified account email change does not move the customer record's address.
- [ ] An unverified account email change does not bind another customer's record.
- [ ] Loading the customer dashboard does not rebind a record the account is not linked to.
- [ ] A dashboard request cannot list or open another customer's orders.
- [ ] A dashboard request cannot read or alter another customer's subscription.
- [ ] Registering with a guest customer's address does not inherit their orders
      (once step 5 lands).
- [ ] A valid claim link moves the address and reparents duplicates.
- [ ] A claim link is refused when expired, tampered with, or opened by a different account.
- [ ] A claim link is refused when another account's record already holds the address.
- [ ] Order/download tokens stop working after a record changes hands.
- [ ] An admin editing only a name never moves a customer's address.
- [ ] A name containing an apostrophe reaches the record exactly as WordPress stores it.

---

## 10. Contact

Raised from the Fluent Support remediation of the Patchstack Broken Access Control
report. Direct questions to that branch's history, which carries the reasoning for
each decision in the commit messages.
