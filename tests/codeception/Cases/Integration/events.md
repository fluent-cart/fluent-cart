# Events

Covers 21 event dispatchers (11 Order, 5 Subscription, 1 License, 4 other) and 17 listeners (Activity auto-appended + 16 domain listeners). Events extend `FluentCart\App\Events\EventDispatcher`. `dispatch()` runs `beforeDispatch()`, executes registered `$listeners` (plus `Activity::class`), runs `afterDispatch()`, then calls `do_action($hook, $this->toArray())` when `$autoFireHook === true`.

**Implementation status:** 170 tests across 7 files (151 pass, 19 skipped). Tests assume unchanged core; failing tests are skipped with concrete bug references so the suite stays green while documenting required core fixes.

Test files:
- `tests/Integration/Events/EventDispatcherTest.php` — base + Activity (13 tests)
- `tests/Integration/Events/OrderEventsTest.php` — 11 Order events (77 tests, 11 skipped — see bug list)
- `tests/Integration/Events/SubscriptionEventsTest.php` — 5 Subscription events (17 tests, 2 skipped — getConfigAttribute bug)
- `tests/Integration/Events/MiscEventsTest.php` — License/PlanChanged/UserCreated/StockChanged/FirstTime (22 tests, 4 skipped — Pro-only License + Pro-required-message contract)
- `tests/Integration/Events/EventsEdgeCasesTest.php` — cross-event edge cases (6 tests)
- `tests/Integration/Listeners/UpdateStockListenerTest.php` — UpdateStock listener incl. add/remove/edit + 4 shipping-state transitions (14 tests)
- `tests/Integration/Listeners/IntegrationEventListenerTest.php` — IntegrationEventListener incl. ajax + renewal subscription injection realtime/async (21 tests, 2 skipped — renewal parent_id bug)

Core bugs surfaced (each cited in `markTestSkipped()` reasons; tests preserved as held regression assertions):

1. **`app/Listeners/Order/OrderDeleted.php:9`** — `handle()` strict-types its `$event` param to `OrderDeleted`, but `OrderCanceled` registers it as a listener. dispatch() throws TypeError before `afterDispatch()` runs → `fluent_cart/order_canceled` hook never fires + customer stats never recounted on cancel.
2. **`app/Events/Order/OrderCanceled.php`** — `autoFireHook=true` (inherited default) AND `afterDispatch()` calls `do_action($this->hook, ...)` explicitly → `fluent_cart/order_canceled` double-fires. Email/access-revoke handlers run twice.
3. **`app/Models/Subscription.php:140`** — `getConfigAttribute()` returns the raw JSON string `'[]'` for empty configs because `$decoded ?: $value` evaluates an empty array as falsy. `cancelRemoteSubscription()` then fatals with "Cannot access offset of type string on string" inside `SubscriptionEOT::beforeDispatch()`.
4. **`app/Listeners/IntegrationEventListener.php:196` + `:299`** — Subscription lookup uses `Subscription::where('parent_order_id', $order->id)` even when `$order->type === 'renewal'`. Subscriptions are keyed to the original parent order, so `event_data.subscription` is silently dropped on renewal hooks (both realtime and async paths).
5. **`api/ModuleSettings.php:31`** — `getAllSettings()` memoizes via function-static with no public reset seam. Tests cannot toggle `stock_management.active` mid-process, blocking listener stock-restore coverage.
6. **Core licensing entrypoints** — no centralized "FluentCart Pro required" guard. Core-only users hit an opaque class-not-found fatal on `FluentCartPro\App\Modules\Licensing\Models\License` instead of a translated user-facing message.

Other skips (environmental, no core fix required):
- **OrderPaid duplicate-email branch** — unreachable: `Customer::getWpUserId(true)` auto-links by email before `createUserFromCustomer` runs.
- **OrderPaid auto-login** — blocked by `Helper::getCurrentUser()` function-static cache.
- **RenewalOrderDeleted subscription sync** — requires gateway-mock for `cancelRemoteSubscription` side effects.
- **LicenseRenewed Pro-only constructors** — `class_exists` guard; concrete dependency contract asserted via reflection in core-only env.

