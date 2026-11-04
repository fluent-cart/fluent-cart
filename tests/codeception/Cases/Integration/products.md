# Products

## Model: Product

### Table & Global Scope
- [x] 1. Product model uses WordPress `posts` table with `post_type = 'fluent-products'` and excludes `auto-draft` status via global scope
- [x] 2. Creating a Product auto-sets `post_type` to `fluent-products` via boot creating event

### Fillable & Hidden
- [x] 3. All 20 fillable fields (post_content, post_title, post_excerpt, post_author, post_date, post_date_gmt, post_content_filtered, post_status, post_type, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_parent, menu_order, post_mime_type) are mass-assignable
- [x] 4. Hidden fields (post_content_filtered, post_password, post_author, to_ping, pinged, post_parent, menu_order, post_mime_type, comment_count) are excluded from serialization

### Accessors
- [x] 5. `thumbnail` accessor returns featured image URL; returns placeholder URL when no featured image is set
- [x] 6. `has_subscription` accessor returns true when any variant has `payment_type = 'subscription'`; false when all variants are `onetime`
- [x] 7. `view_url` accessor returns the product permalink
- [x] 8. `edit_url` accessor returns the admin edit URL for the product
- [ ] 9. ~~`tags` accessor returns terms from `product-tags` taxonomy~~ — RETIRED. `product-tags` does not ship: `ecc86def4` removed the `Product` tag getters and the shop shortcode `tag=` filter per the 2026-08-06 product decision, and `app/CPT/FluentProducts.php:313` records that the taxonomy is deliberately never registered. The two owning tests are removed via `tests/Cases/DELETION-ALLOWLIST.md`; there is no behaviour left to cover.
- [x] 10. `categories` accessor returns terms from `product-categories` taxonomy

### Scopes
- [x] 11. `published` scope filters to `post_status = 'publish'` only
- [x] 12. `statusOf($status)` scope filters to the given post_status
- [x] 13. `adminAll` scope includes all statuses from `Status::productAdminAllStatuses()`
- [x] 14. `bundle` scope filters to products where `other_info.is_bundle_product = 'yes'`
- [x] 15. `nonBundle` scope excludes bundle products
- [x] 16. `byVariantTypes('physical')` filters to products with physical fulfillment_type variants
- [x] 17. `byVariantTypes('digital')` filters to products with digital fulfillment_type variants
- [x] 18. `byVariantTypes('subscription')` filters to products with subscription payment_type variants
- [x] 19. `byVariantTypes('onetime')` filters to products with onetime payment_type variants
- [x] 20. `applyCustomSortBy` sorts by id, date, title, or price correctly

### Relationships
- [x] 21. `detail()` hasOne ProductDetail via post_id — returns single ProductDetail
- [x] 22. `variants()` hasMany ProductVariation via post_id — returns collection of variants
- [x] 23. `downloadable_files()` hasMany ProductDownload via post_id
- [x] 24. `orderItems()` hasMany OrderItem via post_id
- [x] 25. `integrations()` hasMany ProductMeta where object_type = 'product_integration'
- [x] 26. `licensesMeta()` hasOne ProductMeta where meta_key = 'license_settings'

### Methods
- [x] 27. `getProductMeta($metaKey)` returns meta_value for existing key, returns $default for non-existent key
- [x] 28. `updateProductMeta($metaKey, $metaValue)` creates new ProductMeta if key doesn't exist, updates existing if it does
- [x] 29. `isBundleProduct()` returns true when `other_info.is_bundle_product = 'yes'`, false otherwise
- [x] 30. `soldIndividually()` returns true when `other_info.sold_individually = 'yes'`
- [x] 31. `isStock()` returns true when product has available stock; checks bundle children stock recursively for bundle products
- [x] 32. `images()` returns array containing thumbnail, gallery images, and variation images

### Product Duplication
- [x] 33. `duplicateProduct($productId)` creates a new product with same post data, detail, variants, and taxonomy terms; returns new product ID
- [ ] 34. `duplicateProduct` with `import_stock_management = true` copies stock fields to new product
Skipped: Product::duplicateProduct() uses raw SQL transactions (START TRANSACTION/COMMIT) that escape WPTestCase transaction rollback. Verified passing in isolation.
- [ ] 35. `duplicateProduct` with `import_downloadable_files = true` copies downloadable files to new product
Skipped: Same raw-transaction issue as spec 34.
- [x] 36. `duplicateProduct` fires `fluent_cart/product_duplicated` action with original_product_id, new_product_id, options
- [x] 37. `duplicateProduct` with non-existent productId throws RuntimeException('Product not found')

