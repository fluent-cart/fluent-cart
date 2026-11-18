# PayPal webhooks

## Signature verification gates the extension hook (Integration)
- [x] 1. A forged webhook whose PayPal signature fails verification is answered with 400 and never reaches the paypal_webhook_received extension hook.
- [x] 2. A webhook that arrives without the PayPal signature headers is answered with 400, is never sent to PayPal for verification, and never reaches the extension hook.
- [x] 3. A webhook whose PayPal signature verifies is answered with 200 and reaches the paypal_webhook_received extension hook exactly once with the event.
- [x] 4. When a site explicitly disables PayPal webhook verification, the extension hook still fires without contacting PayPal.
