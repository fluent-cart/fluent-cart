# Orders

## Model: Order

### Columns & Casts
- [x] 1. Has columns: id, uuid, status, parent_id, invoice_no, receipt_number, fulfillment_type, type, customer_id, payment_method, payment_method_title, payment_status, currency, subtotal, discount_tax, manual_discount_total, coupon_discount_total, shipping_tax, shipping_total, fee_total, tax_total, tax_behavior, total_amount, rate, note, ip_address, completed_at, refunded_at, total_refund, total_paid, mode, shipping_status, config, created_at, updated_at
- [x] 2. Monetary columns (subtotal, total_amount, shipping_total, tax_total, fee_total, discount_tax, manual_discount_total, coupon_discount_total, shipping_tax) are cast to double
- [x] 3. customer_id is cast to integer
- [x] 4. config attribute is JSON-encoded on set and JSON-decoded on get (empty/null returns [])

### Boot Behavior
- [x] 5. Creating an order auto-generates a uuid — 12 uppercase alphanumeric characters since 47e5be462 (legacy orders keep their 32-char md5) — when uuid is empty
- [x] 6. Creating an order with payment_status='paid' auto-assigns receipt_number and invoice_no
- [x] 7. Creating an order with payment_status='pending' does NOT auto-assign receipt_number
- [x] 8. Creating an order with empty config defaults config to []
- [x] 9. After order created with invoice_no set, fires `fluent_cart/order/invoice_number_added` action

### Relationships
- [x] 10. Order belongsTo Customer via customer_id — verify loaded relation returns correct Customer
- [x] 11. Order hasMany OrderItem via order_id — verify collection returned
- [x] 12. Order hasMany OrderTransaction via order_id — verify collection returned
- [x] 13. Order hasMany Subscription via parent_order_id — verify collection returned
- [x] 14. Order hasOne OrderAddress (type=billing) via billing_address() — verify type filter works
- [x] 15. Order hasOne OrderAddress (type=shipping) via shipping_address() — verify type filter works
- [x] 16. Order hasMany OrderAddress via order_addresses() — returns both billing and shipping
- [x] 17. Order hasMany OrderMeta via order_id
- [x] 18. Order hasMany AppliedCoupon via order_id
- [x] 19. Order hasManyThrough Coupon via AppliedCoupon (usedCoupons)
- [x] 20. Order belongsTo Order (self-referential) via parent_id (parentOrder)
- [x] 21. Order hasMany Order (self-referential) via parent_id (children)
- [x] 22. Order hasMany Order via renewals() — filters type='renewal' and excludes canceled/failed/on-hold
- [x] 23. Order morphMany LabelRelationship via labels()
- [x] 24. Order hasOne OrderOperation via order_id
- [x] 25. Order hasMany OrderItem via feeItems() — filters payment_type='fee' only

### Scopes
- [x] 26. searchBy() finds orders by id, status, total_amount (in cents), payment_status, payment_method, invoice_no
- [x] 27. searchBy() finds orders by related order_items.post_title or order_items.title
- [x] 28. searchBy() finds orders by related customer email, first_name, last_name (multi-word search)
- [x] 29. searchBy() with empty/blank string returns unfiltered query
- [x] 30. ofPaymentStatus() filters by payment_status column
- [x] 31. ofOrderStatus() filters by status column
- [x] 32. ofShippingStatus() filters by shipping_status column
- [x] 33. ofPaymentMethod() filters by payment_method column
- [x] 34. applyCustomFilters() applies 'in' operator for each filter key that is in fillable

### Status Methods
- [x] 35. updateStatus('status', 'completed') sets completed_at to current GMT time
- [x] 36. updateStatus('payment_status', 'refunded') sets refunded_at to current GMT time
- [x] 37. updateStatus() with same old and new status returns order unchanged (no save)
- [x] 38. updatePaymentStatus('refunded') sets refunded_at and saves
- [x] 39. updatePaymentStatus() with same status returns order unchanged

