# Email

## Service: EmailNotifications

### Constants & registry
- [x] 1. `EmailNotifications::META_KEY` equals `'email_notifications_config'`
- [x] 2. `EMAIL_RECIPIENT_MAP` maps `customer` → `{{order.customer.email}}`, `user` → `{{user.user_email}}`, `subscriber` → `{{order.customer.email}}`
- [x] 3. `getDefaultNotifications()` returns keys: order_paid_admin, order_paid_customer, subscription_renewal_customer, subscription_renewal_admin, subscription_canceled_customer, subscription_canceled_admin, order_refunded_admin, order_refunded_customer, order_shipped_customer, order_shipment_created_customer, order_delivered_customer, order_placed_admin, order_placed_customer, renewal_reminder_overdue_customer, subscription_renewal_reminder_customer, subscription_renewal_reminder_admin, subscription_trial_end_reminder_customer, subscription_trial_end_reminder_admin
- [x] 4. Each default notification carries: event, group, group_label, title, description, recipient, smartcode_groups, template_path, is_async, settings
- [x] 5. `order_paid_admin` event=`order_paid_done`, recipient=`admin`, template_path=`order.paid.admin`
- [x] 6. `order_paid_customer` event=`order_paid`, recipient=`customer`, template_path=`order.paid.customer`
- [x] 7. `subscription_renewal_customer` event=`subscription_renewed`, recipient=`customer`
- [x] 8. `order_refunded_customer` event=`order_refunded`, default `active='yes'`
- [x] 9. `order_refunded_admin` defaults to `active='no'`
- [x] 10. `order_placed_admin` & `order_placed_customer` carry `manage_toggle='no'` (auto-enabled for offline payments)
- [x] 11. `renewal_reminder_overdue_customer` carries `manage_toggle='no'` (on-demand)

### getNotifications()
- [x] 12. `getNotifications()` applies filter `fluent_cart/email_notifications` to defaults
- [x] 13. `getNotifications()` injects `name` key matching the array key for every notification
- [x] 14. `getNotifications()` defaults `group_label` to "Other Actions" when empty
- [x] 15. `getNotifications()` merges persisted `notification_config[$key]` into `settings` via `wp_parse_args`
- [x] 16. Backward compat: persisted `attach_pdf='yes'` migrates to `attach_pdf_template='order_receipt'` when `attach_pdf_template` empty
- [x] 17. `attach_pdf` key is unset on every settings entry after migration

### getNotificationsOfEvent()
- [x] 18. `getNotificationsOfEvent('order_paid', $data)` returns only notifications whose event matches
- [x] 19. Returns `[]` when no notifications match the event
- [x] 20. Skips notification when `settings.active != 'yes'` AND event NOT in [`order_placed_offline`, `renewal_reminder_overdue`] (toggle-bypass list)
- [x] 21. Includes notification regardless of `active` flag when event is `order_placed_offline` (auto on-demand)
- [x] 22. Includes notification regardless of `active` flag when event is `renewal_reminder_overdue` (auto on-demand)
- [x] 23. Skips entry when `recipient` is not in [admin, customer, user, subscriber]
- [x] 24. Admin recipient resolves `to` from `EmailNotifications::getSettings('admin_email')`
- [x] 25. Customer/user/subscriber recipient resolves `to` from `EMAIL_RECIPIENT_MAP[$recipient]` (smartcode placeholder)
- [x] 26. Skips when admin recipient configured but `admin_email` is empty

### formatNotification()
- [x] 27. Returns array with keys: `to`, `body`, `pre_header`, `is_async`, `subject`, `is_custom`, `settings`
- [x] 28. `is_custom=false` when `settings.is_default_body='yes'` OR `email_body` empty
- [x] 29. `is_custom=true` when `is_default_body!='yes'` AND `email_body` non-empty
- [x] 30. Default body resolves via `TemplateService::getTemplateByPathName(template_path, viewData)`
- [x] 31. Custom body uses `settings.email_body` verbatim (no template lookup)

