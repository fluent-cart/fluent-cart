# Saved Payment Methods — PayPal

Covers SPMP-01…04. Stripe behaviour is asserted only where PayPal work could
have regressed it.

PayPal is never contacted: every outbound call is intercepted with
`pre_http_request` (see `references/external-http-mocking.md`). The captured-order
payloads used below mirror the field paths core reads in
`PayPalGateway\Processor::maybePersistVaultToken`.

## Deciding whether to save a PayPal account (Integration)

- [ ] 1. A customer who does not consent has nothing saved.
- [ ] 2. A customer who consents has their PayPal account saved.
- [ ] 3. A subscription checkout is left to the store's own saving rules.
- [ ] 4. A checkout the store already chose to save is not overridden.
- [ ] 5. Nothing is saved when the store's PayPal account cannot save methods.

## Recording a saved PayPal account (Integration)

- [x] 6. A saved PayPal account is recorded against the customer who bought.
- [x] 7. The account is identified by the payer's email address.
- [x] 8. Card details are recorded as absent rather than blank, because a PayPal
       account has none.
- [x] 9. Buying twice with the same PayPal account lists it once.
- [x] 10. A customer who declines the save at PayPal has nothing recorded.
- [x] 11. A saved card and a saved PayPal account can be held side by side.

## Charging a saved PayPal account (Integration)

- [ ] 12. Paying twice by accident charges the customer once.
- [ ] 13. Retrying after a failed attempt is allowed.
- [ ] 14. Changing what is being bought is charged as a new payment.
- [ ] 15. A payment still clearing is reported as neither paid nor failed.
- [ ] 16. A customer cannot pay with a payment method belonging to someone else.

## Refunding (Integration)

- [ ] 17. A refund is sent to the provider that took the money.
- [ ] 18. Refunds for payments made before PayPal was supported still go to Stripe.

## Removing a saved PayPal account (Integration)

- [x] 19. Removing an account revokes it at PayPal.
- [x] 20. An account PayPal has already forgotten is still removed locally.
- [x] 21. An account is kept if PayPal could not be told to forget it.

## Telling the merchant PayPal cannot save methods (Integration)

- [x] 22. Saving is assumed to work until PayPal says otherwise.
- [x] 23. A refusal from PayPal switches saving off and is explained to the merchant.
- [x] 24. A customer declining is not treated as the store's problem.
- [x] 25. Saving switches back on once PayPal accepts a save.
- [x] 26. A store that fixes its PayPal account recovers without help.

## Managing saved methods from the customer account (Functional)

- [ ] 27. A customer sees their saved PayPal account listed with their cards.
- [ ] 28. A customer can remove their saved PayPal account.
- [ ] 29. A customer cannot yet make a PayPal account their default.
- [ ] 30. A customer cannot see or remove someone else's saved payment method.
- [ ] 31. A logged-out visitor cannot list saved payment methods.