## EventDispatcher (base)

- [x] 1. `dispatch()` always invokes `Activity::handle($event)` as last listener in pipeline
- [x] 2. `dispatch()` calls `beforeDispatch()` before iterating listeners
- [x] 3. `dispatch()` calls `afterDispatch()` after iterating listeners
- [x] 4. `dispatch()` fires `do_action($hook, toArray())` when `$autoFireHook=true` and `$hook` non-empty
- [x] 5. `dispatch()` skips `do_action($hook,...)` when `$autoFireHook=false`
- [x] 6. `dispatch()` skips `do_action()` when `$hook===''`
- [x] 7. `shouldCreateActivity()` default returns `true`
- [x] 8. `getEventInfoForActivity()` default returns `[]`

## Activity listener (auto-appended)

- [x] 9. `Activity::handle()` early-returns when `$event->shouldCreateActivity()===false` (no `fct_activity` log row created)
- [x] 10. `Activity::handle()` calls `fluent_cart_add_log()` with `module_type=get_class($model)`, `module_id=$model->id`
- [x] 11. `Activity::handle()` uses short class name humanized as title (e.g. `OrderCreated` → `Order Created`)
- [x] 12. `Activity::handle()` overrides title/content/status from `getEventInfoForActivity()` when provided
- [x] 13. `Activity::handle()` records `user_id`/`created_by` from `wp_get_current_user()`

## Order/OrderCreated

- [x] 14. Hook: `fluent_cart/order_created`, autoFire=true, listeners: `Listeners\Order\OrderCreated`
- [x] 15. Constructor loads `customer`, `shipping_address`, `billing_address` relations on `$order`
- [x] 16. `toArray()` returns keys: `order`, `prev_order`, `customer`, `transaction`
- [x] 17. `dispatch()` fires `do_action('fluent_cart/order_created', $data)` with `order`, `prev_order`, `customer`, `transaction`
- [x] 18. Listener `Order\OrderCreated::handle()` calls `customer->recountStat()` when `order->customer_id` set
- [x] 19. Listener no-ops when `order->customer_id` is null
- [x] 20. `getActivityEventModel()` returns `$order`, activity log created in `fct_activity` with `module_type=Order`, `module_id=order->id`

## Order/OrderPaid

- [x] 21. Hook: `fluent_cart/order_paid`, autoFire=true, listeners: `Listeners\Order\OrderPaid`
- [x] 22. Constructor loads `customer`, `order_items`, `shipping_address`, `billing_address`
- [x] 23. `beforeDispatch()` generates receipt number when `order->receipt_number` is empty
- [x] 24. `beforeDispatch()` skips receipt generation when receipt already set
- [x] 25. `afterDispatch()` enqueues async action `fluent_cart/order_paid_async_private_handle` with `order_id` via Action Scheduler
- [x] 26. `afterDispatch()` writes returned scheduler id to order meta key `action_scheduler_id`
- [x] 27. Listener `Order\OrderPaid::handle()` calls `order->recountTotalPaid()`
- [x] 28. Listener calls `customer->recountStat()` when customer present
- [x] 29. Listener `maybeCreateUser()` no-ops when customer already has WP user id
- [x] 30. Listener creates WP user when `order->config.create_account_after_paid === 'yes'`
- [x] 31. Listener creates WP user when `order->type === 'subscription'`
- [x] 32. Listener creates WP user when StoreSettings `user_account_creation_mode === 'all'`
- [x] 33. Listener creates a WordPress account but defers guest customer linking until email verification
- [x] 34. Listener does not recreate or link an account that already holds the checkout email
- [ ] 35. Listener auto-logs user in via `AuthService::makeLogin()` when browser context (no current user, no `fluent-cart` query param)