## Model: ProductDetail

### Table & Columns
- [x] 38. `fct_product_details` table has columns: id, post_id, fulfillment_type, min_price, max_price, default_variation_id, variation_type, stock_availability, other_info, default_media, manage_stock, manage_downloadable

### Fillable & Casts
- [x] 39. `post_id` cast to integer, `min_price` and `max_price` cast to double
- [x] 40. All 11 fillable fields are mass-assignable

### Accessors & Mutators
- [x] 41. `other_info` mutator JSON-encodes arrays on set; getter JSON-decodes on get; null/empty returns empty structure
- [x] 42. `default_media` mutator JSON-encodes arrays on set; getter JSON-decodes on get
- [x] 43. `formatted_min_price` accessor returns `Helper::toDecimal(min_price)` (cents to decimal)
- [x] 44. `formatted_max_price` accessor returns `Helper::toDecimal(max_price)` (cents to decimal)
- [x] 45. `featured_media` accessor returns first gallery image

### Relationships
- [x] 46. `product()` belongsTo Product via post_id
- [x] 47. `variants()` hasMany ProductVariation via post_id, ordered by serial_index ASC
- [x] 48. A product's attribute term links are read through its variations; the product detail has no term-link relation of its own.

### Methods
- [x] 49. `hasPriceVariation()` returns true when min_price != max_price for simple products
- [x] 50. `getStockAvailability()` returns array with manage_stock, availability, class, available_quantity
- [x] 51. `getStockAvailability($variationId)` passes variationId to the `fluent_cart/product_stock_availability` filter (no branching in the method itself)
- [x] 52. Deleting a ProductDetail never removes variation term links, even when a variation id equals the detail id.

## Model: ProductVariation

### Table & Columns
- [x] 53. `fct_product_variations` table has columns: id, post_id, media_id, serial_index, sold_individually, variation_title, variation_identifier, sku, manage_stock, payment_type, stock_status, backorders, total_stock, available, committed, on_hold, fulfillment_type, item_status, manage_cost, item_price, item_cost, compare_price, other_info, downloadable, shipping_class

### Casts
- [x] 54. Numeric casts: post_id (int), media_id (int), item_cost (double), item_price (double), compare_price (double), backorders (int), total_stock (int), available (int), committed (int), on_hold (int), sold_individually (int), serial_index (int), other_info (array)

### Accessors & Mutators
- [x] 55. `other_info` getter includes subscription defaults: installment, repeat_interval, times, trial_days, manage_setup_fee
- [x] 56. `formatted_total` accessor returns `Helper::toDecimal(item_price)`
- [x] 57. `thumbnail` accessor returns first media URL or null when no media set

### Relationships
- [x] 58. `product()` belongsTo Product via post_id
- [x] 59. `product_detail()` belongsTo ProductDetail via post_id
- [x] 60. `shippingClass()` belongsTo ShippingClass via shipping_class
- [x] 61. `media()` hasOne ProductMeta where meta_key = 'product_thumbnail'
- [x] 62. `product_downloads()` hasMany ProductDownload
- [x] 63. `order_items()` hasMany OrderItem via object_id
- [x] 64. `downloadable_files()` hasMany ProductDownload via product_variation_id
- [x] 65. `upgrade_paths()` hasMany Meta where object_type matches PlanUpgradeService meta type
- [x] 66. `bundleChildren()` returns BundleChildrenRelation based on other_info.bundle_child_ids

### canPurchase()
- [x] 67. Returns true for active variant with published product and available stock
- [x] 68. Returns WP_Error when item_status is not 'active'
- [x] 69. Returns WP_Error when product post_status is not 'publish'
- [ ] 70. Returns WP_Error when manage_stock is enabled and available < requested quantity
Skipped: canPurchase() checks ModuleSettings::isActive("stock_management") which requires module settings to be initialized. In the test environment, the stock check branch is never reached.
- [x] 71. Returns WP_Error for subscription variant when quantity > 1 (max 1 subscription)
- [ ] 72. Returns WP_Error when bundle child variation is out of stock
Skipped: canPurchase() bundle child stock check relies on fluent_cart/variation/can_purchase_bundle filter (Pro StockManagement module). Without Pro active, the filter is not registered.

### isStock()
- [x] 73. Returns true when item_status is active, manage_stock is on, and available > 0
- [x] 74. Returns false when stock_status is 'out-of-stock' and manage_stock is off
- [x] 75. Returns false when manage_stock is on and available <= 0 (no backorders)
- [ ] 76. Returns true when available <= 0 but backorders is enabled

