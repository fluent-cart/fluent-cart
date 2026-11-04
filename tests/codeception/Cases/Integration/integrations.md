# Integrations

Covers the integration framework wiring that dispatches order/subscription events to external add-ons (FluentCRM, FluentCommunity, FluentSupport plus the base manager used by any Pro-provided addon). Surface:

- `app/Services/Integration.php` — bootstrap on `init` priority 2 (registered from `app/Hooks/actions.php:188`)
- `app/Listeners/IntegrationEventListener.php` — event fan-out + async runner + receipt-page JS trigger + ajax endpoint
- `app/Modules/Integrations/BaseIntegrationManager.php` — abstract base each addon extends
- `app/Modules/Integrations/FluentPlugins/FluentCRMConnect.php` — FluentCRM product/global feed provider
- `app/Modules/Integrations/FluentPlugins/FluentCommunityConnect.php` — FluentCommunity LMS/spaces feed provider
- `app/Modules/Integrations/FluentPlugins/FluentCRMDeepIntegration.php` — FluentCRM advanced filters + subscriber widget (guarded by `defined('FLUENTCRM')`)
- `app/Modules/Integrations/FluentPlugins/FluentSupportWidget.php` — FluentSupport customer widget (guarded by `defined('FLUENT_SUPPORT_VERSION')`)
- `app/Modules/Integrations/AddOnModule.php` — addon listing + status toggle option (`fluent_cart_global_integrations`)
- `app/Modules/Integrations/GlobalIntegrationSettings.php` — feed CRUD, plugin installer, validation
- `app/Modules/Integrations/GlobalNotificationHandler.php` — notification feed dispatcher + async queue bridge
- `app/Modules/Integrations/IntegrationHelper.php` — feed validator / editor formatter
- `app/Modules/IntegrationActions/BaseIntegrationAction.php` — abstract global-action contract
- `app/Modules/IntegrationActions/GlobalIntegrationActionHandler.php` — fires `fluent_cart/register_integration_action` on `init`

Feed storage: product-scoped feeds in `fct_product_meta` (`object_type='product_integration'`, `meta_key=provider`), global-scoped feeds in `fct_meta` (`object_type='order_integration'`). Addon global toggle map in `fct_meta` option key `fluent_cart_global_integrations` (`'yes'|'no'` per provider).

Hook taxonomy the fan-out listens to (all fired with `['order' => Order]`):
`order_paid_done`, `order_status_changed_to_canceled`, `order_fully_refunded`, `subscription_activated`, `subscription_canceled`, `subscription_renewed`, `subscription_eot`, `subscription_expired_validity`, `shipping_status_changed_to_shipped`, `shipping_status_changed_to_delivered`. Revoke hooks: `subscription_expired_validity`, `order_fully_refunded`, `order_status_changed_to_canceled`.

MailChimp directory (`app/Modules/Integrations/MailChimp/`) contains only `index.php` and has no implementation — excluded from this spec.

## `Services\Integration::register`

- [x] 1. `register()` schedules `init()` on `init` priority 2 and registers `IntegrationEventListener::registerHooks()` on same callback
- [x] 2. `init()` always constructs + registers `FluentCRMConnect` and `FluentCommunityConnect`
- [x] 3. `init()` constructs + calls `FluentCRMDeepIntegration::init()` only when `defined('FLUENTCRM')` is truthy
- [x] 4. `init()` constructs + registers `FluentSupportWidget` only when `defined('FLUENT_SUPPORT_VERSION')` is truthy
- [x] 5. Wired via `app/Hooks/actions.php:188` as `(new Integration)->register()` — removing that line breaks all addon wiring

## `BaseIntegrationManager::register` (provider registration)