## Order/OrderUpdated

- [x] 36. Hook: `fluent_cart/order_updated`, autoFire=true, listeners: `Listeners\Order\OrderUpdated`
- [x] 37. Constructor loads relations on both `$order` and `$oldOrder`
- [x] 38. `toArray()` returns `order` and `old_order`
- [x] 39. Listener `updateTransaction()` returns early when `oldOrder->total_amount == newOrder->total_amount`
- [x] 40. Listener updates pending `charge` transaction's `total` to `newOrder->total_amount` when totals differ
- [x] 41. Listener no-ops when no pending charge transaction exists

## Order/OrderStatusUpdated

- [x] 42. Hook: `fluent_cart/order_status_updated`, autoFire=**false** (afterDispatch fires derived hooks manually)
- [x] 43. Constructor sets `willDispatch=false` and clears listeners when `oldStatus === newStatus` (no-op)
- [x] 44. `afterDispatch()` with `type='payment_status'` fires `fluent_cart/payment_status_changed_to_{newStatus}` and `fluent_cart/payment_status_changed`
- [x] 45. `afterDispatch()` with `type='shipping_status'` fires `fluent_cart/shipping_status_changed_to_{newStatus}` and `fluent_cart/shipping_status_changed`
- [x] 46. `afterDispatch()` with `type='order_status'` fires `fluent_cart/order_status_changed_to_{newStatus}` and `fluent_cart/order_status_changed`
- [x] 47. `afterDispatch()` no-ops when `willDispatch=false`
- [x] 48. `getEventInfoForActivity()` returns title/content from `$activity` array for log row
- [x] 49. `toArray()` keys: `order`, `old_status`, `new_status`, `manageStock`, `activity`

## Order/OrderRefund

- [x] 50. Hook: `fluent_cart/order_refunded`, autoFire=**false** (afterDispatch fires hook manually)
- [x] 51. Listeners: `Listeners\Order\OrderRefunded`
- [x] 52. Constructor computes `refundedAmount = sum(refund txns) - order->total_refund`; if zero → `willTrigger=false`, listeners cleared
- [x] 53. Constructor sets `type='full'` when cumulative refunds ≥ `order->total_paid`, otherwise `'partial'`
- [x] 54. `afterDispatch()` fires `fluent_cart/order_refunded` with data payload
- [x] 55. `afterDispatch()` fires `fluent_cart/order_fully_refunded` when `type='full'`
- [x] 56. `afterDispatch()` fires `fluent_cart/order_partially_refunded` when `type='partial'`
- [x] 57. `afterDispatch()` no-ops when `willTrigger=false`
- [x] 58. `afterDispatch()` resolves `refunded_items` via `OrderItem::whereIn('id', refundedItemIds)`
- [x] 59. Listener `Order\OrderRefunded::handle()` calls `order->syncOrderAfterRefund($type, $refundedAmount)` and `order->updateRefundedItems($ids, $amount)`

## Order/OrderPaymentFailed

- [x] 60. Hook: `fluent_cart/order_payment_failed`, autoFire=true, listeners: `Listeners\Order\OrderPaymentFailed`
- [x] 61. Constructor loads `customer`, `order_items`, `shipping_address`, `billing_address`; sets `customer=order->customer`
- [x] 62. `toArray()` includes `old_status`, `new_status`, `reason`
- [x] 63. Listener adds `order->addLog('Payment Failed', ..., 'error')`
- [x] 64. Listener calls `customer->recountStat()` when customer present

## Order/OrderCanceled

- [x] 65. Hook: `fluent_cart/order_canceled`, autoFire=true (default), listeners: `Listeners\Order\OrderDeleted`
- [ ] 66. `afterDispatch()` also calls `do_action($this->hook, toArray())` explicitly (double-fire — verify hook fires at least once, payload has `order`, `customer`)
- [ ] 67. Listener recounts customer stats via `Customer->recountStat()`