### Meta Methods
- [x] 40. getMeta() returns meta_value for existing meta_key
- [x] 41. getMeta() returns default value when meta_key does not exist
- [x] 42. updateMeta() creates new OrderMeta when key does not exist
- [x] 43. updateMeta() updates existing OrderMeta when key already exists
- [x] 44. deleteMeta() removes the OrderMeta row for the given key

### Financial Methods
- [x] 45. getTotalPaidAmount() returns sum of transactions where status='succeeded'
- [x] 46. getTotalRefundAmount() returns sum of transactions where status='refunded'
- [x] 47. recountTotalPaidAndRefund() sets payment_status='refunded' when refunded >= paid
- [x] 48. recountTotalPaidAndRefund() sets payment_status='partially_refunded' when paid > refunded > 0
- [x] 49. syncOrderAfterRefund('full', amount) sets payment_status='refunded' and adds to total_refund
- [x] 50. syncOrderAfterRefund('partial', amount) sets payment_status='partially_refunded'
- [x] 51. recountTotalPaid() sets total_paid = sum of succeeded/authorized charges, without subtracting refunds
- [x] 52. updateRefundedItems() with 1 item adds refundedAmount to that item's refund_total
- [x] 53. updateRefundedItems() with multiple items distributes refund proportionally based on remaining (line_total - refund_total)
- [x] 54. updateRefundedItems() with 0 item_ids distributes across all order items

### Receipt Number Generation
- [x] 55. generateReceiptNumber() assigns receipt_number and invoice_no with correct prefix
- [x] 56. generateReceiptNumber() is idempotent — calling twice doesn't change the number
- [x] 57. generateReceiptNumber() uses atomic update (WHERE receipt_number IS NULL) to prevent race conditions

### Type Checks & Utility
- [x] 58. isSubscription() returns true when order has items with payment_type='subscription'
- [x] 59. isSubscription() returns false for onetime-only orders
- [x] 60. canBeRefunded() returns false when config.upgraded_to is set
- [x] 61. canBeRefunded() returns true for normal orders without upgrade
- [x] 62. getProductItems() excludes items with payment_type 'fee' and 'signup_fee'
- [x] 63. getAppliedFees() returns array of fee items with key, label, amount, source, item_id

### Deletion Rules (canBeDeleted)
- [x] 64. canBeDeleted() returns true for canceled order with no paid amount
- [x] 65. canBeDeleted() returns true for on-hold order with no paid amount
- [x] 66. canBeDeleted() returns WP_Error for processing/completed order
- [x] 67. canBeDeleted() returns WP_Error for canceled order that has been paid (non-zero total_amount, paid payment_status)
- [x] 68. canBeDeleted() returns WP_Error for subscription order with active subscription
- [x] 69. canBeDeleted() returns true for test mode orders regardless of status
- [x] 70. canBeDeleted() returns true for free orders (total_amount=0) even if payment_status is 'paid'

## Model: OrderItem

### Columns & Casts
- [x] 71. Has columns: id, order_id, post_id, fulfillment_type, fulfilled_quantity, post_title, title, object_id, cart_index, quantity, unit_price, cost, subtotal, tax_amount, discount_total, refund_total, line_total, rate, other_info, line_meta, referrer, object_type, payment_type, created_at
- [x] 72. Monetary columns (unit_price, cost, subtotal, tax_amount, discount_total, line_total, refund_total, shipping_charge) cast to double
- [x] 73. other_info is JSON-encoded on set and JSON-decoded on get (string returns decoded array, non-string returns [])
- [x] 74. line_meta is JSON-encoded on set and JSON-decoded on get

### Appended Attributes
- [x] 75. formatted_total returns Helper::toDecimal(line_total); falls back to Helper::toDecimal(subtotal) only when line_total and discount_total are both zero (spec corrected 2026-07-22, TC-021 — accessor `OrderItem::getFormattedTotalAttribute`)
- [x] 76. payment_info returns subscription info string for subscription items, empty string for onetime
- [x] 77. setup_info returns setup fee info string for subscription items, empty string for onetime
- [x] 78. is_custom returns other_info['is_custom'] value or false
- [x] 79. full_name returns title + ' ' + post_title

