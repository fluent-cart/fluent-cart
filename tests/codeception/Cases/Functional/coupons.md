# Coupons

<!-- Frontend bucket scope note: this spec covers the REST contract layer only. Storefront coupon flows — customers applying, removing, and re-applying coupons during checkout — currently reach the server through the WordPress admin-ajax handler (fluent_cart_cart_update action), not through any REST route, and are therefore out of scope for this file. They are deferred to a separate ajax/checkout suite. The admin-facing apply, cancel, re-apply, and product-eligibility cases in this file exercise the admin REST routes (which require admin permissions), not the storefront flow. -->

## Listing and searching coupons
- [ ] 1. Admin can list coupons, with the most recent first by default.- [x] 2. Admin can paginate the coupons list.
- [x] 3. Admin can request a specific page size when listing coupons.
- [x] 4. Admin receives an empty list, not an error, when requesting a page beyond the available coupons.
- [x] 5. Admin can list active coupon codes for use when creating an order.
- [x] 6. The coupon list shows how many times each coupon has been applied so far.
- [x] 7. Admin sees coupon search input that resembles an SQL injection attempt treated as plain text without causing a server error.

## Viewing a single coupon
- [x] 8. Admin can open a coupon by id and see its details along with its activity history.
- [x] 9. Opening a coupon whose end date has already passed automatically marks it as expired.
- [ ] 10. Opening a coupon that does not exist returns a clear "not found" error.
## Creating a coupon
- [x] 11. Admin can create a coupon with a valid title, code, type, amount, and status.
- [x] 12. Creating a coupon without a title is rejected with a clear validation error.
- [x] 13. Creating a coupon without a code is rejected with a clear validation error.
- [x] 14. Creating a coupon without a type is rejected with a clear validation error.
- [x] 15. Creating a coupon without an amount is rejected with a clear validation error.
- [x] 16. Creating a percentage coupon with an amount greater than 100 is rejected with a clear error.
- [x] 17. Creating a coupon with a code already used by another coupon is rejected with a clear error.
- [x] 18. Creating a coupon whose end date is earlier than its start date is rejected with a clear error.
- [ ] 19. Creating a coupon whose maximum total uses is less than its maximum uses per customer is rejected with a clear error.- [x] 20. A fixed-amount coupon's discount value is stored consistently (e.g. $12.34 round-trips correctly).
- [x] 21. The coupon's start and end dates are recorded in UTC, regardless of the site's timezone.
- [x] 22. Creating a coupon triggers a "coupon created" notification that internal listeners can react to.
- [x] 23. Creating a coupon writes an entry in the activity log so admins can see when and by whom it was created.

## Updating a coupon
- [x] 24. Admin can rename a coupon.
- [x] 25. Admin can change the discount amount of an existing coupon.
- [x] 26. Admin can change a coupon's status from active to disabled.
- [x] 27. Trying to set a coupon to an unknown status is rejected with a clear error.
- [x] 28. Updating a coupon to use a code already used by another coupon is rejected with a clear error.
- [x] 29. Saving a coupon without changing its code does not falsely report the code as duplicated.
- [x] 30. Updating a coupon so its end date is earlier than its start date is rejected with a clear error.
- [x] 31. Updating a coupon triggers a "coupon updated" notification that internal listeners can react to.
- [x] 32. Updating a coupon writes an entry in the activity log so admins can see when and by whom it was changed.
- [ ] 33. Updating a coupon that does not exist returns a clear "not found" error.
## Deleting a coupon
- [x] 34. Admin can delete a coupon; the coupon is removed and no longer appears in the coupon list.
- [ ] 35. Deleting a coupon that does not exist returns a clear "not found" error.
## Applying a coupon to an admin-created order
- [x] 36. Admin can apply a valid active coupon to an order's items and the discount is reflected in the calculated totals.
- [x] 37. Admin can apply more than one stackable coupon at a time and each discount is reflected in the calculated totals.
- [x] 38. Applying a coupon code that does not exist is rejected with a clear error.
- [x] 39. Applying a coupon whose start date has not yet arrived is rejected with a clear error.
- [x] 40. Applying a coupon that has already expired is rejected with a clear error.
- [x] 41. Applying a coupon that has already reached its maximum total uses is rejected with a clear error.
- [x] 42. Applying a coupon to an order whose subtotal is below the coupon's minimum purchase amount is rejected with a clear error.
- [x] 43. Applying a non-stackable coupon when another coupon is already applied is rejected with a clear error.
- [x] 44. Applying a coupon without a coupon code is rejected with a clear error.
- [x] 45. Applying a coupon without any order items is rejected with a clear error.

