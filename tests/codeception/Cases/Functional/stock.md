# Stock

Stock is an event-driven module: it has no REST endpoints of its own. Stock side effects ride on top of frontend shop browsing, cart updates, checkout submission, and admin order lifecycle actions (create, update, pay, change shipping status, cancel, refund, delete). The cases below describe the user-observable stock outcomes of each of those actions.

<!-- skipped: the "Enable Advanced Inventory" setting is a Pro-only feature flag whose user-visible behavior is implemented in the Pro plugin; cases for that surface belong to the Pro test suite, not core. -->

## Browsing the shop
- [x] 1. A logged-out visitor browsing the shop sees only in-stock products by default.
- [x] 2. A logged-out visitor can opt in to see out-of-stock products, and they are then included in the listing.
- [x] 3. A product whose every variant is out of stock is shown as out-of-stock in the listing.
- [x] 4. A product with at least one in-stock variant is shown as in-stock in the listing.

## Adding an item to the cart
- [ ] 5. Customer can add an in-stock product to the cart in any quantity up to the available stock. (skipped: cart-add is the WP AJAX handler `wp_ajax_fluent_cart_checkout_routes` (action=`fluent_cart_cart_update`) → `handleCartUpdateAjax()` in app/Hooks/Cart/WebCheckoutHandler.php:96-97, not a REST endpoint — the stock-availability gate is only reachable from that AJAX path; re-enable when the actor gains an AJAX dispatch helper)
- [ ] 6. Customer trying to add more units of a stock-managed product than are available is rejected with a clear out-of-stock message. (skipped: cart-add is the WP AJAX handler `wp_ajax_fluent_cart_checkout_routes` (action=`fluent_cart_cart_update`) → `handleCartUpdateAjax()` in app/Hooks/Cart/WebCheckoutHandler.php:96-97, not a REST endpoint — re-enable when the actor gains an AJAX dispatch helper)
- [ ] 7. Customer trying to add a stock-managed product that has run out is rejected with a clear out-of-stock message. (skipped: cart-add is the WP AJAX handler `wp_ajax_fluent_cart_checkout_routes` (action=`fluent_cart_cart_update`) → `handleCartUpdateAjax()` in app/Hooks/Cart/WebCheckoutHandler.php:96-97, not a REST endpoint — re-enable when the actor gains an AJAX dispatch helper)
- [ ] 8. Customer can add a product that is not stock-managed in any quantity without being rejected for stock reasons. (skipped: cart-add is the WP AJAX handler `wp_ajax_fluent_cart_checkout_routes` (action=`fluent_cart_cart_update`) → `handleCartUpdateAjax()` in app/Hooks/Cart/WebCheckoutHandler.php:96-97, not a REST endpoint — re-enable when the actor gains an AJAX dispatch helper)
- [ ] 9. Increasing the quantity of an item already in the cart beyond the available stock is rejected with a clear out-of-stock message. (skipped: cart-add/update is the WP AJAX handler `wp_ajax_fluent_cart_checkout_routes` (action=`fluent_cart_cart_update`) → `handleCartUpdateAjax()` in app/Hooks/Cart/WebCheckoutHandler.php:96-97, not a REST endpoint — re-enable when the actor gains an AJAX dispatch helper)

## Submitting checkout
- [ ] 10. A guest visitor submitting checkout for an in-stock cart can complete the purchase, and the purchased quantities are reserved against the placed order. (skipped: checkout submission is the WP AJAX handler `wp_ajax_fluent_cart_place_order` → `handlePlaceOrderAjax()` in app/Hooks/Cart/WebCheckoutHandler.php:37-38, not a REST endpoint — re-enable when the actor gains an AJAX dispatch helper)
- [ ] 11. A guest visitor submitting checkout for a cart whose stock has run out in the meantime is rejected with a clear out-of-stock message. (skipped: checkout submission is the WP AJAX handler `wp_ajax_fluent_cart_place_order` → `handlePlaceOrderAjax()` in app/Hooks/Cart/WebCheckoutHandler.php:37-38, not a REST endpoint — re-enable when the actor gains an AJAX dispatch helper)

## Admin creating an order
- [x] 12. Admin creating an order for an in-stock product reduces that product's available stock by the ordered quantity.
- [x] 13. Admin creating an order for an in-stock product holds the ordered quantity as reserved stock against that order.
- [x] 14. Admin creating an order for a stock-managed product whose stock has run out is rejected with a clear out-of-stock message.
- [x] 15. Admin creating an order whose quantity exceeds the available stock is rejected with a message naming the quantity that is still available.
- [x] 16. After an order consumes the last of a product's stock, the product is shown as out-of-stock to shoppers.
- [x] 17. Creating an order that contains a product which is not stock-managed leaves stock counts unchanged.
- [x] 18. Creating an order records the resulting stock change on the order so later cancellations, refunds, or deletions can roll it back.

## Admin updating an order's items
- [x] 19. Admin increasing the quantity of an item on an existing pending order reduces the available stock by the added units and grows the reserved stock against the order.
- [x] 20. Admin decreasing the quantity of an item on an existing pending order returns the removed units to the available stock and shrinks the reserved stock against the order.
- [x] 21. Admin increasing the quantity of an item on a delivered physical order reduces the available stock by the added units and grows the committed stock against the order.
- [x] 22. Admin decreasing the quantity of an item on a delivered physical order returns the removed units to the available stock and shrinks the committed stock against the order.
- [x] 23. Admin increasing the quantity of an item on a paid digital order reduces the available stock by the added units and grows the committed stock against the order.
- [x] 24. Admin decreasing the quantity of an item on a paid digital order returns the removed units to the available stock and shrinks the committed stock against the order.
- [x] 25. Admin updating an order without changing any quantity leaves stock counts unchanged.