### Relationships
- [x] 80. OrderItem belongsTo Order via order_id
- [x] 81. OrderItem belongsTo Product via post_id
- [x] 82. OrderItem belongsTo ProductVariation via object_id (variants)

## Model: OrderAddress

### Columns & Attributes
- [x] 83. Has columns: id, order_id, type, name, address_1, address_2, city, state, postcode, country, meta
- [x] 84. meta is JSON-encoded on set and JSON-decoded on get
- [x] 85. first_name returns first word of name
- [x] 86. last_name returns last word of name
- [x] 87. full_name returns name
- [x] 88. company_name is stored in meta.other_data.company_name
- [x] 89. phone is stored in meta.other_data.phone
- [x] 90. label is stored in meta.other_data.label

### Relationships
- [x] 91. OrderAddress belongsTo Order via order_id

### Address Formatting
- [x] 92. getFormattedAddress() returns array with country name (not code), state name, and all fields
- [x] 93. getAddressAsText() returns comma-separated address string

## Model: OrderTransaction

### Columns & Boot
- [x] 94. Has columns: id, order_id, order_type, vendor_charge_id, payment_method, payment_mode, payment_method_type, currency, transaction_type, subscription_id, card_last_4, card_brand, status, total, rate, meta, uuid, created_at, updated_at
- [x] 95. Creating a transaction auto-generates uuid when empty
- [x] 96. meta is JSON-encoded on set and JSON-decoded on get

### Relationships
- [x] 97. OrderTransaction belongsTo Order via order_id
- [x] 98. OrderTransaction hasOne Subscription via subscription_id

### Methods
- [x] 99. updateStatus() changes status and saves; with same status returns unchanged
- [x] 100. updateStatus() with otherData merges additional fields before save
- [x] 101. getMaxRefundableAmount() returns (total - meta.refunded_total) for succeeded transactions
- [x] 102. getMaxRefundableAmount() returns 0 for non-succeeded transactions
- [x] 103. getPaymentMethodText() returns "brand ****last4" when card_brand and card_last_4 are set
- [x] 104. getPaymentMethodText() returns payment_method when card info is not set
- [x] 105. bulkDeleteByOrderIds() deletes all transactions for given order IDs

### Scopes
- [x] 106. ofStatus() filters by status column
- [x] 107. ofPaymentMethod() filters by payment_method column

## Model: OrderOperation

- [x] 108. Has columns: id, order_id, created_via, has_tax, has_discount, coupons_counted, emails_sent, sales_recorded, utm_campaign, utm_term, utm_source, utm_content, utm_medium, utm_id, cart_hash, refer_url
- [x] 109. OrderOperation belongsTo Order via order_id
- [x] 110. meta attribute is JSON-encoded on set and decoded on get

## Model: OrderMeta

- [x] 111. Has columns: id, order_id, meta_key, meta_value
- [x] 112. meta_value is JSON-encoded for arrays/objects on set and JSON-decoded on get (plain strings returned as-is)
- [x] 113. OrderMeta belongsTo Order via order_id
- [x] 114. updateMeta() creates new meta when key doesn't exist, updates when it does

## Service: OrderService

### Data Grouping & Sanitization
- [x] 115. groupSanitizedData() separates billing_ and shipping_ prefixed fields into separate address arrays
- [x] 116. groupSanitizedData() copies billing address to shipping when ship_to_different='no'
- [x] 117. sanitizeEmailOrText() uses sanitize_email for 'email' key, sanitize_text_field for others

### Product Validation
- [x] 118. validateProducts() throws Exception when product variation is not found or product is not published/private
- [x] 119. validateProducts() throws Exception when subscription product has quantity > 1
- [x] 120. validateProducts() throws Exception when product is out of stock (stock_management enabled, manage_stock=1, stock_status != 'in-stock')
- [x] 121. validateProducts() throws Exception when requested quantity exceeds available stock
- [x] 122. validateProducts() skips stock validation when stock is already on_hold for the order (prevOrder with stock_movement)
- [x] 123. validateProducts() skips custom items (is_custom=true)