## Canceling an applied coupon
- [x] 46. Admin can cancel a previously applied coupon on an order, and the discount is removed from the calculated totals.
- [x] 47. Canceling a coupon decrements how many times that coupon has been used by one.

## Re-applying coupons
- [ ] 48. Admin can re-apply previously saved coupons to an order's items and the calculated totals match the saved discount state.- [x] 49. Re-applying with no items removes any previously saved coupons from the order.

## Checking product eligibility
- [ ] 50. Admin can ask whether a given product is eligible for an applied coupon and receive a clear answer.- [ ] 51. Checking a product whose category is excluded by a coupon reports the product as ineligible for that coupon.
## Coupon checkout settings
- [ ] 52. Admin can read the current "show coupon field on checkout" setting.- [x] 53. Admin can save the "show coupon field on checkout" setting.

## Permissions
- [x] 54. Store manager with the "view coupons" permission can list coupons.
- [x] 55. Store manager with the "view coupons" permission can view a coupon's details.
- [x] 56. Store manager with the "manage coupons" permission can create a coupon.
- [x] 57. Store manager with the "manage coupons" permission can update a coupon.
- [x] 58. Store manager with the "delete coupons" permission can delete a coupon.
- [x] 59. Store manager with the "manage orders" permission can apply a coupon to an order.
- [x] 60. Store manager with the "manage orders" permission can cancel a coupon on an order.
- [x] 61. Store manager with the "manage orders" permission can re-apply coupons to an order.
- [ ] 62. Store manager with the "manage orders" permission can check whether a product is eligible for a coupon.- [x] 63. Worker with the "view coupons" permission can list coupons.
- [x] 64. Worker with the "view coupons" permission can view a coupon's details.
- [x] 65. Worker with the "manage coupons" permission can create a coupon.
- [x] 66. Worker with the "manage coupons" permission can update a coupon.
- [x] 67. Worker without the "delete coupons" permission cannot delete a coupon.
- [x] 68. Worker without the "manage orders" permission and without the "create orders" permission cannot apply a coupon to an order.
- [x] 69. Worker without the "manage orders" permission and without the "create orders" permission cannot cancel a coupon on an order.
- [x] 70. Worker without the "manage orders" permission and without the "create orders" permission cannot re-apply coupons to an order.
- [x] 71. Worker without the "manage orders" permission and without the "create orders" permission cannot check whether a product is eligible for a coupon.
- [x] 72. Accountant with the "view coupons" permission can list coupons.
- [x] 73. Accountant with the "view coupons" permission can view a coupon's details.
- [x] 74. Accountant without the "manage coupons" permission cannot create a coupon.
- [x] 75. Accountant without the "manage coupons" permission cannot update a coupon.
- [x] 76. Accountant without the "delete coupons" permission cannot delete a coupon.
- [x] 77. Accountant without the "manage orders" permission and without the "create orders" permission cannot apply a coupon to an order.
- [x] 78. Accountant without the "manage orders" permission and without the "create orders" permission cannot cancel a coupon on an order.
- [x] 79. Accountant without the "manage orders" permission and without the "create orders" permission cannot re-apply coupons to an order.
- [x] 80. Accountant without the "manage orders" permission and without the "create orders" permission cannot check whether a product is eligible for a coupon.
- [x] 81. Store manager with the "view coupons" permission can read the coupon checkout settings.
- [x] 82. Worker with the "view coupons" permission can read the coupon checkout settings.
- [x] 83. Accountant with the "view coupons" permission can read the coupon checkout settings.
- [x] 84. Store manager with the "manage coupons" permission can save the coupon checkout settings.
- [x] 85. Worker with the "manage coupons" permission can save the coupon checkout settings.
- [x] 86. Accountant without the "manage coupons" permission cannot save the coupon checkout settings.
- [x] 87. A logged-out visitor cannot list coupons.
- [x] 88. A logged-out visitor cannot view a single coupon.
- [x] 89. A logged-out visitor cannot create a coupon.
- [x] 90. A logged-out visitor cannot update a coupon.
- [x] 91. A logged-out visitor cannot delete a coupon.
- [x] 92. A logged-out visitor cannot apply a coupon to an order.
- [x] 93. A logged-out visitor cannot cancel a coupon on an order.
- [x] 94. A logged-out visitor cannot re-apply coupons to an order.
- [x] 95. A logged-out visitor cannot check whether a product is eligible for a coupon.
- [x] 96. A logged-out visitor cannot read the coupon checkout settings.
- [x] 97. A logged-out visitor cannot save the coupon checkout settings.

