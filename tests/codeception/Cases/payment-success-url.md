# Payment success URL

Canonical post-payment redirect URL: every gateway routes through
`fluent_cart/payment/success_url` with rich context.

## Building the success URL from a transaction (Integration)
- [x] 1. The success URL built from a transaction model matches the one built from its uuid string.
- [x] 2. The success URL built from a transaction contains the payment method, transaction hash, and redirect flag.
- [x] 3. The success URL filter receives the transaction and its order in context.
- [x] 4. The success URL filter context carries no transaction or order when the uuid is unknown.
- [x] 5. Extra query arguments are merged into the success URL built from a transaction.

## Stripe hosted checkout return URL (Integration)

The URL handed to the gateway is a machine contract that triggers the
hosted-return confirmation, so it is internal and deliberately unfiltered.
The `fluent_cart/payment/success_url` filter applies only AFTER confirmation,
as the post-confirm redirect — a filter that sends buyers to another page can
never break payment confirmation.

- [x] 6. The return URL sent to Stripe hosted checkout ignores the success url filter and dispatches the hosted-return action with exactly one transaction hash.
- [x] 7. After a confirmed hosted return, the buyer is redirected to the filtered success URL.
