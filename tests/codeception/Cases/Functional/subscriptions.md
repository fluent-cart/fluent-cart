# Subscriptions

<!-- Bucket A note: subscriptions have no admin create / update / delete REST routes. Subscriptions are created during checkout (covered by checkout.md) and their lifecycle changes flow through domain events. This file covers what the REST surface actually exposes: listing, viewing, cancelling, re-syncing, generating early-payment links, the pause/resume/reactivate lifecycle actions (implemented by the renewal engine), and the customer-portal surface. -->

<!-- Extraction note: app/Http/Requests/SubscriptionRequest.php has empty rules() and sanitize() so the validator-coverage matrix is sparse. The authoritative enums for this domain live in app/Helpers/Status.php (subscription status) and app/Modules/Subscriptions/Services/Filter/SubscriptionFilter.php (billing interval, status view). Sanitization happens inline in the controller (cancel_reason via sanitize_text_field). Bucket G cases are driven by those sources. -->

## Listing and searching
- [ ] 1. Admin can list subscriptions, with the most recent subscription first by default.- [x] 2. Admin can paginate subscription results and request a specific page size.
- [x] 3. Admin can filter subscriptions by status — active, trialing, pending, intended, paused, canceled, failing, expiring, or expired — and only subscriptions in that status are returned.
- [x] 4. Admin can filter subscriptions by billing interval — yearly, half-yearly, quarterly, monthly, weekly, or daily — and only matching subscriptions are returned.
- [x] 5. Admin can filter subscriptions by current payment method and only subscriptions on that payment method are returned.
- [x] 6. Admin can search subscriptions by part of a customer's email and only subscriptions belonging to matching customers are returned.
- [x] 7. Admin can search subscriptions by the gateway subscription identifier and only matching subscriptions are returned.
- [x] 8. Searching subscriptions for "cancelled" (British spelling) returns the same results as searching for "canceled".
- [x] 9. Searching subscriptions by a value prefixed with the hash mark matches by subscription identifier.
- [x] 10. Asking for a page beyond the available subscription results returns an empty list, not an error.
- [x] 11. Search input that looks like an SQL injection attempt is treated as plain text and never causes a server error.

## Viewing a single subscription
- [x] 12. Admin can open a subscription by its identifier and see the subscription along with its customer, billing and shipping addresses, transactions, related orders, and activity log.
- [x] 13. Opening a subscription that does not exist returns a clear "not found" error.
- [x] 14. Viewing an installment subscription reveals how many billing cycles remain.
- [x] 15. The single-subscription view exposes whether the subscription can currently be upgraded to another plan.
- [ ] 16. The single-subscription view exposes whether the customer is allowed to switch or update their payment method.- [x] 17. The single-subscription view exposes the reminder permissions that admins are allowed to manage for this subscription.

## Cancelling a subscription
- [x] 18. Admin can cancel an active subscription by supplying a cancel reason, and the subscription is marked canceled. (Resolved upstream by a6de18654 "record cancel reason and stop losing config writes"; skip removed, test green — TC-034..036 sweep 2026-08-18)
- [x] 19. Cancelling a subscription without supplying a cancel reason is rejected with a clear error.
- [x] 20. The cancel reason supplied by the admin is sanitized as plain text before being stored, so script-like input is stripped. (Resolved upstream by a6de18654; skip removed, test green — TC-034..036 sweep 2026-08-18) so sanitized cancel_reason never persists — Production cite: app/Models/Subscription.php:140, app/Models/Subscription.php:561-563, app/Modules/Subscriptions/Http/Controllers/SubscriptionController.php:87-95 — see Tests\Functional\Rest\Subscriptions\CancelSubscriptionCest::cancelReasonSuppliedByAdminIsSanitizedAsPlainTextBeforeStored; ticket: https://github.com/WPManageNinja/fluent-cart/issues/2451)
- [x] 21. Cancelling a subscription triggers a "subscription canceled" notification that internal listeners can react to. (Resolved upstream by a6de18654; test green — TC-034..036 sweep 2026-08-18)
- [x] 22. When the payment gateway rejects the cancellation, the admin sees a clear error message that names the gateway's response.

## Re-syncing a subscription from the payment gateway
- [x] 23. Admin can re-sync a subscription's state from its payment gateway, and the returned subscription reflects the latest remote values.
- [x] 24. Re-syncing a subscription whose gateway returns an error is rejected with a clear error message.