### updateNotification()
- [x] 32. Persists only whitelisted keys: active, subject, email_body, is_default_body, attach_pdf_template
- [x] 33. Missing keys in payload preserve existing config values
- [x] 34. When `is_default_body='yes'`, clears `email_body` to '' (unless `fluent_cart/keep_email_body_draft` filter returns true)
- [x] 35. Filter `fluent_cart/keep_email_body_draft` returning true preserves `email_body` even when default selected
- [x] 36. Persists into `fct_meta` via `Meta::updateOrCreate(['meta_key' => META_KEY, 'object_type' => 'email_notification'], …)`
- [x] 37. Calls `Cache::forget(META_KEY)` after successful persist
- [x] 38. Returns the updated notification array via `getNotification($name)`

### Settings (getSettings/updateSettings/defaultSettings)
- [x] 39. `defaultSettings()` returns: from_name='', from_email='', reply_to_name='', reply_to_email='', email_footer='', show_email_footer='yes', admin_email='{{wp.admin_email}}'
- [x] 40. `getSettings()` merges cached settings over defaults via `wp_parse_args`
- [x] 41. `getSettings('from_email')` returns the single key value
- [x] 42. `getSettings()['notification_config']` merges cached notification_config over defaults
- [x] 43. `updateSettings(['from_name' => 'Foo'])` persists via Meta::updateOrCreate and forgets cache
- [x] 44. `cachedSettings()` reads from `Cache::get(META_KEY, …)` falling back to Meta row `meta_value`
- [x] 45. `cachedSettings()` returns `[]` when no Meta row exists

## Service: Mailer

### Construction & defaults
- [x] 46. `Mailer::make($to, $subject, $body)` returns Mailer instance with provided fields
- [x] 47. Constructor calls `setDefaults()` populating `from` from EmailNotifications settings
- [x] 48. `from` formatted as `"Name <email>"` when both from_name + from_email present
- [x] 49. `from` equals from_email alone when from_name empty
- [x] 50. `replyTo` formatted as `"Name <email>"` when both reply_to_name + reply_to_email present
- [x] 51. `replyTo` equals reply_to_email when reply_to_name empty

### Builder methods
- [x] 52. `subject()` / `setSubject()` / `body()` / `setIsHtml()` / `setFrom()` / `setReplyTo()` are chainable
- [x] 53. `to($email, $name)` formats as `"Name <email>"`; `to($email)` stores raw email
- [x] 54. `addCC($cc)` appends to cc array; `addBCC($bcc)` appends to bcc array
- [x] 55. `addAttachment($path)` appends to attachments; `setAttachments([])` replaces
- [x] 56. `wrapWithFooter($content)` appends `ShortcodeTemplateBuilder::make($footer, [])` when footer set
- [x] 57. `wrapWithFooter()` appends "Powered by FluentCart" footer block when `App::isProActive()` is false

### send()
- [x] 58. `send()` returns false when no `to`, `cc`, `bcc` set
- [x] 59. `send($cssInliner=true)` runs body through `Emogrifier::emogrify()`
- [x] 60. `send()` adds `Content-Type: text/html; charset=UTF-8` header when isHtml=true
- [x] 61. `send()` adds `Content-Type: text/plain; charset=UTF-8` header when isHtml=false
- [x] 62. `send()` adds `From:`, `Cc:`, `Bcc:`, `Reply-To:` headers only when corresponding fields set
- [x] 63. `send()` forwards $to, $subject, $body, headers, attachments to `wp_mail()`

## Service: EmailNotificationMailer

### Hook registration (register())
- [x] 64. `register()` adds action `fluent_cart/order_placed_offline` → `mailEmailsOfEvent('order_placed_offline', …)` priority 999
- [x] 65. `register()` adds action `fluent_cart/order_paid` priority 999
- [x] 66. `register()` adds action `fluent_cart/order_paid_done` priority 10
- [x] 67. `register()` adds action `fluent_cart/subscription_renewed` priority 999
- [x] 68. `register()` adds action `fluent_cart/subscription_canceled` priority 999
- [x] 69. `register()` adds action `fluent_cart/order_refunded` priority 999
- [x] 70. `register()` adds actions `fluent_cart/shipping_status_changed_to_shipped` and `…_to_delivered` priority 999
- [x] 71. `register()` adds action `fluent_cart/renewal_reminder_overdue` priority 999
- [x] 72. `register()` adds actions `fluent_cart/subscription_renewal_reminder` and `subscription_trial_end_reminder` priority 999