### Other Methods
- [x] 77. `getSubscriptionTermsText()` returns formatted subscription billing text (e.g., "$10/month")
- [x] 78. `getPurchaseUrl()` returns instant checkout URL for the variant
- [x] 79. `soldIndividually()` delegates to product->soldIndividually()
- [x] 80. Deleting variant cascades deletion of media via Meta::deleteVariationMedia() and attrMap()

## Model: ProductDownload

### Table & Columns
- [x] 81. `fct_product_downloads` table has columns: id, post_id, product_variation_id, download_identifier, title, type, driver, file_name, file_path, file_url, file_size, settings, serial

### Accessors & Mutators
- [x] 82. `settings` mutator JSON-encodes with UNESCAPED_UNICODE | UNESCAPED_SLASHES; getter JSON-decodes
- [x] 83. `product_variation_id` mutator converts array/numeric to JSON array; getter returns array from JSON or empty array

### Relationships
- [x] 84. `product()` belongsTo Product via post_id
- [x] 85. `download_permissions()` hasMany OrderDownloadPermission via download_id

### Methods
- [x] 86. `getSignedDownloadUrl()` returns a signed download URL string via DownloadService

## Model: ProductMeta

### Table & Columns
- [x] 87. `fct_product_meta` table has columns: id, object_id, object_type, meta_key, meta_value

### Accessors & Mutators
- [x] 88. `meta_value` mutator JSON-encodes arrays/objects with UNESCAPED_UNICODE | UNESCAPED_SLASHES; getter JSON-decodes or returns as-is for non-JSON strings

## Resource: ProductResource

### create()
- [x] 89. Creates a WordPress post with `post_type = 'fluent-products'` and a ProductDetail record; returns success with ID. Note: ProductResource::create() does NOT create an initial variant — the controller does.
- [x] 90. Sets default `post_status = 'draft'` for new products
- [x] 91. Creates ProductDetail with fulfillment_type from input
- [ ] 92. Initial variant gets default item_price of 0 (in cents) — applies to controller path, not resource

### find()
- [x] 93. `find($id)` delegates to ShopResource::find() and returns product with detail and variants
- [x] 94. `find($nonExistentId)` returns null

