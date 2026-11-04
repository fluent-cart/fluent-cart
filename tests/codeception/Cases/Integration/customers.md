# Customers

## Model: Customer

### Table & Columns
- [x] 1. `fct_customers` table has columns: id, user_id, contact_id, email, first_name, last_name, status, purchase_value, purchase_count, ltv, first_purchase_date, last_purchase_date, aov, notes, uuid, country, city, state, postcode, created_at, updated_at

### Boot & UUID Generation
- [x] 2. Creating a Customer auto-generates a `uuid` via `md5(email + wp_generate_uuid4())`
- [x] 3. Two customers created with the different email get different UUIDs (uuid4 component differs)

### Fillable & Mass Assignment
- [x] 4. All 18 fillable fields (user_id, contact_id, email, first_name, last_name, status, purchase_value, purchase_count, ltv, first_purchase_date, last_purchase_date, aov, notes, uuid, country, city, state, postcode) are mass-assignable

### Accessors & Mutators
- [x] 5. `full_name` accessor returns trimmed `"{first_name} {last_name}"`; returns just first_name if last_name is empty
- [x] 6. `purchase_value` mutator JSON-encodes arrays/objects; getter JSON-decodes back; null/empty returns null
- [x] 7. `country_name` accessor returns human-readable country name from country code via `Helper::getCountryName()`
- [x] 8. `formatted_address` accessor returns array with keys: country, state, city, postcode, first_name, last_name, full_name
- [x] 9. `user_link` accessor returns `admin_url('user-edit.php?user_id=...')` when user_id is set, empty string when null
- [x] 10. `photo` accessor returns custom photo URL from user meta `fc_customer_photo_url` if set, otherwise falls back to Gravatar URL

### Scopes
- [x] 11. `scopeOfActive` filters to `status = 'active'` only
- [x] 12. `scopeOfArchived` filters to `status = 'archived'` only
- [x] 13. `scopeSearchBy` with plain text searches across first_name, last_name, email using LIKE
- [x] 14. `scopeSearchBy` with "first last" splits and searches first_name LIKE "first%" AND last_name LIKE "last%"
- [x] 15. `scopeSearchBy` with operator syntax (e.g., `id=5`, `email!=foo`) applies operator-based where clause on valid fillable columns
- [x] 16. `scopeSearchBy` with colon syntax (e.g., `email:test`) applies LIKE search on the specified column
- [x] 17. `scopeApplyCustomFilters` applies filters with operators: includes (like_all), not_includes (not_like), gt (>), lt (<)
- [x] 18. `scopeSearchByFullName` concatenates first_name + last_name and applies LIKE with starts_with, ends_with, not_like, and contains patterns

### Relationships
- [x] 19. `orders()` hasMany Order via customer_id — returns collection of orders
- [x] 20. `subscriptions()` hasMany Subscription via customer_id
- [x] 21. `shipping_address()` hasMany CustomerAddresses filtered by type='shipping'
- [x] 22. `billing_address()` hasMany CustomerAddresses filtered by type='billing'
- [x] 23. `primary_shipping_address()` hasOne CustomerAddresses where type='shipping' AND is_primary=1
- [x] 24. `primary_billing_address()` hasOne CustomerAddresses where type='billing' AND is_primary=1
- [x] 25. `success_order_items()` hasManyThrough OrderItem via Order, filtered to orders with successful payment statuses
- [x] 26. `labels()` morphMany LabelRelationship
- [x] 27. `wpUser()` belongsTo User via user_id

### Methods
- [x] 28. `recountStat()` calculates purchase_count from orders with successful payment statuses, sets first/last_purchase_date from min/max created_at, computes ltv as sum of (total_paid - total_refund) for positive net amounts, and aov as ltv / purchase_count
- [x] 29. `recountStat()` with no orders sets purchase_count=0, ltv=0, aov=0, dates=null
- [x] 30. `recountStats()` (legacy) counts all orders regardless of payment status and sums total_amount
- [x] 31. `updateCustomerStatus()` changes status, fires `fluent_cart/customer_status_to_{new}` and `fluent_cart/customer_status_updated` hooks with customer, old_status, new_status
- [x] 32. `updateCustomerStatus()` with same status as current does nothing and returns early (no hooks fired)
- [x] 33. `getMeta()` returns meta_value for existing key, returns $default for non-existent key
- [x] 34. `updateMeta()` creates new CustomerMeta if key doesn't exist, updates existing if it does
- [x] 35. `getWpUser()` returns WP user by user_id only; no email fallback, never writes (identity binding Case 2)
- [x] 36. `getWpUserId($recheck=true)` returns the stored user_id; `$recheck` no longer looks up by email or writes (identity binding Case 2)