### registerAsyncMails()
- [x] 73. `registerAsyncMails()` registers `fluent_cart/async_mail/order_*` actions delegating to `sendAsyncOrderMail`
- [x] 74. `registerAsyncMails()` registers `fluent_cart/async_mail/subscription_*` actions delegating to `sendAsyncSubscriptionMail`

### formatParsable()
- [x] 75. `formatParsable()` converts each entry that is a Model to its `toArray()` form
- [x] 76. `formatParsable()` injects `order.customer` from top-level `customer` key when missing

### mailEmailsOfEvent()
- [x] 77. `mailEmailsOfEvent('order_paid', $data)` invokes `Mailer::send(true)` for every active matching notification
- [x] 78. Filter `fluent_cart/should_send_email_notification` returning false skips that notification entirely
- [x] 79. Filter receives args: event, mail_name, order
- [x] 80. When notification `is_async=true` and `$asyncHook` provided, enqueues `as_enqueue_async_action($asyncHook, …)` instead of sending sync
- [x] 81. Attaches PDF when `settings.attach_pdf_template` set AND `FLUENT_PDF` constant defined
- [x] 82. Backward compat: `settings.attach_pdf='yes'` (legacy) maps to `templateId='order_receipt'` when no `attach_pdf_template`
- [x] 83. Skips PDF attachment when `FLUENT_PDF` not defined
- [x] 84. `generateOrderPdf()` returns null and logs error via `fluent_cart_add_log()` when generation throws
- [x] 85. After send, temp PDF file deleted via `unlink()`
  - TC-003 (2026-07-21): PDF generation moved to Pro behind the `fluent_cart/pdf/generate_receipt` filter (`OrderReceiptPdfService` no longer exists in the free plugin). Tests 81-85 now drive that filter directly, with the `fluent_cart/pdf/can_generate_receipt` testability seam opening `OrderService::canGenerateReceiptPdf()` (Pro is not active in the Integration suite; see TESTABILITY-DEBT #10).

### parseEmailContent()
- [x] 86. Returns indexed array `[0=>body, 1=>subject, 2=>to]`
- [x] 87. Custom block emails: applies filter `fluent_cart/parse_email_block_content` then `fluent_cart/render_block_email_template`
- [x] 88. Default emails: renders `emails.parts.order_header` + `emails.general_template` views with body/preheader/header/emailFooter
- [x] 89. Body, subject, and to are processed through `ShortcodeTemplateBuilder::make($value, $data)` for shortcode resolution

### Footer
- [x] 90. `getEmailFooter()` returns custom footer (parsed via ShortcodeTemplateBuilder) when `email_footer` setting non-empty
- [x] 91. `getEmailFooter()` appends "Powered by FluentCart" branding when `!App::isProActive()` OR `show_email_footer='yes'`

### Async mailers
- [x] 92. `sendAsyncOrderMail($emailName, $orderId)` loads Order with relations [customer, shipping_address, billing_address, transactions]
  - Core bug fixed: `mailByEmailName()` no longer runs `$data` through `formatParsable()`; the original Order Model is passed to `parseEmailContent()` so view rendering works. Test now executes end-to-end.
  - TC-003 (2026-07-21): that fix (201a14885) was reverted by 44a39aa1d during the PDF-filter migration — `order_header.php` reads `$order->invoice_no` / `$order->orderTaxRates` off an array again, erroring tests 92, 93, 95. Re-fixed by this ticket's fix commit; tests unchanged.
- [x] 93. `sendAsyncOrderMail()` passes `transaction = order.transactions->first()` (or `[]`)
  - Test now executes end-to-end and verifies the FIRST transaction's smartcode resolves (with a second transaction confirming order matters).
- [x] 94. `sendAsyncOrderMail()` no-ops when Order not found
- [x] 95. `sendAsyncSubscriptionMail($emailName, $subId)` loads Subscription with [customer, transactions, order]
  - Test now executes end-to-end and asserts subject + recipient.
- [x] 96. `sendAsyncSubscriptionMail()` returns early when subscription has no parent order

## Service: EmailPreviewService

- [x] 97. `getPreviewData('order.paid.customer')` returns array with keys: order, subscription, customer, transaction, reminder
- [x] 98. Dummy order has: id=1001, invoice_no='FC-1001', total_amount=4900, fulfillment_type='digital'
- [x] 99. Dummy customer has: full_name='John Doe', email='john.doe@example.com'
- [x] 100. `getPreviewData('subscription.renewal.customer')` swaps `transaction` to subscription's latest transaction
- [x] 101. `getPreviewData('subscription.trial_end.customer')` sets `reminder.stage='trial_end_3'`
- [x] 102. `getPreviewData('subscription.canceled.customer')` adds `reason` key with localized text
- [x] 103. `reminder.due_at` is 2 days ahead in GMT; `billing_date` 30 days; `trial_end_date` 3 days

## Service: BlockEditorHelper

- [x] 104. `getStyleDefaults()` returns array with keys: spacing (7 entries), font-family (8), font-size (5), color (12)
- [x] 105. Spacing slugs: fct-xx-small (5px), fct-x-small (10px), fct-small (14px), fct-medium (20px), fct-large (30px), fct-x-large (45px), fct-xx-large (60px)
- [x] 106. `getDefaultPreset('color')` returns the color array; `getDefaultPreset('unknown')` returns []
- [x] 107. `getStyleDefaultPresets()` produces CSS string `--wp--preset--spacing--{slug}: {size};` for every spacing entry
- [x] 108. `replaceStyleSlugsWithValues($css)` replaces `var(--wp--preset--spacing--fct-medium)` with `20px`
- [x] 109. `replaceStyleSlugsWithValues()` replaces color, font-size, font-family CSS vars with concrete values
- [x] 110. `replaceStyleSlugsWithValues('')` returns `''`
- [x] 111. `getDynamicCssForEditor()` outputs `.editor-styles-wrapper .has-{slug}-font-family { font-family: …; }` per font
- [x] 112. `getThemeColorPalette()` reads `theme.json[settings.color.palette]` when present, falls back to `editor-color-palette` theme support, returns `[]` when neither
- [x] 113. `getThemePrefScheme()` applies filter `fluent_cart/theme_pref` returning `['colors' => […], 'font_sizes' => […]]`
- [x] 114. `getThemePrefScheme()` caches result in static var (subsequent calls do not re-apply filter)

## Service: ConditionPresets

- [x] 115. `all()` returns 12 presets covering: is_digital_order, is_physical_order, is_order_paid, is_order_pending, is_order_failed, has_discount, has_shipping, has_tax, has_refund, has_shipping_tax, has_order_note, has_downloads
- [x] 116. Each preset has keys: id, label, hint, shortcode, condition, compareValue
- [x] 117. `is_digital_order` preset: shortcode=`{{order.is_digital}}`, condition=`equal`, compareValue=`yes`
- [x] 118. `has_discount` preset: shortcode=`{{order.discount_total}}`, condition=`greater_than`, compareValue=`0`
- [x] 119. Filter `fluent_cart/condition_presets` mutates result of `all()`
- [x] 120. `find('is_order_paid')` returns the matching preset; `find('unknown')` returns null
- [x] 121. `forJs()` returns array of `{id, label, hint}` only — strips shortcode/condition/compareValue
- [x] 122. `resolve('is_order_paid')` returns array with `shortcode`, `condition`, `compareValue` keys
- [x] 123. `resolve()` returns `['callback' => callable]` when preset defines a callable callback
- [x] 124. `resolve('unknown')` returns null

## Service: ShortcodeTemplateBuilder

- [x] 125. `ShortcodeTemplateBuilder::make($template, $data)` instantiates SmartCodeParser and returns parseTemplate result
- [x] 126. `makeFromTemplates([$tplA, $tplB], $data)` concatenates parsed output of each template
- [x] 127. `makeFromTemplatesArray()` recursively parses every string entry, preserving array shape

## Service: SmartCodeParser

- [x] 128. `parseTemplate("hello {{user.user_email}}", $data)` resolves the smartcode against UserParser
- [x] 129. Template wrapper resolved from config `template_string` ("{{:template:}}") yielding start `{{` end `}}`
- [x] 130. `##code##` syntax also matched (alternate delimiter pair)
- [x] 131. Empty `{{}}` falls through filter `fluent_cart/smartcode_fallback`
- [x] 132. Unknown parser group returns code unchanged (preserves original `{{…}}` text)
- [x] 133. Single-segment code like `{{foo}}` (no dot) routes through `fluent_cart/smartcode_fallback`
- [x] 134. `getParseHandlerReference('order.id')` returns parser=`order`, accessor=`id`, handler=`OrderParser::class`
- [x] 135. `getParseHandlerReference()` caches lookup in static `$reference` array
- [x] 136. `parseCode()` calls `transform()` to apply pipe transformers/defaults
- [x] 137. `wrapSmartCode('order.id')` returns `'{{order.id}}'`

## ValueTransformer

- [x] 138. `evaluateCondition('order.status||title_case|fallback')` returns `transformer='title_case'`, `default_value='fallback'`, `accessor='order.status'`
- [x] 139. `evaluateCondition('foo|bar')` returns `default_value='bar'`, transformer=null
- [x] 140. Transformer `trim`, `ucfirst`, `strtolower`, `strtoupper`, `ucwords` invoke matching PHP function on value
- [x] 141. Transformer `concat_first` prepends default to value (`Hi ` + name) only when value non-empty
- [x] 142. Transformer `concat_last` appends default after value only when value non-empty
- [x] 143. Transformer `title_case` returns `Str::title($value)`; empty value returns unchanged
- [x] 144. Transformer `headline` returns `Str::headline($value)`
- [x] 145. Transformer `show_if` returns default when value non-empty, else empty string
- [x] 146. Empty value with non-empty default substitutes default; empty value with empty default applies `smartcode_fallback` filter

## Parsers

### OrderParser
- [x] 147. Constructor reads `data.order` and `data.order.config.user_tz` (defaults to 'UTC' when timezone invalid)
- [x] 148. `parse('billing.first_name')` delegates to `parseAddressFields()` returning billing address field
- [x] 149. `parse('shipping.city')` returns formatted city when address has `getFormattedAddress()` method
- [x] 150. `parse('created_at')` formats date via `wp_date(date_format, ts, orderTz)` and runs `Helper::translateNumber`
- [x] 151. Cent column accessors (total_amount, subtotal, manual_discount_total, coupon_discount_total, shipping_tax, shipping_total, fee_total, tax_total, total_paid, total_refund, discount_tax) return `amount/100` as string
- [x] 152. `_formatted` suffix on cent column returns `CurrencySettings::getPriceHtml(amount, currency)`
- [x] 153. `methodMap['item_count']` returns string count of `order.order_items`; `0` when empty
- [x] 154. `methodMap['is_digital']` returns `'yes'` when fulfillment_type='digital', else `'no'`
- [x] 155. `attributeMap` resolves id, status, created_at, updated_at from `order.*` paths
- [x] 156. `parse('order.items_table')` (htmlParsers) renders `emails.parts.items_table` view with order + items
- [x] 157. `parse('order.subscription_details')` renders `invoice.parts.subscription_items` view when order has subscriptions; '' otherwise
- [x] 158. `parse('order.license_details')` renders `emails.parts.licenses` view when order has licenses; '' otherwise
  - Skipped: `Order::getLicenses()` depends on `FluentCartPro\App\Modules\Licensing\Models\License` (Pro). Class not loaded in core test env.
- [x] 159. `parse('order.download_details')` renders `emails.parts.downloads` when order has downloads; '' otherwise
- [x] 160. `parse('order.address_details')` renders `emails.parts.addresses` view
- [x] 161. `parse('order.payment_summary')` renders items_table without heading
- [x] 162. `parse('order.payment_receipt')` renders concatenated items_table + subscriptions + licenses + downloads + addresses with `<hr />`
  - Skipped: same Pro Licensing dependency as 158.
- [x] 163. `getDiscountTotal()` returns `(coupon_discount_total + manual_discount_total)/100`
- [x] 164. `getDiscountTotalFormatted()` returns CurrencySettings::getPriceHtml of summed cents
- [x] 165. `getOrderRef()` returns invoice_no when present, else order uuid
- [x] 166. `getCustomerDashboardLink()` returns plain link when user logged in; `wp_login_url($orderLink)` when guest
- [x] 167. `getCustomerDashboardAnchorLink()` returns `<a href=…>{defaultValue or invoice_no}</a>` when profile page configured
- [x] 168. `getAdminOrderLink()` returns `admin_url('admin.php?page=fluent-cart#/orders/{id}/view')`
- [x] 169. `getDownloads()` returns rendered downloads view; '' when no downloads
- [x] 170. `getLicenses()` returns rendered licenses view; '' when none
  - Skipped: same Pro Licensing dependency as 158.

### SettingsParser
- [x] 171. `parse('store_name')` returns `StoreSettings::get('store_name')`
- [x] 172. `parse('store_logo')` returns `<img style='max-height: 50px' src='{url}' alt='{name}'>` when logo url set; empty string when not set
- [x] 173. `parse('store_brand')` returns store name when no logo, else logo HTML
- [x] 174. `parse('store_country')` returns country NAME via `AddressHelper::getCountryNameByCode($code)`
- [x] 175. `parse('store_state')` returns state NAME via `AddressHelper::getStateNameByCode($state, $country)`
- [x] 176. `parse('store_address')` returns `store_address1`; `parse('store_address2')` returns `store_address2`
- [x] 177. `parse('store_city')` / `parse('store_postcode')` return matching settings keys

### WPParser
- [x] 178. `parse('site_url')` returns `get_bloginfo('url')`
- [x] 179. `parse('url')` aliased to `getSiteUrl()`
- [x] 180. `parse('admin_email')` returns `get_bloginfo('admin_email')` (resolved via getAdminEmail when no method map)
- [x] 181. `parse('site_name')` returns `get_bloginfo('name')`

### UserParser
- [x] 182. Constructor reads `data.user_id` first, falls back to `data.customer.user_id`
- [x] 183. `parse('first_name')` / `parse('last_name')` read from `get_user_meta($uid, $key, true)` (NOT `WP_User->{key}`)
- [x] 184. `parse('display_name')` / `parse('user_email')` / `parse('email')` read directly from `WP_User` instance
- [x] 185. Returns '' when user does not exist (`get_user_by` returned false)
- [ ] 186. `methodMap` exposes admin_email, site_url, site_name (via `get…` methods) �
  - Skipped: core gap. `UserParser::$methodMap` declares `admin_email`/`site_url`/`site_name` -> `getAdminEmail`/`getSiteUrl`/`getSiteName`, but those getters are NOT defined on UserParser (they live on WPParser). `parse()` routes through `BaseParser::getByMethod()` and produces a fatal "Call to undefined method UserParser::getAdminEmail()". Either add the getters or remove the methodMap entries.

### TransactionParser
- [x] 187. Returns `$code` unchanged when transaction missing
- [x] 188. `parse('total')` returns `total/100` as string when numeric
- [x] 189. `parse('total_formatted')` returns `CurrencySettings::getPriceHtml(total, currency)`
- [x] 190. `getRefundAmount()` returns `total/100`; `getRefundAmountFormatted()` returns price HTML

### ItemParser
- [x] 191. Constructor reads `data.item` and `data.order`
- [x] 192. `parse('name')` returns `item.post_title` when set, else `item.title` (esc_html)
- [x] 193. `parse('variant')` returns `item.title` (esc_html)
- [x] 194. `parse('quantity')` returns `item.quantity`
- [x] 195. `parse('price')` returns `item.line_total/100`; `parse('price_formatted')` returns `item.formatted_total`
- [x] 196. `parse('unit_price')` returns `item.unit_price/100`; `_formatted` returns `Helper::toDecimal()` esc_html
- [x] 197. `parse('subtotal')` returns `item.subtotal/100`; `_formatted` returns Helper::toDecimal esc_html
- [x] 198. `parse('payment_info')` returns `wp_kses_post(item.payment_info)`
- [x] 199. Unknown accessor falls through to `Arr::get(item, accessor, '')`

### SubscriptionParser
- [x] 200. Returns '' when subscription missing
- [x] 201. `parse('item_name')` returns esc_html(`subscription.item_name`)
- [x] 202. `parse('status')` / `parse('billing_interval')` return esc_html of corresponding fields
- [x] 203. `parse('recurring_amount')` returns `recurring_amount/100`; '0' when zero
- [x] 204. `parse('recurring_amount_formatted')` returns `Helper::toDecimal(recurring_amount)`
- [x] 205. `parse('next_billing_date')` returns `'M j, Y'` formatted date; 'N/A' when null
- [x] 206. `parse('bill_times')` returns 'Unlimited' when null, else esc_html value
- [x] 207. `parse('trial_days')` returns esc_html(value) or '0'
- [x] 208. `parse('expire_at')` returns formatted date or 'Never'
- [x] 209. Unknown accessor falls through to `Arr::get((array) subscription, accessor, '')`

### LicenseParser
- [x] 210. Returns '' when license missing
- [x] 211. `parse('key')` returns esc_html(`license.license_key`)
- [x] 212. `parse('product_name')` returns related product post_title; '' when no product
- [x] 213. `parse('variant')` returns variant variation_title; '' when none
- [x] 214. `parse('limit')` returns `limit` when set, else 'Unlimited'
- [x] 215. `parse('expiration_date')` returns `'M j, Y'` formatted date; 'Never' when null

### DownloadParser
- [x] 216. `parse('title')` returns esc_html(`download.title`)
- [x] 217. `parse('product_name')` returns esc_html(`download.product_name`)
- [x] 218. `parse('url')` returns esc_url(`download.download_url`)
- [x] 219. `parse('file_size')` returns esc_html(`download.formatted_file_size`)

## REST API: /email-notification

### Routes & policy
- [x] 220. All routes registered under prefix `email-notification` with policy `StoreSensitivePolicy`
- [x] 221. `StoreSensitivePolicy::verifyRequest()` requires capability `store/sensitive` (super admin)
- [x] 222. Policy fires `fluent_cart/policy/store_sensitive_request` action with request
- [x] 223. GET `/email-notification` → returns `{data: notifications}` from `EmailNotifications::getNotifications()`
- [x] 224. GET `/email-notification/get-short-codes` → returns `{data: {email_templates, shortcodes, buttons}}`
- [x] 225. GET `/email-notification/get-settings` → returns `{data: settings, shortcodes}`
- [x] 226. POST `/email-notification/save-settings` validates EmailSettingsRequest (from_name, from_email required + email-format)
- [x] 227. POST `/save-settings` forces `show_email_footer='yes'` when `App::isProActive()` is false
- [x] 228. GET `/email-notification/reminders` → returns `{settings, fields: {reminders: …}}` from StoreSettings
- [x] 229. POST `/email-notification/reminders` validates SchedulingSettingsRequest, merges with existing, persists
- [x] 230. POST `/email-notification/enable-notification/{name}` updates notification with `{active: <yes|no>}`
- [x] 231. POST `/email-notification/send-manual-reminder` reaches `ReminderService::sendManualReminder` — an unknown order id returns the service's 422 "Order or customer not found" error
- [x] 232. `send-manual-reminder` returns 422 with the required-fields message when `event` or `entity_id` empty
- [x] 233. POST `/email-notification/preview-default-template` resolves template via TemplateService, wraps with general_template, runs ShortcodeTemplateBuilder, appends pointer-events:none CSS — and renders 200 for EVERY registered notification template_path
- [x] 234. GET `/email-notification/{notification}` returns `{data, shortcodes, has_fluent_pdf, fonts_ready, setup_url, pdf_templates}`
- [x] 235. GET `/{notification}` returns a 422 "Notification Details not found" error when notification name not found
  - Unskipped (TC-013): `rebuildRestServerPerDispatch` defeats WPFluent's stale route-param cache; asserts known-200 + unknown-422 with no payload leak.
- [x] 236. GET `/{notification}` returns `has_fluent_pdf=true` only when `FLUENT_PDF` constant defined
- [x] 237. PUT `/email-notification/{notification}` validates EmailNotificationRequest (subject required, maxLength 255)
- [x] 238. PUT applies filter `fluent_cart/prepare_email_template_data` (free strips email_body + forces is_default_body='yes')
- [x] 239. PUT of an unknown notification returns 422 "Failed to update notification" (the config write still lands before the falsy lookup — pinned as current behavior)
- [x] 240. A submitted `email_body` payload is never persisted in free core (controller strips it and forces `is_default_body='yes'`, which clears the stored body)
- [x] 241. `EmailSettingsRequest` rejects a malformed `from_email` at the `email` validation rule with 422 and persists nothing

## Permissions

- [x] 242. Unauthenticated request to `/email-notification` returns 401 (no capability)
  - Now executable: `Helper::getCurrentUser()` exposes a `$refresh` arg + `RestTestCase::dispatchRequestAsGuest()` invalidates the static cache before dispatch. Test asserts denial status (401/403) AND that the response payload does not leak `order_paid_customer` notification config.
- [x] 243. Subscriber without `store/sensitive` capability returns 403
  - Now executable via `dispatchRequestAs($subscriberId)`; same denial assertions as 242.
- [x] 244. Administrator with `manage_options` is allowed (super admin shim)
- [x] 245. User with explicit `store/sensitive` capability is allowed even without admin role

## Filters & Actions (exhaustive)

- [x] 246. Filter `fluent_cart/email_notifications` — applied in `EmailNotifications::getNotifications()`
- [x] 247. Filter `fluent_cart/keep_email_body_draft` — preserves email_body on default switch
- [x] 248. Filter `fluent_cart/should_send_email_notification` — short-circuits per-notification dispatch
- [x] 249. Filter `fluent_cart/parse_email_block_content` — pro-only block content parser
- [x] 250. Filter `fluent_cart/render_block_email_template` — pro-only block email template renderer
- [x] 251. Filter `fluent_cart/prepare_email_template_data` — strips/restores email_body on update (free vs pro)
- [x] 252. Filter `fluent_cart/condition_presets` — appends/modifies condition presets
- [x] 253. Filter `fluent_cart/theme_pref` — overrides BlockEditorHelper theme preference scheme
- [x] 254. Filter `fluent_cart/smartcode_fallback` — fallback for unrecognized smartcodes
- [x] 255. Action `fluent_cart/policy/store_sensitive_request` — fired by StoreSensitivePolicy

## Edge Cases

- [x] 256. Notification with `recipient='admin'` and empty `admin_email` setting is silently skipped (no exception)
- [x] 257. `mailEmailsOfEvent()` with empty `notifications` for the event is a no-op (no Mailer instantiated)
- [x] 258. `Mailer::send()` short-circuits to false (no `wp_mail` call) when no recipient — verify via mock action `wp_mail`
- [x] 259. Backward compat: persisted config with `attach_pdf='yes'` AND no `attach_pdf_template` migrates to `'order_receipt'` exactly once per `getNotifications()` call
- [x] 260. `updateNotification()` with `is_default_body='yes'` AND filter returning true KEEPS existing email_body (draft mode)
- [x] 261. SmartCodeParser handles nested mixed delimiters: `{{order.id}} ##settings.store_name##` resolves both
- [x] 262. ShortcodeTemplateBuilder with empty data array returns template unchanged for unresolvable codes (preserves original `{{…}}`)
- [x] 263. OrderParser with order.config.user_tz = 'Invalid/Zone' falls back to UTC (no PHP warning)
- [x] 264. `EmailPreviewService::getPreviewData()` returns deterministic dummy data — no DB lookups required
- [x] 265. `BlockEditorHelper::getThemePrefScheme()` cache survives within a single request only (static var, not persisted)
- [x] 266. PDF attachment cleanup: `unlink()` called even when `wp_mail` returns false
- [x] 267. `formatNotification()` falls back to default body when `email_body` empty even though `is_default_body='no'`

## Factories Needed

- `OrderFactory` (exists) — required for order/transaction-driven event payloads
- `CustomerFactory` (exists) — required for customer-recipient resolution
- `SubscriptionFactory` (exists) — required for subscription_* event tests
- `EmailNotificationConfigFactory` (NEW) — seeds `fct_meta` row with object_type='email_notification', meta_key=EMAIL_NOTIFICATIONS_CONFIG_META_KEY, meta_value=<config array>
- `WpUserFactory` — wrapper around `static::factory()->user->create()` for UserParser/permission tests
- No new model factories required for parser-only tests (parsers operate on plain arrays/Models)