- [x] 6. Always adds `fluent_cart/integration/order_integrations` filter entry keyed by `$integrationKey`, at priority `$priority`, with `getInfo()` array
- [x] 7. When `isConfigured()` returns false → only the `order_integrations` filter is added; `run/`, `get_integration_defaults_`, `get_integration_settings_fields_`, `integration_saving_data_` hooks are NOT registered
- [x] 8. When configured → adds `fluent_cart/integration/get_integration_defaults_{key}` filter → `getIntegrationDefaults($settings, $args)`
- [x] 9. When configured → adds `fluent_cart/integration/get_integration_settings_fields_{key}` filter → `getSettingsFields`
- [x] 10. When configured → adds `fluent_cart/integration/integration_saving_data_{key}` filter → `validateFeedData`
- [x] 11. When configured → adds `fluent_cart/integration/run/{key}` action at priority `$priority` → calls `processAction($order, $eventData)` only when `$eventData['order']` is set (no-op otherwise)
- [x] 12. `getInfo()` returns keys: `priority`, `title`, `description`, `category`, `disable_global_settings`, `config_url`, `logo`, `enabled`, `scopes`, `installable`, `delay_on_product_action`, `delay_on_global_action`
- [x] 13. `getInfo().config_url` = `''` when `disableGlobalSettings=true`, else `admin_url('admin.php?page=fluent-cart#/integrations/{key}')`
- [x] 14. `getInfo().enabled` reflects `isConfigured()` result at call time
- [x] 15. `isConfigured()` (default) returns true only when `fluent_cart_get_option('_integration_api_{key}')` contains `status=true`
- [x] 16. `getApiSettings()` (default) decodes JSON string options, falls back to `['apiKey'=>'', 'status'=>false]` when missing/empty `status`
- [x] 17. `getSelectedTagIds()` with `routing='simple'` → returns flat array under `$simpleKey`
- [x] 18. `getSelectedTagIds()` with router routing → returns `evaluateRoutings($routers, $inputData)` selections
- [x] 19. `evaluateRoutings()` returns only `input_value` of routings whose `ConditionAssesor::evaluate()` passes; skips rows with empty `input_value`
- [x] 20. `parseSmartCode($text, $order)` passes `order` and `customer` context to `ShortcodeTemplateBuilder::make` and casts result to string

## `FluentCRMConnect`

- [x] 21. Constructs with title=`FluentCRM`, key=`fluentcrm`, priority=12, `disableGlobalSettings=true`, `runOnBackgroundForProduct=false`, `integrationId=1`, `installable='fluent-crm/fluent-crm.php'`
- [x] 22. Constructor registers `fluent_cart/checkout_page_name_fields_schema` filter ONLY when `isConfigured()` true (FLUENTCRM defined)
- [x] 23. `isConfigured()` returns `defined('FLUENTCRM')`
- [x] 24. `getApiSettings()` returns `['status'=>defined('FLUENTCRM'), 'api_key'=>'']` (independent of stored option)
- [x] 25. `getIntegrationDefaults()` keys: `enabled`, `list_name`, `name`, `list_ids`, `remove_list_ids`, `other_fields`, `tag_ids`, `remove_tag_ids`, `tag_routers`, `event_trigger`, `tag_ids_selection_type`, `double_opt_in='yes'`, `watch_on_access_revoke='no'`, `note`
- [ ] 26. `getSettingsFields()` returns `fields` array including name/list_ids/tag_ids/remove_list_ids/remove_tag_ids/note/double_opt_in/watch_on_access_revoke PLUS `actionFields()` appended
- [x] 27. `maybeSetNameEmailAtCheckout()` returns fields unchanged when billing_full_name or billing_email already has `value`, or when `get_current_user_id()` truthy
- [ ] 28. `maybeSetNameEmailAtCheckout()` returns fields unchanged when `fluentcrm_get_current_contact()` returns null
- [ ] 29. `maybeSetNameEmailAtCheckout()` fills empty `billing_full_name.value` and `billing_email.value` from FluentCRM contact when all guards pass
- [ ] 30. `processAction()` with `is_revoke_hook='yes'` and unknown contact → early return (no attach/detach calls)
- [ ] 31. `processAction()` with `is_revoke_hook='yes'` + listIds + tagIds → calls `detachLists($listIds)` and `detachTags($tagIds)` (NOT `remove_list_ids`/`remove_tag_ids` — revoke reuses primary list/tag ids)
- [ ] 32. `processAction()` normal path: `createOrUpdate` gets `email`, `first_name`, `last_name`, plus billing address fields when `$order->billing_address` present
- [ ] 33. `processAction()` sets `status='subscribed'` on contactData when `double_opt_in != 'yes'`, omits status when double opt-in enabled
- [ ] 34. `processAction()` calls `sendDoubleOptinEmail()` only when `double_opt_in=yes` AND contact status != 'subscribed'
- [ ] 35. `processAction()` `removeListIds` → `$contact->detachLists($removeListIds)`; then `attachLists($listIds)` when listIds present
- [ ] 36. `processAction()` `removeTagIds` → calls `$contact->detachTags($tagIds)` — BUG: uses primary `$tagIds` instead of `$removeTagIds` (`FluentCRMConnect.php:258`). Spec as failing regression with `markTestSkipped` citing the file/line until fixed.
- [ ] 37. `processAction()` attaches tags via `attachTags($tagIds)` when tagIds present
- [ ] 38. `processAction()` with non-empty `note` → parses smart codes, wraps in `wpautop`/`wp_kses_post`, creates `FluentCrm\App\Models\SubscriberNote` with `type='info'`
- [ ] 39. `processAction()` appends `$order->addLog('FluentCRM Contact Created', ..., 'info', 'FluentCRM Integration')` on success
- [ ] 40. `processAction()` sets `timezone` from `order.config.user_tz` when present; `contactData` empty values filtered via `array_filter`