## Model: CustomerAddresses

### Table & Columns
- [x] 37. `fct_customer_addresses` table has columns: id, customer_id, is_primary, type, status, label, name, address_1, address_2, city, state, postcode, country, phone, email, meta, created_at, updated_at (note: no company_name column — it is derived from meta JSON via `meta.other_data.company_name`)

### Accessors & Mutators
- [x] 38. `meta` mutator JSON-encodes value on set; getter JSON-decodes on get; null/empty returns empty array
- [x] 39. `company_name` is a virtual attribute — setter writes to `meta.other_data.company_name` inside the JSON `meta` column; getter reads it back from the same path (no dedicated DB column exists)
- [x] 40. `formatted_address` accessor returns array with keys: country, state, city, postcode, address_1, address_2, type, name, first_name, last_name, full_name, company_name, label, phone, full_address (comma-joined non-empty parts)

### Scopes
- [x] 41. `scopeOfActive` filters to status='active'; `scopeOfArchived` filters to status='archived'

### Relationships
- [x] 42. `customer()` belongsTo Customer via customer_id

### Methods
- [x] 43. `getFormattedDataForCheckout('billing_')` returns array with billing_full_name, billing_address_1, billing_address_2, billing_city, billing_state, billing_phone, billing_postcode, billing_country, billing_company_name
- [x] 44. `getFormattedDataForCheckout('shipping_')` returns array with shipping_ prefix

## Model: CustomerMeta

### Table & Columns
- [x] 45. `fct_customer_meta` table has columns: id, customer_id, meta_key, meta_value

### Accessors & Mutators
- [x] 46. `meta_value` mutator JSON-encodes arrays/objects with UNESCAPED_UNICODE; getter JSON-decodes strings, returns original if decode fails
- [x] 47. `meta_value` getter returns original value if JSON decode returns null (plain string stored)

### Relationships
- [x] 48. `customer()` belongsTo Customer via customer_id

## Resource: CustomerResource

### create()
- [x] 49. Creates a customer with required fields (first_name, email), sets purchase_value to empty array, generates uuid
- [x] 50. Uses `firstOrCreate` — creating with a duplicate email returns WP_Error "Customer already exists"
- [x] 51. If a WP user with matching email exists, auto-links user_id to the customer
- [x] 52. With `wp_user=yes` and no existing WP user, creates a WP user via AuthService::createUserFromCustomer

### find()
- [x] 53. `find($id)` returns `['customer' => $customer]`; `selected_labels` is only populated when `labels` relation is present in the result (eager-loaded via `with`). Note: `CustomerController::find()` computes `selected_labels` separately from the resource method
- [x] 54. `find($nonExistentId)` returns `['customer' => null]`

### update()
- [x] 55. Updates customer fields and returns refreshed customer
- [x] 56. If customer has user_id != 0, also updates the linked WP user's first_name, last_name, display_name
- [x] 57. Update with non-existent customer ID returns WP_Error "Customer not found"
- [x] 58. When customer has linked WP user, email is preserved from existing customer (not overwritten from input)

### delete()
- [x] 59. Deletes customer(s) by IDs array and also deletes associated orders
- [x] 60. Deleting multiple customers in one call removes all specified customers

### updateStatus()
- [x] 61. Changes status of multiple customers to a valid status
- [x] 62. Returns error for empty new_status
- [x] 63. Returns error for invalid status not in `Status::getEditableCustomerStatuses()`

### manageCustomer()
- [x] 64. With action='delete_customers' delegates to delete()
- [x] 65. With action='change_customer_status' delegates to updateStatus()
- [x] 66. With empty customer_ids returns error "Customers selection is required"
- [x] 67. With invalid action returns error "Selected action is invalid"

### getCurrentCustomer()

