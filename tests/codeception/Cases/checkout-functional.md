# Checkout (Functional) — Campaign 1

Spec for `tests/Functional/Frontend/Checkout/`. Surface: the public `checkout`
routes (`place-order`, `get-order-info`, `get-checkout-summary-view`,
`get-available-shipping-methods`, `get-shipping-methods-list-view`).

The legacy `Cases/Functional/checkout.md` (items 1-7, `PlaceOrderCest.php`)
pins the ingress: one guest order, one logged-in order, two rejections, stock,
double-submit, coupon. This spec goes deeper on each of those paths. Numbering
is global within this file. Every checkout submission below goes through the
in-process stub gateway (`Tests\Support\Doubles\CheckoutGateway`), so no
gateway I/O ever happens.

## Placing an order — happy path (Functional) — TC-101 `PlaceOrderHappyPathCest`
- [x] 1. A guest who submits a valid cart gets a success response whose redirect identifies the payment transaction that was recorded for the new order.
- [x] 2. The stored order keeps the exact cent totals of the cart, starts on hold with payment pending, and carries the store currency, order mode, and chosen payment method.
- [x] 3. The stored order receives a short unique order handle automatically and no receipt or invoice number while payment is still pending.
- [x] 4. Every cart line becomes one stored order item with the same product, variation, quantity, unit price, and line totals in cents.
- [x] 5. Placing an order records exactly one pending charge transaction for the full order total in the order currency.
- [x] 6. The submitted billing details are stored as the order's billing address and as the new customer's profile.
- [x] 7. Order, item, and transaction timestamps are recorded in UTC regardless of the site's timezone setting.
- [x] 8. Placing an order links the cart to the created order and its customer and moves the cart out of the draft stage.

## Placing an order — validation (Functional) — TC-102 `PlaceOrderValidationCest`
Legacy items 3 and 4 already pin the missing-name and malformed-email rejections.
- [x] 9. Placing an order without a billing email is rejected, names the email field, and creates no order.
- [x] 10. Placing an order with every required billing address field left blank reports each missing field that can be checked without a country (country, street, city, postcode) at once and creates no order.
- [x] 11. Placing an order with a country code the store does not know is rejected as an invalid country.
- [x] 12. Placing an order with a state that does not belong to the chosen country is rejected as an invalid state.
- [x] 13. Placing an order with a payment method the store does not offer is rejected and creates no order.
- [x] 14. Placing an order for a cart that does not exist is rejected and creates no order.
- [x] 15. Placing an order whose cart line points at a variation that no longer exists is rejected before any customer or order is created.
- [x] 16. Placing an order for a product that was trashed after it was added to the cart is rejected as unavailable.
- [x] 17. Placing an order for more units than a stock-managed variation has available is rejected and tells the shopper how many are left.
- [x] 18. Markup submitted inside the billing name is stripped before the order and customer are stored.
- [x] 26. Placing an order for a country that has states, with the state left blank, is rejected as a missing state and creates no order.

## Placing an order — coupons (Functional) — TC-103 `CheckoutCouponCest`
Ingress note (verified 2026-09-02): coupons reach a cart only through the web
surface — the instant-checkout web route reads a `coupons` query parameter and
calls `Cart::applyCoupon()`; the REST `apply-coupon` / `cancel-coupon` /
`re-apply-coupon` routes are commented out. That application ingress is
therefore AJAX/web-only and belongs behind TC-200's cart harness. This section
covers the REST half the kanban names — what `place-order` persists from a
cart that already carries coupons — with the coupon applied on the cart model
exactly as the web route does. Legacy item 7 pins the single fixed coupon.
- [x] 19. A percentage coupon is rounded to whole cents once, and the same cents appear on the order total, the order item, and the recorded coupon usage.
- [x] 20. A fixed coupon spread over several lines is split in proportion to each line and the line shares add up to the coupon amount exactly.
- [x] 21. Two stackable coupons applied to one cart one after the other are both kept on the cart, both recorded on the order, each counted as used once, and their discounts add up on the order total.
- [x] 22. When a non-stackable coupon is already stored on the cart, a later attempt to add a second coupon is refused, leaves the stored cart and its discount untouched, and checkout records only the first.
- [x] 23. A coupon that expires between carting and checkout is dropped at checkout: the order is charged full price and the coupon is not recorded or counted.
- [x] 24. A coupon that is deactivated between carting and checkout is dropped at checkout the same way.
- [x] 25. A coupon whose usage limit is reached between carting and checkout is dropped at checkout the same way.

