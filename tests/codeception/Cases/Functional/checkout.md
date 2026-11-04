# Checkout

## Placing an order
- [x] 1. A guest can place an order from a valid cart and the response and stored order preserve the exact cent totals.
- [x] 2. A logged-in customer can place an order and the order is attached to that customer and WordPress user identity.
- [x] 3. Placing an order without the required customer name is rejected and creates no order.
- [x] 4. Placing an order with an invalid billing email is rejected and creates no order.
- [x] 5. Placing an order for a stock-managed product decrements available stock by the ordered quantity exactly once.
- [x] 6. Submitting the same completed cart twice does not create a duplicate order.
- [x] 7. A coupon applied to the cart reduces the exact stored order total and is recorded on the order.
- [x] 8. A guest cannot copy another customer's order billing address into their own order by posting that order's ids.
- [x] 9. A guest cannot copy another customer's order shipping address into their own order by posting that order's ids.
- [x] 10. A logged-in customer cannot copy another customer's order billing address into their own order by posting that order's ids.
- [x] 11. Paying a due order through its locked cart still reuses that order's own billing address.
- [x] 12. A checkout field holding only a smartcode is treated as empty and the order is rejected on the required field.
- [x] 13. Smartcode syntax typed into checkout fields is stripped before the order's address is stored.
- [x] 14. A guest may post place-order at most 5 times in a minute from one IP; the 6th post gets 429 before the cart is read, and no order is created. The REST route counts each post once _(SCAN-08, PlaceOrderRateLimitCest)_