### Amount Calculations
- [x] 124. getItemsAmountWithoutDiscount() sums (unit_price * quantity) for onetime items
- [x] 125. getItemsAmountWithoutDiscount() for subscription items includes signup_fee
- [x] 126. getItemsAmountWithoutDiscount() for subscription with trial_days > 0 returns only signup_fee
- [x] 127. getItemsAmountTotal() subtracts discount_total from subtotal for each item
- [x] 128. getItemsAmountTotal() for subscription with trial_days > 0 charges (signup_fee - discount_total)
- [x] 129. getItemsAmountTotal() adds shipping_total to final result
- [x] 130. getItemsAmountTotal() clamps result to minimum 0 (non-negative)
- [x] 131. getItemsAmountTotal() with formatted=true returns Helper::toDecimal() string
- [x] 132. getCouponDiscountTotal() sums discount_total across all items

### Line Item Preparation
- [x] 133. makeLineItemsFromOrderItems() separates subscription items from regular items
- [x] 134. makeLineItemsFromOrderItems() prepares subscription items with billing_interval, signup_fee, bill_times, trial_days
- [x] 135. makeLineItemsFromOrderItems() prepares regular items with product_id, unit_price, quantity, line_total

### Utility
- [x] 136. getNextReceiptNumber() returns max(receipt_number) + 1
- [x] 137. getNextReceiptNumber() respects min_receipt_number setting (uses the higher value)
- [x] 138. getInvoicePrefix() returns configured prefix (default 'INV-')
- [x] 139. getRootOrderId() follows parent_id chain to find root order with no parent
- [x] 140. getRootOrderId() breaks infinite loops by tracking visited IDs
- [x] 141. pluckProductIds() returns array of post_id values from order items

## Service: Refund

- [x] 142. processRefund() with refundAmount <= 0 returns WP_Error 'invalid_refund_amount'
- [x] 143. processRefund() with refundAmount > net paid amount returns WP_Error
- [x] 144. processRefund() with refundAmount > transaction maxRefundableAmount returns WP_Error
- [x] 145. processRefund() creates a new OrderTransaction with transaction_type='refund', status='refunded'
- [x] 146. processRefund() updates parent transaction meta.refunded_total
- [x] 147. processRefund() dispatches OrderRefund event
- [x] 148. processRefund() calls gateway processRefund when manual_refund filter is not 'yes'
- [x] 149. createOrRecordRefund() returns existing refund if vendor_charge_id matches
- [x] 150. createOrRecordRefund() updates local refund with vendor_charge_id when total and parent match
- [x] 151. recordRefund() with empty or zero total returns WP_Error

## Resource: OrderResource

### CRUD
- [x] 152. create() calculates subtotal from item amounts, totalAmount from subtotal + tax + shipping - discounts
- [x] 153. create() sets payment_status='paid' when totalAmount is 0, 'pending' otherwise
- [x] 154. create() sets status='on-hold' for new orders
- [x] 155. create() determines fulfillment_type='physical' if any item has physical fulfillment, else 'digital'
- [x] 156. create() generates invoice_no using prefix + receipt_number + suffix

- [x] 157. updatedPlaceOrder() validates products before creating order
- [x] 158. updatedPlaceOrder() distributes manual discount proportionally across items
- [x] 159. updatedPlaceOrder() creates order addresses from customer billing/shipping addresses
- [x] 160. updatedPlaceOrder() dispatches OrderCreated event after success

- [x] 161. update() rejects update for completed or canceled orders
- [x] 162. update() returns 404 error for non-existent order
- [x] 163. update() deletes specified order items from deletedItems array
- [x] 164. update() dispatches OrderUpdated event on successful update

