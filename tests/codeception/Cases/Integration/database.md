# Database

## Table Existence

All 35 `fct_*` tables must exist after `DBMigrator::migrate()` runs.

- [x] 1. `fct_meta` table exists
- [x] 2. `fct_atts_groups` table exists
- [x] 3. `fct_atts_relations` table exists
- [x] 4. `fct_atts_terms` table exists
- [x] 5. `fct_carts` table exists
- [x] 6. `fct_coupons` table exists
- [x] 7. `fct_customer_addresses` table exists
- [x] 8. `fct_customer_meta` table exists
- [x] 9. `fct_customers` table exists
- [x] 10. `fct_order_addresses` table exists
- [x] 11. `fct_order_download_permissions` table exists
- [x] 12. `fct_order_meta` table exists
- [x] 13. `fct_order_operations` table exists
- [x] 14. `fct_order_items` table exists
- [x] 15. `fct_orders` table exists
- [x] 16. `fct_order_tax_rate` table exists
- [x] 17. `fct_order_transactions` table exists
- [x] 18. `fct_product_details` table exists
- [x] 19. `fct_product_downloads` table exists
- [x] 20. `fct_product_meta` table exists
- [x] 21. `fct_subscription_meta` table exists
- [x] 22. `fct_subscriptions` table exists
- [x] 23. `fct_tax_classes` table exists
- [x] 24. `fct_tax_rates` table exists
- [x] 25. `fct_product_variations` table exists
- [x] 26. `fct_applied_coupons` table exists
- [x] 27. `fct_label` table exists
- [x] 28. `fct_label_relationships` table exists
- [x] 29. `fct_activity` table exists
- [x] 30. `fct_webhook_logger` table exists
- [x] 31. `fct_shipping_zones` table exists
- [x] 32. `fct_shipping_methods` table exists
- [x] 33. `fct_shipping_classes` table exists
- [x] 34. `fct_scheduled_actions` table exists
- [x] 35. `fct_retention_snapshots` table exists

## Schema: fct_orders

- [x] 36. Has columns: id, status, parent_id, receipt_number, invoice_no, fulfillment_type, type, mode, shipping_status, customer_id, payment_method, payment_status, payment_method_title, currency, uuid, config, note, ip_address, completed_at, refunded_at, created_at, updated_at
- [x] 37. Has monetary columns stored as BIGINT: subtotal, discount_tax, manual_discount_total, coupon_discount_total, shipping_tax, shipping_total, fee_total, tax_total, total_amount, total_paid, total_refund
- [x] 38. Has key defaults: status='draft', type='payment', fulfillment_type='physical', mode=ENUM 'live'
- [x] 39. Has rate as DECIMAL(12,4), tax_behavior as TINYINT(1) DEFAULT 0, config as JSON
- [x] 40. Has indexes: customer_id, type, (created_at, completed_at), (payment_status, id)

## Schema: fct_order_items

- [x] 41. Has columns: id, order_id, post_id, fulfillment_type, payment_type, post_title, title, object_id, cart_index, quantity
- [x] 42. Has monetary columns as BIGINT: unit_price, cost, subtotal, tax_amount, shipping_charge, discount_total, line_total, refund_total, rate
- [x] 43. Has JSON columns: other_info, line_meta
- [x] 44. Has composite index on (order_id, object_id)
- [x] 45. Has index on post_id

## Schema: fct_customers

- [x] 46. Has columns: id, user_id, contact_id, email, first_name, last_name, status, purchase_value, purchase_count, ltv, notes, uuid, country, city, state, postcode
- [x] 47. Has `purchase_value` as JSON column
- [x] 48. Has `ltv` as BIGINT (lifetime value in cents)
- [x] 49. Has `aov` as DECIMAL(18,2) (average order value)
- [x] 50. Has `first_purchase_date` and `last_purchase_date` as DATETIME
- [x] 51. Has index on `email` (fct_cus_email)
- [x] 52. Has index on `user_id` (fct_cus_user_id)

## Schema: fct_product_details

- [x] 53. Has columns: id, post_id, fulfillment_type, min_price, max_price, default_variation_id, manage_stock, stock_availability, variation_type, manage_downloadable
- [x] 54. Has `default_media` as JSON column
- [x] 55. Has `other_info` as JSON column
- [x] 56. Has `min_price` and `max_price` as DOUBLE (not BIGINT — display-level cached prices)
- [x] 57. Has index on post_id (fct_pd_product_id_idx)
- [x] 58. Has index on stock_availability (fct_pd_product_stock_stockx)