## Generating an early-payment link
- [x] 25. Admin can generate an early-installment payment link for an active subscription that still has remaining installments.
- [x] 26. Generating an early-payment link when the early-payment feature is disabled site-wide is rejected with a clear error.
- [x] 27. Generating an early-payment link for a subscription that does not belong to the supplied order is rejected with a clear error.
- [x] 28. Generating an early-payment link for an open-ended subscription (no fixed installment count) is rejected with a clear error.
- [x] 29. Generating an early-payment link for a canceled or expired subscription is rejected with a clear error.
- [x] 30. Generating an early-payment link for a subscription whose installments are already fully paid is rejected with a clear error.

## Pause / resume / reactivate (contract superseded 2026-07-22 — the renewal engine implemented these actions; "not available yet" stubs are gone; Cest renamed NotYetAvailableActionsCest → AdminPauseResumeReactivateCest)
- [x] 31. Reactivating a gateway-managed subscription is rejected as store-billed only.
- [x] 31a. Admin can pause a store-billed subscription and it is recorded as paused.
- [x] 31c. Pausing a system (auto-charge) subscription unschedules its queued charge and clears the charge state; the open renewal order is voided.
- [x] 32. Pausing a gateway-managed subscription whose gateway cannot pause is rejected.
- [x] 33. Resuming a gateway-managed subscription whose gateway cannot resume is rejected.
- [x] 33a. Admin can resume a paused store-billed subscription and it is recorded as active.

## Listing my subscriptions (customer portal)
- [ ] 34. Customer can list their own subscriptions, with the most recent subscription first by default.- [x] 35. Customer cannot see another customer's subscriptions in their list.
- [x] 36. An unpaid first-purchase subscription in pending or intended status is hidden from the customer's list.
- [x] 36a. A billed subscription stays on the customer's list through repeated failed reactivation attempts; an unbilled first purchase awaiting activation stays hidden.
- [x] 37. Customer can paginate their subscription list and request a specific page size.
- [ ] 38. A logged-out visitor asking for their subscriptions receives an empty list rather than an error, so the customer portal renders without crashing.
## Viewing a single subscription (customer portal)
- [x] 39. Customer can open one of their own subscriptions by its public identifier and see the subscription with its billing info, transactions, and payment-method options.
- [x] 40. Customer cannot open another customer's subscription by guessing its identifier.
- [x] 41. Opening an unpaid first-purchase subscription in pending or intended status returns a "not found" error.
- [x] 41a. Customer can reopen an established installment subscription after a failed reactivation.
- [x] 42. The single-subscription view exposes whether the customer is currently allowed to make an early payment.
- [x] 43. The single-subscription view exposes the number of installments that remain for an installment subscription.

## Cancelling auto-renew (customer portal)
- [x] 44. Customer can cancel auto-renew on their own active subscription, and the subscription is marked canceled at the payment gateway.
- [x] 45. Customer cannot cancel auto-renew on another customer's subscription.
- [x] 46. When the payment gateway rejects auto-renew cancellation, the customer receives a generic friendly message rather than the raw gateway error.

## Switching or updating payment method (customer portal)
- [ ] 47. Customer can request a payment-method update on their own subscription when the gateway supports it.- [ ] 48. A payment-method update request without naming a payment method is rejected with a clear error.- [ ] 49. Customer can ask the gateway to prepare a plan for a payment-method switch when the gateway supports it.- [x] 50. A prepare-plan request without naming a payment method is rejected with a clear error.
- [ ] 51. Customer can switch to a different gateway on their own subscription when both gateways support switching.- [x] 52. A switch request that is missing either the current or the new payment method is rejected with a clear error.
- [ ] 53. Customer can confirm a two-step payment-method switch by supplying the new vendor subscription identifier returned by the gateway.- [x] 54. A confirmation request that is missing the new vendor subscription identifier is rejected with a clear error.
- [x] 55. Customer cannot request a payment-method update on another customer's subscription.
- [x] 56. Customer cannot ask the gateway to prepare a plan on another customer's subscription.
- [x] 57. Customer cannot switch the payment method on another customer's subscription.
- [x] 58. Customer cannot confirm a payment-method switch on another customer's subscription.

