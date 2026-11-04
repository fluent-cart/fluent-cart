# Cart

## Public cart ingress
- [x] 1. A visitor can add a published product variation to the cart through the public cart ingress.
- [x] 2. Adding a variation preserves the server-owned unit price in cents instead of trusting a caller-supplied price.
- [x] 3. A visitor can replace an existing cart item's quantity through the public cart ingress.
- [x] 4. Updating an item to zero removes it from the cart.
- [x] 5. Adding an unknown variation is rejected without changing the cart.
- [x] 6. Adding more units than a stock-managed variation has available is rejected without changing the cart.
- [x] 7. The public cart status ingress returns the current device cart without mutating it.
- [x] 8. Cart ingress requests without a valid security token are rejected without mutating a cart.