## Authentication and CSRF
- [x] 98. A logged-in admin without a valid security token is still rejected from coupon-changing actions.
- [x] 99. An expired security token is rejected with a clear error.

## New scenarios (added 2026-05-07)

<!-- This section is the date-stamped append target sanctioned by SKILL.md Step 6 ("File exists — preserve all existing content. Append a new section at the bottom titled `## New scenarios (added YYYY-MM-DD)`"). It is an admin/audit header, not a user-facing action heading, and is therefore exempt from Surface B check 5. The cases below are grouped by the existing top-level section they would belong to if they had been authored in the original pass: applying coupons, creating coupons, checking product eligibility, listing active coupon codes. -->

- [x] 100. Applying a coupon whose status is disabled is rejected with a clear error.
- [x] 101. Applying a coupon to a customer who has already used it up to its per-customer limit is rejected with a clear error.
- [ ] 102. Checking a product listed in a coupon's excluded-products restriction reports the product as ineligible for that coupon.- [x] 103. Creating a coupon with a code that contains punctuation or other non-alphanumeric characters preserves the code exactly as entered when the coupon is read back.

<!-- Configuration matrix gaps surfaced by /functional-testing:write-cases Step 2.5 extraction (validator enums, conditions fields, sanitize round-trips). Service-level math behaviors deliberately skipped: apply_to_whole_cart toggle, apply_to_quantity toggle, buy_quantity / get_quantity calculation for buy-X-get-Y discount math, and the runtime application of max_discount_amount cap on a calculated discount. Those belong in CouponService integration tests, not REST functional tests. The "priority" field (intval sort-order on the listing) is also skipped — it is a non-behavioral display ordering field and a round-trip case adds no signal beyond integer-sanitize boilerplate. -->

- [x] 104. Admin can create a free-shipping coupon, and reading the coupon back confirms it is a free-shipping coupon.
- [x] 105. Admin can create a buy-X-get-Y coupon, and reading the coupon back confirms it is a buy-X-get-Y coupon.
- [x] 106. Creating a coupon with an end date but no start date is rejected with a clear error.
- [ ] 107. Saving a coupon with HTML in its title strips the HTML and stores the plain title safely.- [ ] 108. Saving a coupon with HTML in its notes strips the HTML and stores the plain notes safely.- [x] 109. Admin can save a coupon as stackable, and reading the coupon back confirms it is stackable.
- [x] 110. Admin can save a coupon as non-stackable, and reading the coupon back confirms it is non-stackable.
- [x] 111. Applying a coupon to an order whose subtotal exceeds the coupon's maximum purchase amount is rejected with a clear error.
- [x] 112. Applying a coupon to a customer whose email is not on the coupon's email allowlist is rejected with a clear error.
- [x] 113. Admin can create a coupon flagged as recurring so it applies to subsequent renewals, and reading the coupon back confirms the recurring flag.
- [x] 114. Admin can create a coupon with a maximum discount cap, and reading the coupon back returns the same cap value.
- [ ] 115. Checking a product that is not on a coupon's included-products list reports the product as ineligible for that coupon.- [ ] 116. Checking a product whose category is on a coupon's included-categories list reports the product as eligible for that coupon.
<!-- Audit: a previous iteration appended a case "Applying a coupon whose status is scheduled is rejected with a clear error". Source-code verification showed CanValidateCoupon::isCouponActive does not reject by the status field at all — it defers to start_date / end_date checks for both `active` and `scheduled` rows. The status="scheduled" value is a helper-managed label for "start date in future" set by CouponHelper::updateCouponStatus. The functional-equivalent rejection is already covered by #39 ("Applying a coupon whose start date has not yet arrived is rejected"). The contradicting case was therefore dropped before final approval, and the cases below were renumbered (former 118-125 became 117-124) so the spec stays gap-free. -->

