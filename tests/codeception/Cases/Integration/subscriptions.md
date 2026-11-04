# Subscriptions

## Database Schema

### Table: fct_subscriptions
- [x] 1. Table exists with primary key `id` BIGINT UNSIGNED AUTO_INCREMENT
- [x] 2. Has columns: uuid, customer_id, parent_order_id, product_id, item_name, quantity, variation_id, billing_interval, signup_fee, initial_tax_total, recurring_amount, recurring_tax_total, recurring_total, bill_times, bill_count, expire_at, trial_ends_at, canceled_at, restored_at, collection_method, next_billing_date, trial_days, vendor_customer_id, vendor_plan_id, vendor_subscription_id, status, original_plan, vendor_response, current_payment_method, config, created_at, updated_at
- [x] 3. Monetary columns (signup_fee, initial_tax_total, recurring_amount, recurring_tax_total, recurring_total) are BIGINT UNSIGNED, default 0
- [x] 4. `quantity` default 1, `bill_times` default 0, `bill_count` default 0, `trial_days` default 0
- [x] 5. `collection_method` is ENUM('automatic','manual','system') default 'automatic'
- [x] 6. `config` column is JSON (nullable)
- [x] 7. Has index `*_order_subscription_idx` on `parent_order_id`
- [ ] 8. `SubscriptionsMigrator::addUuidColumn()` adds uuid column idempotently (no error when column already exists)
- [ ] 9. `SubscriptionsMigrator::renameInitialAmountToSignupFee()` renames legacy `initial_amount` to `signup_fee`
- [ ] 10. `SubscriptionsMigrator::backfillEmptyUuids()` fills empty/null uuids in batches of 500

### Table: fct_subscription_meta
- [x] 11. Table exists with columns: id, subscription_id, meta_key, meta_value, created_at, updated_at
- [x] 12. `meta_key` is VARCHAR(192), `meta_value` is LONGTEXT (both nullable)
- [x] 13. Has indexes on `subscription_id` and `meta_key`

## Model: Subscription

### Fillable & Appended
- [x] 14. `$fillable` includes: customer_id, parent_order_id, product_id, item_name, variation_id, billing_interval, signup_fee, quantity, recurring_amount, recurring_tax_total, recurring_total, bill_times, bill_count, expire_at, trial_ends_at, canceled_at, restored_at, collection_method, trial_days, vendor_customer_id, vendor_plan_id, vendor_subscription_id, next_billing_date, status, original_plan, vendor_response, current_payment_method, config
- [x] 15. `$guarded = ['id']` prevents mass-assign of id
- [x] 16. Appends url, payment_info, billingInfo, overridden_status, currency, reactivate_url to array output

### Boot
- [x] 17. Creating Subscription with empty uuid auto-generates uuid via md5(time() . wp_generate_uuid4())
- [x] 18. Creating Subscription with existing uuid preserves the provided uuid

### Config Attribute
- [x] 19. `getConfigAttribute()` decodes stored JSON string into array
- [x] 20. `getConfigAttribute()` returns [] when config is null/empty
- [x] 21. `setConfigAttribute()` encodes array to JSON with UNESCAPED_UNICODE|UNESCAPED_SLASHES flags
- [x] 22. `setConfigAttribute()` with non-array value stores literal '[]'

### Relationships
- [x] 23. Subscription belongsTo Customer via customer_id
- [x] 24. Subscription belongsTo Product via product_id → posts.ID
- [x] 25. Subscription belongsTo ProductVariation via variation_id
- [x] 26. Subscription belongsTo Order (parent) via parent_order_id
- [x] 27. Subscription hasMany SubscriptionMeta via subscription_id
- [x] 28. Subscription hasMany OrderTransaction via subscription_id
- [x] 29. Subscription hasMany CustomerAddresses (type=billing) via customer_id (billing_addresses)
- [x] 30. Subscription morphMany LabelRelationship via labels()
- [x] 31. Subscription belongsTo ProductDetail via variation_id (product_detail)

### Overridden Status Attribute
- [x] 32. Returns `active` when status=trialing AND config.is_trial_days_simulated='yes'
- [x] 33. Returns `trialing` when status=active AND trial_days>0 AND trial period not elapsed AND config.is_trial_days_simulated!='yes'
- [x] 34. Returns raw status when no simulated condition matches