## `FluentCommunityConnect`

- [x] 41. Constructs with title=`FluentCommunity`, key=`fluent_community`, priority=12, `category='lms'`, `disableGlobalSettings=true`, `runOnBackgroundForProduct=false`, `installable='fluent-community/fluent-community.php'`
- [x] 42. `isConfigured()` returns `defined('FLUENT_COMMUNITY_PLUGIN_VERSION')`
- [x] 43. `getIntegrationDefaults()` keys: `enabled='yes'`, `name`, `space_ids`, `remove_space_ids`, `course_ids`, `remove_course_ids`, `event_trigger`, `tag_ids_selection_type='simple'`, `mark_as_verified='no'`, `watch_on_access_revoke='yes'`
- [ ] 44. `getSettingsFields()` omits `course_ids` and `remove_course_ids` when `Helper::isFeatureEnabled('course_module')` false; includes both when true
- [ ] 45. `processAction()` resolves user via `$customer->getWpUserId(true)`; creates user via `AuthService::createUserFromCustomer($customer)` when missing
- [ ] 46. `processAction()` on `AuthService::createUserFromCustomer` returning `WP_Error` → logs error via `$order->addLog(..., 'error', 'FluentCommunity')` and returns (no enrollment)
- [ ] 47. `processAction()` returns early when `User::find($userId)` returns null or `syncXProfile(true)` returns falsy
- [ ] 48. `processAction()` with `mark_as_verified=yes` → sets `$xprofile->is_verified=1` and saves
- [ ] 49. `processAction()` removes spaces via `Helper::removeFromSpace($spaceId, $userId, 'by_admin')` for each `remove_space_ids`
- [ ] 50. `processAction()` leaves courses via `CourseHelper::leaveCourses($removeCourseIds, $userId, 'by_admin')`
- [ ] 51. `processAction()` enroll-to-spaces: if pivot exists → merges `order->id` into `meta.fct_ids` (array_unique) and saves pivot; else calls `Helper::addToSpace($spaceId, $userId, 'member', 'by_admin')`
- [ ] 52. `processAction()` enroll-to-courses: same pivot meta accumulation pattern with role `'student'` on add
- [ ] 53. `processAction()` revoke path with no `$userId` OR empty `allSpaceCourseIds` → returns without touching pivots
- [ ] 54. `processAction()` revoke path with `XProfile::where('user_id',$userId)->first()` null → returns without touching pivots
- [ ] 55. `processAction()` revoke path: for each pivot in `space_ids ∪ course_ids` — if `meta.fct_ids` contains this order, filters it out; leaves pivot intact if other order ids remain; removes user from space only when fct_ids becomes empty
- [ ] 56. `processAction()` revoke path: pivot with no fct_ids record → unconditionally `Helper::removeFromSpace`
- [ ] 57. `processAction()` logs success via `$order->addLog('FluentCommunity Integration Success', ...)` listing space + course ids
- [ ] 58. `processAction()` revoke path logs `'FluentCommunity Access Removed'` with removed space ids when any removal actually happened

## `FluentCRMDeepIntegration` (requires FLUENTCRM)

