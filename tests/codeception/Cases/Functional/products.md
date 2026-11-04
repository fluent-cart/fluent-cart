# Products

## Listing and searching products
- [x] 1. Admin can list products, with the most recently created first by default.
- [x] 2. Admin can paginate the product list.
- [x] 3. Admin can request a specific page size when listing products.
- [x] 4. Admin receives an empty list, not an error, when requesting a page beyond the available results.
- [x] 5. Admin can search products by part of a product title and only matching products are returned.
- [x] 6. Admin can filter products by category and only products in that category are returned.
- [x] 7. Admin can filter products by status and only products with that status are returned.
- [x] 8. Admin sees product search input that resembles an SQL injection attempt treated as plain text without causing a server error.
- [x] 9. Admin can fetch products by id when given a list of product ids.
- [x] 10. Admin can fetch variations by id when given a list of variation ids.
- [x] 11. Admin can list product variations in a stable order.
- [x] 12. Admin can list every variant across all products for cross-product variant pickers.
- [x] 226. Admin can request the product variations listing without supplying any query parameters and receives an empty list instead of a server error.
- [x] 13. Admin can search variations by part of a variation title and only matching variations are returned.
- [ ] 14. Admin can search product variant options by attribute and only options matching the chosen attribute terms are returned.- [x] 15. Admin can request a list of subscription variations and only variations sold as subscriptions are returned.
- [x] 16. Admin can ask the system to suggest a unique SKU for a product title and the suggestion is not already in use.
- [x] 17. Admin is rejected when requesting an auto-generated SKU without providing a product title.
- [x] 18. Admin can read the configured maximum excerpt word count for product descriptions.
- [x] 19. Admin can fetch the list of available product taxonomy terms — categories and brands; product tags are deliberately not shipped. (won't-ship decision 2026-08-06; test pins product-tags absent from the catalog)
- [x] 20. Admin can fetch the child terms of a selected parent term.

## Viewing a single product
- [x] 21. Admin can open any product by id and see the product with its details and variations; the downloadable_files relation is never exposed through the `with` parameter, even when explicitly requested. (Hardened by b4cb8f1ea #2524 2026-08-06: `with` resolves against ProductController::allowedWiths(), which keeps downloadable_files — protected file paths — off the map; the edit screen loads downloads via its own endpoint.)
- [ ] 22. Admin receives a clear "not found" error when opening a product that does not exist.- [x] 23. Admin can view a product's pricing widgets when configuring storefront blocks.
- [x] 24. Admin can view related products for a given product, optionally constrained by category or brand.
- [x] 25. Admin can read a product's bundle configuration when it is a bundle.
- [x] 26. Admin can fetch a product's pricing summary on its own.

## Creating a product
- [x] 27. Admin can create a product with a title and a fulfilment type.
- [x] 28. Admin sees a unique identifier automatically assigned to a newly created product.
- [x] 29. Admin sees an initial variation automatically created and marked in stock when creating a simple product.
- [x] 29a. Admin creating a Simple Variations product gets no starter variation, because the pricing table builds them on the edit page.
- [x] 29b. Admin creating an Advanced Variations product gets no starter variation, because the attribute combinations build them on the edit page.
- [x] 30. Admin sees the product's creation timestamp recorded in UTC regardless of the site's timezone.
- [x] 31. Admin is rejected when creating a product without a title.
- [x] 32. Admin is rejected when creating a product with a fulfilment type other than "physical" or "digital".
- [x] 33. Admin can bulk-create up to ten products in a single request.
- [x] 34. Admin is rejected when bulk-creating more than ten products in a single request.
- [x] 35. Admin is rejected when bulk-creating with no products in the request.
- [x] 36. Admin can create seeded dummy products in a chosen category for store testing.
- [x] 37. Admin can duplicate a product.
- [x] 38. Admin sees the duplicate of a product saved as a draft so it is not visible on the storefront until reviewed.
- [x] 39. Admin can choose whether the duplicate inherits stock management, licence settings, or downloadable files from the original. (2026-08-06: the parked NULL-vs-empty-string claim does not reproduce — object_type persists as true NULL on this stack, verified against live rows and the test DB; test un-skipped and green with no production change)
- [ ] 40. Admin receives a clear "not found" error when duplicating a product that does not exist.- [x] 41. A worker without the "create products" permission cannot create a product.
- [x] 42. A store manager with the "create products" permission can create a product.
- [x] 43. An accountant cannot create a product.
- [x] 44. A worker without the "create products" permission cannot duplicate a product.
- [x] 45. A worker without the "create products" permission cannot bulk-create products.
- [x] 46. A worker without the "create products" permission cannot create dummy products.

## Updating a product
- [x] 47. Admin can rename a product.
- [x] 48. Admin can change a product's description.
- [x] 49. Admin can update a product's status.
- [x] 50. Admin can change a product's variation prices.
- [x] 50a. Admin can change one advanced variation's price on its own, sending only that variation's identity and the changed column, and the new price is stored.
- [x] 50b. Admin sees an advanced variation's stock, SKU and compare price left untouched when a price-only edit omits them, rather than reset from the editor's snapshot.
- [x] 50c. Admin sees every variation keep its display position when only one of them is edited, rather than the edited variation being renumbered to the front.
- [x] 50d. Admin sees display order preserved when a save carries a non-contiguous subset of the variations, such as editing the first and third but not the second.
- [x] 50e. Admin can still reorder variations, with the new order stored, when the save carries an explicit display position for every variation.
- [x] 51. Admin sees variation prices stored consistently in cents (e.g. $12.34 round-trips correctly).
- [x] 52. Admin sees the product's minimum and maximum price recalculated when variation prices change.
- [x] 53. Admin sees a "product updated" notification triggered when updating a product.
- [x] 54. Admin sees the product's modification timestamp recorded in UTC regardless of the site's timezone.
- [x] 55. Admin is rejected when updating a simple-variation product without sending any variant data.
- [x] 56. Admin can change the long-description editor mode on a product.
- [x] 57. Admin can save the variant option configuration on a product.
- [x] 58. Admin can create new category or brand terms for product assignment; the unshipped product-tags taxonomy is rejected. (won't-ship decision 2026-08-06)
- [x] 59. Admin can sync the full set of taxonomy term assignments for a product.
- [x] 60. Admin can remove a single taxonomy term assignment from a product.
- [x] 61. Admin can save a single product detail field on a product.
- [x] 61a. Switching a multi-variation product to Simple deletes the image on the variation it keeps.
- [x] 61b. Switching a product to Simple with a foreign variation id does not delete another product's image.
- [x] 62. Admin can load products into the bulk-edit spreadsheet with the fields needed for editing.
- [x] 63. Admin can bulk-update up to ten products' shared fields in a single request.
- [x] 64. Admin is rejected when bulk-updating more than ten products in a single request.
- [x] 65. A worker without the "edit products" permission cannot update a product.
- [x] 66. An accountant cannot update a product.
- [x] 67. A worker without the "edit products" permission cannot bulk-update products.
- [x] 68. A worker without the "edit products" permission cannot change a product's editor mode.
- [x] 69. A worker without the "edit products" permission cannot save variant option configuration.
- [x] 70. A worker without the "edit products" permission cannot sync taxonomy term assignments.
- [x] 231. Admin clearing a Simple product's SKU (explicit empty string) persists the cleared value; a previously stored SKU does not remain after reload, and a non-empty sku on a later save continues to save normally.
- [x] 231a. Admin omitting the sku field entirely on a Simple product update leaves the previously stored SKU unchanged.
- [x] 231b. Admin clearing one variant's SKU on a Simple Variations product persists the cleared value without affecting sibling variants' SKUs.
- [x] 231c. Admin clearing one variant's SKU on an Advanced Variations product persists the cleared value without affecting sibling variants' SKUs.
- [x] 231d. Admin clearing a variant's SKU through the bulk-edit spreadsheet on a Simple Variations product persists the cleared value, even though that path bypasses the standard product-update sanitizer.
- [x] 231e. Admin setting a variant's SKU to the literal string "0" persists it as "0", not NULL.
- [x] 231f. Admin attempting to give two variants the same non-empty SKU of the literal string "0" never ends up with both variants persisting that duplicate value — sku_unique still applies to "0" exactly as it does to any other real SKU.

## Deleting a product
- [x] 71. Admin can delete a product.
- [x] 72. Admin sees the product's variations, details, downloadable files, and licence metadata removed when the product is deleted.
- [x] 73. Admin sees the product's taxonomy term assignments cleared for categories, tags, and brands when the product is deleted.
- [x] 74. Admin is rejected when deleting a product that has pending orders attached so order processing is not disrupted.
- [x] 75. Admin sees a success entry written to the activity log when a product is deleted.
- [ ] 76. Admin receives a clear "not found" error when deleting a product that does not exist.- [x] 77. Admin can run a bulk delete action across selected products.
- [x] 78. Admin sees any product with pending orders excluded from a bulk delete action, with the reason listed in the response.
- [x] 79. A store manager with the "delete products" permission can delete a product.
- [x] 80. A worker without the "delete products" permission cannot delete a product.
- [x] 81. An accountant cannot delete a product.
- [x] 82. A worker without the "edit products" permission cannot run a bulk delete action.
- [x] 83. An accountant cannot run a bulk delete action.

## Working with variations
- [x] 84. Admin can add a new variation to a product.
- [x] 85. Admin sees a new variation linked to the product's detail record automatically.
- [x] 86. Admin can rename a variation.
- [x] 87. Admin can change a variation's price.
- [x] 88. Admin sees a variation's price stored consistently in cents.
- [x] 89. Admin can change a variation's payment type to subscription.
- [x] 90. Admin can attach images to a variation.
- [x] 91. Admin sees a variation's previous gallery replaced when new images are attached.
- [x] 92. Admin can update a variation's pricing-table description.
- [x] 93. Admin sees newlines preserved in a variation's pricing-table description.
- [x] 94. Admin can delete a variation.
- [x] 95. A worker without the "create products" permission cannot create a variation.
- [x] 96. A worker without the "edit products" permission cannot update a variation.
- [x] 97. A store manager with the "delete products" permission can delete a variation.
- [x] 98. An accountant cannot create a variation.
- [x] 99. An accountant cannot update a variation.
- [x] 100. An accountant cannot delete a variation.
- [x] 227. Admin sees the custom SKU length message when group-bulk-updating a variation with a SKU longer than 30 characters.
- [x] 228. Admin can group-bulk-update a variation's SKU when it is 30 characters or fewer.

## Managing inventory and stock
- [x] 101. Admin can update a variation's available stock count.
- [x] 102. Admin sees a variation's stock status recalculated automatically based on the new available quantity.
- [x] 103. Admin sees a variation marked out of stock when its available stock is set to zero.
- [x] 104. Admin can enable stock management on a product so all of its variations begin tracking stock.
- [x] 105. Admin sees every variation marked in stock regardless of stored quantity when stock management is disabled.
- [x] 106. Admin receives a clear "not found" error when updating inventory for a variation that does not exist. (Issue #2476: `ProductController::updateInventory` now returns an explicit 404, green since 2026-09-14 once the Cest arms the Stock Management module.)
- [x] 107. A worker without the "edit products" permission cannot update inventory.
- [x] 234. Admin cannot update a variation's inventory while the Stock Management module is disabled.
- [x] 108. An accountant cannot update inventory.
- [x] 232. Admin can change the price of a variation whose stored stock counters are empty while inventory tracking is off, without being asked to supply a stock quantity.
- [x] 233. Admin is still required to supply a stock quantity for a variation that has inventory tracking switched on.

## Managing downloadable files
- [x] 109. Admin can attach downloadable files to a product.
- [x] 110. Admin sees a unique download identifier automatically assigned to each downloadable file.
- [x] 111. Admin can rename a downloadable file.
- [x] 112. Admin can change a downloadable file's storage driver.
- [x] 113. Admin can update a downloadable file's variant assignments.
- [x] 114. Admin can delete a downloadable file from a product.
- [x] 115. Admin can request a time-limited download URL for a downloadable file.
- [x] 115a. The copied download URL expires within seven days. (SCAN-04: a week in seconds was passed as minutes, so links lasted about 420 days)
- [x] 115b. Worker and Accountant, who may only view products, cannot request a download URL. (SCAN-04: the URL carries no order, so it skips the purchase check)
- [x] 115c. Store manager, who may edit products, can still request a download URL.
- [ ] 116. Admin sees a downloadable file's URL expire after the configured window. (CODEX-REVIEW-NEEDED: stop-time review gate flagged the test as WEAK — only inspects signed valid_till + replicates FileDownloader inequality; never observes an expired URL be denied. Full proof requires dispatching the URL through the frontend `?fluent-cart=download-by-id` handler which is out of REST functional scope — see Tests\Functional\Rest\Products\GetProductDownloadableUrlCest::downloadableFileUrlExpiresAfterConfiguredWindow)
- [x] 117. Admin is rejected when attaching a downloadable file with missing required fields.
- [x] 229. Admin can attach a local downloadable file without a file_url and it succeeds.
- [x] 117a. A product editor cannot attach a downloadable file pointing at a bucket the store has not configured — the stored bucket is the store's own. (FC-SEC-04: settings.bucket was stored verbatim and fed straight into cloud download signing; it is now resolved from driver settings)
- [x] 117b. A product editor cannot substitute an unconfigured bucket by editing an existing downloadable file either.
- [x] 117c. A downloadable file already carrying a foreign bucket is still signed against the store's own bucket.
- [x] 117d. A product editor cannot attach a downloadable file to a storage driver the store has not enabled.
- [x] 117e. Attaching a downloadable file to local storage keeps working and stores no bucket.
- [x] 230. Admin is rejected when a provided file_url exceeds 200 characters.
- [x] 231. Admin can attach multiple local downloadable files, including one scoped to a variation, none supplying a file_url.
- [x] 118. A worker without the "edit products" permission cannot attach downloadable files.
- [x] 119. A worker without the "edit products" permission cannot update a downloadable file.
- [x] 120. A worker without the "delete products" permission cannot delete a downloadable file.
- [x] 121. An accountant cannot attach downloadable files.
- [x] 122. An accountant cannot update a downloadable file.
- [x] 123. An accountant cannot delete a downloadable file.

## Managing tax and shipping classes
- [x] 124. Admin can assign a tax class to a product.
- [x] 125. Admin sees a previously assigned tax class replaced when a new one is set.
- [x] 126. Admin can remove a product's tax class so it falls back to the store default.
- [x] 127. Admin receives a clear "not found" error when assigning a tax class that does not exist.
- [x] 128. Admin can assign a shipping class to a product.
- [x] 129. Admin sees a previously assigned shipping class replaced when a new one is set.
- [x] 130. Admin can remove a product's shipping class.
- [x] 131. A worker without the "edit products" permission cannot assign a tax class to a product.
- [x] 132. A worker without the "edit products" permission cannot remove a tax class from a product.
- [x] 133. A worker without the "edit products" permission cannot assign a shipping class to a product.
- [x] 134. A worker without the "edit products" permission cannot remove a shipping class from a product.
- [x] 135. An accountant cannot assign a tax class to a product.
- [x] 136. An accountant cannot remove a tax class from a product.
- [x] 137. An accountant cannot assign a shipping class to a product.
- [x] 138. An accountant cannot remove a shipping class from a product.

## Managing bundle products
- [x] 139. Admin can save a bundle's child variation list against a parent variation.
- [x] 140. Admin receives a clear "not found" error when saving bundle information for a variation that does not exist.
- [x] 141. Admin can read a product's bundle configuration to populate the bundle editor.
- [x] 142. A worker without the "edit products" permission cannot save bundle information.
- [x] 143. An accountant cannot save bundle information.

## Managing upgrade paths
- [x] 144. Admin can add an upgrade path to a product so customers can move to a higher-priced variation later.
- [x] 145. Admin can view the upgrade-path settings configured on a product.
- [x] 146. Admin can update an existing upgrade path's pricing rule.
- [x] 147. Admin can delete an upgrade path from a product.
- [x] 148. Admin can list a variation's available upgrade paths.
- [x] 149. Admin is rejected when adding an upgrade path with invalid pricing input.
- [x] 150. A worker without the "edit products" permission cannot add an upgrade path.
- [x] 151. A worker without the "edit products" permission cannot update an upgrade path.
- [x] 152. A worker without the "delete products" permission cannot delete an upgrade path.
- [x] 153. An accountant cannot add an upgrade path.
- [x] 154. An accountant cannot update an upgrade path.
- [x] 155. An accountant cannot delete an upgrade path.

## Managing product integrations
- [x] 156. Admin can list the integration feeds configured on a product.
- [x] 157. Admin sees the integration feed list filtered to integrations that are enabled and apply to products.
- [x] 158. Admin can open the settings panel for a specific product-scoped integration on a product.
- [x] 159. Admin can save a new integration feed against a product with the chosen provider.
- [x] 160. Admin can update an existing integration feed on a product.
- [x] 161. Admin sees previously saved integration settings replaced when a feed is updated.
- [x] 162. Admin can enable an integration feed on a product without deleting it.
- [x] 163. Admin can disable an integration feed on a product without deleting it.
- [x] 164. Admin can delete an integration feed from a product.
- [x] 165. Admin receives a clear "not found" error when saving an integration feed against a product that does not exist.
- [x] 166. Admin is rejected when saving an integration feed referencing an unknown integration name.
- [x] 167. A worker without the "edit products" permission cannot save a new integration feed.
- [x] 168. A worker without the "edit products" permission cannot update an existing integration feed.
- [x] 169. A worker without the "edit products" permission cannot toggle the enabled state of an integration feed.
- [x] 170. A worker without the "delete products" permission cannot delete an integration feed.
- [x] 171. An accountant cannot save a new integration feed.
- [x] 172. An accountant cannot update an existing integration feed.
- [x] 173. An accountant cannot toggle the enabled state of an integration feed.
- [x] 174. An accountant cannot delete an integration feed.

## Managing attributes (option groups)
- [x] 175. Admin can list the attribute groups defined for the store.
- [x] 176. Admin can open a single attribute group and see its editable details.
- [x] 177. Admin can create an attribute group such as "Color" or "Size".
- [x] 178. Admin can rename an attribute group.
- [ ] 179. Admin can edit an attribute group's settings.- [x] 180. Admin can delete an attribute group.
- [x] 181. Admin can list the terms defined inside an attribute group, in their configured display order.
- [ ] 182. Admin can add a term such as "Red" to an attribute group.- [ ] 183. Admin can rename a term inside an attribute group.- [x] 184. Admin can remove a term from an attribute group.
- [x] 185. Admin can reorder the terms inside an attribute group.
- [x] 186. A worker without the "create products" permission cannot create an attribute group.
- [x] 187. A worker without the "create products" permission cannot create a term inside an attribute group.
- [x] 188. A worker without the "edit products" permission cannot rename an attribute group.
- [x] 189. A worker without the "edit products" permission cannot rename an attribute term.
- [x] 190. A worker without the "edit products" permission cannot reorder attribute terms.
- [x] 191. A worker without the "delete products" permission cannot delete an attribute group.
- [x] 192. A worker without the "delete products" permission cannot delete an attribute term.
- [x] 193. An accountant cannot create an attribute group.
- [x] 194. An accountant cannot create a term inside an attribute group.
- [x] 195. An accountant cannot rename an attribute group.
- [x] 196. An accountant cannot rename an attribute term.
- [x] 197. An accountant cannot reorder attribute terms.
- [x] 198. An accountant cannot delete an attribute group.
- [x] 199. An accountant cannot delete an attribute term.

## Browsing the public storefront
- [x] 200. A logged-out visitor can list public products and only published products are returned.
- [x] 201. A logged-out visitor sees draft and pending products hidden from the public product list.
- [x] 202. A logged-out visitor sees out-of-stock products hidden from the public product list by default.
- [x] 203. A logged-out visitor sees out-of-stock products included in the public product list when choosing that option.
- [x] 204. A logged-out visitor does not see item cost or raw stock levels on the public product list.
- [x] 204a. A logged-out visitor does not see item cost or raw stock levels even when variants are not explicitly requested (the has_subscription accessor lazy-loads them).
- [x] 204b. A logged-out visitor does not see admin-only variant other_info keys (tax class, tax exempt, package slug, bundle child ids) on the public product list.
- [x] 205. A logged-out visitor does not see the long product description on the public product list.
- [x] 206. A logged-out visitor can filter the public product list by category, tag, or brand term and only matching products are returned.
- [x] 207. A logged-out visitor can sort the public product list and the requested order is honoured.
- [x] 208. A logged-out visitor sees the chosen number of products per page on the public product list when requesting a specific page size.
- [x] 209. A logged-out visitor can include or exclude specific products when listing the public catalogue.
- [x] 210. A logged-out visitor can request only on-sale products on the public list.
- [x] 211. A logged-out visitor sees the configured storefront product list view when one is provided.
- [x] 212. A logged-out visitor can search the public storefront by product name and only matching products are returned.
- [x] 213. A logged-out visitor receives an empty list when searching with a term that matches no published products.
- [x] 214. A logged-out visitor sees public storefront search input that resembles an SQL injection attempt treated as plain text without causing a server error.
- [x] 215. Customer sees the same public product list as a logged-out visitor when browsing the storefront.
- [x] 227. Listing products reads variant thumbnails in one query however many variants are on the page.
- [x] 228. Listing products reads product gallery images in one query however many products are on the page.
- [x] 229. Listing products resolves product permalinks without one post lookup per product.
- [x] 230. A variant's thumbnail is still returned in the product list.

## Accessing product administration
- [x] 216. A logged-out visitor is forbidden from accessing admin product endpoints.
- [x] 217. Customer is forbidden from accessing admin product endpoints.
- [x] 218. Worker can list products.
- [x] 219. Worker can open a single product.
- [x] 220. Accountant can list products.
- [x] 221. Accountant can open a single product.
- [x] 222. Store manager can list products.
- [x] 223. Store manager can open a single product.

## Changing products securely
- [ ] 224. Admin is rejected from product-changing actions without a valid security token.- [ ] 225. Admin is rejected from product-changing actions when the security token is expired.