## Schema: fct_product_variations

- [x] 59. Has columns: id, post_id, media_id, serial_index, sold_individually, variation_title, variation_identifier, sku, manage_stock, payment_type, stock_status, backorders, total_stock, on_hold, committed, available, fulfillment_type, item_status, manage_cost
- [x] 60. Has price columns as DOUBLE: item_price, item_cost, compare_price
- [x] 61. Has `other_info` as LONGTEXT (not JSON)
- [x] 62. Has `downloadable` as VARCHAR(30) DEFAULT 'false'
- [x] 63. Has UNIQUE index on `sku` (sku_unique)
- [x] 64. Has index on post_id and stock_status

## Schema: fct_subscriptions

- [x] 65. Has columns: id, uuid, customer_id, parent_order_id, product_id, item_name, quantity, variation_id, billing_interval
- [x] 66. Has monetary columns as BIGINT UNSIGNED: signup_fee, initial_tax_total, recurring_amount, recurring_tax_total, recurring_total
- [x] 67. Has `bill_times` as BIGINT UNSIGNED (0 = unlimited)
- [x] 68. Has `bill_count` as INT UNSIGNED
- [x] 69. Has `collection_method` as ENUM('automatic','manual','system') DEFAULT 'automatic'
- [x] 70. Has date columns: expire_at, trial_ends_at, canceled_at, restored_at, next_billing_date
- [x] 71. Has vendor columns: vendor_customer_id, vendor_plan_id, vendor_subscription_id
- [x] 72. Has index on parent_order_id

## Schema: fct_order_transactions

- [x] 73. Has columns: id, order_id, order_type, transaction_type, subscription_id, card_last_4, card_brand, vendor_charge_id, payment_method, payment_mode, payment_method_type, status, currency, total, rate, uuid, meta
- [x] 74. Has `transaction_type` DEFAULT 'charge'
- [x] 75. Has `total` as BIGINT (in cents)
- [x] 76. Has indexes on vendor_charge_id, payment_method, status, order_id

## Schema: fct_coupons

- [x] 77. Has columns: id, title, code, priority, type, conditions, amount, use_count, status, notes, stackable, show_on_checkout, start_date, end_date
- [x] 78. Has `code` as VARCHAR(50) UNIQUE
- [x] 79. Has `conditions` as JSON column
- [x] 80. Has `stackable` and `show_on_checkout` as VARCHAR(3) with 'no'/'yes' defaults
- [x] 81. Has indexes on code and status

## Schema: fct_carts

- [x] 82. Has columns: customer_id, user_id, order_id, cart_hash, checkout_data, cart_data, utm_data, coupons, first_name, last_name, email, stage, cart_group, user_agent, ip_address
- [x] 83. Has `cart_hash` as UNIQUE KEY
- [x] 84. Has `stage` DEFAULT 'draft' and `cart_group` DEFAULT 'global'
- [x] 85. Has `deleted_at` TIMESTAMP NULL (soft delete)
- [x] 86. Does NOT have an `id` auto-increment primary key (cart_hash is the unique identifier)

## Schema: fct_tax_rates

- [x] 87. Has columns: id, class_id, country, state, postcode, city, rate, name, group, priority, is_compound, for_shipping, for_order
- [x] 88. Does NOT have created_at/updated_at columns
- [x] 89. Has index on class_id and priority

## Schema: fct_shipping_zones

- [x] 90. Has columns: id, name, region, order, meta, created_at, updated_at
- [x] 91. `meta` column is added via `migrated()` method (post-migration), not in initial schema

## Schema: fct_shipping_methods

- [x] 92. Has columns: id, zone_id, title, type, settings, is_enabled, states, amount, order, meta
- [x] 93. Has `amount` as DECIMAL(10,2) (not BIGINT)
- [x] 94. Has `states` and `meta` as JSON columns
- [x] 95. Has index on zone_id and order

## Schema: fct_activity

- [x] 96. Has columns: id, status, log_type, module_type, module_id, module_name, user_id, title, content, read_status, created_by
- [x] 97. Has `read_status` DEFAULT 'unread'
- [x] 98. Has `created_by` DEFAULT 'FCT-BOT'
- [x] 99. Has index on module_id

## Schema: fct_meta

- [x] 100. Has columns: id, object_type, object_id, meta_key, meta_value, created_at, updated_at
- [x] 101. Has `object_type` as VARCHAR(50) NOT NULL
- [x] 102. Has `meta_value` as LONGTEXT NULL
- [x] 103. Has index on object_type (fct_mt_mt_idx)
- [x] 104. Has index on object_id (fct_mt_mto_id_idx)