- [ ] 59. `init()` registers all 6 filters: `fluentcrm_contacts_filter_fluent_cart`, `fluentcrm_advanced_filter_options`, `fluentcrm_ajax_options_product_selector_fluent_cart`, `fluent_crm/cascade_selection_options_fct_variations`, `fluent_crm/subscriber_info_widgets`, `fluent_crm/purchase_history_fluent_cart`
- [x] 60. `addAdvancedFilterOptions()` base options always present: `commerce_exist`, `ltv`, `aov`, `first_purchase_date`, `last_purchase_date`, `purchased_items`, `variation_purchased`, `purchased_categories`, `commerce_coupons`
- [x] 61. `addAdvancedFilterOptions()` license options (`active_licenses`, `active_variation_licenses`, `expired_licenses`, `expired_variation_licenses`, `license_exist`) appended only when `ModuleSettings::isActive('license')`
- [ ] 62. `applyFilter()` `commerce_exist` + `operator='exist'` → emits `whereExists` on `fct_customers.email = fc_subscribers.email`; `operator='not_exist'` → `whereNotExists`
- [ ] 63. `applyFilter()` `ltv`/`aov` converts value via `Helper::toCent` before comparing on `fct_customers.{ltv|aov}`
- [ ] 64. `applyFilter()` `first_purchase_date`/`last_purchase_date` with `BETWEEN` operator → `whereBetween`; other operators → `where`
- [ ] 65. `applyFilter()` `purchased_items` → joins `fct_orders` (with `payment_status` in `getOrderPaymentSuccessStatuses()`) and `fct_order_items.post_id`
- [ ] 66. `applyFilter()` `variation_purchased` → parses `'{productId}||{variationId}'` tokens to variation ids, filters on `fct_order_items.object_id`
- [ ] 67. `applyFilter()` `license_exist`, `active_licenses`, `expired_licenses`, `*_variation_licenses` → joins `fct_licenses` with correct status constraints (active/inactive vs expired)
- [x] 68. `handleProductSelectorAjax()` returns products matching `whereLike post_title`, limited 50; appends leftover `$includedIds` products not yet in results
- [ ] 69. `handleProductVariationsAjax()` returns cascade-option tree, filtering out products with ≤1 variant children; includes prev-selected product ids
- [x] 70. `pushInfoWidgetToContact()` returns widgets unchanged when no matching `Customer` found by user_id/email
- [x] 71. `pushInfoWidgetToContact()` adds `widgets['fluent_cart']` with title `'Commerce Info'` and HTML from `getStatsHtml`
- [x] 72. `getStatsHtml()` includes LTV (linked to customers admin page), purchase_count, first/last purchase dates, and aggregated `success_order_items` summary
- [ ] 73. Legacy `fluent_crm/purchase_history_fluent_cart` filter rewrites `fluent-crm#/orders/` → `fluent-cart#/orders/` in each item's `action`

## `FluentSupportWidget` (requires FLUENT_SUPPORT_VERSION)

- [x] 74. `register()` hooks `fluent_support/customer_extra_widgets` filter
- [x] 75. `getPurchaseWidgets()` returns widgets unchanged when no `Customer` matches the FluentSupport customer email
- [x] 76. `getPurchaseWidgets()` delegates HTML to `FluentCRMDeepIntegration::getStatsHtml($customer)`, appends inline CSS, returns `widgets['fluent_cart_purchases']` with header `'Purchases'`

## `AddOnModule`

- [x] 77. `showAddOns()` returns `['addOns' => apply_filters('fluent_cart/integration/order_integrations', [])]`
- [x] 78. `updateAddOnsStatus($request)` writes option `fluent_cart_global_integrations` from `request.addons` (wp_unslash) and returns success message
- [x] 79. `getPremiumAddOns()` returns `[]` in core (Pro may override)
- [x] 80. `getFluentCrm()` returns single-item descriptor with title, description, logo (Vite asset), `enabled='yes'`, purchase_url wp.org, category `'crm'`, btnTxt `'Install & Activate'`
- [x] 81. `getFluentSupport()` returns `[]` in core

## `IntegrationEventListener::registerHooks`

- [x] 82. Registers 10 actions on hooks: `order_paid_done`, `order_status_changed_to_canceled`, `order_fully_refunded`, `subscription_activated`, `subscription_canceled`, `subscription_renewed`, `subscription_eot`, `subscription_expired_validity`, `shipping_status_changed_to_shipped`, `shipping_status_changed_to_delivered` — each with `fluent_cart/` prefix at priority 11
- [x] 83. Registers `fluent_cart/init_order_async_runner` → `initOrderAsyncRunner`, `fluent_cart/after_receipt_first_time` → `addJsToReceipt`, `wp_ajax_fluent_cart_run_order_actions` + `wp_ajax_nopriv_fluent_cart_run_order_actions` → `runOrderActionsAjax`

## `IntegrationEventListener::mapAllIntegrationActions`

