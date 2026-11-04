# Orders

## Listing and searching orders
- [ ] 1. Admin can list orders, with the most recent first by default.- [x] 2. Admin can paginate the orders list.
- [x] 3. Admin can request a specific page size when listing orders.
- [x] 4. Admin receives an empty list, not an error, when requesting a page beyond the available results.
- [x] 5. Admin can search orders by part of an invoice number and only matching orders are returned.
- [x] 6. Admin can search orders by part of a customer's email and only orders belonging to matching customers are returned.
- [x] 7. Admin can search orders by part of a customer's name and only orders belonging to matching customers are returned.
- [x] 8. Admin can search orders by part of an order item's title and only orders containing matching items are returned.
- [ ] 9. Admin can filter orders by status and only orders with that status are returned.- [ ] 10. Admin can filter orders by payment status and only orders with that payment status are returned.- [ ] 11. Admin can filter orders by shipping status and only orders with that shipping status are returned.- [x] 12. Admin can filter orders by date range and only orders inside the range are returned.
- [x] 13. Admin sees order search input that resembles an SQL injection attempt treated as plain text without causing a server error.

## Viewing a single order
- [x] 14. Admin can open a specific order and see the order along with its items, customer, billing and shipping addresses, and transactions.
- [x] 15. Admin sees an order's receipt link included in the single-order view.
- [x] 16. Admin receives a clear "not found" error when opening an order that does not exist.
- [x] 17. Customer cannot view another customer's order through the admin order management area.

## Viewing order transactions
- [x] 18. Admin can list the transactions belonging to an order.
- [x] 19. Admin can open a single transaction that belongs to a given order, and the response carries only that transaction.
- [x] 19a. Admin receives a "not found" error when the requested transaction belongs to a different order.
- [x] 19b. Admin receives a "not found" error when the requested transaction does not exist.

## Creating an order
- [x] 20. Admin can create a manual order with a customer and at least one item, and the order receives a unique identifier automatically.
- [x] 21. Admin is rejected with a clear validation error when creating an order without selecting a customer.
- [x] 22. Admin is rejected with a clear validation error when creating an order without any items.
- [x] 23. Admin is rejected when creating a manual order whose items include a subscription product that the payload labels as a one-time line.
- [x] 23a. Admin can create a manual order whose subscription product is labelled as a subscription line, and the order is stored as a subscription order.
- [x] 23b. Admin is rejected when a one-time product is submitted labelled as a subscription line.
- [x] 23c. Admin is rejected when a line carries conflicting payment_type labels at the top level and inside other_info.
- [x] 23d. Admin is rejected when a subscription line omits other_info.payment_type, the copy the order processor reads.
- [x] 24. Admin sees money amounts on a created order stored consistently (e.g. $12.34 round-trips correctly).
- [x] 25. Admin sees a created order's creation timestamp recorded in UTC regardless of the site's timezone.
- [x] 26. Admin sees an "order created" notification triggered when a new order is created.
- [x] 26a. Creating an order without a shipping total stores zero shipping rather than failing, since the field is optional.
- [x] 26b. Creating an order with a shipping total stores that exact amount — including an explicit zero, which is valid free shipping — and a fractional amount is rounded to the nearest cent rather than truncated down.
- [x] 26c. Creating an order with an out-of-range (e.g. 1e19) or negative shipping total is rejected with a validation error rather than stored as a wrapped or negative cents value.
- [x] 26d. Admin can create a manual order with a coupon applied, using the exact applied-coupon payload the apply-coupon endpoint returns, and the discount is recorded against the order.
- [x] 26e. Admin is rejected when creating an order whose applied-coupon entry omits the coupon id the order processor needs to link the discount.
- [x] 26f. Admin is rejected when creating an order that combines a non-stackable coupon with another coupon, and no order is created and no coupon usage is recorded.
- [x] 26g. The discount recorded for a coupon on a created order is the amount the store calculates, not an amount supplied in the request.

## Updating an order
- [x] 27. Admin can change non-status fields of an existing order, such as the customer note or shipping total.
- [x] 28. Admin is rejected when trying to update a subscription order through the standard order update flow.
- [x] 29. Admin is rejected when trying to set an order's status to "completed" through the standard order update flow.
- [x] 30. Admin sees an order's payment status automatically marked partially paid when the new total exceeds the amount already paid.
- [x] 31. Admin sees the order's overall total automatically adjusted when the shipping total changes during an update.
- [x] 31a. Admin is rejected when updating an order to combine a non-stackable coupon with another coupon, and no coupon is attached to the order.
- [x] 31b. Admin is rejected when updating an order whose submitted coupon discount differs from the discount the store calculates, and the order is left unchanged.
- [x] 31c. Admin can update an order with a coupon using the discounts the apply-coupon endpoint returned, and the coupon is attached with that discount.

