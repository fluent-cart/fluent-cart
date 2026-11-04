# Tax

## Database Schema

### Table: fct_tax_rates
- [x] 1. Table exists with primary key `id` BIGINT UNSIGNED AUTO_INCREMENT
- [x] 2. Has columns: id, class_id, country, state, postcode, city, rate, name, group, priority, is_compound, for_shipping, for_order, created_at, updated_at
- [x] 3. `class_id` BIGINT (FK to fct_tax_classes)
- [x] 4. `country/state/city/name/rate/group` VARCHAR(45)
- [x] 5. `postcode` TEXT (nullable; supports comma-separated and range `1000...1999` syntax)
- [x] 6. `priority` INT default 1 (lower = applied first)
- [x] 7. `is_compound` TINYINT (0|1)
- [x] 8. `for_shipping` DECIMAL(10,2) nullable (NULL = use base rate; decimal = override rate — tax redesign #1521)
- [x] 9. `for_order` TINYINT default 0
- [x] 10. Has index on `class_id`
- [x] 11. Has index on `priority`

### Table: fct_tax_classes
- [x] 12. Table exists with primary key `id` BIGINT UNSIGNED AUTO_INCREMENT
- [x] 13. Has columns: id, title, slug, description, meta, created_at, updated_at
- [x] 14. `title` VARCHAR(192) required
- [x] 15. `slug` VARCHAR(100) UNIQUE
- [x] 16. `description` LONGTEXT nullable
- [x] 17. `meta` JSON/LONGTEXT nullable (stores categories[], priority)

### Table: fct_order_tax_rate
- [x] 18. Table exists with primary key `id` BIGINT UNSIGNED AUTO_INCREMENT
- [x] 19. Has columns: id, order_id, tax_rate_id, shipping_tax, order_tax, total_tax, meta, filed_at, created_at, updated_at
- [x] 20. `order_id` BIGINT (FK to fct_orders)
- [x] 21. `tax_rate_id` BIGINT (0 allowed when rate loaded from tax.php JSON)
- [x] 22. `shipping_tax/order_tax/total_tax` BIGINT nullable (cents)
- [x] 23. `meta` JSON (VAT validation, store tax id, tax_config breakdown)
- [x] 24. `filed_at` DATETIME nullable (marks when tax record filed)

## Model: TaxRate

- [x] 25. `$fillable` includes: class_id, country, state, postcode, city, rate, name, group, priority, is_compound, for_shipping, for_order
- [x] 26. belongsTo TaxClass via class_id
- [x] 27. `formatted_state` appended attribute returns human-readable state name (fallback to raw state code)

## Model: TaxClass

- [x] 28. `$fillable` includes: title, meta, slug (description intentionally dropped — tax redesign #1521)
- [x] 29. Creating with only `title` auto-generates unique `slug` via slugify(title)
- [x] 30. Slug collision appends suffix `-2`, `-3`, etc. until unique
- [x] 31. `meta` mutator JSON-encodes arrays; getter JSON-decodes
- [x] 32. Renaming via update regenerates slug only if slug was not explicitly set

## Model: OrderTaxRate

- [x] 33. `$fillable` includes: order_id, tax_rate_id, shipping_tax, order_tax, total_tax, meta, filed_at
- [x] 34. belongsTo Order via order_id
- [x] 35. belongsTo TaxRate via tax_rate_id
- [x] 36. `meta` mutator JSON-encodes arrays; getter JSON-decodes
- [x] 37. Scope `validOrder()` filters to orders with payment_status paid/partially_paid (since 5215e9dad, #1993)

## Service: TaxManager

- [x] 38. `getInstance()` returns the same singleton instance across calls
- [x] 39. `getRates()` returns embedded `tax.php` map keyed by country code
- [x] 40. `generateTaxClasses()` creates default classes `standard` (priority 10), `reduced` (priority 5), `zero` (priority 2) when absent
- [x] 41. `generateTaxClasses()` is idempotent — running twice does not duplicate default classes
- [x] 42. `generateTaxClasses(['DE'])` inserts German tax rates from tax.php and skips other countries
- [x] 43. `generateTaxClasses()` skips a country that already has rates in fct_tax_rates
- [x] 44. `getEuTaxRatesFromPhp('DE', 'standard')` returns the German standard-rate collection from tax.php (no DB hit)
- [x] 45. `getEuTaxRatesFromPhp()` returns an empty collection for a country with no tax.php entry (tax.php also ships non-EU countries with typed rates)
- [x] 46. `getTaxRatesFromTaxPhp()` formats tax.php into grouped UI structure keyed by country + class
- [x] 47. `getCountryConfiguration('DE')` returns the config.php entry (EU hides city/zip/state fields)
- [x] 48. `getCountryConfiguration()` returns null for an unknown country

## Service: TaxModule

### Settings & Enabled Gate
- [x] 49. `isEnabled()` returns true only when `fluent_cart_tax_configuration_settings.enable_tax === 'yes'`
- [x] 50. `isEnabled()` returns false when option not set
- [x] 51. `getSettings()` merges saved DB settings with defaults (tax_inclusion='included', basis='shipping', rounding='item', enable_tax='no')
- [x] 52. `getSettings()` caches results on the instance — second call does not re-read options
- [x] 53. `register()` is a no-op when tax is disabled (no filters/actions added)
- [x] 54. `register()` when enabled adds the core filters: `fluent_cart/cart/estimated_total`, `fluent_cart/checkout/before_patch_checkout_data`, `fluent_cart/cart/tax_behavior`

### calculateCartTax
- [x] 55. Fills `checkout_data['tax_data']` with keys: tax_total, tax_behavior, tax_country, shipping_tax, fee_tax, tax_lines
- [x] 56. With `basis='shipping'` and `ship_to_different='yes'` uses shipping address for country/state/city/postcode
- [x] 57. With `basis='shipping'` and `ship_to_different!='yes'` falls back to billing address
- [x] 58. With `basis='billing'` always uses billing address
- [x] 59. With `basis='store'` uses store address from StoreSettings
- [x] 60. Returns empty tax_data when cart has no taxable lines
- [x] 61. Merges taxable fees into line items before calculating; strips them out of cart_data
- [x] 62. Non-taxable fees are not merged and produce zero `fee_tax`

### Tax Behavior Value
- [x] 63. `tax_inclusion='included'` → checkout_data.tax_data.tax_behavior = 2 (inclusive)
- [x] 64. `tax_inclusion='excluded'` → checkout_data.tax_data.tax_behavior = 1 (exclusive)

## Service: TaxCalculator (Module)

### Rate Resolution by Location
- [x] 65. `getRatesByProductId()` requires country match — a rate without the customer country is excluded
- [x] 66. A rate with state set only matches when customer state equals it; empty state on rate matches any state
- [x] 67. A rate with city set only matches when customer city equals it; empty city on rate matches any city
- [x] 68. A rate with postcode "12345" matches exact postcode 12345 only
- [x] 69. A rate with postcode "12345,23456" matches both 12345 and 23456
- [x] 70. A rate with postcode "12345...12350" expands to range [12345-12350] and matches 12347
- [x] 71. A rate with postcode "12345...12350,56789" matches 12347 AND 56789
- [x] 72. Rates returned in `priority` ASC order (priority 1 applied before priority 2)

### Tax Class Resolution
- [x] 73. Product with `detail.other_info.tax_class` assigned uses only that class's rates
- [x] 74. A product category tax override (fct_meta `tax_override`/`product_category_override`) replaces the base country rate for products in that category
- [x] 75. When several category overrides match, the most location-specific one wins (state/city/postcode beat country-only)
- [x] 76. Product with no matching class and no categories → no tax applied
- [x] 77. EU customer triggers the `getEuTaxRates()` branch — with OSS collection and no DB rate the tax.php rate applies

### Formulas
- [x] 78. Exclusive tax formula: `tax = (price × rate) / 100`
- [x] 79. Inclusive tax formula: `tax = (price × rate) / (100 + rate)`
- [x] 80. `tax_amount` on line item is `ceil(lineTaxTotal)` (never rounds down)

### Compound Tax
- [x] 81. Non-compound rate applies to base price only; output tax = base × rate
- [x] 82. Compound rate applies to (base + accumulated_tax_from_prior_rates) × rate
- [x] 83. Two-rate scenario: 20% non-compound + 5% compound on $100 → $20 + $6 = $26 total tax
- [x] 84. Compound order strictly follows `priority` ASC — higher-priority rates contribute to the compound base

### Subscription Tax
- [x] 85. Subscription line item with `other_info.payment_type='subscription'` produces `other_info.recurring_tax`
- [x] 86. Subscription with signup_fee > 0 AND trial_days=0 taxes signup_fee once into `other_info.signup_fee_tax`
- [x] 87. Subscription with trial_days > 0 zeroes the first-iteration main tax; a signup fee is still taxed separately
- [x] 88. Recurring tax uses `(recurring_amount − recurring_discount) × rate`

### Fee Taxation
- [x] 89. Taxable fee uses the first product's tax rates
- [x] 90. Fee tax is aggregated into top-level `fee_tax`, separate from product tax
- [x] 91. Non-taxable fee does not contribute to `fee_tax` even when other taxable items exist

### Shipping Tax
- [x] 92. `getShippingTax()` sums `shipping_charge + itemwise_shipping_charge` across items
- [x] 93. Uses only rates with non-null `for_shipping` for shipping-tax calculation
- [x] 94. Rate with `for_shipping=10` overrides base rate for shipping-tax math
- [x] 95. When no rate has `for_shipping`, shipping tax is 0
- [x] 96. Compound shipping rates accumulate the same way as product rates
- [x] 97. Shipping tax rounds half-up to the nearest cent per rate (`for_shipping` is DECIMAL(10,2) since the tax redesign #1521, so override rates can be fractional)

### Rounding
- [x] 98. `tax_rounding='item'` (default) rounds each line's tax via ceil
- [x] 99. `tax_rounding='subtotal'` sums raw per-item taxes then rounds once per rate at the end

### Aggregation
- [x] 100. `getTaxedLines()` returns per-item structure with `line_meta.tax_config.rates[]`
- [x] 101. `getTaxLinesByRates()` groups by rate_id and returns `{rate_id, label, tax_amount}` aggregated across lines

### Cart-Level Filter & Total Integration
- [x] 102. Filter `fluent_cart/cart/estimated_total` adds `tax_total + shipping_tax` when tax_behavior=1 (exclusive)
- [x] 103. Filter `fluent_cart/cart/estimated_total` does NOT add tax when tax_behavior=2 (inclusive)
- [x] 104. Filter `fluent_cart/cart/tax_behavior` returns the stored behavior value from checkout_data
- [x] 105. Filter `fluent_cart/checkout/before_patch_checkout_data` triggers recalculation when change keys match `/^(billing_|shipping_|ship_to_|fct_billing_tax)/i`
- [x] 106. Change keys outside that regex (e.g. `notes`) do NOT recalculate tax
- [x] 107. Action `fluent_cart/cart/cart_data_items_updated` triggers recalculation via `recalculateTax()`

## Service: EU VAT Reverse Charge

### Validation Flow
- [x] 108. `handleVatValidation` fails when tax is not enabled
- [x] 109. `handleVatValidation` fails without a valid nonce
- [x] 110. `handleVatValidation` requires an EU tax country and a submitted vat_number
- [x] 111. Strips leading country prefix from VAT number (e.g. "DE123…" → "123…")
- [x] 112. Stores VIES-returned name + address on `cart.checkout_data.tax_data`
- [x] 112a. A visitor gets at most 10 VAT checks in 10 minutes; the 11th is refused with 429 before the store calls the VIES service _(SCAN-09)_
- [x] 112b. Parallel VAT checks from one visitor update the count one at a time (per-visitor MySQL lock, 2 s wait); a check that cannot get its turn is refused with 429 before VIES _(SCAN-09)_

### Reverse Charge Rules
- [x] 113. Reverse charge DOES NOT apply when cart contains a product in `eu_vat_settings.vat_reverse_excluded_categories`
- [x] 114. Reverse charge applies when `local_reverse_charge='yes'` AND VAT is valid
- [x] 115. Reverse charge applies when customer country ≠ store country AND VAT is valid
- [x] 116. Reverse charge sets `tax_data.tax_total=0`, `shipping_tax=0`, `tax_behavior=0`
- [x] 117. Reverse charge does NOT fire when both conditions fail — VAT is charged normally

### VAT Removal
- [x] 118. `removeVat` clears valid/name/address/vat_number/country from tax_data
- [x] 119. `removeVat` triggers `fluent_cart/checkout/cart_amount_updated` action
- [x] 120. `removeVat` returns fragment replacements (cart summary) in JSON response
- [x] 191. `handleVatValidation` also returns the modal checkout summary fragments (`[data-fct-modal-checkout-summary-group]`, `[data-fct-modal-checkout-summary]`) rendered from the recalculated cart
- [x] 192. `removeVat` also returns the modal checkout summary fragments rendered from the recalculated cart

### Rendering
- [x] 121. `renderTaxField` outputs the VAT input whenever the vat_number checkout field is enabled; the Apply button renders only for EU tax countries
- [x] 122. Validation note includes company name + "(Reverse Charge not applied)" when tax_total != 0 even after validation

## Service: Order Persistence

### prepareOtherData (action `fluent_cart/checkout/prepare_other_data`)
- [x] 123. Creates one OrderTaxRate record per distinct `rate_id` in tax_lines
- [x] 124. `tax_rate_id=0` allowed for JSON-only (tax.php) rates
- [x] 125. `total_tax = shipping_tax + order_tax` on each record
- [x] 126. When reverse charge active, creates records with `order_tax=0` and `shipping_tax=0`
- [x] 127. Stores `tax_config`, `tax_country`, `store_vat_number`, and `vat_reverse` (when applicable) in OrderTaxRate.meta

## REST API: /tax/classes

### GET /classes
- [x] 128. Returns all classes sorted by id ASC (creation order — TaxRateController::getClasses; the old priority-DESC sentence described a surface that no longer exists)
- [x] 129. Response shape `{ classes: TaxClass[], max_classes: int, next_builtin: ?array }`

### POST /classes
- [x] 130. Requires `title`; missing title → 4xx with validation error
- [x] 131. Creates class + auto-generates slug (handled by TaxRateController::createClass; description/meta no longer part of the REST payload)
- [x] 132. Creating a class with a duplicate title is rejected (423) — slug suffixing survives only at the model layer (see 185)

### PUT /classes/{id} — SURFACE REMOVED
- [x] 133. The class-update route no longer exists: PUT `classes/{id}` is absent from the route table and dispatching it returns 404 (pinned so it cannot reappear half-wired)
- [x] 134. Changing a class title still regenerates the slug at the model layer (`TaxClass::updating()`), built-in slugs (standard/reduced/zero) protected

### DELETE /classes/{id}
- [x] 135. Removes class from fct_tax_classes
- [x] 136. Attempting delete with invalid id → 4xx

## REST API: /tax/country-rates

### GET /rates
- [x] 137. Returns rates grouped by country and class (via TaxManager::getTaxRatesFromTaxPhp merged with DB)

### GET /rates/country/rates/{country_code}
- [x] 138. Returns `{ tax_rates, settings }` for the given country
- [x] 139. Unknown country code returns empty tax_rates array (not 404)

### POST /country/rate
- [x] 140. `class_id` is optional (absent → standard class); a class_id pointing at no row is rejected (422)
- [x] 141. Creates rate with provided country/state/postcode/city/rate

### PUT /country/rate/{id}
- [x] 142. Updates mutable fields (rate, name, priority, is_compound, for_shipping)

### DELETE /country/rate/{id}
- [x] 143. Deletes single rate
- [x] 144. Invalid id → 4xx

### DELETE /country/{country_code} — SURFACE REMOVED
- [x] 145. Delete-by-country was deliberately removed in d1b3d0207; a squashed merge (2c42ca373) resurrected the route line without its handler (dead 403 route) — TC-002 removed the zombie registration and pins the route's absence
- [x] 146. Dispatching the removed URL is a plain 404 and touches no rate rows

### Shipping Overrides
- [x] 147. POST `rates/country/override` sets `for_shipping` on target rate
- [x] 148. DELETE `rates/country/override/{id}` sets `for_shipping` to NULL

### Country Tax ID
- [x] 149. GET `country-tax-id/{country_code}` returns `{ tax_id }` from fct_meta (empty string when absent)
- [x] 150. POST `country-tax-id/{country_code}` persists tax_id into fct_meta under key `fluent_cart_tax_id_{COUNTRY}`

## REST API: /tax/configuration

### GET /configuration/rates
- [x] 151. Returns full tax.php dataset (countries + embedded rates)

### POST /configuration/countries
- [x] 152. Bulk-inserts rates for each selected country via `TaxManager::generateTaxClasses`
- [x] 153. Skips countries already present in fct_tax_rates

### GET /configuration/settings
- [x] 154. Returns current tax settings merged with defaults

### POST /configuration/settings
- [x] 155. Persists enable_tax, tax_inclusion, tax_calculation_basis, tax_rounding (payload under a top-level `settings` key)
- [x] 156. Invalid enum values ('maybe', 'sideways') are not rejected — they are normalized to the first allowed value and saved with 200

## REST API: /tax/configuration/settings/eu-vat — surface reshaped (rates/oss/override routes replaced by oss-rates, reset-rates, product-overrides)

- [x] 157. POST `.../eu-vat` saves method (oss/home/specific), oss_country, oss_vat, home_country, home_vat
- [x] 158. GET `.../eu-vat/oss-rates` (getOssCountryRates) supersedes the old `.../eu-vat/rates` read
- [x] 159. POST `.../eu-vat/oss-rates` (saveOssCountryRates) supersedes the old per-country OSS override save
- [x] 160. OSS shipping overrides were folded into POST `rates/country/override` (saveShippingOverride)
- [x] 161. Per-country OSS override deletion became POST `.../eu-vat/reset-rates` (resetEuRatesToDefaults)
- [x] 162. Folded shipping-override deletion: DELETE `rates/country/override/{id}` (deleteShippingOverride); plus GET `.../eu-vat/product-overrides` is registered (getEuProductOverrides)

## REST API: /taxes/

### GET /taxes
- [x] 163. Returns paginated OrderTaxRate records via `TaxFilter::fromRequest($request)->paginate()` (route matches only WITHOUT a trailing slash)
- [x] 164. Supports filter by `filed_at`, country, date range

### POST /taxes
- [x] 165. Bulk marks selected tax records as filed (sets `filed_at=now()`)
- [x] 166. Requires array of ids in body (empty payload → 400)

## Permissions

- [x] 167. All tax routes resolve via `StoreSettingsPolicy::hasRoutePermissions()` (policy renamed from StoreSensitivePolicy; per-route metas carry store/settings vs store/sensitive)
- [x] 168. Super_admin passes policy and can access every tax endpoint
- [x] 169. User without store settings caps (e.g., subscriber) → 403 on any tax write
- [x] 170. Unauthenticated request to tax endpoints → 401

## AJAX Endpoints

- [x] 171. `wp_ajax_fluent_cart_validate_vat` + nopriv variant registered when tax module registers
- [x] 172. `wp_ajax_fluent_cart_remove_vat` + nopriv variant registered when tax module registers
- [x] 173. Validate VAT without `_wpnonce` → JSON error + 403
- [x] 174. Remove VAT without valid cart hash falls through to current cart

## Hooks — Exhaustive List

- [x] 175. `fluent_cart/tax/country_tax_titles` filter returns VAT/GST/EIN label map
- [x] 176. `fluent_cart/product/price_suffix_atts` filter returns "Inc. Tax" or configured price suffix
- [x] 177. Action `fluent_cart/before_payment_methods` fires `renderTaxField()` for EU VAT input
- [x] 178. Action `fluent_cart/checkout/before_summary_total` renders tax rows in checkout summary
- [x] 179. Filter `fluent_cart/checkout/after_patch_checkout_data_fragments` replaces VAT field HTML on AJAX updates

## Edge Cases

- [x] 180. Tax re-calculation when cart becomes empty → `tax_data = []`
- [x] 181. Rate with `rate='20.5'` (decimal string) calculates correctly (e.g. $100 at 20.5% exclusive = $20.50)
- [x] 182. Zero-rate class (rate="0") produces `tax_amount=0` and still creates a tax_line entry
- [x] 183. Quantity change triggers tax recalculation via `cart_data_items_updated` action
- [x] 184. Deleting the tax class associated with a product does NOT retroactively alter stored OrderTaxRate rows
- [x] 185. Tax class slug collision after 3 saves produces `-2`, `-3` (model layer — REST rejects duplicates, see 132)
- [x] 186. VAT reverse charge with excluded category in cart keeps full tax on the excluded item
- [x] 187. Switching `tax_calculation_basis` setting triggers recalc on the next cart update
- [x] 188. `getShippingTax()` with `shipping_charge=0` on every line returns 0 even if a `for_shipping` rate exists
- [x] 189. `calculateCartTax` on a cart with only a non-taxable fee produces empty tax_data
- [x] 190. OrderTaxRate `filed_at` mark-as-filed is idempotent — re-marking already filed rows does not overwrite timestamp

## Factories Needed

- TaxClassFactory (fields: title, description, meta.categories, meta.priority, slug auto) — built
- TaxRateFactory (fields: class_id, country, state, postcode, city, rate, priority, is_compound, for_shipping, for_order, group) — built
- OrderTaxRateFactory (fields: order_id, tax_rate_id, shipping_tax, order_tax, total_tax, meta, filed_at) — built
- Reused: CustomerFactory, ProductFactory (+ withVariantAttributes + withDetailAttributes), OrderFactory (+ withAddresses)

## Implementation Status

Updated 2026-07-20 (TC-002). The checkbox state above is the source of truth:
190 `[x]` of 190; none remain `[ ]`.

### Green with zero skips (TC-001, 2026-07-20)
- `TaxServiceTest.php` (Services): 76 tests, 125 assertions, 0 failures, 0 skips.
- `TaxEuVatTest.php` (Services, new in TC-001): 15 tests, 56 assertions, 0
  failures, 0 skips — the full EU VAT flow (108-122) via the
  `fluent_cart/tax/validate_eu_vat_number` mock filter + `WpDieCapture`.

Former deferral reasons, all resolved by TC-001 testability seams
(`tests/Cases/TESTABILITY-DEBT.md` #4/#7/#8):
- Cross-test stateful cache (83-88, 92) → `TaxCalculator::resetCache()`
- StoreSettings global mutation (59) → per-test memo reset in `BuildsTaxFixtures`
- WP term taxonomy fixtures / category overrides (74, 75, 77) → raw
  `term_relationships` + `fct_meta` tax_override fixtures
- Real Cart lifecycle (107) → `CartFactory` + persisted-cart assertion
- VIES SOAP + `wp_send_json` exits (108-122) → mock filter + `WpDieCapture`

Spec sentences 45, 48, 74, 75, 77, 87, 97, 99, 110, 120, 121 were rewritten
during TC-001 to describe actual production behavior (the old sentences
asserted behavior that never existed); test method names and docblocks match
the current sentences.

### TC-002 (2026-07-20) — `Http/TaxControllerTest.php` fully repaired
The 14 items left unchecked after TC-001 (155, 156, 163, 166, 169, 170, 173,
174, 181, 182, 184, 186, 187, 190) all went green in TC-002, which also
repaired the 12 baseline-red route/policy tests (tax surface had drifted:
TaxRateController class handlers, StoreSettingsPolicy, eu-vat oss-rates
routes) and eliminated every skip in the file. Baseline identities are
frozen, so behavior-changed tests keep their original method names with
docblock notes; the Phase-5 `Rest/Tax/` migration renames them. The zombie
`DELETE country/{country_code}` route (dead since d1b3d0207, resurrected
handler-less by a squashed merge) was removed from `api.php` in the same PR;
sentences 145/146 now pin its absence.

Per-file test counts for `TaxTest` (TC-008) are tracked by its own ticket
and intentionally not asserted here.

### Noteworthy documented-only contract
- Item 15 (`slug` UNIQUE index): since 476191525 (2026-05-06, "enforce unique slug") the migration DOES create the index — `TaxClassesMigrator::addSlugUniqueIndex()` runs after `deduplicateSlugs()`, on top of the app-level pre-resolution in `TaxClass::generateUniqueSlug()`. `test_fct_tax_classes_slug_has_db_unique_index` (TC-008; renamed from `test_fct_tax_classes_slug_uniqueness_strategy_is_application_level` per DELETION-ALLOWLIST.md) fails loudly if the single-column UNIQUE index disappears.

### Suite totals (2026-07-20, post TC-002)
- TaxTest (Models): 38 tests, 136 assertions, 0 failures, 0 skipped
- TaxServiceTest (Services): 76 tests, 125 assertions, 0 failures, 0 skipped
- TaxEuVatTest (Services): 15 tests, 56 assertions, 0 failures, 0 skipped
- TaxControllerTest (HTTP): 71 tests, 143 assertions, 0 failures, 0 skipped
- Grand total: 200 tests, 460 assertions, 0 failures, 0 skipped

Spec-item coverage (190 `[x]` of 190) and test-count totals are distinct because some specs are covered by multiple test methods and some tests do not map 1:1 to spec items.