- [x] 84. Early return when `$data.order` missing
- [x] 85. Early return when `fluent_cart/integration/order_integrations` filter returns no `enabled` addons
- [x] 86. Loads product-scoped feeds via `ProductMeta::whereIn('object_id', productIds)->where('object_type','product_integration')` using unique `order_items.post_id` values
- [x] 87. Loads global-scoped feeds via `Meta::whereIn('object_type', ['order_integration'])`
- [x] 88. Product feed with `feed.conditional_variation_ids` non-empty AND no intersection with order's `order_items.object_id` → filtered out (not dispatched)
- [x] 89. Valid feeds sorted ascending by `priority` (product list + global list sorted separately then concatenated product-first)
- [x] 90. Per-request de-dup: feeds sharing `uuid = {scope}_{feedId}_{orderId}` run at most once; cache persists on listener instance
- [x] 91. `hook=order_paid_done` → runs ALL valid feeds real-time (ignores `async` flag)
- [x] 92. `fluent_cart/integration/run_all_actions_on_async` filter returning true → ALL feeds queued async (realtime list empty)
- [x] 93. Default split: feeds with `async=false` run real-time; `async=true` queued via `ScheduledAction::create` + `as_enqueue_async_action('fluent_cart/init_order_async_runner', [actionId], 'fluent-cart')`
- [x] 94. ScheduledAction payload: `action='fluent_cart/integration/run_order_actions'`, `status='pending'`, `group='order_integration'`, `object_type='order'`, `data.hook=<hook>`, `data.actions` each with `integration_id`, `priority`, `scope`, `is_revoke_hook`
- [x] 95. Before dispatching real-time feeds, listener reloads order `with(['order_items','customer','shipping_address','billing_address'])`
- [ ] 96. When `order.type` in `['subscription','renewal']` → `$data.subscription` set to `Subscription::where('parent_order_id', $order->id)->first()` before dispatch — BUG: renewals key off the original parent order, not the renewal order id, so `event_data.subscription` is null on renewal hooks. Skip with reference to `IntegrationEventListener.php:196` / `:299`.
- [x] 97. For each real-time feed: fires `do_action('fluent_cart/integration/run/{provider}', $integrationArray)` with `order` and `event_data` attached
- [x] 98. Handler wrapped in try/catch; exception → `$order->addLog('Integration Error: {provider}', message, 'error')`; other feeds continue

## `IntegrationEventListener::formatIntegrationFeed`

- [x] 99. Returns null when feed integration `enabled !== 'yes'`
- [x] 100. Returns null when addon `enabled` filter absent for this provider
- [x] 101. Returns null when `hook` not in `feed.event_trigger` AND not a revoke hook (or `watch_on_access_revoke !== 'yes'`)
- [x] 102. `is_revoke_hook='yes'` only when hook in revoke set AND `feed.watch_on_access_revoke='yes'`
- [x] 103. Returned array keys: `uuid`, `priority`, `integration_id`, `scope`, `provider`, `trigger`, `is_revoke_hook`, `feed`, `async`
- [x] 104. `async` resolved from `addOns.{provider}.delay_on_{scope}_action` (false by default)

## `IntegrationEventListener::initOrderAsyncRunner`

- [x] 105. Early return when `ScheduledAction` not found, action name mismatched, or status != `pending`
- [x] 106. Sets status `'running'` before dispatching; sets `'completed'` with `response_note='Integration actions completed successfully.'` and `completed_at` at end
- [x] 107. Reloads `Order` with order_items/customer/billing_address/shipping_address relationships
- [x] 108. Loads product-scoped actions by `ProductMeta::whereIn('id', productBasedIds)` filtering `object_type='product_integration'`
- [ ] 109. Loads global-scoped actions by `Meta::whereIn('id', globalIds)` filtering `object_type='order_integration'`
- [x] 110. Missing/failed feed formatting → action entry skipped; early return when no formatted actions remain
- [x] 111. Per-action exception caught → `$order->addLog('Integration Error: {provider}', ..., 'error')`; other actions continue

## `IntegrationEventListener::runOrderActionsAjax`

- [x] 112. Missing `order_hash` → JSON response `'Order hash is required'` (200 OK)
- [x] 113. Unknown `order_hash` → JSON response `'Order not found'`
- [x] 114. Non-renewal order → fires `fluent_cart/order_paid_async_private_handle` with `order_id`
- [x] 115. Renewal order → skips the order_paid_async_private_handle action entirely
- [x] 116. No pending ScheduledAction for this order → JSON `'No pending actions found for this order'`
- [x] 117. Pending action exists → calls `initOrderAsyncRunner($action->id)` and returns `'Integration actions are being processed'`

## `IntegrationEventListener::addJsToReceipt`

- [x] 118. Returns null when `$data.order` missing
- [x] 119. Emits `<script>` block that POSTs `admin-ajax.php?action=fluent_cart_run_order_actions&order_hash={uuid}` via XHR on `DOMContentLoaded`
- [x] 120. URL is run through `esc_url_raw` before printing

## `GlobalIntegrationSettings`