<!-- Listing active coupon codes — permission boundaries for the /listCoupons route, whose gate (orders/create OR orders/manage OR coupons/view) differs from the /coupons index route covered above. -->
- [x] 117. Store manager can list active coupon codes for use when creating an order.
- [x] 118. Worker can list active coupon codes for use when creating an order.
- [x] 119. Accountant can list active coupon codes for use when creating an order.
- [x] 120. A logged-out visitor cannot list active coupon codes for use when creating an order.
- [x] 121. A customer (a WordPress user with only the customer role) cannot list active coupon codes for use when creating an order.
- [x] 122. A worker whose permissions are adjusted to grant only "manage orders" (with no "view coupons" and no "create orders") can still list active coupon codes for use when creating an order.
- [x] 123. A worker whose permissions are adjusted to grant only "create orders" (with no "view coupons" and no "manage orders") can still list active coupon codes for use when creating an order.
- [x] 124. A worker whose permissions are adjusted to remove "view coupons", "manage orders", and "create orders" cannot list active coupon codes for use when creating an order.
- [x] 125. Applying an email-restricted coupon with a customer email on the allowlist succeeds.
- [x] 126. A legacy applied-coupon row recorded without a coupon id still counts toward the per-customer limit.

## New scenarios (added 2026-08-07)

<!-- Regression: the admin form always submits start_date/end_date keys, nulling them when the schedule is left empty (AddOrEditCoupon.vue validateDate()). PR #2525 removed `nullable` from the start_date rule so required_with could fire, but the bare `string` rule then rejects the explicit null — every unscheduled coupon create 422s with "The start_date must be a string." -->

- [x] 127. Admin can create a coupon without schedule dates even when the form submits the empty schedule as explicit nulls.

<!-- Spend-amount validation gaps: conditions.max_purchase_amount had no rules() entry at all (only a floatval sanitize), so a negative max spend saved successfully, and nothing cross-checked min against max, so min 100 / max 50 also saved. min_purchase_amount already carried nullable|numeric|min:0. -->

- [x] 128. Creating a coupon with a negative minimum spend amount is rejected with a clear error.
- [x] 129. Creating a coupon with a negative maximum spend amount is rejected with a clear error.
- [x] 130. Creating a coupon whose minimum spend amount is greater than its maximum spend amount is rejected with a clear error.
- [x] 131. Creating a coupon with a positive minimum spend amount and a zero (no-limit) maximum spend amount succeeds.

## New scenarios (added 2026-08-10)

<!-- Cases 128-131 above were added by PR #2538 (spend-amount validation); numbering here
     continues from 132. -->

<!-- Coupon-module audit finding C1: applying any coupon to an admin order containing a product
     variation returns 422 ("order_items.0.subtotal must be a number"). The admin UI computes
     per-item subtotal as unit_price*quantity - item.cost (cartService.js:22) but
     productService.js's createVariant branch sets item_cost and never cost, so variations produce
     NaN which JSON-serializes as null — and the FrontendRequests\CouponRequest rule added by
     PR #2492 (`order_items.*.subtotal => numeric`) rejects the explicit null. The fix must not
     simply add `nullable`: sanitize floatval(null) = 0 would zero the items total and silently
     defeat the min/max purchase-amount enforcement #2492 introduced, so the service falls back
     to unit_price*quantity when subtotal is not numeric. -->

- [x] 132. Admin can apply a coupon to a line item that submits a null subtotal (variation-shaped payload) and the discount is calculated from the item's real unit price and quantity.
- [x] 133. A coupon's maximum purchase amount is still enforced against the real line totals when the line items submit null subtotals.
- [x] 134. A coupon's minimum purchase amount is satisfied by the real line totals when the line items submit null subtotals.

<!-- Audit finding C6: the code-uniqueness closure in CouponRequest queried the RAW submitted
     value while sanitize() runs sanitize_text_field (which trims) afterwards. A code differing
     from an existing one only by leading whitespace passed validation, was trimmed on store, and
     hit the fct_coupons UNIQUE index uncaught — the response leaked the full INSERT/UPDATE
     statement and the wpdbconnection.php file path via plugin_exception. Leading space matters
     to MySQL VARCHAR comparison (trailing does not), and pasting codes from a spreadsheet
     produces exactly this input. Fix: the closure compares sanitize_text_field($value) — the
     value that will actually be stored — same ordering the MCP CouponTools always used. -->

- [x] 135. Creating a coupon whose code differs from an existing code only by surrounding whitespace is rejected as a duplicate, without leaking SQL.
- [x] 136. Creating a coupon with surrounding whitespace around a unique code succeeds and stores the trimmed code.
- [x] 137. Updating a coupon to a code that differs from another coupon's code only by surrounding whitespace is rejected as a duplicate, without leaking SQL.