- [x] 165. view() returns order with customer, order_items, transactions, addresses, subscriptions, labels
- [x] 166. view() returns WP_Error for non-existent order

### Status Updates
- [x] 167. updateStatuses() with change_order_status updates order status and dispatches OrderStatusUpdated
- [x] 168. updateStatuses() with change_shipping_status updates shipping status and dispatches OrderStatusUpdated
- [x] 169. updateStatuses() rejects status change from 'canceled' (cannot change once canceled)
- [x] 170. updateStatuses() returns error when new status equals old status
- [x] 171. updateStatuses() returns error for invalid status value
- [x] 172. updateStatuses() for shipping status change updates fulfilled_quantity on physical order items

### Deletion
- [x] 173. delete() calls canBeDeleted() and returns WP_Error when not deletable
- [x] 174. delete() cascades deletion to: transactions, addresses, items, meta, tax_rates, operations, applied_coupons, carts, download_permissions, label_relationships
- [x] 175. delete() for subscription orders also deletes child orders and subscriptions
- [x] 176. delete() dispatches OrderDeleting before data removal and OrderDeleted after
- [x] 177. delete() for renewal orders dispatches RenewalOrderDeleted event
- [x] 178. delete() wraps in transaction and rolls back on failure

- [x] 179. bulkDeleteByOrderIds() iterates over order IDs and deletes each, collecting failed/succeeded

## REST API: /fluent-cart/v2/orders

### GET /orders (index)
- [x] 180. Returns paginated order list with customer and filteredOrderItems relations
- [x] 181. Accepts per_page, page, sort, and filter parameters
- [x] 182. Permission: requires 'orders/view'

### POST /orders (store)
- [x] 183. Creates order from request data and returns order_id and uuid
- [x] 184. Dispatches OrderCreated event
- [x] 185. Rejects subscription items in manual orders (returns 400) unless filtered
- [x] 186. Permission: requires 'orders/create'

### GET /orders/{order_id} (getDetails)
- [x] 187. Returns full order view with customer, items, transactions, addresses, subscriptions, labels, operation
- [x] 188. Returns 404-style error for non-existent order
- [x] 189. Permission: requires 'orders/view'

### POST /orders/{order_id} (updateOrder)
- [x] 190. Updates order data, items, discount, shipping, and coupons
- [x] 191. Rejects subscription order updates (returns 400)
- [x] 192. Rejects setting status to 'completed' directly (returns 400)
- [x] 193. Adjusts total_amount when shipping_total changes
- [x] 194. Sets payment_status='partially_paid' when total_paid > 0 and updatedTotal > totalPaid
- [x] 195. Permission: requires 'orders/manage'

### POST /orders/{order_id}/refund (refundOrder)
- [x] 196. Validates required fields: transaction_id, amount
- [x] 197. Converts amount to cents via Helper::toCent()
- [x] 198. Returns refund result with fluent_cart_refund and gateway_refund status
- [x] 199. Rejects refund when order canBeRefunded() returns false
- [x] 200. Optionally cancels subscription when cancelSubscription='true'
- [x] 201. Permission: requires 'orders/can_refund'

### POST /orders/{order}/mark-as-paid (markAsPaid)
- [x] 202. Creates or updates transaction with status='succeeded' and total = due amount
- [x] 203. Sets order payment_status='paid' and status='processing'
- [x] 204. For digital orders, auto-completes order (status='completed', completed_at set)
- [x] 205. Returns error when order has no due amount (already paid)
- [x] 206. Returns error when order status is 'canceled'
- [x] 207. Dispatches OrderPaid and OrderStatusUpdated events
- [x] 208. Permission: requires 'orders/manage'

### PUT /orders/{order}/statuses (updateStatuses)
- [x] 209. Updates order status or shipping status based on action parameter
- [x] 210. Permission: requires 'orders/manage_statuses'