- [x] 121. `getGlobalSettingsData()` with unknown key → `wp_send_json` 422 with error message mentioning the settings_key
- [x] 122. `getGlobalSettingsData()` applies default text on missing `save_button_text`/`valid_message`/`invalid_message` before sending
- [x] 123. `authenticateCredentials()` fires `fluent_cart/integration/authenticate_global_credentials_{key}` with `settings_key` and `integration` payload
- [x] 124. `saveGlobalSettingsData()` fires `fluent_cart/integration/save_global_integration_settings_{key}`, then falls back to 422 `'Sorry, no Integration found.'` when no hook returns (hooks short-circuit via `wp_send_json`)
- [x] 125. `getFeeds()` returns only feeds whose provider has `global` in `scopes` AND is `enabled`
- [x] 126. `getNotificationFeeds()` uses `fluent_cart/integration/global_notification_types` filter as allowlist of `meta_key`; empty filter → no feeds
- [x] 127. `formatFeedData()` runs through `fluent_cart/integration/global_notification_feed_{meta_key}` filter for provider-specific shaping
- [x] 128. `getIntegrationSettings()` with `integrationId` and unknown feed → `WP_Error('integration_not_found')`
- [x] 129. `getIntegrationSettings()` with `integrationId` resolves Meta vs ProductMeta based on `feedType` (`global` vs `product`)
- [x] 130. `getIntegrationSettings()` merges stored feed over `get_integration_defaults_{name}` filter; result includes `settings`, `settings_fields`, `shortcodes`, `inputs`, `merge_fields`
- [x] 131. `prepareFeedData()` with empty `integration_name` → `wp_send_json` 422 validation error
- [x] 132. `prepareFeedData()` with validation errors from required fields → returns `WP_Error('validation_error')` with per-field messages
- [x] 133. `prepareFeedData()` with `data_type='stringify'` → decodes `integration` as JSON; otherwise `wp_unslash` raw value
- [x] 134. `prepareFeedData()` passes final `$integration` through `fluent_cart/integration/save_integration_values_{name}` filter before returning meta payload
- [x] 135. `validateFields()` marks field as error when required key absent OR empty array OR trimmed-empty string; non-required skipped
- [x] 136. `saveIntegrationSettings()` with `integration_id` + non-existent Meta → `WP_Error('integration_not_found')`
- [x] 137. `saveIntegrationSettings()` uses `IntegrationHelper::validateAndFormatIntegrationFeedSettings`; `WP_Error` short-circuits
- [x] 138. `saveIntegrationSettings()` updates existing Meta or creates new (`object_type='order_integration'`); response flags `created` via `wasRecentlyCreated`
- [x] 139. `deleteIntegrationFeed()` removes Meta by id and returns success message
- [x] 140. `getIntegrationList()` returns `merge_fields` from `fluent_cart/integration/get_integration_merge_fields_{name}` filter
- [x] 141. `chainedData()` fires `fluent_cart/integration/chained_{route}` with `data`
- [x] 142. `installPlugin()` `WP_Error('permission_denied')` when user lacks `install_plugins`
- [x] 143. `installPlugin()` aliases `'fluentcrm'` → `'fluent-crm'` before allowlist check
- [x] 144. `installPlugin()` with slug outside allowlist (`fluent-crm`, `fluent-smtp`, `fluent-community`, `fluent-security`, `fluentform`, `fluent-support`) → `WP_Error('invalid_plugin')`
- [x] 145. `installPlugin()` allowlist is filterable via `fluent_cart/installable_repo_plugins`
- [x] 146. `installPlugin()` delegates to `BackgroundInstaller::installPlugin`; WP_Error bubbles up
- [ ] 147. `changeStatus()` unknown feed id → `'Feed not found'`
- [x] 148. `changeStatus()` sets `meta_value.enabled='yes'|'no'` based on `status` request param and persists

## `GlobalNotificationHandler`

- [x] 149. `maybeHandleGlobalNotify()` calls `getNotificationFeeds()` and forwards to `triggerNotification` with supplied `$targetHook` and `$group`
- [x] 150. `triggerNotification()` skips feed when `enabled` falsy OR `feed.event_trigger` missing current hook
- [x] 151. `triggerNotification()` for each qualifying feed creates ScheduledAction data with `action='fluent_cart/integration/integration_notify_{provider}'`, `object_id=order.id`, `object_type=$object_type` (default `'order_integration'`), `group=$group`, `scheduled_at=DateTime::gmtNow()`, `data` JSON including `feed_id`
- [x] 152. `triggerNotification()` when `fluent_cart/integration/notifying_async_{provider}` filter returns true → enqueues via `JobRunner::async('fluent_cart/integration/schedule_feed', scheduleActionData)`
- [x] 153. `triggerNotification()` when filter returns false → fires `fluent_cart/integration/integration_notify_{provider}` synchronously with `(feed, order, customer)` payload
- [x] 154. `triggerNotification()` fires `fluent_cart/integrations/global_notify_completed` ONLY when no feeds were queued async (`$asyncFeeds` empty)
- [x] 155. `processFeedData()` merges customer fields (first_name, last_name, email, phone, country, city, state, postcode) into `feed.feed` array
- [x] 156. `handleGlobalAsyncIntegration()` reads `data.feed_id` (decodes string JSON), early returns when missing
- [ ] 157. `handleGlobalAsyncIntegration()` resolves feed via `Meta` for `object_type='order_integration'`, else `ProductMeta` — uses queue's `object_type`
- [ ] 158. `handleGlobalAsyncIntegration()` updates `ScheduledAction` to `SCHEDULE_PROCESSING` with `retry_count+1` and `scheduled_at=gmtNow()` before dispatch
- [ ] 159. `handleGlobalAsyncIntegration()` completes with `status=SCHEDULE_COMPLETED` after `processNotification`
- [x] 160. `processNotification()` → `handleGlobalIntegration()` → fires the supplied `$action` hook with `(parsedFeed, order, customer, transaction)`
- [x] 161. `processIntegrationAction($queueId)` early returns when queue missing or `action` empty
- [x] 162. Internal caches (`$customerCache`, `$transactionCache`, `$orderCache`) return cached instances on repeat lookup within same request