### Currency Attribute
- [x] 35. Returns uppercase store-settings currency when config is empty
- [x] 36. Returns uppercase store-settings currency when config.currency is empty string
- [x] 37. Returns uppercase config.currency when set

### Payment Method Text
- [x] 38. `getPaymentMethodText()` returns "brand ***last4" when billingInfo.details has brand and last_4
- [x] 39. `getPaymentMethodText()` returns details.method when card info missing

### Meta Methods
- [x] 40. `getMeta($key, $default)` returns meta_value for matching key on this subscription
- [x] 41. `getMeta()` returns `$default` when key does not exist
- [x] 42. `updateMeta()` creates new SubscriptionMeta row when key absent
- [x] 43. `updateMeta()` updates existing SubscriptionMeta row when key exists
- [x] 44. SubscriptionMeta setter JSON-encodes arrays/objects; getter decodes JSON strings; plain strings pass through

### Transactions
- [x] 45. `getLatestTransaction()` returns latest OrderTransaction for this subscription where transaction_type='charge'
- [x] 46. `getLatestTransaction()` returns null when no charge transactions exist

### Access & Gates
- [x] 47. `hasAccessValidity()` returns true for statuses active, trialing, completed
- [x] 48. `hasAccessValidity()` returns false for statuses expired, past_due, intended, pending
- [x] 49. `hasAccessValidity()` for other statuses returns true when `next_billing_date` is in future
- [x] 50. `hasAccessValidity()` for other statuses returns false when `next_billing_date` has passed
- [x] 51. `hasAccessValidity()` falls back to `guessNextBillingDate()` when `next_billing_date` is null
- [x] 52. `canUpgrade()` returns true when Meta with key=variant_upgrade_path and object_id=variation_id exists AND status in (active, trialing)
- [x] 53. `canUpgrade()` returns false when no upgrade Meta exists OR status not in (active, trialing)
- [x] 54. `canUpdatePaymentMethod()` returns false when gateway does not support `card_update`
- [x] 55. `canUpdatePaymentMethod()` returns true for statuses active, trialing, paused, intended, past_due, failing, expiring (when gateway supports card_update)
- [x] 56. `canSwitchPaymentMethod()` returns false when gateway or `switch_payment_method` feature missing
- [x] 57. `canSwitchPaymentMethod()` returns true for statuses active, trialing, paused (when feature supported)
- [x] 58. `switchablePaymentMethods()` returns configured supported_gateways list from gateway feature
- [x] 59. `canReactive()` returns '' when Pro is not active
- [x] 60. `canReactive()` returns '' when config.upgraded_to_sub_id is set OR recurring_amount <= 0
- [x] 61. `canReactive()` returns '' when config.cancellation_reason='refunded'
- [x] 62. `canReactive()` returns true for statuses canceled, failing, expired, paused, expiring, past_due
- [x] 63. `canReactive()` result passes through `fluent_cart/subscription/can_reactivate` filter
- [x] 64. `getReactivateUrl()` returns empty string when `canReactive()` is falsy
- [x] 65. `getReactivateUrl()` returns URL with query args `fluent-cart=reactivate-subscription` and `subscription_hash={uuid}`

### Billing Date Calculation
- [x] 66. `guessNextBillingDate()` returns existing `next_billing_date` when set and not forced
- [x] 67. `guessNextBillingDate(true)` recalculates even when `next_billing_date` is set
- [x] 68. `guessNextBillingDate()` for subscription with trial_days>0 and no renewal orders returns created_at + trial_days days
- [x] 69. `guessNextBillingDate()` for subscription with renewal orders uses latest renewal created_at + interval days
- [x] 70. `guessNextBillingDate()` for parent order (non-renewal) without trial uses parent_order.created_at + interval days
- [x] 71. `guessNextBillingDate()` preserves existing `next_billing_date` when status=canceled
- [x] 72. `getReactivationTrialDays()` returns 0 when `hasAccessValidity()` is false
- [x] 73. `getReactivationTrialDays()` returns floor((nextBilling - now) / 86400), clamped to 0 when result <= 1