## Placing an order — stock (Functional) — TC-104 `CheckoutStockCest`
Legacy `Cases/Functional/checkout.md` item 5 pins the bare decrement; `Cases/Functional/stock.md`
items 10-11 name the guest checkout reservation but were parked on the AJAX ingress. This
section covers the same reservation through the REST `place-order` route, its failure and
retry paths, and the release on cancellation. Stock management is switched on per test
through the actor (the plugin boots with it off in this suite); item 35 runs with it off.
- [x] 27. Placing an order for a stock-managed variation moves exactly the ordered units from available onto hold, leaves committed and total stock unchanged, and records the reservation on the order so it can be undone later.
- [x] 28. Placing an order that takes the last available unit marks both the variation and its product as out of stock.
- [x] 29. In a cart that mixes a stock-managed variation with one that is not stock-managed, only the stock-managed variation is reserved.
- [x] 30. Placing an order for a stock-managed variation that has already run out is refused as out of stock and creates no order.
- [x] 31. When the payment step fails after the order is created, the order and its reservation are kept for a retry: the units stay on hold, the cart stays linked, and only the payment transaction is marked failed.
- [x] 32. Resubmitting the same cart after a failed payment reuses the pending order and does not reserve the units a second time.
- [x] 33. Resubmitting the same cart after a failed payment is not refused as out of stock when the shelf now holds fewer units than the cart asks for, because the order's own reservation is counted.
- [x] 34. Cancelling the pending order returns the held units to available and the product is in stock again.
- [x] 35. With stock management switched off, placing an order for a variation flagged for stock management changes no stock counter and is never refused for stock.
- [x] 63. A second order for the same variation adds its units to the units already on hold instead of replacing them, and cancelling one order releases only that order's units.
- [x] 73. Retrying after a failed payment with a larger quantity reserves the extra units, so the order holds exactly what it now asks for.
- [x] 74. Retrying after a failed payment with more units than the shelf plus the order's own reservation is refused as out of stock and reserves nothing more.
- [x] 75. Retrying after a failed payment with a smaller quantity returns the units no longer asked for to the shelf.
- [x] 76. Retrying after a failed payment with a different variation on the reused order line releases the first variation's units and reserves the new variation's units.
- [x] 77. Cancelling an order whose quantity was raised on a retry returns exactly the units it holds, so the shelf ends where it started.
- [x] 78. Refunding with restock an order whose quantity was raised on a retry returns exactly the units it holds, so the shelf ends where it started.
- [x] 79. Retrying after a failed payment that held no stock with a stock-managed variation reserves that variation's units.
- [x] 80. After a failed retry that switched to a variation without stock management, a later retry back to a stock-managed variation reserves its units again.

## Shipping, tax, and the checkout summary (Functional) — TC-105 `CheckoutShippingTaxTotalsCest`
Legacy `Cases/Functional/shipping.md` items 92-101 pin which methods the public lookup
returns for a country. This section covers how a chosen method turns into money: the
checkout summary totals, the rate types, shipping classes, and what `place-order` stores
for shipping and tax. Tax is switched on per test through the actor (the plugin boots
this suite with tax off, the same boot-time gate as stock); rates live on the standard
tax class. The cart carries the shopper's address and chosen method in its checkout
data exactly as the storefront patches them before submission.
- [x] 36. A shopper asking for a country's shipping methods gets the enabled methods of the zones covering that country, most expensive first, each with its id, title, type, and amount.
- [x] 37. The checkout summary for a physical cart reports the item subtotal and, with a chosen shipping method, adds that method's charge to the total in cents and echoes the chosen method.
- [x] 38. The checkout summary for an all-digital cart charges nothing for shipping even when a shipping method is passed.
- [x] 39. A per-order flat rate is charged once whatever the quantity, while a per-item rate is multiplied by the number of physical units in the cart.
- [x] 40. A shipping class with a fixed cost on a physical product adds that cost on top of the method's rate.
- [x] 41. Placing an order with a chosen shipping method stores the shipping charge, the method's id and title, marks the order physical and unshipped, and includes the charge in the order total.
- [x] 42. With tax on and prices entered without tax, placing a physical order stores the item tax and the shipping tax separately and adds both to the order total.
- [x] 43. With tax on and prices entered including tax, the item tax and shipping tax are carved out of the prices and the order total is not increased.
- [x] 44. When a rate carries its own shipping rate, the shipping tax uses that rate while items keep the base rate.
- [x] 45. When the shopper's country has no tax rate, placing the same physical order stores zero item tax and zero shipping tax.
- [x] 46. The shipping charge on the order comes from the method the address validation accepted; a request that names an available method in one field and a different, unavailable method in the other cannot be priced by the second.