## `IntegrationHelper`

- [x] 163. `validateAndFormatIntegrationFeedSettings()` returns `WP_Error('integration_validation_error')` when provider or scope missing
- [x] 164. `validateAndFormatIntegrationFeedSettings()` strips integration to `Arr::only` over `['enabled', 'conditional_variation_ids']` + all known field keys
- [x] 165. `validateAndFormatIntegrationFeedSettings()` returns WP_Error with per-field `{label} is required.` only when `integration.enabled` is truthy AND required field missing — disabled feeds skip validation
- [x] 166. `validateAndFormatIntegrationFeedSettings()` returns value filtered through `fluent_cart/integration/integration_saving_data_{provider}`
- [x] 167. `formatFeedDataForEditing()` normalizes `enabled` to boolean, runs `fluent_cart/integration/editing_integration_{meta_key}` filter on feed data, returns shape `{id, name, enabled, provider, feed, scope}`

## `BaseIntegrationAction` / `GlobalIntegrationActionHandler`

- [x] 168. `GlobalIntegrationActionHandler::register()` hooks `fluentcart_loaded` → `init`; `init()` hooks `init` → `do_action('fluent_cart/register_integration_action')`
- [x] 169. `BaseIntegrationAction::init()` adds `fluent_cart/integration/get_global_integration_actions` filter returning registered `$actions` array + calls `registerHooks()`
- [x] 170. `BaseIntegrationAction::register()` appends `['slug'=>getSlug(), 'instance'=>$this]` to the shared static `$actions` list (retained across instances in same process)
- [x] 171. `GlobalIntegrationActionHandler::getAll()` returns result of `fluent_cart/integration/get_global_integration_actions` filter (empty array when no actions registered)

## Integration event wiring with IntegrationEventListener

- [ ] 172. End-to-end: dispatching `fluent_cart/order_paid_done` with an enabled global `fluentcrm` feed + active FLUENTCRM invokes `FluentCRMConnect::processAction` once per matching feed
- [ ] 173. End-to-end: dispatching `fluent_cart/order_fully_refunded` with `watch_on_access_revoke=yes` feed → provider runs with `is_revoke_hook='yes'`
- [ ] 174. End-to-end: dispatching the same non-`order_paid_done` hook twice within a process for a feed with `async=false` runs the provider just once (uuid de-dup)
- [ ] 175. End-to-end: dispatching an async-flagged feed → provider NOT invoked synchronously; `ScheduledAction` row created and `fluent_cart/init_order_async_runner` enqueued

## Storage / schema

- [x] 176. Product-scoped feeds persist in `fct_product_meta` rows with `object_type='product_integration'` and `meta_key=<provider>`
- [x] 177. Global-scoped feeds persist in `fct_meta` rows with `object_type='order_integration'` and `meta_key=<provider>`
- [x] 178. Addon global enabled map lives in `fct_meta` option `fluent_cart_global_integrations`
- [x] 179. Addon-level API settings stored under `fct_meta` option `_integration_api_{key}` (decoded JSON or array) — consumed by `BaseIntegrationManager::getApiSettings`

## Edge cases