### Financial
- [x] 74. `getCurrentRenewalAmount()` returns config.current_renewal_amount when set (non-zero)
- [x] 75. `getCurrentRenewalAmount()` returns `recurring_total` when config.current_renewal_amount is 0/unset
- [x] 76. `getRequiredBillTimes()` returns (bill_times - bill_count) when bill_times>0 and difference >0
- [x] 77. `getRequiredBillTimes()` returns 0 when bill_times=0 (unlimited subscription)
- [x] 78. `getRequiredBillTimes()` recounts bill_count from succeeded charge transactions when stale, then returns revised remaining
- [x] 79. `getRequiredBillTimes()` includes early_payment_history meta (count>1 adds paidCount-1) in bill_count recount
- [x] 80. `getRequiredBillTimes()` returns -1 when revised bill_count meets or exceeds bill_times

### Downloads
- [x] 81. `getDownloads()` returns [] when variation_id is missing or status != active
- [x] 82. `getDownloads()` returns ProductDownload rows for product_id when status=active
- [x] 83. `getDownloads()` attaches product_title, variation_ids, variation_titles to each download
- [x] 84. `getDownloads()` unsets raw product_variation_id field after mapping

### View URLs
- [x] 85. `getViewUrl('customer')` returns customer profile URL with subscription/{uuid} path
- [x] 86. `getViewUrl('admin')` returns admin URL with subscriptions/{id}/view path

### Remote Gateway Sync
- [x] 87. `reSyncFromRemote()` returns WP_Error 'invalid_payment_method' when gateway has no subscriptions feature
- [x] 88. `reSyncFromRemote()` delegates to gateway->subscriptions->reSyncSubscriptionFromRemote() when supported

### Cancel Remote Subscription
- [x] 89. `cancelRemoteSubscription()` returns WP_Error 'subscription_already_cancelled' when status=canceled
- [ ] 90. `cancelRemoteSubscription()` without gateway returns WP_Error but still sets local status=canceled with canceled_at=now
- [x] 91. `cancelRemoteSubscription()` sets status=canceled and updates canceled_at GMT timestamp
- [x] 92. `cancelRemoteSubscription()` when status=completed preserves status=completed and clears canceled_at
- [x] 93. `cancelRemoteSubscription(['reason'=>'x'])` stores reason in config.cancellation_reason
- [x] 94. `cancelRemoteSubscription()` dispatches `SubscriptionCanceled` event when fire_hooks=true and status != completed
- [x] 95. `cancelRemoteSubscription()` with fire_hooks=false suppresses the event
- [x] 96. `cancelRemoteSubscription(['note'=>'x'])` writes note to parent order.note

### Expiration Job
- [x] 97. `checkAndExpireSubscriptions()` returns stats with keys checked, validity_expired, batches
- [x] 98. Transitions active/trialing with `next_billing_date` past grace period to status=expired
- [x] 99. Grace periods per interval from `SubscriptionHelper::getSubscriptionsGracePeriodDays()`: daily=1, weekly=3, monthly=7, quarterly=15, half_yearly=15, yearly=15; unknown fallback=7
- [x] 100. Sets `next_billing_date=NULL` on expiration
- [x] 101. Stores `validity_expired_at` meta with current GMT time
- [x] 102. Dispatches `SubscriptionValidityExpired` event for each expired subscription
- [x] 103. Canceled subscriptions with config.upgraded_to_sub_id set are SKIPPED (not expired)
- [x] 104. Canceled subscriptions past grace period clear `next_billing_date` but keep status=canceled (do not set to expired)
- [x] 105. Subscriptions still within grace period are not modified
- [x] 106. Processes in batches of 100 (configurable) using `id > lastId` cursor
- [x] 107. Writes summary log entry via `fluent_cart_add_log` when any subscriptions checked

## Service: SubscriptionService

### recordRenewalPayment
- [x] 108. Returns WP_Error 'subscription_not_found' when subscriptionId has no model
- [x] 109. Returns WP_Error 'transaction_exists' when OrderTransaction with same vendor_charge_id already exists
- [x] 110. Returns WP_Error 'parent_order_not_found' when subscription has no parent order
- [x] 111. Creates child Order with parent_id=parentOrder.id, type='renewal', currency/tax_behavior inherited
- [x] 112. Child order status='completed' when fulfillment_type='digital'; status='processing' when 'physical'
- [x] 113. Child order shipping_status='unshipped' when physical; empty when digital
- [x] 114. Child order payment_status='paid' when transaction status='succeeded'; 'pending' otherwise
- [x] 115. Copies parent tax_id meta to child order when present
- [ ] 116. Copies parent OrderTaxRate rows to child order (tax_rate_id, shipping_tax, order_tax, total_tax, meta)
- [ ] 117. Inserts child OrderAddresses (billing & shipping) from parent addresses + customer info
- [x] 118. Creates child OrderItem with payment_type='subscription', post_id=product_id, object_id=variation_id
- [x] 119. Child OrderItem inherits bundle_item_ids from parent order_item.line_meta when present
- [x] 120. Child OrderItem tax_amount falls back to recurring_tax_total, then parent other_info.recurring_tax when tax_behavior=2
- [x] 121. Creates OrderTransaction with order_type='renewal', transaction_type='charge', subscription_id set
- [x] 122. Calls `syncSubscriptionStates()` after creating renewal records
- [x] 123. Dispatches `SubscriptionRenewed` event with subscription, newOrder, oldOrder, customer