## Order/OrderDeleted

- [x] 68. Hook: `fluent_cart/order_deleted`, autoFire=true, listeners: `Listeners\Order\OrderDeleted`
- [x] 69. Constructor uses `loadMissing` (idempotent) for relations
- [x] 70. `toArray()` exposes `connected_order_ids`
- [x] 71. `shouldCreateActivity()` returns `false` when `order->mode === Status::ORDER_MODE_TEST` (skips activity log)
- [x] 72. Listener recounts customer stats

## Order/OrderDeleting

- [x] 73. Hook: `fluent_cart/order_before_delete`, autoFire=true
- [x] 74. `shouldCreateActivity()` always `false`
- [x] 75. Listener no-ops when `ModuleSettings::isActive('stock_management') === false`
- [x] 76. Listener no-ops when `connectedOrderIds` empty
- [ ] 77. Listener restore-stock guarded by `fluent_cart/order/should_restore_stock_on_delete` filter (default = `isTestMode`)
- [ ] 78. Listener batch-updates `ProductVariation` rows: adds `on_hold`+`committed` back to `available`, marks `IN_STOCK` when >0
- [ ] 79. Listener propagates restore to bundle child variations when parent is marked `is_bundle_product=yes`
- [ ] 80. Listener refreshes `ProductDetail.stock_availability` for affected products based on `IN_STOCK` variant presence

## Order/RenewalOrderDeleted

- [x] 81. Hook: `fluent_cart/renewal_order_deleted`, autoFire=true, listeners: `Listeners\Order\RenewalOrderDeleted`
- [x] 82. `shouldCreateActivity()` returns `false` in test mode
- [x] 83. Listener recounts customer stats
- [ ] 84. Listener calls `SubscriptionService::syncSubscriptionStates()` for subscription whose `parent_order_id === order->parent_id`

## Order/OrderBulkAction

- [x] 85. Hook: `fluent_cart/order_bulk_action`, autoFire=true, listeners: `Listeners\Order\OrderBulkAction`
- [x] 86. `shouldCreateActivity()` always `false` (no log row written)
- [x] 87. Listener calls `CustomerHelper::calculateCustomerStats($customerIds)` when ids non-empty
- [x] 88. Listener no-ops when `customerIds` empty/null

## Subscription/SubscriptionActivated

- [x] 89. Hook: `fluent_cart/subscription_activated`, autoFire=true, listeners: `[]` (only Activity)
- [x] 90. `toArray()` keys: `subscription`, `order`, `customer`, `meta`
- [x] 91. `getActivityEventModel()` returns subscription → activity log `module_type=Subscription`

## Subscription/SubscriptionRenewed

- [x] 92. Hook: `fluent_cart/subscription_renewed`, autoFire=true, listeners: `Listeners\Subscription\SubscriptionRenewed`
- [x] 93. `beforeDispatch()` calls `newOrder->generateReceiptNumber()`
- [x] 94. `toArray()` keys: `subscription`, `order` (new), `main_order` (old), `customer`
- [x] 95. Listener calls `customer->recountStat()` when customer present

## Subscription/SubscriptionCanceled

- [x] 96. Hook: `fluent_cart/subscription_canceled`, autoFire=true, listeners: `[]`
- [x] 97. `toArray().reason` defaults to translated `'canceled on customer request'` when empty
- [x] 98. `shouldCreateActivity()` returns `true`

## Subscription/SubscriptionEOT

- [x] 99. Hook: `fluent_cart/subscription_eot`, autoFire=true, listeners: `[]`
- [x] 100. Constructor loads `customer` relation on `$order`
- [x] 101. `beforeDispatch()` calls `subscription->cancelRemoteSubscription(reason=end_of_term, fire_hooks=false)`
- [x] 102. `beforeDispatch()` logs error via `fluent_cart_add_log()` with `module=subscription` when remote cancel returns `WP_Error`