## Adding a custom item to an order
- [ ] 32. Admin can add a custom line item to an existing order, and the order reflects the new item.
## Managing addresses on an order
- [x] 33. Admin can switch which saved customer address is used as an order's billing or shipping address.
- [x] 34. Admin receives a clear "not found" error when switching addresses on an order that does not exist.
- [x] 35. Admin can edit the details of an order's billing or shipping address directly.

## Changing the customer on an order
- [x] 36. Admin can attach an existing customer to an order, replacing the previous one.
- [x] 37. Admin can create a brand-new customer and attach them to an order in a single step.
- [x] 38. Admin sees the customer change recorded in the order's activity log.

## Marking an order as paid
- [x] 39. Admin can mark a pending offline-payment order as paid, and the order moves into processing.
- [x] 40. Admin is rejected from marking an order as paid when its due amount is already settled.
- [x] 41. Admin is rejected when marking a canceled order as paid.
- [x] 42. Admin sees an "order paid" notification triggered when an order is marked as paid.
- [x] 43. Admin sees a digital-only order automatically advance to "completed" after being marked as paid.
- [x] 43a. Two simultaneous admin mark-as-paid requests record exactly one succeeded charge; one request is rejected as already paid and the amount paid never exceeds the order total. (FC-SEC-03)
- [x] 43b. Order-paid listeners run only after the mark-as-paid database transaction has committed, so third-party hooks never execute while the order row is locked. (FC-SEC-03 review)

## Updating order statuses
- [x] 44. Admin can change an order's overall status or shipping status through the dedicated statuses action; the payment-status action is clearly rejected because payment transitions flow through their dedicated endpoints. (contract decision 2026-08-06: statuses endpoint is order/shipping-only — payment transitions use mark-as-paid, transaction status updates, refunds, and gateway webhooks so money state stays consistent with transactions)
- [x] 45. Admin sees an "order status updated" notification triggered when the order status changes.
- [x] 46. Admin sees an order's completion timestamp recorded automatically when the order moves to "completed".
- [x] 47. Admin sees an order's refund timestamp recorded automatically when the order is fully refunded through the refund action. (2026-08-25, FC-SEC-09: the transaction-status action no longer changes a succeeded charge)
- [x] 48. Admin can resync an order's status from its latest payment when the two are out of step.
- [x] 118. Admin trying to change the status of an already-canceled order is rejected with a client error, not a server error.

## Updating an order's transaction payment status
- [x] 49. Admin can change a specific transaction's payment status, and the order's payment status and paid total reflect the new state. (TC-053 / FC-SEC-09 resolved 2026-08-25)
- [x] 49a. Admin is rejected when trying to change a succeeded transaction to another status. (FC-SEC-09)
- [x] 50. Admin is rejected when changing a transaction's status to the same status it already has.
- [x] 51. Admin is rejected when the chosen transaction does not belong to the chosen order.

## Refunding an order
- [ ] 52. Admin can refund the full amount of a paid order; the order is marked refunded and stock is restored.- [x] 53. Admin can refund part of a paid order, and the order is marked partially refunded.
- [x] 54. Admin is rejected when refunding more than the amount paid on an order.
- [x] 55. Admin is rejected when refunding an order that is already fully refunded.
- [x] 56. Admin is rejected with a clear validation error when refunding without specifying a transaction or an amount.
- [x] 57. Admin sees an "order refund" notification triggered when a refund is processed.
- [ ] 58. Admin can choose to cancel the linked subscription as part of refunding a recurring order's payment.
## Accepting a transaction dispute
- [ ] 59. Admin can accept a dispute on an order's transaction with an explanatory note.
## Generating missing licenses
- [ ] 60. Admin can regenerate missing licenses for an order whose expected license count is greater than the issued count.- [ ] 61. Admin is rejected when asking to regenerate licenses on an order that is not missing any.
## Deleting an order
- [x] 62. Admin can delete an order, and the order's related records are removed alongside it.
- [x] 63. Admin sees subscriptions linked to a deleted subscription-type order removed alongside the order.
- [x] 64. Admin sees an "order deleted" notification triggered when a regular order is removed.
- [x] 65. Admin sees a "renewal order deleted" notification triggered when a renewal order is removed.
- [x] 66. Admin receives a clear "not found" error when deleting an order that does not exist.
- [x] 67. Admin is rejected with a clear error when deleting an order that the system considers undeletable.