### update()
- [x] 95. Updates WordPress post fields (title, content, excerpt, status)
- [x] 96. Batch updates variant prices — stores the submitted cents unchanged (since #2615 the editor sends cents; the resource only normalizes with Helper::roundCent())
- [x] 97. Recalculates min_price and max_price on ProductDetail from all variant item_prices after update
- [ ] 98. Dispatches StockChanged event when stock-related fields change
- [ ] 99. Updates gallery images and thumbnail when provided
- [ ] 100. With future post_date, sets post_status to 'future' and converts to GMT

### delete()
- [ ] 101. Prevents deletion if product has orders with PROCESSING or ON_HOLD status — returns error
- [x] 102. Successful deletion cascades to: variants, detail, downloads, licenses, taxonomy terms
- [ ] 103. Logs successful product deletion

### manageBulkActions()
- [ ] 104. `delete_products` action deletes multiple products (respects pending order check per product)
- [ ] 105. `duplicate_products` action duplicates multiple products with options

### syncVariantOption()
- [ ] 106. Syncs advanced variant attribute combinations via ProductAdminHelper for a given product

### countTotalProducts()
- [x] 107. Returns count of products that have both a ProductDetail and at least one ProductVariation

## Resource: ProductVariationResource

### create()
- [x] 108. Creates variant with other_info processed based on payment_type (onetime strips subscription-only keys; subscription stores an enabled setup fee as the submitted cents and strips fee keys when setup fee is disabled)
- [x] 109. Stores item_price, compare_price, item_cost as the submitted cents unchanged (Helper::roundCent() normalization only, since #2615)
- [ ] 110. Sets stock_status based on manage_stock and available quantity
- [ ] 111. Updates parent ProductDetail via variant_modified action after creation

### update()
- [ ] 112. Preserves existing other_info fields (is_bundle_product, bundle_child_ids) when updating
- [ ] 113. Stores the submitted cents unchanged on update (since #2615)
- [ ] 114. Updates or deletes media associations based on input
- [ ] 115. Updates parent ProductDetail via variant_modified action after update

### delete()
- [x] 116. Prevents deletion if variant has orders with PROCESSING or ON_HOLD status
- [ ] 117. Deletes media associations on variant deletion
- [ ] 118. Logs successful variant deletion

### setImage()
- [ ] 119. Sets variant thumbnail via ProductMetaResource (creates or updates)

### updatePricingTable()
- [x] 120. Updates variant description in other_info

## Resource: ProductDetailResource

### update() with action='variant_modified'
- [x] 121. Sets stock_availability to IN_STOCK if manage_stock is off
- [ ] 122. Sets stock_availability based on whether any variant has in-stock status when manage_stock is on

### update() with action='change_variation_type'
- [x] 123. Changing to 'simple' variation_type deletes orphan variants (keeps only the first/default)

### update() — price range
- [ ] 124. Updates min_price and max_price; preserves existing values if not provided in input

### update() — other_info merge
- [x] 125. Merges new other_info with existing (new values overwrite, existing values preserved)
- [ ] 126. Stores subscription signup_fee as the submitted cents unchanged (since #2615)

## REST API: /fluent-cart/v2/products

### GET /products
- [x] 127. Returns paginated product list with default sort by id DESC
- [x] 128. Supports search filtering by post_title
- [x] 129. Supports filter by post_status (publish, draft, future, trash)

### POST /products
- [x] 130. Creates product with required post_title (max 200 chars), returns success with product ID
- [x] 131. Returns validation error when post_title is missing
- [ ] 132. Accepts detail.fulfillment_type ('physical' or 'digital'); defaults to 'digital' if empty
- [ ] 133. Accepts detail.other_info.is_bundle_product ('yes' or 'no')

### GET /products/{product}
- [x] 134. Returns product data (title, type, ID) for existing product. Note: detail/variants only loaded when `?with=` param is passed; the default response is the route-model-bound Product.
- [x] 135. Returns error for non-existent product ID

### GET /products/{productId}/pricing
- [x] 136. Returns full product with relationships and taxonomy terms

### POST /products/{postId}/pricing
- [ ] 137. Updates product with validated fields — post_title required, max 200 chars
- [ ] 138. Validates post_excerpt max 55 words
- [ ] 139. Validates variant compare_price >= item_price
- [ ] 140. Validates subscription fields: repeat_interval required when payment_type is 'subscription'
- [ ] 141. Validates signup_fee and signup_fee_name required when manage_setup_fee is 'yes'
- [ ] 142. Returns validation error for invalid detail.other_info.group_pricing_by (must be payment_type, repeat_interval, or none)

### DELETE /products/{product}
- [x] 143. Deletes product and cascades to related records
- [ ] 144. Returns error if product has pending/processing orders

### POST /products/{productId}/duplicate
- [x] 145. Duplicates product with all data; returns new product ID

### POST /products/do-bulk-action
- [x] 146. With action=delete_products deletes selected products
- [ ] 147. With action=duplicate_products duplicates selected products

### POST /products/bulk-insert
- [ ] 148. Creates up to 10 products in bulk
- [ ] 149. Enforces max 10 product limit

### GET /products/bulk-edit-data
- [ ] 150. Returns products data formatted for bulk editing

### POST /products/bulk-update
- [ ] 151. Updates up to 10 products in bulk

## REST API: Variants

### POST /products/variants
- [x] 152. Creates variant with required fields: variation_title (max 200), fulfillment_type, other_info.payment_type (onetime|subscription)
- [ ] 153. Validates SKU uniqueness across fct_product_variations table (max 30 chars)
- [ ] 154. Validates item_price is numeric and min:0
- [ ] 155. Validates stock fields required: total_stock, available, committed, on_hold (all numeric)
- [ ] 156. Validates trial_days max 365 for subscription variants

### POST /products/variants/{variantId}
- [x] 157. Updates variant fields and returns updated variant

### DELETE /products/variants/{variantId}
- [x] 158. Deletes variant; returns error if variant has processing/on_hold orders

### POST /products/variants/{variantId}/setMedia
- [ ] 159. Sets variant thumbnail image

### PUT /products/variants/{variantId}/pricing-table
- [ ] 160. Updates variant pricing description

## REST API: Inventory & Stock

### PUT /products/{postId}/update-inventory/{variantId}
- [ ] 161. Updates variant stock levels (total_stock, available, committed, on_hold)

### PUT /products/{postId}/update-manage-stock
- [x] 162. Toggles stock management on/off for product

## REST API: Tax & Shipping Classes

### POST /products/{postId}/tax-class
- [ ] 163. Sets tax class on product detail
### POST /products/{postId}/tax-class/remove
- [ ] 164. Removes tax class from product detail
### POST /products/{postId}/shipping-class
- [ ] 165. Sets shipping class on product variants
### POST /products/{postId}/shipping-class/remove
- [ ] 166. Removes shipping class from product variants

## REST API: Taxonomy

### POST /products/sync-taxonomy-term/{postId}
- [ ] 167. Syncs category/tag/brand terms to product
### POST /products/delete-taxonomy-term/{postId}
- [ ] 168. Deletes specific taxonomy term from product
### POST /products/add-product-terms
- [ ] 169. Creates new taxonomy term and associates with product
### GET /products/fetch-term
- [x] 170. Returns all formatted taxonomy terms (categories, tags, brands)

## REST API: Search & Utility

### GET /products/searchProductByName
- [x] 171. Searches published products by name, returns matching products
### GET /products/searchVariantByName
- [ ] 172. Searches variants by name across all products
### GET /products/search-product-variant-options
- [ ] 173. Searches products/variants respecting stock and subscription filters
### GET /products/suggest-sku
- [x] 174. Generates unique SKU from product title; appends numeric suffix if SKU already exists
### GET /products/fetchProductsByIds
- [x] 175. Returns products matching provided ID list
### GET /products/fetchVariationsByIds
- [ ] 176. Returns variations matching provided ID list

## REST API: Bundle Products

### GET /products/get-bundle-info/{productId}
- [ ] 177. Returns bundle structure with child variation IDs and their details
### POST /products/save-bundle-info/{variationId}
- [ ] 178. Saves bundle child IDs to variant other_info; prevents nested bundles (bundle cannot contain another bundle's variant)

## REST API: Upgrade Paths

### GET /products/{id}/upgrade-paths
- [ ] 179. Returns upgrade path settings for product
### POST /products/{id}/upgrade-path
- [ ] 180. Creates upgrade path — validates from_variant and to_variants exist in fct_product_variations
### POST /products/upgrade-path/{id}/update
- [ ] 181. Updates existing upgrade path
### DELETE /products/upgrade-path/{id}/delete
- [ ] 182. Deletes upgrade path
### GET /products/variation/{variantId}/upgrade-paths
- [ ] 183. Returns available upgrade paths for a specific variation

## REST API: Downloadable Files

### POST /products/{postId}/sync-downloadable-files
- [ ] 184. Syncs downloadable files to product/variants
### PUT /products/{downloadableId}/update
- [ ] 185. Updates downloadable file metadata
### DELETE /products/{downloadableId}/delete
- [ ] 186. Deletes downloadable file
### GET /products/getDownloadableUrl/{downloadableId}
- [ ] 187. Returns download URL for file

## REST API: Product Detail

### POST /products/detail/{detailId}
- [ ] 188. Updates product detail (change variation_type); changing to 'simple' cleans up orphan variants

## REST API: Other

### GET /products/{productId}/pricing-widgets
- [x] 189. Returns sales statistics widget data for product
### POST /products/{postId}/update-long-desc-editor-mode
- [x] 190. Sets editor mode to 'wp-editor' or 'custom'
### GET /products/{productId}/related-products
- [ ] 191. Returns related products based on shared category/brand terms

## Permissions

### Route Permissions
- [ ] 192. All GET /products/* routes require `products/view` permission
- [ ] 193. POST /products (create) requires `products/create` permission
- [ ] 194. POST /products/{postId}/pricing (update) requires `products/edit` permission
- [ ] 195. DELETE /products/{product} requires `products/delete` permission
- [ ] 196. POST /products/variants requires `products/create` permission
- [ ] 197. POST /products/variants/{variantId} (update) requires `products/edit` permission
- [ ] 198. DELETE /products/variants/{variantId} requires `products/delete` permission
- [x] 199. All three permission keys (products/view, products/create, products/edit, products/delete) registered in PermissionManager

### Access Control
- [x] 200. Admin user can access all product endpoints
- [ ] 201. Unauthenticated request returns 401/403
- [ ] 202. User without products/view cannot GET /products
- [ ] 203. User without products/create cannot POST /products
- [ ] 204. User without products/edit cannot update product/variant
- [ ] 205. User without products/delete cannot delete product/variant
Specs 201-205 skipped: Helper::getCurrentUser() caches the resolved user in a function-static variable with no public reset seam. User-context switching is not deterministic in a shared test process.

## Validation: ProductCreateRequest
- [x] 206. post_title is required, max 200 chars
- [x] 207. detail.fulfillment_type must be 'physical' or 'digital'; defaults to 'digital' if empty
- [x] 208. post_title is sanitized via sanitize_text_field

## Validation: ProductUpdateRequest
- [ ] 209. post_title required, max 200 chars; post_status required
- [ ] 210. post_excerpt max 55 words (custom word count validation)
- [ ] 211. post_date must be future date when post_status is 'future'
- [ ] 212. detail.fulfillment_type required, max 100 chars
- [ ] 213. detail.variation_type required, max 100 chars
- [ ] 214. detail.other_info.group_pricing_by must be in: payment_type, repeat_interval, none
- [ ] 215. detail.other_info.sold_individually must be in: yes, no
- [ ] 216. variants.*.variation_title required, max 200 chars
- [ ] 217. variants.*.item_price numeric, min:0; compare_price must be >= item_price
- [ ] 218. Subscription variant: other_info.payment_type required (onetime|subscription), repeat_interval required if subscription
- [ ] 219. Subscription variant: manage_setup_fee required if subscription; signup_fee and signup_fee_name required if manage_setup_fee is 'yes'

## Validation: ProductVariationRequest
- [ ] 220. variation_title required, max 200 chars
- [ ] 221. SKU nullable, max 30 chars, unique in fct_product_variations (excludes current record on update)
- [ ] 222. fulfillment_type required, max 100 chars
- [ ] 223. Stock fields: total_stock, available, committed, on_hold all required and numeric
- [ ] 224. stock_status required when manage_stock is 1
- [ ] 225. trial_days nullable, numeric, max 365
- [ ] 226. item_cost required when manage_cost is enabled

## Validation: UpgradePathSettingRequest
- [ ] 227. from_variant required, must exist in fct_product_variations
- [ ] 228. to_variants required array, each must exist in fct_product_variations
- [ ] 229. discount_amount must be numeric

## Edge Cases

- [x] 230. Product with `post_status = 'auto-draft'` is excluded from all queries via global scope
- [ ] 231. Deleting a product with pending orders returns error; product is preserved
- [ ] 232. Deleting the last variant of a product — detail min_price and max_price should handle gracefully
- [x] 233. Variant with payment_type='subscription' and quantity > 1 — canPurchase returns WP_Error
- [ ] 234. Bundle product where a child variant is out of stock — parent canPurchase returns false
- [ ] 235. Bundle product cannot contain another bundle's variant (prevents nested bundles)
- [ ] 236. SKU generation from title with special characters — sanitized and made unique with numeric suffix
- [x] 237. Price stored as 0 cents when item_price is empty or null (afterValidation normalization)
- [x] 238. Changing variation_type from 'simple_variations' to 'simple' deletes orphan variants, keeps only default
- [x] 239. Product update fires `fluent_cart/product_updated` action with data and product
- [x] 240. ProductVariation other_info preserves bundle data (is_bundle_product, bundle_child_ids) across updates
- [x] 241. ProductDetail min_price/max_price auto-calculated as min/max of all variant item_prices
- [x] 242. Subscription signup_fee stored as the submitted cents when manage_setup_fee = 'yes' (since #2615)

## Cents normalization at the resource boundary (Integration)

Since #2615 the editor converts dollars to cents and the resources normalize
what arrives. These sentences pin the normalization itself, which whole-cent
fixtures cannot see: `roundCent(1999)` and `(int) 1999` agree.

Numbering starts at 282, not 243. IDs 243-281 are already claimed by inline
`// Spec N:` comments in `Integration/Http/ProductPartialUpdateTest.php` for the
partial-update path; those sentences were never written into this file, so the
list above ends at 242 while the numbers are taken. Grep the product tests for
`Spec <n>`, not just this file, before assigning a new ID.

- [x] 282. Creating a variant through the resource rounds a float-artifact price to the nearest whole cent instead of truncating it — item price, compare price and item cost each land on the cent the browser meant.
- [x] 283. Creating a variant rounds a half cent up rather than down, so a price that lands exactly between two cents never loses one.
- [x] 284. Creating a subscription variant with an enabled setup fee rounds a float-artifact fee to the nearest whole cent.
- [x] 285. Updating a product through the resource rounds a float-artifact variant price to the nearest whole cent, on the update path as well as create.
- [x] 286. Updating a product detail rounds a float-artifact subscription setup fee to the nearest whole cent.
- [x] 287. Creating a variant rounds a price below the half cent down, so normalization is nearest-cent rounding and not a ceiling.