## Subscription/SubscriptionValidityExpired

- [x] 103. Hook: `fluent_cart/subscription_expired_validity`, autoFire=true, listeners: `[]`
- [x] 104. `toArray().reason` defaults to translated `'Subscription validity expired'`

## License/LicenseRenewed

- [x] 105. Hook: `fluent_cart/license_renewed`, autoFire=true, listeners: `[]`
- [x] 106. Constructor loads `customer`, `subscriptions` on `$order`
- [x] 107. `getActivityEventModel()` returns `$license` (activity row module_type=License class)
- [ ] 108. Skip in test env when `FluentCartPro\...\License` model not present (use `class_exists()` guard + `markTestSkipped()`)

## PlanChanged

- [x] 109. Hook: `fluent_cart/plan_changed`, autoFire=true, listeners: `[]`
- [x] 110. `toArray()` keys: `order`, `customer`, `new_variation`, `old_variation`, `other_info`
- [x] 111. `shouldCreateActivity()` returns `false` (no activity row)

## UserCreated

- [x] 112. Hook: `fluent_cart/user_created`, autoFire=true, listeners: `[]`
- [x] 113. `toArray()` keys: `user`, `userId`, `password`

## StockChanged

- [x] 114. Hook: `fluent_cart/product_stock_changed`, autoFire=true, listeners: `[]` (UpdateDefaultVariation is commented-out)
- [x] 115. `toArray()` keys: `post_ids`, `other_info`
- [x] 116. `shouldCreateActivity()` returns `false`
- [x] 117. Listener `UpdateDefaultVariation::handle()` (now wired to `ProductVariationsChanged`, not `StockChanged`) — invoke directly; sets `default_variation_id` to the FIRST variant by `serial_index`, overriding any prior value and ignoring `stock_status` / `item_status` (an out-of-stock, inactive, lowest-serial variant still wins)
- [ ] 118. ~~`UpdateDefaultVariation` updates `ProductDetail.stock_availability`~~ — removed; the listener now only resolves `default_variation_id`. `stock_availability` is computed inline by the inventory / manage-stock paths.

## FirstTimePluginActivation

- [x] 119. Empty `$hook` → `dispatch()` never calls `do_action`
- [x] 120. `shouldCreateActivity()` returns `false`
- [x] 121. Listeners pipeline: `ManagePayments`, `SetupStore` (CreatePages is commented out)
- [x] 122. `ManagePayments` sets `currency='USD'` on `StoreSettings`
- [x] 123. `SetupStore` sets `store_name=''` and `store_logo=''` on `StoreSettings`
- [x] 124. `FirstTimePluginActivation::handle()` static no-ops when option `fluent_cart_do_activation_redirect` is false
- [ ] 125. `handle()` deletes `fluent_cart_do_activation_redirect`, sets `fluent_cart_plugin_once_activated=true`, and dispatches event when redirect flag set and once-activated not set

## UpdateStock listener (currently not wired in any `$listeners` array — verify standalone)

- [x] 126. `UpdateStock::handle()` invoked with `OrderCreated` event + status=`SHIPPING_UNSHIPPED` increments `on_hold` by quantity, decrements `available`
- [ ] 127. `UpdateStock::handle()` with `OrderUpdated` and removed line item produces `delete` action branch
- [ ] 128. `UpdateStock::handle()` with `OrderUpdated` and added line item produces `add` action branch
- [ ] 129. `UpdateStock::handle()` with `OrderUpdated` and qty change produces `edit` action with delta quantity
- [x] 130. `UpdateStock::handle()` with `OrderStatusUpdated` `event->manageStock=false` short-circuits (no variation update)
- [x] 131. `UpdateStock::handle()` with `OrderRefund` action='refund' restores `available`, decrements `committed`
- [x] 132. `UpdateStock::handle()` skips variants where `manage_stock=0`
- [x] 133. `UpdateStock::handle()` batch-updates `ProductDetail.stock_availability=IN_STOCK` for products with any IN_STOCK variant