## Admin paying an order
- [x] 26. Marking a digital order as paid moves the order's reserved stock into committed stock.
- [x] 27. Marking a physical order as paid does not move reserved stock into committed stock; that only happens on delivery.

## Admin changing the shipping status
- [x] 28. Marking a physical order as delivered moves the order's reserved stock into committed stock.
- [x] 29. Reverting a physical order's shipping status away from delivered moves the order's committed stock back to reserved.
- [x] 30. Shipping status changes on a digital order do not move stock between reserved and committed.

## Admin cancelling an order
- [x] 31. Admin cancelling an order whose stock is still reserved returns the reserved quantities to the available stock.
- [x] 32. Admin cancelling a delivered order returns the committed quantities to the available stock.
- [x] 33. A product whose stock returns above zero after a cancellation is shown as in-stock again to shoppers.

## Admin refunding an order
- [x] 34. Admin refunding the full quantity of an item returns that quantity to the available stock and removes the line from the order.
- [x] 35. Admin refunding part of an item returns the refunded units to the available stock and reduces the order line by the same number of units.
- [x] 36. Admin refunding an order without opting in to stock restoration leaves stock counts unchanged.
- [x] 37. A product whose stock returns above zero after a refund is shown as in-stock again to shoppers.
- [x] 53. Admin refunding a canceled order with stock restoration does not return stock a second time, because cancelling already returned it.
- [x] 54. Admin cannot cancel an order while one of its refunds is waiting for re-sync.
- [x] 55. Once that refund is no longer waiting for re-sync, the admin can cancel the order.
- [x] 56. A refund waiting for re-sync whose units are still on the order does not block cancelling.

## Admin deleting an order
- [x] 38. Deleting an order whose stock is still reserved returns the reserved quantities to the available stock.
- [x] 39. Deleting an order whose stock has already been committed returns the committed quantities to the available stock.
- [x] 40. When a site has turned off the restore-stock-on-delete behaviour, deleting an order leaves stock counts unchanged.

## Bundle products
- [ ] 41. Adding a bundle product to the cart is rejected when any of its stock-managed child products has run out. (skipped: cart-add is the WP AJAX handler `wp_ajax_fluent_cart_checkout_routes` (action=`fluent_cart_cart_update`) → `handleCartUpdateAjax()` in app/Hooks/Cart/WebCheckoutHandler.php:96-97, not a REST endpoint — the bundle availability gate `fluent_cart/variation/can_purchase_bundle` at app/Modules/StockManagement/StockManagement.php:56-78 is only reachable from that AJAX path; re-enable when the actor gains an AJAX dispatch helper)
- [x] 42. Creating an order containing a bundle reduces the available stock of each stock-managed child product by the ordered bundle quantity.
- [x] 43. Refunding a bundle product returns stock to each of its stock-managed child products.

## Items that are not stock-managed
- [x] 44. Adding a subscription signup fee to an order never changes any stock counts.
- [x] 45. Adding a standalone fee item to an order never changes any stock counts.
- [x] 46. Any order action involving a product whose stock management is turned off leaves stock counts unchanged.

## When stock management is disabled
- [ ] 47. With stock management disabled, a customer can add any quantity of any product to the cart without being rejected for stock reasons. (skipped: cart-add is the WP AJAX handler `wp_ajax_fluent_cart_checkout_routes` (action=`fluent_cart_cart_update`) → `handleCartUpdateAjax()` in app/Hooks/Cart/WebCheckoutHandler.php:96-97, not a REST endpoint — re-enable when the actor gains an AJAX dispatch helper)
- [x] 48. With stock management disabled, creating, paying, cancelling, refunding, or deleting an order makes no changes to stock counts.
- [x] 49. With stock management disabled, the shop listing shows every product regardless of stock status.

## Permissions
<!-- Stock has no admin endpoints of its own; per-role allow/deny lives on each calling endpoint and is covered in orders.md and products.md. The cases below cover only the cross-cutting "a denied caller cannot trigger the side effect" assertions. -->
- [x] 50. A worker without the "create orders" permission cannot trigger the stock decrement that would follow from creating an order.
- [x] 51. A logged-out visitor cannot trigger any admin-side stock transition because they cannot access the admin order endpoints.

## Authentication and CSRF
<!-- Stock has no admin endpoints of its own; full authentication and CSRF cases live on each calling endpoint. The single case below confirms the cross-cutting assertion. -->
- [ ] 52. A logged-in admin without a valid security token cannot trigger a stock change through any order-changing action. (CODEX-REVIEW-NEEDED: false-negative — codex repeatedly enumerated new order-changing surfaces beyond the 6+bulk probed; last unresolved finding cites PUT /orders/{order}/sync-statuses via app/Helpers/StatusHelper.php:210 and :221 — see Tests\Functional\Permissions\StockSecurityCest::adminWithoutValidSecurityTokenCannotTriggerStockChange)