### DELETE /orders/{order_id} (deleteOrder)
- [x] 211. Deletes order and all related data when canBeDeleted() passes
- [x] 212. Returns error with reason when canBeDeleted() fails
- [x] 213. For subscription orders, deletes child orders and subscriptions in transaction
- [x] 214. Dispatches appropriate event (OrderDeleted or RenewalOrderDeleted)
- [x] 215. Permission: requires 'orders/delete'

### POST /orders/{order_id}/change-customer (changeCustomer)
- [x] 216. Changes customer on order and all connected orders (children, renewals)
- [x] 217. Updates subscription customer_id for connected orders
- [x] 218. Recounts stats on both old and new customer
- [x] 219. Returns error when customer_id is missing
- [x] 220. Returns message when customer_id is already the same
- [x] 221. Permission: requires 'orders/manage'

### POST /orders/do-bulk-action (handleBulkActions)
- [x] 222. delete_orders: calls bulkDeleteByOrderIds for selected order IDs
- [x] 223. change_order_status: validates status, updates each order, collects failed IDs
- [x] 224. change_shipping_status: validates status against allowed shipping statuses
- [x] 225. Returns error when no order_ids provided
- [x] 226. Permission: requires 'orders/manage'

### Other Endpoints
- [x] 227. PUT /orders/{order}/address/{id} — updates order address fields; permission: 'orders/manage'
- [x] 228. PUT /orders/{order}/transactions/{transaction}/status — updates transaction status; permission: 'orders/manage'
- [x] 229. POST /orders/{order}/create-custom — creates custom order item; permission: 'orders/create'
- [x] 230. POST /orders/calculate-shipping — calculates shipping; permission: 'orders/create' or 'orders/manage'
- [x] 231. PUT /orders/{order}/sync-statuses — syncs order statuses; permission: 'orders/manage'

## Permissions

- [x] 232. Admin (super_admin) can access all order endpoints
- [x] 233. User with 'orders/view' permission can GET /orders and GET /orders/{id} but cannot POST/PUT/DELETE
- [x] 234. User with 'orders/create' permission can POST /orders but cannot delete or manage
- [x] 235. User with 'orders/manage' permission can update orders, change customers, mark as paid, do bulk actions
- [x] 236. User with 'orders/manage_statuses' permission can PUT /orders/{order}/statuses
- [x] 237. User with 'orders/can_refund' permission can POST /orders/{order_id}/refund
- [x] 238. User with 'orders/delete' permission can DELETE /orders/{order_id}
- [x] 239. Unauthenticated request to any order endpoint returns 401
- [x] 240. User without any order permission returns 403

## Events

- [x] 241. OrderCreated event dispatches `fluent_cart/order_created` hook with order data
- [x] 242. OrderPaid event dispatches `fluent_cart/order_paid` hook; triggers receipt number generation, WP user creation
- [x] 243. OrderRefund event dispatches `fluent_cart/order_refunded` (always), `fluent_cart/order_fully_refunded` (full), `fluent_cart/order_partially_refunded` (partial)
- [x] 244. OrderStatusUpdated event dispatches `fluent_cart/order_status_changed_to_{status}` and `fluent_cart/order_status_changed`
- [x] 245. OrderStatusUpdated for shipping dispatches `fluent_cart/shipping_status_changed_to_{status}` and `fluent_cart/shipping_status_changed`
- [x] 246. OrderDeleted event dispatches `fluent_cart/order_deleted` hook
- [x] 247. OrderDeleting event fires before order data is removed (inside transaction)
- [x] 248. OrderUpdated event dispatches `fluent_cart/order_updated` hook
- [x] 249. OrderCanceled event dispatches `fluent_cart/order_status_changed_to_canceled`
- [x] 250. OrderPaymentFailed event dispatches `fluent_cart/order_payment_failed`

## Edge Cases