## Running bulk actions on orders
- [x] 68. Admin can delete multiple orders in a single bulk action.
- [x] 69. Admin is rejected when running the retired bulk order-status action, and no order status changes.
- [x] 70. Admin is rejected when running the retired bulk payment-status action, and no order payment status changes.
- [x] 71. Admin is rejected when running the retired bulk capture-payments action, and no order changes. (resolved 2026-08-06: the capture_payments branch called an Order method that never existed and fataled on first use; branch removed — bulk payment capture, if built later, is a gateway feature with its own spec)
- [x] 72. Admin can clean up test-mode orders in paginated batches.
- [x] 73. Admin is rejected when running a bulk action without selecting any orders.
- [x] 74. Admin is rejected when running a bulk action with an unknown action name.
- [x] 75. Admin bulk-deleting a mix of deletable and undeletable orders deletes the deletable ones and is told which ones could not be deleted.
- [x] 75a. A store manager who can manage orders but cannot delete them is rejected when bulk-deleting orders, and the selected orders survive. (FC-SEC-01: POST /orders/do-bulk-action declared orders/manage while every action it accepts deletes orders; the route now declares orders/delete)
- [x] 75b. A store manager who can manage orders but cannot delete them is rejected when bulk-deleting test-mode orders, and those orders survive.
- [x] 75c. A store manager with the "delete orders" permission can still bulk-delete orders.

## Calculating shipping and tax for an order
- [x] 76. Admin can calculate a shipping charge for a chosen shipping method and a set of items.
- [x] 77. Admin can list shipping methods available for a chosen country and state, separated from methods that do not apply there.
- [x] 78. Admin can preview tax for an admin-built order from an address and a list of items without changing the order.
- [ ] 79. Admin sees the tax calculation safely capped when more than one hundred items are submitted in a single calculation. (skipped: cap fires at OrderController::calculateTax before AdminOrderTaxService; no externally observable signal when TaxModule is disabled in the core test env — re-enable when the test moves to a tax-enabled fixture — see Tests\Functional\Rest\Orders\CalculateOrderShippingAndTaxCest::adminSeesTaxCalculationSafelyCappedWhenMoreThanOneHundredItemsSubmitted)

## Working with orders as a Store manager
- [x] 80. Store manager with the "view orders" permission can list and view orders.
- [x] 81. Store manager without the "create orders" permission cannot create an order.
- [x] 82. Store manager without the "create orders" permission cannot add a custom line item to an order.
- [x] 83. Store manager with the "manage orders" permission can edit an order.
- [x] 84. Store manager with the "delete orders" permission can delete an order.
- [x] 85. Store manager with the "refund orders" permission can refund an order.
- [x] 86. Store manager with the "manage order statuses" permission can change an order's status.
- [x] 87. Store manager with the "manage orders" permission can preview shipping and tax for an order.

## Working with orders as a Worker
- [x] 88. Worker with the "view orders" permission can list and view orders.
- [x] 89. Worker with the "manage order statuses" permission can change an order's status.
- [x] 90. Worker without the "create orders" permission cannot create an order.
- [x] 91. Worker without the "manage orders" permission cannot edit an order.
- [x] 92. Worker without the "delete orders" permission cannot delete an order.
- [x] 93. Worker without the "refund orders" permission cannot refund an order.
- [x] 94. Worker without the "delete orders" permission cannot run a bulk action that deletes orders.
- [x] 95. Worker without the "manage orders" permission cannot mark an order as paid.
- [x] 96. Worker without the "manage orders" permission cannot preview shipping or tax for an order.
- [x] 97. Worker without the "manage orders" permission cannot regenerate missing licenses on an order.
- [x] 98. Worker without the "manage orders" permission cannot accept a transaction dispute.
- [x] 99. Worker without the "manage orders" permission cannot change a transaction's payment status.
- [x] 100. Worker without the "manage orders" permission cannot resync an order's status from its latest payment.

## Working with orders as an Accountant
- [x] 101. Accountant with the "view orders" permission can list and view orders.
- [x] 102. Accountant without the "create orders" permission cannot create an order.
- [x] 103. Accountant without the "manage orders" permission cannot edit an order.
- [x] 104. Accountant without the "delete orders" permission cannot delete an order.
- [x] 105. Accountant without the "refund orders" permission cannot refund an order.
- [x] 106. Accountant without the "manage order statuses" permission cannot change an order's status.
- [x] 107. Accountant without the "manage orders" permission cannot preview shipping or tax for an order.
- [x] 108. Accountant without the "manage orders" permission cannot list shipping methods applicable to a country and state.
- [x] 109. Accountant without the "delete orders" permission cannot run a bulk action that deletes orders.
- [x] 110. Accountant without the "manage orders" permission cannot mark an order as paid.
- [x] 111. Accountant without the "manage orders" permission cannot regenerate missing licenses on an order.
- [x] 112. Accountant without the "manage orders" permission cannot accept a transaction dispute.
- [x] 113. Accountant without the "manage orders" permission cannot change a transaction's payment status.
- [x] 114. Accountant without the "manage orders" permission cannot resync an order's status from its latest payment.

## Visiting orders without signing in
- [x] 115. A logged-out visitor cannot access any order management action.

## Submitting order changes securely
- [x] 116. An admin without a valid security token is rejected from any order-changing action.
- [x] 117. An admin with an expired security token is rejected with a clear error.