## Schema: fct_atts_groups

- [x] 105. Has columns: id, title, slug, description, settings, created_at, updated_at
- [x] 106. Has `title` as VARCHAR(192) UNIQUE
- [x] 107. Has `slug` as VARCHAR(192) UNIQUE
- [x] 108. Has `settings` as LONGTEXT NULL

## Schema: fct_atts_relations

- [x] 109. Has columns: id, group_id, term_id, object_id, created_at, updated_at
- [x] 110. Has index on group_id (fct_at_rel_group_id_idx)
- [x] 111. Has index on term_id (fct_at_rel_term_id_idx)
- [x] 112. Has index on object_id (fct_at_rel_obj_id_idx)

## Schema: fct_atts_terms

- [x] 113. Has columns: id, group_id, serial, title, slug, description, settings, created_at, updated_at
- [x] 114. Has index on group_id (fct_attt_group_id_idx)

## Schema: fct_applied_coupons

- [x] 115. Has columns: id, order_id, coupon_id, code, amount, created_at, updated_at
- [x] 116. Has `order_id` as BIGINT UNSIGNED NOT NULL
- [x] 117. Has `amount` as DOUBLE NOT NULL
- [x] 118. Has index on code (fct_acoup_code_idx)

## Schema: fct_customer_addresses

- [x] 119. Has columns: id, customer_id, is_primary, type, status, label, name, address_1, address_2, city, state, phone, email, postcode, country, meta
- [x] 120. Has `is_primary` as TINYINT(1) DEFAULT 0
- [x] 121. Has `type` DEFAULT 'billing'
- [x] 122. Has `status` DEFAULT 'active'
- [x] 123. Has `meta` as JSON column
- [x] 124. Has composite index on (customer_id, is_primary)
- [x] 125. Has index on type and status

## Schema: fct_customer_meta

- [x] 126. Has columns: id, customer_id, meta_key, meta_value, created_at, updated_at
- [x] 127. Has index on meta_key (fct_cm_meta_key)
- [x] 128. Has index on customer_id (fct_cm_customer_id)

## Schema: fct_order_addresses

- [x] 129. Has columns: id, order_id, type, name, address_1, address_2, city, state, postcode, country, meta, created_at, updated_at
- [x] 130. Has `type` DEFAULT 'billing'
- [x] 131. Has `meta` as JSON column

## Schema: fct_order_download_permissions

- [x] 132. Has columns: id, order_id, variation_id, download_id, download_count, download_limit, access_expires, customer_id, created_at, updated_at
- [x] 133. Has index on order_id (fct_odp_order_id_idx)
- [x] 134. Has index on download_id (fct_odp_download_id_idx)
- [x] 135. Has index on variation_id (fct_odp_variation_id_idx)

## Schema: fct_order_meta

- [x] 136. Has columns: id, order_id, meta_key, meta_value, created_at, updated_at
- [x] 137. Has index on order_id (fct_om_ord_id_idx)

## Schema: fct_order_operations

- [x] 138. Has columns: id, order_id, created_via, emails_sent, sales_recorded, utm_campaign, utm_term, utm_source, utm_medium, utm_content, utm_id, cart_hash, refer_url, meta, created_at, updated_at
- [x] 139. Has `emails_sent` and `sales_recorded` as TINYINT(1) DEFAULT 0
- [x] 140. Has `meta` as JSON column
- [x] 141. Has index on order_id (fct_oo_order_operations_idx)

## Schema: fct_order_tax_rate

- [x] 142. Has columns: id, order_id, tax_rate_id, shipping_tax, order_tax, total_tax, meta, filed_at, created_at, updated_at
- [x] 143. Has monetary columns as BIGINT: shipping_tax, order_tax, total_tax
- [x] 144. Has `meta` as JSON column

## Schema: fct_product_downloads

- [x] 145. Has columns: id, post_id, product_variation_id, download_identifier, title, type, driver, file_name, file_path, file_url, file_size, settings, serial, created_at, updated_at
- [x] 146. Has `download_identifier` as VARCHAR(100) UNIQUE
- [x] 147. Has `driver` DEFAULT 'local'
- [x] 148. Has `product_variation_id` as LONGTEXT (stores multiple variation IDs)

## Schema: fct_product_meta

- [x] 149. Has columns: id, object_id, object_type, meta_key, meta_value, created_at, updated_at
- [x] 150. Has index on meta_key (fct_pm_meta_key)