<!-- Audit finding C7: the schedule-date rules verified string-ness (start_date, since PR #2535)
     and end-after-start ordering (end_date) but never that the strings PARSE. strtotime('garbage')
     is false, which the end_date comparison coerced to timestamp 0 — so an unparseable start_date
     beside a real end_date passed validation and crashed in DateTime::anyTimeToGmt(), returning a
     500 plugin_exception whose data.file disclosed the absolute DateTime.php server path. A
     garbage start_date with no end_date crashed the same way (the comparison never ran), and a
     garbage end_date was misreported as "end date must be after the start date" — or slipped
     through entirely beside a pre-1970 start date's negative timestamp. API-only today (the admin
     date picker clears unparseable input on blur) but reachable via import, integration, or any
     non-UI client. Fix: both closures require non-empty values to satisfy strtotime() !== false;
     the ordering comparison runs only when both sides parse. -->

- [x] 138. Creating a coupon with an unparseable start date string alongside a valid end date is rejected with a validation error, not a 500 that leaks the server file path.
- [x] 139. Creating a coupon with an unparseable start date string and no end date is rejected with a validation error, not a 500.
- [x] 140. Creating a coupon with an unparseable end date string is rejected as an invalid date, not with the misleading end-after-start message.

<!-- Audit finding C9: CouponResource::applyCoupon built its rejection envelope via
     makeErrorResponse WITHOUT the status argument, so the WP_Error carried no data.status and
     WP_REST_Server defaulted every coupon rejection on the admin apply route — stacking, expiry,
     uses limits, spend gates, email restrictions — to HTTP 500 (with a body that even claims
     code 400). Same family as issues #2471/#2472; the sibling update/delete paths already pass
     their status. Fix: pass 422 — a validation outcome — through the existing parameter. -->

- [x] 141. Admin coupon-apply rejections carry a 4xx validation status, not a 500 server error.

<!-- Audit finding C12: conditions.max_uses was nullable|numeric with no min:0, so -5 persisted
     via the REST API (the admin form input blocks negatives). The usage gates compare
     `count >= limit` behind truthiness checks, so a negative (truthy) limit permanently reports
     "max uses exceeded" the moment the coupon has a single use — a silent trap. The per-customer
     limit (conditions.max_per_customer) had the identical gap with the identical consequence on
     its leg, plus it forces the login requirement. 0 remains valid on both — it means unlimited
     and is falsy-guarded past the gates. -->

- [x] 142. Creating a coupon with a negative maximum-uses limit is rejected with a validation error.
- [x] 143. Creating a coupon with a negative per-customer usage limit is rejected with a validation error.
- [x] 144. Creating a coupon with explicit zero usage limits (meaning unlimited) still succeeds.

<!-- Audit finding C13: stackable and show_on_checkout were required|sanitizeText|maxLength:50 —
     any string persisted ("maybe", "banana"). Worse than silently meaning "no": the admin
     stacking gate checks === 'no' while the storefront checks === 'yes', so a bogus value means
     STACKABLE to one calculator and NON-STACKABLE to the other. status and type already use in:
     constraints; these two flags were overlooked. Fix: required|in:yes,no on both. -->

- [x] 145. Creating a coupon with a stackable value other than yes/no is rejected with a validation error.
- [x] 146. Creating a coupon with a show_on_checkout value other than yes/no is rejected with a validation error.
- [x] 147. Creating a coupon with both flags explicitly set to no still succeeds.

## New scenarios (added 2026-10-07)

- [x] 148. Admin can apply a second stackable coupon to a saved order while the first coupon is applied but not yet saved, and both discounts are reflected.
- [x] 149. Applying a non-stackable coupon to a saved order while another coupon is applied but not yet saved is rejected with the stacking error, not a server error.

## Optional notes (added 2026-10-26)

<!-- fct_coupons.notes is LONGTEXT NOT NULL but CouponRequest marks notes nullable, so a REST
     client that omits it sent NULL into the insert: a 500 leaking the SQL. The admin form always
     sends notes: '', which is why only direct API callers hit it. -->

- [x] 150. Creating a coupon without a notes field succeeds and stores empty notes.
- [x] 151. Creating a coupon with notes explicitly null succeeds and stores empty notes.
- [x] 152. Updating a coupon without a notes field keeps its existing notes.