- [x] 251. Order with mixed physical + digital items gets fulfillment_type='physical'
- [x] 252. Order with only digital items and status='processing' auto-completes to 'completed' on markAsPaid
- [x] 253. Refund distributed proportionally: if item A has $70 remaining and item B has $30, a $50 refund gives A=$35 and B=$15 (last item gets remainder to avoid rounding issues)
- [x] 254. Receipt number generation respects min_receipt_number setting — if max existing is 5 but min_receipt_number is 100, next is 100
- [x] 255. Concurrent receipt number generation — two simultaneous calls should not create duplicate receipt numbers (atomic WHERE NULL update)
- [x] 256. Deleting a parent subscription order cascades to all child renewal orders
- [x] 257. Changing customer on a renewal order also updates the parent order and all sibling renewals
- [x] 258. Order search with numeric term also searches total_amount converted to cents
- [x] 259. Free order (total_amount=0) gets payment_status='paid' immediately on creation
- [x] 260. Manual discount distribution: for 3 items with subtotals $50/$30/$20, a $10 discount distributes as $5/$3/$2 (rounding diff added to first item)
- [x] 261. Calling OrderResource::updatedPlaceOrder() directly without a shipping_total stores zero shipping, not NULL — the resource is a public API and callers that bypass OrderRequest still get a valid cents column
- [x] 262. Calling OrderResource::updatedPlaceOrder() directly with a fractional shipping_total rounds it to the NEAREST cent — neither truncating down nor ceiling up — matching what the request sanitizer does for REST callers
- [x] 263. Passing an order payload through OrderRequest's sanitize map via Request::getSafe() yields shipping_total as a whole-cent INTEGER at the boundary — JS float artifacts round to the nearest cent before any resource or model code runs, and even a clean integer input emerges as an int, not a float; an even-base half (2000.5) rounds UP to 2001, never banker's-rounded back to 2000
- [x] 264. Both direct cents boundaries — Helper::roundCent() reached via OrderRequest's sanitize closure or via OrderResource::updatedPlaceOrder() — REJECT magnitudes beyond float's exact-integer range (|value| > 9.0e15) by throwing InvalidArgumentException instead of letting the int cast wrap 1e19 into a negative-but-valid BIGINT; the resource boundary additionally rejects NEGATIVE shipping (sign checked on the RAW value BEFORE rounding, so -0.4 rejects instead of rounding to 0 and passing as free shipping) and PRESENT non-numeric shipping the same way (REST callers cannot reach either — OrderRequest's numeric/min:0 rules 422 them — so both guards exist purely for direct callers); only an absent or null shipping_total defaults to 0, and roundCent() itself still normalizes non-numeric input to 0 for the omitted-key case

## New scenarios (added 2026-08-14)

<!-- A payment can settle long after its pending transaction row was created
     (payment links, delayed webhooks); created_at is the checkout time, so the
     settlement moment needs its own record. -->

- [x] 265. Marking a pending transaction succeeded stamps the settlement time in meta.settled_at (GMT)
- [x] 266. A transaction that already has meta.settled_at keeps its original value when saved again
- [x] 267. A transaction created directly with status succeeded gets meta.settled_at stamped at creation
- [x] 268. A transaction that stays pending gets no meta.settled_at
- [x] 269. Stripe confirmPaymentSuccessByCharge records the Stripe charge's own created timestamp as meta.settled_at, not the confirmation time

## New scenarios (added 2026-10-06)

<!-- The admin edit screen saves the order without its note (notes have their
     own endpoint), and REST callers may send only the fields they change. -->

- [x] 270. Saving an order from the admin edit screen without a note keeps the stored note
- [x] 271. Saving an order with a note writes it, line breaks included
- [x] 272. Saving an order with a partial payload leaves every unsent column unchanged (uuid, invoice_no, payment_status, ip_address, completed_at, note)
- [x] 273. Saving an order without shipping_total leaves shipping_total and total_amount unchanged
- [x] 274. Attaching a note through notes/attach keeps its line breaks
- [x] 275. Marking an order as paid with a payment note keeps the order note and records the payment note (escaped, line breaks kept) as a "Payment note" order activity credited to the admin who marked it paid
- [x] 276. Marking an order as paid without a payment note records no payment-note activity and keeps the order note