## Schema: fct_subscription_meta

- [x] 151. Has columns: id, subscription_id, meta_key, meta_value, created_at, updated_at
- [x] 152. Has index on subscription_id (fct_sm_subs_id_key)
- [x] 153. Has index on meta_key (fct_sm_meta_key)

## Schema: fct_label

- [x] 154. Has columns: id, value, created_at, updated_at
- [x] 155. Has `value` as VARCHAR(192) UNIQUE

## Schema: fct_label_relationships

- [x] 156. Has columns: id, label_id, labelable_id, labelable_type, created_at, updated_at
- [x] 157. Has index on label_id (fct_labr_label_id_idx)
- [x] 158. Has index on labelable_id (fct_labr_labelable_id_idx)

## Schema: fct_webhook_logger

- [x] 159. Has columns: id, source, event_type, payload, status, created_at, updated_at
- [x] 160. Has `status` DEFAULT 'pending'
- [x] 161. Has `payload` as LONGTEXT

## Schema: fct_shipping_classes

- [x] 162. Has columns: id, name, cost, per_item, type, created_at, updated_at
- [x] 163. Has `cost` as DECIMAL(10,2) DEFAULT 0.00
- [x] 164. Has `per_item` as TINYINT(1) DEFAULT 0
- [x] 165. Has `type` DEFAULT 'fixed'
- [x] 166. Has index on name (fct_sc_name_idx)

## Schema: fct_scheduled_actions

- [x] 167. Has columns: id, scheduled_at, action, status, group, object_id, object_type, completed_at, retry_count, data, created_at, updated_at, response_note
- [x] 168. Has `data` as JSON column
- [x] 169. Has `response_note` as LONGTEXT
- [x] 170. Has `retry_count` DEFAULT 0
- [x] 171. Has index on scheduled_at (fct_sch_var_scheduled_at_idx)
- [x] 172. Has index on status (fct_sch_var_status_idx)

## Schema: fct_retention_snapshots

- [x] 173. Has columns: id, cohort, period, product_id, cohort_customers, cohort_mrr, retained_customers, retained_mrr, new_customers, churned_customers, retention_rate_customers, retention_rate_mrr, period_offset, created_at, updated_at
- [x] 174. Has UNIQUE composite index on (cohort, period, product_id)
- [x] 175. Has individual indexes on cohort, period, product_id, period_offset
- [x] 176. Has `retention_rate_customers` and `retention_rate_mrr` as DECIMAL(5,2)

## Schema: fct_tax_classes

- [x] 177. Has columns: id, title, slug, description, meta, created_at, updated_at
- [x] 178. Has `meta` as JSON column

## DBMigrator

- [x] 179. `DBMigrator::migrate()` creates all 35 tables without errors
- [x] 180. `DBMigrator::migrate()` is idempotent — running twice does not error or duplicate tables
- [x] 181. `DBMigrator::migrateUp()` calls `migrate()` then `maybeMigrateDBChanges()` then updates `_fluent_cart_db_version` option
- [x] 182. `DBMigrator::maybeMigrateDBChanges()` is safe to call when DB is already at current version
- [x] 183. Each individual migrator creates its table via `Schema::createTableIfNotExist()`

## Edge Cases

- [x] 184. Tables use the correct WordPress table prefix (`$wpdb->prefix . 'fct_*'`)
- [x] 185. All monetary columns in fct_orders are BIGINT (not DECIMAL or DOUBLE) — verify subtotal, tax_total, total_amount, total_paid, total_refund
- [x] 186. fct_product_variations.item_price is DOUBLE (not BIGINT) — intentional for variation-level pricing
- [x] 187. fct_product_details.min_price and max_price are DOUBLE — cached display prices, not transactional amounts
- [x] 188. fct_shipping_methods.amount is DECIMAL(10,2) — shipping uses decimal, not cents
- [x] 189. JSON columns accept valid JSON: fct_orders.config, fct_order_items.other_info, fct_coupons.conditions, fct_customers.purchase_value
- [x] 190. fct_carts has no auto-increment id — cart_hash is the unique key
- [x] 191. fct_tax_rates has no timestamp columns (created_at/updated_at)
- [x] 192. fct_shipping_zones.meta column is added in `migrated()` hook, not initial CREATE TABLE

## Upgrade-Path Migrations (MigrationUpgradeTest)