## Setup-intent remaining attempts (customer portal)
- [x] 59. Customer using Stripe can see how many setup-intent attempts remain before the gateway rate-limits them. Authoritative contract: 5 attempts per customer per day by default (`fluent_cart/stripe/setup_intent_rate_limit_customer_daily` filter, lowered from 7 by d9793c3bf #2483 on 2026-08-03), one shared source for the displayed count AND enforcement — enforcement pinned by `Tests\Integration\Modules\PaymentMethods\StripeSetupIntentRateLimitTest`.
- [x] 60. Customer using a non-Stripe gateway asking for setup-intent attempts is rejected with a clear "payment method not supported" message.
- [x] 61. Customer cannot inspect setup-intent attempts on another customer's subscription.

## Initiating an early payment (customer portal)
- [x] 62. Customer can initiate an early installment payment on their own subscription when the early-payment feature is enabled and the subscription is eligible.
- [x] 63. Customer cannot initiate an early payment on a subscription that is not eligible (open-ended, canceled, expired, or already fully paid).
- [x] 64. Customer cannot initiate an early payment on another customer's subscription.

## Permissions
- [x] 65. A store manager can list subscriptions.
- [x] 66. A worker with the "view subscriptions" permission can list subscriptions.
- [x] 67. An accountant with the "view subscriptions" permission can list subscriptions.
- [x] 68. A logged-out visitor cannot access the admin subscriptions list.
- [x] 69. A store manager can open a subscription's detail view.
- [x] 70. A worker with the "view subscriptions" permission can open a subscription's detail view.
- [x] 71. An accountant with the "view subscriptions" permission can open a subscription's detail view.
- [x] 72. A logged-out visitor cannot open a subscription's detail view through the admin route.
- [x] 73. A store manager can re-sync a subscription from the payment gateway.
- [x] 74. A worker with the "view subscriptions" permission can re-sync a subscription from the payment gateway, because re-syncing is treated as a read of remote state.
- [x] 75. An accountant with the "view subscriptions" permission can re-sync a subscription from the payment gateway.
- [x] 76. A logged-out visitor cannot re-sync a subscription from the payment gateway through the admin route.
- [ ] 77. A store manager can cancel a subscription.- [x] 78. A worker without the "manage subscriptions" permission cannot cancel a subscription.
- [x] 79. An accountant without the "manage subscriptions" permission cannot cancel a subscription.
- [x] 80. A store manager can generate an early-payment link.
- [x] 81. A worker without the "manage subscriptions" permission cannot generate an early-payment link.
- [x] 82. An accountant without the "manage subscriptions" permission cannot generate an early-payment link.
- [x] 83. A store manager is authorized to attempt reactivating a subscription; the automatic-collection request is rejected by the store-billed-only guard. (contract superseded 2026-07-22 — renewal engine implemented reactivate)
- [x] 84. A worker without the "manage subscriptions" permission cannot attempt to reactivate a subscription.
- [x] 85. An accountant without the "manage subscriptions" permission cannot attempt to reactivate a subscription.
- [x] 86. A logged-out visitor cannot attempt to reactivate a subscription through the admin route.
- [x] 87. A store manager is authorized to attempt pausing a subscription; the request is rejected because the gateway cannot pause it. (contract superseded 2026-07-22 — renewal engine implemented pause)
- [x] 88. A worker without the "manage subscriptions" permission cannot attempt to pause a subscription.
- [x] 89. An accountant without the "manage subscriptions" permission cannot attempt to pause a subscription.
- [x] 90. A logged-out visitor cannot attempt to pause a subscription through the admin route.
- [x] 91. A store manager is authorized to attempt resuming a subscription; the request is rejected because the gateway cannot resume it. (contract superseded 2026-07-22 — renewal engine implemented resume)
- [x] 92. A worker without the "manage subscriptions" permission cannot attempt to resume a subscription.
- [x] 93. An accountant without the "manage subscriptions" permission cannot attempt to resume a subscription.
- [x] 94. A logged-out visitor cannot attempt to resume a subscription through the admin route.

## Authentication and CSRF
- [x] 95. A logged-in admin without a valid security token is rejected from cancelling a subscription.
- [x] 96. An expired security token is rejected with a clear error when generating an early-payment link.
- [x] 97. A logged-out visitor cannot cancel a subscription through the admin route.
- [x] 98. A logged-out visitor cannot generate an early-payment link through the admin route.

## Pausing and resuming from the customer portal
- [x] 99. A customer can pause their own active store-billed subscription.
- [x] 100. A customer cannot pause another customer's subscription by guessing its identifier.
- [x] 101. A customer can resume their own paused store-billed subscription.
- [x] 102. A customer cannot resume another customer's subscription by guessing its identifier.