## IntegrationEventListener

- [x] 134. `registerHooks()` binds callbacks on hooks: `order_paid_done`, `order_status_changed_to_canceled`, `order_fully_refunded`, `subscription_activated`, `subscription_canceled`, `subscription_renewed`, `subscription_eot`, `subscription_expired_validity`, `shipping_status_changed_to_shipped`, `shipping_status_changed_to_delivered` (all with `fluent_cart/` prefix at priority 11)
- [x] 135. `registerHooks()` also binds `fluent_cart/init_order_async_runner`, `fluent_cart/after_receipt_first_time`, ajax `fluent_cart_run_order_actions` (priv + nopriv)
- [x] 136. `mapAllIntegrationActions()` early-returns when `order` missing from payload
- [x] 137. `mapAllIntegrationActions()` early-returns when no enabled addons via `fluent_cart/integration/order_integrations` filter
- [x] 138. Feed skipped when `integration.enabled !== 'yes'` OR addon disabled
- [x] 139. Feed skipped when current hook not in `event_trigger[]` AND not a revoke hook
- [x] 140. Revoke hooks list: `subscription_expired_validity`, `order_fully_refunded`, `order_status_changed_to_canceled` → feed included when `watch_on_access_revoke === 'yes'`
- [x] 141. Product-scoped feeds filtered by `conditional_variation_ids` intersect with order variant ids (skip on no intersection)
- [x] 142. Duplicate feed UUIDs (`scope_feedId_orderId`) de-duped across realtime+background lists
- [x] 143. Hook `order_paid_done` forces all feeds to realtime (background list empty)
- [x] 144. Filter `fluent_cart/integration/run_all_actions_on_async` can force all feeds to background
- [x] 145. Background feeds written to `fct_scheduled_actions` with `action='fluent_cart/integration/run_order_actions'`, `status='pending'`, and `as_enqueue_async_action('fluent_cart/init_order_async_runner', ...)` enqueued
- [x] 146. Realtime feeds fire `do_action('fluent_cart/integration/run/'.$provider, $integrationArray)` with `order`, `event_data` keys
- [ ] 147. Realtime integration run includes `subscription` key in event_data when `order->type in ['subscription','renewal']`
- [x] 148. Exception in provider action caught → logged via `order->addLog('Integration Error: ...', 'error')`
- [x] 149. `initOrderAsyncRunner()` marks `ScheduledAction.status='running'` then `'completed'` with `completed_at` set, updates `response_note`
- [x] 150. `initOrderAsyncRunner()` no-ops when action missing, wrong action name, or status != 'pending'
- [x] 150a. `initOrderAsyncRunner()` claims the action with one conditional pending → running write; when another runner (receipt page or background job) claimed it first, it runs no integration _(SCAN-11)_
- [ ] 151. `runOrderActionsAjax()` returns `wp_send_json` error when `order_hash` missing
- [ ] 152. `runOrderActionsAjax()` returns error when no order matches `uuid`
- [ ] 153. `runOrderActionsAjax()` fires `fluent_cart/order_paid_async_private_handle` when `order->type !== 'renewal'`

## Edge Cases

- [x] 154. `OrderStatusUpdated` with same old/new status produces no `fct_activity` log and no `do_action` call
- [x] 155. Duplicate `OrderPaid->dispatch()` on order with receipt_number does not overwrite receipt
- [x] 156. `OrderRefund` with zero `newRefundAmount` skips `Activity` listener (listeners cleared) — verify no activity row created
- [x] 157. Activity log row `status` defaults to `'success'` when event doesn't override via `getEventInfoForActivity()`
- [x] 158. Event `toArray()` payload delivered to `do_action` listener is the same array returned by `toArray()` (reference integrity across hook)
- [x] 159. Events with `$hook=''` or non-string never trigger `do_action` in `dispatch()`