- [ ] 180. Registering a provider whose `integrationKey` duplicates another provider → latest `getInfo()` wins on the `order_integrations` filter (last-register-wins); documents the ordering hazard
- [x] 181. `mapAllIntegrationActions` with order that has zero `order_items` → no product feeds resolved; still processes global feeds
- [x] 182. `mapAllIntegrationActions` when all matched feeds are filtered out by conditional_variation_ids → no dispatch AND no ScheduledAction inserted
- [ ] 183. `FluentCommunityConnect::processAction` revoke path with pivot `meta.fct_ids` containing ONLY this order id → pivot deleted (removeFromSpace) and listed in `$removedIds`
- [ ] 184. `FluentCommunityConnect::processAction` enroll-to-course path with `course_module` disabled → `course_ids` field absent from settings; feed saving rejects `course_ids` via `Arr::only` allowlist
- [x] 185. `GlobalNotificationHandler::triggerNotification` with a feed whose provider is empty string → no action fires and no async job queued
- [ ] 186. `IntegrationEventListener::runOrderActionsAjax` ran twice for same order → second call sees completed action + returns `'No pending actions found for this order'`
- [ ] 187. Integration fan-out retries: `ScheduledAction.retry_count` increments on each `handleGlobalAsyncIntegration` entry (document rather than assert if current implementation only increments on async notification path, not on `init_order_async_runner`)
- [ ] 188. `BaseIntegrationManager::register` reacts to runtime `isConfigured()` toggling — value captured at registration time, not re-checked per dispatch (document as register-time snapshot)
- [ ] 189. `FluentCRMConnect::processAction` revoke path with no contact found short-circuits BEFORE attempting attach/detach — asserted to guard against NPE regressions
- [ ] 190. `fluent_cart/installable_repo_plugins` filter allowing a new slug → `installPlugin()` accepts it; removing default slug blocks install even when plugin truly exists

## `FluentCRMDeepIntegration::formatDateForFluentCrm` (ticket #3632, extended for the FluentCRM Purchases-page date consistency fix)

Renamed from `formatFluentCrmWidgetDate` and made `public`: it is no longer
scoped to the Commerce Info widget alone. `FluentCrm\App\Services\ExternalIntegrations\FluentCart\FluentCart`
(in the fluent-crm plugin) now calls this same method for every date in the
FluentCRM contact profile's Purchases page — the Purchase History table,
Order Summary (`First Order`/`Last Order`), and Purchased Products list —
so the whole page reads from one FluentCRM-context date formatter instead of
duplicating `date_i18n(get_option('date_format'), ...)` per call site.

- [x] 191. Empty/falsy `$datetime` → returns `''` (feeds the widget's existing `N/A` fallback, doesn't render its own)
- [x] 192. FluentCRM `Helper` class unreachable → falls back to `DateFormatter::format($datetime, false, wp_timezone())`, the widget's compact date-only output. This is now ONLY the "Helper unreachable" case: a reachable `Helper` never falls through to `DateFormatter`, in either of FluentCRM's display modes — see 194 below (behavior changed by the Purchases-page consistency fix; previously `classic_date_time` enabled also fell through to `DateFormatter`, which let FluentCart's own date format setting leak into this FluentCRM-owned page)
- [x] 193. No order context + `timezone_source='fluent_cart'` → widget renders in `wp_timezone()`, NOT the UTC fallback `DateFormatter::format()` uses on its own for the same no-context call (regression pin for the German-store no-context bug)
- [ ] 194. With a reachable FluentCRM `Helper`, EITHER display mode (`classic_date_time` = `'no'`, the relative "X ago" default, or `'yes'`, FluentCRM's own absolute WordPress-formatted "classic" mode) → defers to `Helper::formatDateTime()`, never to `DateFormatter`. Not exercised by this suite (FluentCRM plugin isn't loaded by `Integration.suite.yml`/`IntegrationPro.suite.yml`, so the `Helper` class never resolves); verified in `fluent-crm/tests/integration/00-fluentcrm-deep-integration-dates.php` (that plugin's own suite, where `Helper` genuinely resolves) and via Codex/browser against a live FluentCRM install — see spec 191-193 for the code path this suite CAN reach
- [x] 195. `getStatsHtml()` takes an optional `$dateFormatter` callable (default `[DateFormatter::class, 'format']`, unchanged). Only `pushInfoWidgetToContact()` passes `formatDateForFluentCrm` explicitly — `FluentSupportWidget::getPurchaseWidgets()` calls `getStatsHtml($customer)` with no formatter and must keep rendering plain `DateFormatter` output even when a FluentCRM `Helper` is reachable and its relative-display mode is on, proven with a stub `Helper` class whose `formatDateTime()` return value must NOT appear in the FluentSupport widget's HTML
- [x] 196. `getStatsHtml()`'s "Recent purchases" list is capped to the 20 most-recently-purchased distinct products, computed with a SQL `GROUP BY` + `LIMIT` (not by loading every paid order item into PHP and grouping there) — a customer with 22 distinct paid products renders exactly 20 product rows, keeping the most recent 20 and dropping the 2 oldest (regression pin: an earlier version had no cap at all, so a long-time customer's full order-item history loaded on every FluentCRM profile view)