Specs 68, 69, and 71 are still exercised via actual `getCurrentCustomer()` calls. Specs 70 and 72 are currently skipped because `CustomerResource::getCurrentCustomer()` caches results in a function static variable without a public reset seam, so fresh-resolution scenarios are not deterministic in this suite.

- [x] 68. Returns null when user is not logged in
- [x] 69. Returns existing customer matched by user_id or email for logged-in user
- [ ] 70. With createIfNotExists=true, creates new customer from WP user data if none exists
- [x] 71. Caches result in static variable — second call returns same instance without DB query
- [ ] 72. If existing customer's user_id doesn't match current WP user ID, updates user_id

### updateAdditionalInfo()
- [x] 73. Updates customer labels via LabelResource::addLabelToLabelRelationships
- [x] 74. Returns error when customer not found (ID=404)
- [x] 75. Returns error when no label changes provided

## Resource: CustomerAddressResource

### create()
- [x] 76. Creates address with customer_id, auto-sets is_primary=1 if no primary address of that type exists
- [x] 77. If a primary address already exists for that type, new address gets is_primary=0 (default)
- [x] 78. When order_id is provided and address is primary, also creates/updates OrderAddress

### find()
- [x] 79. Returns first address fields (address_1, address_2, city, state, postcode, country) for customer by type
- [x] 80. Returns empty array when no addresses exist

### update()
- [x] 81. Updates address fields and returns refreshed address
- [x] 82. Returns error for null/missing address ID
- [x] 83. Returns error for non-existent address ID
- [x] 84. When order_id provided, also updates the corresponding OrderAddress

### delete()
- [x] 85. Cannot delete a primary address — returns error "Primary address cannot be deleted"
- [x] 86. Cannot delete when only 1 address remains — returns error "At least one address must remain"
- [x] 87. Deletes non-primary address when customer has multiple addresses
- [x] 88. Returns error for null ID or non-existent address

### makePrimary()
- [x] 89. Sets all addresses of same type for customer to is_primary=0, then sets target address to is_primary=1
- [x] 90. Returns error if address doesn't match customer_id and type

## Filter: CustomerFilter

- [x] 91. Simple search matches against CONCAT(first_name, ' ', last_name) LIKE pattern
- [x] 92. Numeric search also matches against customer id
- [x] 93. Non-space search also matches against email
- [x] 94. Advance filter by customer_full_name uses searchByFullName scope
- [x] 95. Advance filter by email filters on email column
- [x] 96. Advance filter by ltv filters on ltv column (treated as cent column)
- [x] 97. Date columns include first_purchase_date and last_purchase_date

## REST API: /fluent-cart/v2/customers

### GET /customers
- [x] 98. Returns paginated customer list with default sort by id DESC
- [x] 99. Supports search parameter filtering by name/email
- [x] 100. Supports sort_by and sort_type parameters

### POST /customers
- [x] 101. Creates customer with valid data (email required; name field depends on store config — first_name when `isFullNameRequired()` is false, full_name when true), returns success response
- [x] 102. Returns validation error when email is missing
- [x] 103. Returns validation error when name field is missing (first_name or full_name depending on `CheckoutFieldsSchema::isFullNameRequired()`)
- [x] 104. Returns error when email already exists for another customer

### GET /customers/{id}
- [x] 105. Returns customer with labels; `selected_labels` is computed by the controller (not the resource) from the labels relation
- [x] 106. Returns 404-style error for non-existent customer ID

### PUT /customers/{id}
- [x] 107. Updates customer fields and returns updated customer
- [x] 108. Returns error for non-existent customer ID

### GET /customers/{id}/orders
- [x] 109. Returns paginated orders filtered by customer_id

### GET /customers/{id}/address?type=billing
- [x] 110. Returns billing addresses for the customer
- [x] 111. Returns shipping addresses when type=shipping