### syncSubscriptionStates
- [x] 124. Recalculates bill_count from succeeded charge transactions (total > 0)
- [x] 125. Adds (count - 1) per early_payment_history entry where count > 1
- [x] 126. Sets status='completed', next_billing_date=NULL, canceled_at=NULL when bill_count >= bill_times and bill_times>0
- [x] 127. When NOT EOT and next_billing_date absent, sets next_billing_date via `guessNextBillingDate()`
- [x] 128. When incoming status=canceled and canceled_at empty, sets canceled_at to now GMT
- [x] 129. Fires `fluent_cart/subscription/data_updated` when status unchanged but other fields dirty
- [x] 130. Dispatches `SubscriptionEOT` event when EOT transition occurs
- [x] 131. Fires `fluent_cart/payments/subscription_status_changed` action on status change
- [x] 132. Fires dynamic action `fluent_cart/payments/subscription_{new_status}` on status change
- [x] 133. On transition to status=expired, writes `validity_expired_at` meta and dispatches `SubscriptionValidityExpired`
- [x] 134. Writes all `$args['meta']` entries via `updateMeta()`

### recordManualRenewal
- [x] 135. Updates renewal order: type='renewal', payment_status='paid', total_paid=transaction.total, completed_at=now
- [x] 136. Sets order status='completed' for digital; 'processing' for physical fulfillment
- [x] 137. Stores `billing_info` arg on subscription meta `active_payment_method` when provided
- [x] 138. Calls `syncSubscriptionStates()` with status=active and current_payment_method from transaction
- [x] 139. Dispatches `SubscriptionRenewed` only when transaction.total > 0
- [ ] 140. Invokes `StatusHelper::syncOrderStatuses()` after order update

## Service: SubscriptionHelper

### getNextBillingDate
- [x] 141. Returns null when status=completed OR bill_count >= bill_times (bill_times > 0)
- [x] 142. Returns canceled_at when status=canceled or expired
- [x] 143. Returns trial_ends_at when bill_count=0 AND trial_days>0 AND trial_ends_at set
- [x] 144. Returns created_at + trial_days days (GMT) when bill_count=0, trial_days>0, trial_ends_at empty
- [x] 145. Returns stored next_billing_date when it is in the future
- [x] 146. Advances by interval (daily=+1 day, weekly=+1 week, monthly=+1 month, quarterly=+3 months, half_yearly=+6 months, yearly=+1 year) when no stored date in future
- [x] 147. Unknown interval follows the day-count contract (`PaymentHelper::getIntervalDays()` + `fluent_cart/subscription_interval_in_days` filter), same as the renewal engine

### getSubscriptionCancelAtTimeStamp
- [x] 148. Returns null when both billTimes and trialDays are 0
- [x] 149. Returns timestamp for billTimes periods + trialDays seconds offset
- [x] 150. Maps interval 'daily' to 'day'; maps day/weekly/monthly/yearly to plural forms only when billTimes>0

### getSubscriptionsGracePeriodDays
- [x] 151. Returns array with intervals: daily=1, weekly=3, monthly=7, quarterly=15, half_yearly=15, yearly=15
- [x] 152. Array is filterable via `fluent_cart/subscription/grace_period_days`

## Service: PlanUpgradeService