- [x] 193. OrdersMigrator::addFeeTotalColumn adds `fee_total` BIGINT when missing
- [x] 194. OrdersMigrator::addTaxBehaviorColumn adds `tax_behavior` TINYINT when missing
- [x] 195. OrdersMigrator::addReceiptNumberColumn adds `receipt_number` BIGINT when missing
- [x] 196. OrdersMigrator::renameDiscountTotalColumn renames `discount_total` to `manual_discount_total` preserving data
- [x] 197. OrdersMigrator::addPaymentStatusIndex adds `fct_payment_status` index when missing
- [x] 198. SubscriptionsMigrator::addUuidColumn adds `uuid` VARCHAR when missing
- [x] 199. SubscriptionsMigrator::renameInitialAmountToSignupFee renames `initial_amount` to `signup_fee` preserving data
- [x] 200. SubscriptionsMigrator::backfillEmptyUuids fills empty UUIDs with unique values
- [x] 201. ShippingZonesMigrator::migrated() renames legacy `regions` to `region` preserving data
- [x] 202. ShippingZonesMigrator::migrated() adds `meta` JSON column when missing
- [x] 203. ShippingMethodsMigrator::addStatesColumn adds `states` JSON when missing
- [x] 204. ShippingMethodsMigrator::modifyStatesToJson changes `states` from TEXT to JSON
- [x] 205. ShippingMethodsMigrator::addMetaColumn adds `meta` JSON when missing
- [x] 206. ShippingMethodsMigrator::changeAmountToDecimal changes `amount` from INT to DECIMAL(10,2)
- [x] 207. TaxClassesMigrator::renameCategoriesToMeta renames `categories` to `meta` preserving data
- [x] 208. TaxClassesMigrator::addSlugColumn adds `slug` VARCHAR when missing
- [x] 209. TaxClassesMigrator::addDescriptionColumn adds `description` LONGTEXT when missing
- [x] 210. Migration seeds exactly one mandatory 'standard' tax class (asserted on the truncate-then-migrate clean state each suite boot)
- [x] 210. MetaMigrator::renameKeyToMetaKey renames `key` to `meta_key` preserving data
- [x] 211. MetaMigrator::renameValueToMetaValue renames `value` to `meta_value` preserving data
- [x] 212. OrderMetaMigrator::renameKeyToMetaKey renames `key` to `meta_key` preserving data
- [x] 213. OrderMetaMigrator::renameValueToMetaValue renames `value` to `meta_value` preserving data
- [x] 214. CustomersMigrator::addLtvColumn adds `ltv` BIGINT when missing
- [x] 215. CustomerAddressesMigrator::addMetaColumn adds `meta` JSON when missing
- [x] 216. OrderAddressesMigrator::addMetaColumn adds `meta` JSON when missing
- [x] 217. OrderTaxRateMigrator::addMetaColumn adds `meta` JSON when missing
- [x] 218. OrderTaxRateMigrator::addFiledAtColumn adds `filed_at` DATETIME when missing
- [x] 219. TaxRatesMigrator::addGroupColumn adds `group` VARCHAR when missing
- [x] 220. ProductMetaMigrator::dropCompositeUniqueIndex drops the composite unique index
- [x] 221. ProductVariationMigrator::addSkuColumn adds `sku` VARCHAR + unique index when missing
- [x] 236. ProductMetaMigrator::addObjectMetaIndex adds the composite (object_id, meta_key) index when missing
- [x] 222. DBMigrator::run_migrate restores missing columns when DB version is old
- [x] 223. DBMigrator::run_migrate is safe at current version
- [x] 224. All migrated() hooks are idempotent — running twice does not error

## Plugin Lifecycle (LifecycleTest)

- [x] 225. ActivationHandler::handle creates tables and sets DB version
- [x] 226. ActivationHandler::handle registers Action Scheduler hooks (5min, hourly, daily)
- [x] 227. ActivationHandler::handle is idempotent
- [x] 228. DeactivationHandler::handle clears WP cron hooks
- [x] 229. DeactivationHandler::handle unschedules Action Scheduler five_minutes_tasks
- [x] 230. DeactivationHandler::handle does not drop tables
- [x] 231. DBMigrator::migrateDown deletes fluent-products CPT posts (does not delete regular posts)
- [x] 232. DBMigrator::migrateDown iterates all migrators without fatal errors
- [x] 233. Real table drops work via raw connection (verifies drop path outside WPTestCase filter)
- [x] 234. DBMigrator::refresh deletes fluent-products CPT posts, then re-creates tables
- [x] 235. Real drop + recreate clears data (marker row is gone after refresh)