## Guest and signed-in checkout — customer linkage (Functional) — TC-106 `GuestVsCustomerCheckoutCest`
Legacy `Cases/Functional/checkout.md` items 1-2 pin that a guest order gets a customer with
no user link and that a signed-in order keeps the signed-in identity. Item 6 above pins
first-time customer creation. This section covers how checkout reuses, links, and completes
customer records in every other combination, and what it saves to the customer profile.
- [x] 47. A guest whose billing email already belongs to an unlinked customer gets the order attached to that customer, and no second customer is created.
- [x] 48. A guest using a registered account email gets an unlinked customer record until ownership is proved.
- [x] 49. A signed-in user with no customer record gets one created with the account email and linked to the user id, and a different billing email in the form is ignored.
- [x] 50. A signed-in user whose account email matches an unlinked customer record does not take it over at checkout; the order uses the existing row without granting access.
- [x] 51. A first-time customer gets one billing and one shipping address saved to the profile; a returning customer with saved addresses gets no duplicates.
- [x] 52. Checkout writes the submitted first and last name onto the customer record, and for a signed-in user onto the WordPress profile as well.
- [x] 53. With account creation left at the store default, a guest order is flagged to create an account after payment.
- [x] 54. With account creation set to the customer's choice, a guest order is flagged only when the guest asks for an account.
- [x] 55. When the shopper ships to a different address, the order stores that shipping address separately from billing; otherwise the shipping address mirrors the billing one.
- [x] 65. A returning guest keeps the saved customer name; the new billing name belongs to the order.

## Placing an order — resubmission and idempotency (Functional) — TC-107 `PlaceOrderIdempotencyCest`
Legacy `Cases/Functional/checkout.md` item 6 pins that a completed cart cannot be submitted
twice. Items 32-33 above pin that a retry after a failed payment reuses the pending order.
This section covers what a resubmission does to the charge transaction, to a changed cart,
to carts tied to an already-finished order, and to a submission that arrives while another
submission of the same cart is still running.
- [x] 56. Resubmitting a cart after a failed payment retries the same charge in place: the transaction keeps its identifier, goes back to pending with its attempt counter raised, and no second transaction is recorded.
- [x] 57. Resubmitting a cart whose charge is still pending reuses that transaction unchanged, with the attempt counter not raised, so the gateway sees the same attempt rather than a new charge.
- [x] 58. Resubmitting after the shopper changed the quantity updates the same pending order's line, totals, and charge amount instead of creating a second order.
- [x] 59. A pay-for-order cart locked to an order that is already paid is refused as already completed and creates no order or transaction.
- [x] 60. A cart still linked to a paid order but never marked completed is detached and gets a fresh order, leaving the paid order untouched.
- [x] 61. A submission that arrives while another submission of the same cart is still being processed is refused as already being processed and creates nothing; once that other submission has finished, the cart can be submitted.
- [x] 62. A submission takes the cart's lock before it re-reads the cart and looks for an earlier order, so two simultaneous first submissions can never both see "no order yet".
- [x] 64. Every further retry after another failed payment raises the attempt counter again on the same transaction, and a later duplicate of a pending charge leaves the counter where it is.

## Shipping tax after the gateway's order-info call (Functional) — ticket #2848 `ShippingTaxAfterOrderInfoCest`
A signed-in customer whose saved billing address fills the checkout has no shipping
country of their own (shipping = billing). The page load saves the first shipping method
and its per-item share; card gateways then ask for the shipping charge on load to size
their payment amount. This section pins that the lookup reads the address the order ships
to and never changes the cart, so placement taxes the shipping it charges.
- [x] 66. A signed-in customer whose saved billing address fills the checkout, with two methods for that address, keeps the method the page selected through the gateway's order-info call, the payment amount includes shipping, and the placed order carries that method's shipping tax.
- [x] 67. The same signed-in checkout with a single method for the address places an order with that method's shipping tax.
- [x] 68. When shipping is not taxable, the same signed-in checkout charges the shipping and adds no shipping tax.
- [x] 69. A guest who types the address keeps the chosen method through the order-info call and gets the shipping tax on the order.
- [x] 70. With ship-to-different on, the payment amount's shipping comes from the shipping address: a complete one is charged its method, and an empty one is not filled in from the billing address.
- [x] 71. With ship-to-different off, shipping fields left over from an earlier toggle do not decide the payment amount's shipping; the billing address does, the same address placement validates.
- [x] 72. Loading the payment method never changes the cart, even when the saved method does not serve the address; it only leaves shipping out of the payment amount.

## Retrying after a failed payment — the address on the form (Functional) — ticket #5548 `PlaceOrderRetryAddressCest`
A retry submits the same form as the first attempt, so it must be checked and saved with the
address the shopper submitted. A saved address is used only when the shopper picked one.
- [x] 81. A signed-in customer whose saved billing address has a country but no state can retry after a failed payment; the retry is accepted and the order carries the address they typed.
- [x] 82. A signed-in customer who typed an address keeps it on the order after a failed payment and a retry, even with a complete saved address in another country on their profile.
- [x] 83. A signed-in customer who picked a saved address keeps that saved address on the order after a failed payment and a retry.
- [x] 84. A guest using a saved customer's email keeps the address they typed after a failed payment and a retry; the saved customer's street and phone never reach the order.