- [x] 153. `getUpgradeSettings($productId, $variantId)` returns Meta rows where object_id=variantId, object_type='variant_upgrade', meta_key='variant_upgrade_path'
- [x] 154. `getUpgradeSettings($productId)` without variantId returns Meta via upgradeablePath scope
- [x] 155. `saveUpgradeSetting()` creates Meta with object_id=from_variant, object_type='variant_upgrade', meta_key='variant_upgrade_path', and meta_value containing to_variants, is_prorate, discount_amount
- [x] 156. `updateUpgradeSetting($id, $settings)` updates meta_value JSON with to_variants, is_prorate, discount_amount
- [x] 157. `validateUpgradePaths()` sends JSON error response with 423 status when a variation has duplicate target_variation_id entries
- [ ] 158. `getUpgardePathsFromVariation()` returns [] when variationId or orderHash missing
- [ ] 159. `getUpgardePathsFromVariation()` for subscription order uses latest renewal (paid/partially_refunded) as the reference order
- [ ] 160. `getUpgardePathsFromVariation()` returns [] for bundle items (payment_type='bundle')
- [ ] 161. Computed `cost = item_price + signup_fee - prorateCredit - discountAmount`, floored at 0
- [ ] 162. For subscription target variant, payment_summary format: "<strong>{cost}</strong> first {interval}, then {item_price}/{interval} thereafter."
- [ ] 163. `calculateUpgradeToDiscount()` for onetime item returns totalPaid (line_total - refund_total, including additional_item_ids), floored at 0
- [ ] 164. `calculateUpgradeToDiscount()` returns 0 when subscription lacks access validity OR is trialing with is_trial_days_simulated!='yes'
- [ ] 165. `calculateUpgradeToDiscount()` for renewal order resolves parentOrderId = order.parent_id
- [x] 166. Prorate discount = round(totalPaid * daysRemaining / intervalDays); intervalDays from `PaymentHelper::getIntervalDays()` (monthly=days-in-current-month, quarterly=90, half_yearly=182, yearly=365, weekly=7, daily=1)
- [x] 167. Prorate credits quarterly (÷90) and half_yearly (÷182) — regression for the private map that returned 0 for them; returns 0 only for an interval neither core nor `fluent_cart/subscription_interval_in_days` resolves
- [x] 168. Prorate clamps daysRemaining to interval divider (never discount more than one full period)

## Service: EarlyPaymentFeature

- [x] 169. `isEnabled()` returns false when Pro is not active
- [ ] 170. `isEnabled()` reads store setting `enable_early_payment_for_installment` default 'yes'
- [x] 171. `isEnabled()` is filterable via `fluent_cart/subscription/early_payment_enabled`
- [x] 172. `canPay()` returns true when enabled AND bill_times>0 AND bill_count<bill_times AND status in (active, trialing)
- [x] 173. `canPay()` returns false when any condition fails
- [x] 174. `canPay()` result passes through `fluent_cart/subscription/can_early_pay` filter

## REST API: /fluent-cart/v2/subscriptions

### GET /subscriptions (index)
- [x] 175. Returns paginated data via `SubscriptionFilter::fromRequest($request)->paginate()`
- [x] 176. Response shape: `{ data: {...paginator} }`
- [x] 177. Permission: requires `subscriptions/view`

### GET /subscriptions/{subscriptionOrderId}
- [x] 178. Returns subscription with loaded labels, activities.user, customer primary addresses, order billing/shipping addresses
- [x] 179. Attaches parent order billing_address and shipping_address directly on subscription
- [x] 180. Includes `related_orders` (parent order + all children) ordered DESC by id
- [x] 181. Includes `selected_labels` (pluck label_id from labels)
- [x] 182. Includes `reminder_permissions` from `ReminderService::getSubscriptionReminderPermissions()`
- [x] 183. Returns entityNotFoundError when subscription not found (message "Subscription not found", redirect to /subscriptions)
- [x] 184. Response filtered via `fluent_cart/subscription/view`
- [x] 185. Permission: requires `subscriptions/view`

## REST API: /fluent-cart/v2/orders/{order}/subscriptions/{subscription}

### PUT /cancel
- [ ] 186. Returns error when cancel_reason is empty (SKIPPED — WPFluent route param caching)
- [x] 187. Calls `$subscription->cancelRemoteSubscription(['reason' => cancel_reason])`
- [x] 188. Returns WP_Error message when result is WP_Error
- [ ] 189. Returns vendor error message prefixed with "Subscription cancelled locally." when vendor_result is WP_Error (SKIPPED — route param caching)
- [x] 190. On success returns message + refreshed subscription row
- [x] 191. Permission: requires `subscriptions/manage`