### POST /customers/{id}/address
- [x] 112. Creates new address for customer with required fields (name, email, address_1, city, country) (TC-009 fix commit: omitting the `nullable` `label` 500'd — `Request::getSafe`/pickKeys null-fills omitted sanitize-map keys into the NOT NULL DEFAULT '' column; `CustomerAddressResource::create()` now coerces omitted label to ''. The same getSafe root cause in shipping zone updates was fixed in #2516.)
- [x] 113. Returns validation error for missing required address fields

### PUT /customers/{id}/address
- [x] 114. Updates existing address

### DELETE /customers/{id}/address
- [x] 115. Deletes non-primary address when multiple exist

### POST /customers/{id}/address/make-primary
- [x] 116. Sets specified address as primary, unsets previous primary

### POST /customers/{id}/attachable-user
- [x] 117. Attaches WP user to customer that has no linked user
- [x] 118. Returns error when customer already has a linked WP user (unskipped TC-009: `RestTestCase::$rebuildRestServerPerDispatch` defeats the WPFluent `Route::getParameter()` stale-param memo — TESTABILITY-DEBT #3)

### POST /customers/{id}/detach-user
- [x] 119. Sets customer user_id to null
- [x] 120. Returns error for non-existent customer

### POST /customers/{id}/recalculate-ltv
- [x] 121. Calls recountStat() and returns updated customer with recalculated ltv, aov, purchase_count
- [x] 122. Returns error for non-existent customer

### POST /customers/do-bulk-action
- [x] 123. With action=delete_customers deletes selected customers
- [x] 124. With action=change_customer_status updates status for selected customers

### GET /customers/attachable-user
- [x] 125. Returns WP users that are NOT already linked to a customer

## Permissions

The admin-positive route check and permission-key registration still execute. The unauthenticated/subscriber route checks are currently skipped because `Helper::getCurrentUser()` caches the resolved user in a function static variable without a public reset seam, so user-context switching is not deterministic in this suite (CustomerPermissionsTest.php).

- [ ] 126. GET /customers returns 403 for subscriber without `customers/view`; 200 for admin
- [ ] 127. POST /customers and POST /do-bulk-action return 403 for subscriber without `customers/manage`
- [ ] 128. DELETE /customers/{id}/address returns 403 for subscriber without `customers/delete`
- [ ] 129. Unauthenticated GET and POST return 401 or 403
- [x] All three permission keys (`customers/view`, `customers/manage`, `customers/delete`) registered in PermissionManager

## Validation: CustomerRequest

- [x] 130. Email is required, must be valid email format, max 255 chars
- [x] 131. When `CheckoutFieldsSchema::isFullNameRequired()` returns true, `full_name` is required (max 255); when false, `first_name` is required (max 255) and `last_name` is optional
- [x] 132. Duplicate email check excludes current customer ID on update (allows keeping own email)
- [x] 133. Email sanitized via sanitize_email; text fields via sanitize_text_field

## Validation: CustomerAddressRequest

- [x] 134. Required fields: name, email (valid format), address_1, city, country
- [x] 135. Label max length is 15 characters
- [x] 136. company_name is optional, max 255 chars
- [x] 137. is_primary defaults to 0 in beforeValidation

## Edge Cases

- [x] 138. Customer with orders — deleting customer cascades to delete associated orders
- [x] 139. Customer created without user_id — getWpUser() returns false and does not link, even when a WP user holds the email (identity binding Case 2)
- [x] 140. recountStat() with refunded orders where total_refund > total_paid — negative net amounts are excluded from LTV (only positive net counted)
- [x] 141. updateCustomerStatus() fires both specific and generic hooks — listeners can act on `fluent_cart/customer_status_to_active` and `fluent_cart/customer_status_updated`
- [x] 142. CustomerMeta meta_value with nested JSON object — mutator encodes with UNESCAPED_UNICODE, getter decodes back to original structure
- [x] 143. Customer searchBy with invalid column in operator syntax (e.g., `invalid_col=5`) falls through to regular LIKE search
- [x] 144. CustomerAddressResource::create with order_id — when order already has address of same type, updates it; when not, creates new OrderAddress
- [x] 145. Customer `with` map, partial actor (IntegrationPro `PartialRolePermissionsTest`): a Pro-active manager — customers/view and subscriptions/view but no labels/view — resolves `billing_address` and `subscriptions`, `labels` drops out to its own gate, and the off-map `orders` stays refused; proves each `CustomerController::allowedWiths()` entry is gated on its own permission (the all-or-none Integration actors cannot discriminate a gate swapped to a permission the actor also holds)