### PUT /fetch
- [x] 192. Calls `$subscription->reSyncFromRemote()` and delegates once to `gateway->subscriptions` when the gateway declares `subscriptions` (COD no longer does — test grants the capability to a spy module)
- [ ] 193. Returns error when reSyncFromRemote returns WP_Error (SKIPPED — route param caching)
- [x] 194. Permission: requires `subscriptions/view`

### POST /early-payment-link
- [x] 195. Returns error "Early payment is not enabled for this site." when feature disabled
- [ ] 196. Returns error "Invalid subscription for the specified order." when subscription.parent_order_id != order.id (SKIPPED — route param caching)
- [ ] 197. Returns error "Early payment is only available for installment subscriptions." when bill_times <= 0 (SKIPPED — WPFluent route param caching)
- [ ] 198. Returns error "Subscription must be active to make early payments." when status not in (active, trialing) (SKIPPED — WPFluent route param caching)
- [ ] 199. Returns error "All installments have already been paid." when bill_count >= bill_times (SKIPPED — WPFluent route param caching)
- [ ] 200. On success returns payment_url with query args `fluent-cart=early-installment-payment`, `subscription_hash={uuid}` (SKIPPED — Pro not active in test env)
- [x] 201. Permission: requires `subscriptions/manage`

### PUT /reactivate, /pause, /resume
- [x] 202. All three return error "Not available yet" (endpoints stubbed)
- [x] 203. Permission: requires `subscriptions/manage`

## Permissions

- [x] 204. super_admin can access all subscription endpoints
- [ ] 205. Manager with `subscriptions/view` can GET list and detail but cannot cancel/pause/resume
- [ ] 206. Worker with `subscriptions/manage` can cancel, generate early-payment link, hit reactivate/pause/resume stubs
- [ ] 207. Accountant without subscription caps → 403 on all subscription endpoints (SKIPPED — Helper::getCurrentUser() static cache)
- [ ] 208. Unauthenticated request to any subscription endpoint → 401 (SKIPPED — Helper::getCurrentUser() static cache)

## Events

- [x] 209. `SubscriptionActivated` dispatches hook `fluent_cart/subscription_activated`
- [x] 210. `SubscriptionActivated::toArray()` exposes subscription, order, customer, meta
- [x] 211. `SubscriptionActivated::getActivityEventModel()` returns subscription
- [x] 212. `SubscriptionCanceled` dispatches hook `fluent_cart/subscription_canceled`
- [x] 213. `SubscriptionCanceled::toArray()` exposes reason (defaults to "canceled on customer request" when empty)
- [x] 214. `SubscriptionCanceled::shouldCreateActivity()` returns true
- [x] 215. `SubscriptionRenewed` dispatches hook `fluent_cart/subscription_renewed`
- [x] 216. `SubscriptionRenewed::beforeDispatch()` calls `newOrder->generateReceiptNumber()`
- [x] 217. `SubscriptionRenewed::toArray()` exposes subscription, order (newOrder), main_order (oldOrder), customer
- [x] 218. `SubscriptionRenewed` has registered listener `Listeners\Subscription\SubscriptionRenewed`
- [x] 219. `SubscriptionEOT` dispatches hook `fluent_cart/subscription_eot`
- [x] 220. `SubscriptionEOT::beforeDispatch()` calls cancelRemoteSubscription(['reason'=>'end_of_term','fire_hooks'=>false])
- [x] 221. `SubscriptionEOT::beforeDispatch()` logs error via fluent_cart_add_log when remote cancel returns WP_Error
- [x] 222. `SubscriptionValidityExpired` dispatches hook `fluent_cart/subscription_expired_validity`
- [x] 223. `SubscriptionValidityExpired::toArray()` reason defaults to "Subscription validity expired"
- [x] 224. `PlanChanged` dispatches hook `fluent_cart/plan_changed` with order, newVariation, oldVariation, otherInfo

## Edge Cases

- [x] 225. Creating Subscription without config persists default '[]' (not null) via setConfigAttribute
- [ ] 226. `hasAccessValidity()` with null `next_billing_date` and status not in explicit lists calls `guessNextBillingDate()` → uses parent_order + trial_days or created_at
- [x] 227. `guessNextBillingDate()` with no paid parent order falls back to `created_at + trial_days` (trial_days=0 means created_at itself)
- [x] 228. `cancelRemoteSubscription()` preserves existing canceled_at when already set (does not overwrite)
- [x] 229. `syncSubscriptionStates()` with no status change AND no dirty data does NOT fire `data_updated` action
- [ ] 230. `recordRenewalPayment()` when recurring_tax_total=0 and parent tax_behavior=2 pulls recurring_tax from parent order_item.other_info
- [x] 231. `getRequiredBillTimes()` self-heals stale bill_count when transactions count differs and saves the corrected value
- [x] 232. `checkAndExpireSubscriptions()` loop terminates when `Subscription::query()->get()` returns empty (no infinite loop)
- [ ] 233. `SubscriptionsMigrator::backfillEmptyUuids()` terminates when batch returns fewer rows than chunkSize (500)
- [x] 234. UUID collision: duplicate uuid generated by md5(time() . wp_generate_uuid4()) is astronomically unlikely, but two subscriptions inserted in same second still get distinct uuids (wp_generate_uuid4 random part)
- [x] 235. Overridden status does NOT mutate stored `status` column (only affects response attribute)

## New scenarios (added 2026-08-14)

<!-- Renewal payments can be recorded long after the remote charge (delayed
     webhooks, admin resync); the gateway-supplied settlement time must survive
     into meta.settled_at instead of the processing-time fallback stamp. -->

- [x] 236. recordRenewalPayment carries a gateway-supplied meta.settled_at onto the created renewal transaction, and the model fallback does not overwrite it
- [x] 237. recordRenewalPayment without a gateway-supplied settlement time still yields a settled_at on the succeeded renewal transaction (the fallback stamp)
- [x] 238. recordRenewalPayment paying an existing pending manual renewal invoice carries the gateway settlement time onto that pending transaction, without overwriting one already present

## Report: SubscriptionReportService::getFutureRenewals() (added 2026-08-21)

<!-- Projects next-quarter renewals by stepping next_billing_date forward by
     PaymentHelper::getIntervalDays(); the trait's private map used to miss
     half_yearly (→ 30-day default, projected as monthly). -->

- [x] 239. half_yearly subscription with next_billing_date inside the quarter projects exactly 1 renewal (182-day cadence), totalProjected = recurring_total
- [x] 240. quarterly subscription with next_billing_date inside the quarter projects exactly 1 renewal
- [x] 241. Interval that neither core nor the filter resolves (0 days) is excluded — 0 renewals, no infinite loop — instead of being projected as monthly

## Factories Needed

- SubscriptionFactory (fields: customer_id, parent_order_id, product_id, variation_id, billing_interval, recurring_total, bill_times, status)
- SubscriptionMetaFactory
- OrderFactory with type='subscription' and type='renewal' support
- OrderItemFactory with payment_type='subscription'
- OrderTransactionFactory with transaction_type='charge' + status='succeeded'
- ProductFactory + ProductVariationFactory with payment_type='subscription'
- MetaFactory (for variant_upgrade_path)
- CustomerFactory + CustomerAddressesFactory

## Implementation Status

**Test suite totals: 219 tests, 16 skipped, 0 fail (389 assertions)**

**Spec coverage: ~203 / 235 items checked off (~86%)**

- Models: 104/107 covered (remaining: 8-10 migrators)
- Services: 56/67 covered (remaining: 116-117 tax/address copy, 140 syncOrderStatuses, 158-168 prorate, 170 store setting)
- HTTP/REST: 21/29 covered (6 skipped for route-param cache, 2 for Pro)
- Permissions: 2/5 covered (3 skipped for Helper::getCurrentUser cache)
- Events: 16/16 covered
- Edge cases: 7/11 covered (remaining: 226 duplicate of 51, 230 tax fallback, 233 migrator)

**Skipped (16):** known framework limitations
- 186, 189, 193, 196-199: WPFluent `Route::getParameter()` caches URL params across dispatches (2nd dispatch to same route gets stale IDs)
- 200: requires Pro-active to succeed
- 205-208: `Helper::getCurrentUser()` static cache — role-context switches non-deterministic
- 170: `App::storeSettings()` container-cached StoreSettings instance
- 62/63/65 (model side): 2 conditional skips when Pro not active

**Not implemented (≈32):** deferred — requires migrator-level fixture or complex tax/prorate setup.
- Migrator methods (8-10, 233)
- OrderTaxRate copy (116), OrderAddresses from parent (117), StatusHelper::syncOrderStatuses (140)
- PlanUpgradeService prorate math (158-165); 166-168 implemented
- Tax fallback edge (230)
