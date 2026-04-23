# FluentCart Block Comparison
> Generated: 2026-02-21 | Updated: 2026-02-26
> FluentCart blocks: 68 | WooCommerce blocks: ~160 | SureCart blocks: ~139
> Review: [review-summary.md](review-summary.md) | Patterns: [patterns.md](patterns.md)

---

## FluentCart Complete Block Inventory (68 blocks)

### Standalone / Top-Level Blocks (23)

| # | Slug | Human Name | Type |
|---|------|-----------|------|
| 1 | `fluent-cart/products` | Products (Shop App) | Parent |
| 2 | `fluent-cart/product-carousel` | Product Carousel | Parent |
| 3 | `fluent-cart/media-carousel` | Media Carousel | Parent |
| 4 | `fluent-cart/checkout` | Checkout Page | Parent |
| 5 | `fluent-cart/related-product` | Related Products | Parent |
| 6 | `fluent-cart/product-info` | Product Info | Parent |
| 7 | `fluent-cart/product-card` | Product Card | Standalone |
| 8 | `fluent-cart/product-gallery` | Product Gallery | Standalone |
| 9 | `fluent-cart/product-title` | Product Title | Standalone |
| 10 | `fluent-cart/product-image` | Product Image | Standalone (Parent) |
| 11 | `fluent-cart/price-range` | Price Range | Standalone |
| 12 | `fluent-cart/excerpt` | Excerpt | Standalone |
| 13 | `fluent-cart/buy-section` | Buy Section | Standalone |
| 14 | `fluent-cart/buy-now-button` | Buy Now Button | Standalone |
| 15 | `fluent-cart/add-to-cart-button` | Add to Cart Button | Standalone |
| 16 | `fluent-cart/mini-cart` | Mini Cart | Standalone |
| 17 | `fluent-cart/fluent-products-search-bar` | Product Search | Standalone |
| 18 | `fluent-cart/product-pricing-table` | Pricing Table | Standalone |
| 19 | `fluent-cart/customer-profile` | Customer Dashboard | Standalone |
| 20 | `fluent-cart/product-categories-list` | Product Categories List | Standalone |
| 21 | `fluent-cart/store-logo` | Store Logo | Standalone |
| 22 | `fluent-cart/customer-dashboard-button` | Customer Dashboard Button | Standalone |
| 23 | `fluent-cart/stock` | Stock Indicator | Standalone (conditional) |

> **Review files:** Each block has a review in [reviews/](reviews/). Parent blocks include their InnerBlocks.
> **Planned (not yet built):** Product Description ([plan](product-description-plan.md)), Sale Badge ([plan](sale-badge-plan.md))

### ShopApp InnerBlocks (22 child blocks)

| # | Slug | Human Name | Parent |
|---|------|-----------|--------|
| 1 | `fluent-cart/shopapp-product-title` | Product Title | shopapp-product-loop |
| 2 | `fluent-cart/shopapp-product-excerpt` | Product Excerpt | shopapp-product-loop |
| 3 | `fluent-cart/shopapp-product-price` | Product Price | shopapp-product-loop |
| 4 | `fluent-cart/shopapp-product-image` | Product Image | shopapp-product-loop |
| 5 | `fluent-cart/shopapp-product-buttons` | Product Button | shopapp-product-loop |
| 6 | `fluent-cart/shopapp-product-container` | Product Container | products |
| 7 | `fluent-cart/shopapp-product-filter` | Product Filter | shopapp-product-container |
| 8 | `fluent-cart/shopapp-product-view-switcher` | View Switcher | shopapp-product-action-container |
| 9 | `fluent-cart/shopapp-product-filter-sort-by` | Filter Sort By | shopapp-product-action-container |
| 10 | `fluent-cart/shopapp-product-action-container` | Action Container | products |
| 11 | `fluent-cart/shopapp-product-no-result` | No Result | products |
| 12 | `fluent-cart/shopapp-product-filter-search-box` | Filter Search Box | shopapp-product-filter |
| 13 | `fluent-cart/shopapp-product-filter-filters` | Filter Filters | shopapp-product-filter |
| 14 | `fluent-cart/shopapp-product-filter-button` | Filter Button | shopapp-product-filter |
| 15 | `fluent-cart/shopapp-product-filter-apply-button` | Filter Apply Button | shopapp-product-filter-button |
| 16 | `fluent-cart/shopapp-product-filter-reset-button` | Filter Reset Button | shopapp-product-filter-button |
| 17 | `fluent-cart/shopapp-product-loop` | Product Loop | products |
| 18 | `fluent-cart/product-paginator` | Paginator | products |
| 19 | `fluent-cart/product-paginator-info` | Paginator Info | product-paginator |
| 20 | `fluent-cart/product-paginator-number` | Paginator Number | product-paginator |
| 21 | `fluent-cart/shopapp-product-loader` | Product Loader | products |
| 22 | `fluent-cart/shopapp-product-spinner` | Product Spinner | shopapp-product-loader |

### Checkout InnerBlocks (22 child blocks)

| # | Slug | Human Name | Parent |
|---|------|-----------|--------|
| 1 | `fluent-cart/checkout-name-fields` | Name Fields | checkout |
| 2 | `fluent-cart/checkout-create-account-field` | Create Account Field | checkout |
| 3 | `fluent-cart/checkout-address-fields` | Address Fields (wrapper) | checkout |
| 4 | `fluent-cart/checkout-billing-address-field` | Billing Address Field | checkout-address-fields |
| 5 | `fluent-cart/checkout-shipping-address-field` | Shipping Address Field | checkout-address-fields |
| 6 | `fluent-cart/checkout-ship-to-different-field` | Ship to Different | checkout-address-fields |
| 7 | `fluent-cart/checkout-shipping-methods` | Shipping Methods | checkout |
| 8 | `fluent-cart/checkout-payment-methods` | Payment Methods | checkout |
| 9 | `fluent-cart/checkout-agree-terms-field` | Agree Terms | checkout |
| 10 | `fluent-cart/checkout-submit-button` | Submit Button | checkout |
| 11 | `fluent-cart/checkout-order-notes-field` | Order Notes | checkout |
| 12 | `fluent-cart/checkout-summary` | Checkout Summary (wrapper) | checkout |
| 13 | `fluent-cart/checkout-order-summary` | Order Summary Items | checkout-summary |
| 14 | `fluent-cart/checkout-summary-footer` | Summary Footer (wrapper) | checkout-summary |
| 15 | `fluent-cart/checkout-subtotal` | Subtotal | checkout-summary-footer |
| 16 | `fluent-cart/checkout-shipping` | Shipping | checkout-summary-footer |
| 17 | `fluent-cart/checkout-coupon` | Coupon | checkout-summary-footer |
| 18 | `fluent-cart/checkout-manual-discount` | Manual Discount | checkout-summary-footer |
| 19 | `fluent-cart/checkout-tax` | Tax | checkout-summary-footer |
| 20 | `fluent-cart/checkout-shipping-tax` | Shipping Tax | checkout-summary-footer |
| 21 | `fluent-cart/checkout-total` | Total | checkout-summary-footer |
| 22 | `fluent-cart/checkout-order-bump` | Order Bump | checkout |

### Product Carousel InnerBlocks (3 child blocks)

| # | Slug | Human Name | Parent |
|---|------|-----------|--------|
| 1 | `fluent-cart/product-carousel-loop` | Carousel Loop | product-carousel |
| 2 | `fluent-cart/product-carousel-controls` | Carousel Controls | product-carousel |
| 3 | `fluent-cart/product-carousel-pagination` | Carousel Pagination | product-carousel |

### Media Carousel InnerBlocks (4 child blocks)

| # | Slug | Human Name | Parent |
|---|------|-----------|--------|
| 1 | `fluent-cart/media-carousel-loop` | Media Carousel Loop | media-carousel |
| 2 | `fluent-cart/media-carousel-product-image` | Product Image | media-carousel-loop |
| 3 | `fluent-cart/media-carousel-controls` | Carousel Controls | media-carousel |
| 4 | `fluent-cart/media-carousel-pagination` | Carousel Pagination | media-carousel |

### Related Product InnerBlocks (1 child block)

| # | Slug | Human Name | Parent |
|---|------|-----------|--------|
| 1 | `fluent-cart/product-template` | Product Template | related-product |

---

## Comparison by Functional Category

### 1. Product Display - Grid/Collection

| Function | FluentCart | WooCommerce | SureCart |
|----------|-----------|-------------|---------|
| Product grid/list | `fluent-cart/products` (ShopApp) | `woocommerce/product-collection`, `woocommerce/all-products` | `surecart/product-list` |
| Product card | `fluent-cart/product-card` | `woocommerce/single-product` | `surecart/product-form` |
| Featured product | --- | `woocommerce/featured-product` | --- |
| Featured category | --- | `woocommerce/featured-category` | --- |
| Hand-picked products | --- | `woocommerce/handpicked-products` | --- |
| Best sellers | --- | `woocommerce/product-best-sellers` | --- |
| Top rated | --- | `woocommerce/product-top-rated` | --- |
| New products | --- | `woocommerce/product-new` | --- |
| On-sale products | --- | `woocommerce/product-on-sale` | --- |
| Products by attribute | --- | `woocommerce/products-by-attribute` | --- |
| Products by category | --- | `woocommerce/product-category` | --- |
| Products by tag | --- | `woocommerce/product-tag` | --- |
| Product carousel | `fluent-cart/product-carousel` | --- | --- |
| Media carousel | `fluent-cart/media-carousel` | --- | --- |
| Product template (loop) | `fluent-cart/shopapp-product-loop` | `woocommerce/product-template` | `surecart/product-template-container` |
| Pricing table | `fluent-cart/product-pricing-table` | --- | `surecart/price-selector` |
| Collection no results | `fluent-cart/shopapp-product-no-result` | `woocommerce/product-collection-no-results` | --- |

### 2. Single Product Elements

| Function | FluentCart | WooCommerce | SureCart |
|----------|-----------|-------------|---------|
| Product title | `fluent-cart/product-title` | `woocommerce/product-title` | `surecart/product-title` |
| Product image | `fluent-cart/product-image` | `woocommerce/product-image` | `surecart/product-image` |
| Product gallery | `fluent-cart/product-gallery` | `woocommerce/product-gallery` + sub-blocks | `surecart/product-media` |
| Product price/range | `fluent-cart/price-range` | `woocommerce/product-price` | `surecart/price-amount` + sub-blocks |
| Product excerpt/summary | `fluent-cart/excerpt` | `woocommerce/product-summary` | `surecart/product-description` |
| Product description (full) | --- | `woocommerce/product-description` | `surecart/product-description` |
| Buy section (variants + button) | `fluent-cart/buy-section` | `woocommerce/add-to-cart-form` | --- |
| Add to cart with options | `fluent-cart/buy-section` (partial) | `woocommerce/add-to-cart-with-options` (11 sub-blocks) | `surecart/product-buy-button` |
| Product SKU | `fluent-cart/product-sku` | `woocommerce/product-sku` | --- |
| Product meta (SKU/cats/tags) | --- | `woocommerce/product-meta` | --- |
| Stock indicator | `fluent-cart/stock` | `woocommerce/product-stock-indicator` | --- |
| Sale badge | --- | `woocommerce/product-sale-badge` | `surecart/sale-badge` |
| Product specifications | --- | `woocommerce/product-specifications` | --- |
| Product details (tabs) | --- | `woocommerce/product-details` | --- |
| Related products | `fluent-cart/related-product` | `woocommerce/related-products` | `surecart/related-products` |
| Quantity selector | --- | `woocommerce/add-to-cart-with-options-quantity-selector` | `surecart/product-quantity` |
| Variation selector | --- (handled in buy-section) | `woocommerce/add-to-cart-with-options-variation-selector` (5 sub-blocks) | --- |
| Selected variant image | --- | --- | `surecart/product-selected-variant-image` |
| Product note | --- | --- | `surecart/product-note` |

### 3. Rating & Reviews

| Function | FluentCart | WooCommerce | SureCart |
|----------|-----------|-------------|---------|
| Average rating | --- | `woocommerce/product-average-rating` | --- |
| Rating stars | --- | `woocommerce/product-rating-stars` | --- |
| Rating counter | --- | `woocommerce/product-rating-counter` | --- |
| Product rating (combined) | --- | `woocommerce/product-rating` | --- |
| Product reviews | --- | `woocommerce/product-reviews` (10+ sub-blocks) | --- |
| Reviews by product | --- | `woocommerce/reviews-by-product` | --- |
| Reviews by category | --- | `woocommerce/reviews-by-category` | --- |
| All reviews | --- | `woocommerce/all-reviews` | --- |

### 4. Cart

| Function | FluentCart | WooCommerce | SureCart |
|----------|-----------|-------------|---------|
| Full cart page | --- (shortcode-based) | `woocommerce/cart` (parent, ~16 sub-blocks) | `surecart/cart` |
| Cart items | --- | `woocommerce/cart-items-block` | `surecart/cart-items` |
| Cart line items | --- | `woocommerce/cart-line-items-block` | (line item sub-blocks) |
| Cart totals | --- | `woocommerce/cart-totals-block` | `surecart/cart-subtotal` |
| Cart order summary | --- | `woocommerce/cart-order-summary-block` (8 sub-blocks) | --- |
| Cart coupon form | --- | `woocommerce/cart-order-summary-coupon-form-block` | `surecart/cart-coupon` |
| Cart shipping | --- | `woocommerce/cart-order-summary-shipping-block` | --- |
| Cart cross-sells | --- | `woocommerce/cart-cross-sells-block` | --- |
| Proceed to checkout | --- | `woocommerce/proceed-to-checkout-block` | `surecart/cart-submit-button` |
| Express payment (cart) | --- | `woocommerce/cart-express-payment-block` | --- |
| Accepted payment methods | --- | `woocommerce/cart-accepted-payment-methods-block` | --- |
| Empty cart | --- | `woocommerce/empty-cart-block` | --- |
| Filled cart | --- | `woocommerce/filled-cart-block` | --- |
| Cart link | --- | `woocommerce/cart-link` | --- |

### 5. Mini Cart / Slide-out Cart

| Function | FluentCart | WooCommerce | SureCart |
|----------|-----------|-------------|---------|
| Mini cart icon/trigger | `fluent-cart/mini-cart` | `woocommerce/mini-cart` | `surecart/cart-toggle-icon`, `surecart/floating-cart-icon` |
| Mini cart contents | (rendered inline) | `woocommerce/mini-cart-contents` (12 sub-blocks) | `surecart/cart` (slide-out) |
| Mini cart title | --- | `woocommerce/mini-cart-title-block` | `surecart/cart-header` |
| Mini cart items counter | --- | `woocommerce/mini-cart-title-items-counter-block` | `surecart/cart-items-count` |
| Mini cart footer | --- | `woocommerce/mini-cart-footer-block` | --- |
| Cart close button | --- | --- | `surecart/close-cart-button` |
| Cart message | --- | --- | `surecart/cart-message` |

### 6. Checkout

| Function | FluentCart | WooCommerce | SureCart |
|----------|-----------|-------------|---------|
| Checkout container | `fluent-cart/checkout` | `woocommerce/checkout` | (form-based) |
| Name fields | `fluent-cart/checkout-name-fields` | (part of contact-information) | `surecart/full-name` |
| Email/contact info | (part of name-fields) | `woocommerce/checkout-contact-information-block` | (form field) |
| Phone | --- | (part of contact) | `surecart/phone` |
| Create account | `fluent-cart/checkout-create-account-field` | (part of contact) | --- |
| Billing address | `fluent-cart/checkout-billing-address-field` | `woocommerce/checkout-billing-address-block` | (address fields) |
| Shipping address | `fluent-cart/checkout-shipping-address-field` | `woocommerce/checkout-shipping-address-block` | (address fields) |
| Ship to different | `fluent-cart/checkout-ship-to-different-field` | (built-in toggle) | --- |
| Shipping methods | `fluent-cart/checkout-shipping-methods` | `woocommerce/checkout-shipping-methods-block` | --- |
| Payment methods | `fluent-cart/checkout-payment-methods` | `woocommerce/checkout-payment-block` | --- |
| Express payment | --- | `woocommerce/checkout-express-payment-block` | (Apple Pay/Google Pay) |
| Agree terms | `fluent-cart/checkout-agree-terms-field` | `woocommerce/checkout-terms-block` | --- |
| Submit/place order | `fluent-cart/checkout-submit-button` | `woocommerce/checkout-actions-block` | `surecart/submit-button` |
| Order notes | `fluent-cart/checkout-order-notes-field` | `woocommerce/checkout-order-note-block` | --- |
| Order summary | `fluent-cart/checkout-summary` | `woocommerce/checkout-order-summary-block` (8 sub-blocks) | `surecart/totals` |
| Order items list | `fluent-cart/checkout-order-summary` | `woocommerce/checkout-order-summary-cart-items-block` | (line item blocks) |
| Subtotal | `fluent-cart/checkout-subtotal` | `woocommerce/checkout-order-summary-subtotal-block` | `surecart/subtotal-line-item` |
| Shipping cost | `fluent-cart/checkout-shipping` | `woocommerce/checkout-order-summary-shipping-block` | --- |
| Coupon | `fluent-cart/checkout-coupon` | `woocommerce/checkout-order-summary-coupon-form-block` | `surecart/coupon` |
| Manual discount | `fluent-cart/checkout-manual-discount` | `woocommerce/checkout-order-summary-discount-block` | --- |
| Tax | `fluent-cart/checkout-tax` | `woocommerce/checkout-order-summary-taxes-block` | --- |
| Shipping tax | `fluent-cart/checkout-shipping-tax` | --- | --- |
| Fees | --- | `woocommerce/checkout-order-summary-fee-block` | --- |
| Total | `fluent-cart/checkout-total` | `woocommerce/checkout-order-summary-totals-block` | `surecart/total` |
| Order bump | `fluent-cart/checkout-order-bump` | --- | `surecart/order-bumps` (14 sub-blocks) |
| Additional info | --- | `woocommerce/checkout-additional-information-block` | --- |
| Pickup options | --- | `woocommerce/checkout-pickup-options-block` | --- |
| Conditional fields | --- | --- | `surecart/conditional-block` |
| Divider | --- | --- | `surecart/divider` |
| Custom text field | --- | --- | `surecart/text-field` |

### 7. Order Confirmation / Thank You

| Function | FluentCart | WooCommerce | SureCart |
|----------|-----------|-------------|---------|
| Order status | --- | `woocommerce/order-confirmation-status` | --- |
| Order totals | --- | `woocommerce/order-confirmation-totals` | --- |
| Order summary | --- | `woocommerce/order-confirmation-summary` | --- |
| Billing address | --- | `woocommerce/order-confirmation-billing-address` | --- |
| Shipping address | --- | `woocommerce/order-confirmation-shipping-address` | --- |
| Downloads | --- | `woocommerce/order-confirmation-downloads` | --- |
| Create account (post-purchase) | --- | `woocommerce/order-confirmation-create-account` | --- |
| Additional fields | --- | `woocommerce/order-confirmation-additional-fields-wrapper` | --- |

### 8. Customer Account

| Function | FluentCart | WooCommerce | SureCart |
|----------|-----------|-------------|---------|
| Customer dashboard | `fluent-cart/customer-profile` | --- | `surecart/customer-dashboard` |
| Dashboard button/link | `fluent-cart/customer-dashboard-button` | `woocommerce/customer-account` | `surecart/customer-dashboard-button` |
| Session info | --- | --- | `surecart/session-info` |
| Logout button | --- | --- | `surecart/logout-button` |

### 9. Filters & Search

| Function | FluentCart | WooCommerce | SureCart |
|----------|-----------|-------------|---------|
| Filter container | `fluent-cart/shopapp-product-filter` | `woocommerce/product-filters`, `woocommerce/filter-wrapper` | `surecart/filter-dropdown` |
| Filter by category/taxonomy | `fluent-cart/shopapp-product-filter-filters` | `woocommerce/product-filter-taxonomy` | `surecart/filter-checkboxes` |
| Filter by attribute | (via filters) | `woocommerce/product-filter-attribute` | --- |
| Filter by price | (via filters) | `woocommerce/product-filter-price`, `woocommerce/product-filter-price-slider` | --- |
| Filter by rating | --- | `woocommerce/product-filter-rating` | --- |
| Filter by stock status | --- | `woocommerce/product-filter-status`, `woocommerce/stock-filter` | --- |
| Active filters display | --- | `woocommerce/active-filters`, `woocommerce/product-filter-active` | `surecart/applied-filters` |
| Filter clear/reset | `fluent-cart/shopapp-product-filter-reset-button` | `woocommerce/product-filter-clear-button` | `surecart/clear-all` |
| Filter apply | `fluent-cart/shopapp-product-filter-apply-button` | --- | --- |
| Filter chips | --- | `woocommerce/product-filter-chips`, `woocommerce/product-filter-removable-chips` | `surecart/filter-tags` |
| Filter checkbox list | --- | `woocommerce/product-filter-checkbox-list` | `surecart/filter-checkboxes` |
| Product search | `fluent-cart/fluent-products-search-bar` | `woocommerce/product-search` (WP core) | `surecart/search` |
| Sort by dropdown | `fluent-cart/shopapp-product-filter-sort-by` | `woocommerce/catalog-sorting` | `surecart/sort-dropdown`, `surecart/sort-radio-group` |
| View mode switcher | `fluent-cart/shopapp-product-view-switcher` | --- | --- |

### 10. Navigation & Store-Wide

| Function | FluentCart | WooCommerce | SureCart |
|----------|-----------|-------------|---------|
| Store logo | `fluent-cart/store-logo` | --- | --- |
| Breadcrumbs | --- | `woocommerce/breadcrumbs` | --- |
| Store notices | --- | `woocommerce/store-notices` | --- |
| Category title | --- | `woocommerce/category-title` | --- |
| Category description | --- | `woocommerce/category-description` | --- |
| Product categories list | `fluent-cart/product-categories-list` | `woocommerce/product-categories` | `surecart/collection-tags` |
| Product results count | --- | `woocommerce/product-results-count` | --- |
| Pagination | `fluent-cart/product-paginator` | (via WP core) | `surecart/pagination` |
| Payment method icons | --- | `woocommerce/payment-method-icons` | --- |
| Coming soon page | --- | `woocommerce/coming-soon` | --- |
| Currency switcher | --- | --- | `surecart/currency-switcher` |
| Sticky purchase bar | --- | --- | `surecart/sticky-purchase` |

### 11. Upsell & Marketing

| Function | FluentCart | WooCommerce | SureCart |
|----------|-----------|-------------|---------|
| Order bump (checkout) | `fluent-cart/checkout-order-bump` (Pro) | --- | `surecart/order-bumps` (14 sub-blocks) |
| Cross-sells (cart) | --- | `woocommerce/cart-cross-sells-block` | --- |
| Upsell (post-purchase) | --- | --- | (one-click upsells, not block) |
| Coupon code display | --- | `woocommerce/coupon-code` | --- |

### 12. Email & Utility

| Function | FluentCart | WooCommerce | SureCart |
|----------|-----------|-------------|---------|
| Email content block | --- | `woocommerce/email-content` | --- |
| Classic shortcode wrapper | --- | `woocommerce/classic-shortcode` | --- |
| Accordion | --- | `woocommerce/accordion-group` (4 blocks) | --- |
| Page content wrapper | --- | `woocommerce/page-content-wrapper` | --- |

---

## What FluentCart Has That Others Do Not

| Block | Description | WC Equivalent | SC Equivalent |
|-------|-------------|---------------|---------------|
| `fluent-cart/product-carousel` | Swiper-based product carousel with controls/pagination | None (requires 3rd party) | None |
| `fluent-cart/media-carousel` | Image gallery as carousel (Swiper) | None | None |
| `fluent-cart/buy-section` | All-in-one variant selector + buy | Separate blocks | Separate blocks |
| `fluent-cart/product-pricing-table` | Pricing table display | None | Partial (price-selector) |
| `fluent-cart/checkout-manual-discount` | Manual discount line in checkout | Partial (discount block) | None |
| `fluent-cart/checkout-shipping-tax` | Separate shipping tax display | None | None |
| `fluent-cart/store-logo` | Store branding block | None | None |
| View mode switcher | Grid/list toggle | None | None |
| Filter search + filter box | Integrated search within filter sidebar | Separate blocks | Separate blocks |

---

## Prioritized Missing Blocks for FluentCart

### Must-Have (Both WC and SC have, core eCommerce feature)

| Priority | Block to Build | WC Reference | SC Reference | Complexity | Status |
|----------|---------------|--------------|--------------|------------|--------|
| 1 | **Product Description (full)** | `woocommerce/product-description` | `surecart/product-description` | Low | Planned — [plan](product-description-plan.md) |
| 2 | **Sale Badge / On-Sale Badge** | `woocommerce/product-sale-badge` | `surecart/sale-badge` | Low | Planned — [plan](sale-badge-plan.md) |
| 3 | **Breadcrumbs** | `woocommerce/breadcrumbs` | --- | Low | Not started |
| ~~4~~ | ~~**Product SKU**~~ | ~~`woocommerce/product-sku`~~ | --- | ~~Low~~ | **DONE** — `fluent-cart/product-sku` |
| 4 | **Product Meta (SKU + Categories + Tags)** | `woocommerce/product-meta` | --- | Low-Medium | Not started |
| 5 | **Cart Page (block-based)** | `woocommerce/cart` (16 sub-blocks) | `surecart/cart` | High | Not started |
| 6 | **Order Confirmation / Thank You blocks** | `woocommerce/order-confirmation-*` (10 blocks) | --- | Medium-High | Not started |
| 7 | **Express Payment (checkout)** | `woocommerce/checkout-express-payment-block` | Apple Pay/Google Pay | Medium | Not started |
| 8 | **Active Filters display** | `woocommerce/product-filter-active` | `surecart/applied-filters` | Medium | Not started |
| 9 | **Quantity Selector (standalone)** | `woocommerce/add-to-cart-with-options-quantity-selector` | `surecart/product-quantity` | Low | Not started |

### Nice-to-Have (One competitor has, or useful but non-essential)

| Priority | Block to Build | Reference | Complexity | Notes |
|----------|---------------|-----------|------------|-------|
| 11 | **Featured Product** | WC: `featured-product` | Medium | Hero-style product highlight with background image. |
| 12 | **Featured Category** | WC: `featured-category` | Medium | Same as above but for categories. |
| 13 | **Product Rating / Reviews** | WC: 10+ review blocks | High | Review system with stars, forms, pagination. Major feature. Consider as a separate module. |
| 14 | **Store Notices** | WC: `store-notices` | Low | Display admin-set notices to customers. |
| 15 | **Hand-picked Products** | WC: `handpicked-products` | Low-Medium | Manual product selection grid. ShopApp with default_filters can partially do this. |
| 16 | **Filter by Price Slider** | WC: `product-filter-price-slider` | Medium | Price range slider for filtering. Currently text-based filter. |
| 17 | **Filter by Rating** | WC: `product-filter-rating` | Low-Medium | Star rating filter. Requires rating system first. |
| 18 | **Currency Switcher** | SC: `currency-switcher` | Medium | Switch display currency. Depends on multi-currency support. |
| 19 | **Sticky Purchase Bar** | SC: `sticky-purchase` | Medium | Fixed bar at bottom with buy button when scrolled past main CTA. |
| 20 | **Cart Cross-Sells** | WC: `cart-cross-sells-block` | Medium | Suggest related products in the cart page. |
| 21 | **Category Title** | WC: `category-title` | Low | Display current archive/category title. |
| 22 | **Category Description** | WC: `category-description` | Low | Display current archive/category description. |
| 23 | **Product Results Count** | WC: `product-results-count` | Low | "Showing 1-12 of 36 results" text. |
| 24 | **Accepted Payment Methods Icons** | WC: `cart-accepted-payment-methods-block` | Low | Display payment method logos. |
| 25 | **Conditional Block (checkout)** | SC: `conditional-block` | Medium | Show/hide checkout fields based on conditions. |
| 26 | **Product Details / Tabs** | WC: `product-details` | Medium | Tabbed or accordion layout for description, reviews, specifications. |
| 27 | **Order Bump (more sub-blocks)** | SC: 14 order bump sub-blocks | Medium | More granular control over order bump display (title, description, image, CTA separately). |
| 28 | **Product Specifications** | WC: `product-specifications` | Low-Medium | Table display for product attributes/specs. |
| 29 | **Best Sellers / Top Rated / New** | WC: 3 blocks | Low | Pre-filtered product grids. Could be presets of ShopApp. |
| 30 | **Accordion** | WC: 4 accordion blocks | Low-Medium | Generic accordion/FAQ component. Useful for product details. |

### Consider Later (Edge case or niche)

| Block | Reference | Notes |
|-------|-----------|-------|
| Pickup options | WC: `checkout-pickup-options-block` | Local pickup feature |
| Grouped product selector | WC: 5 grouped product blocks | Complex product type support |
| Email content blocks | WC: `email-content` | For block-based email templates |
| Coming soon page | WC: `coming-soon` | Maintenance/launch page |
| Variation description | WC: `add-to-cart-with-options-variation-description` | Show variant-specific description |
| Session info / logout | SC: `session-info`, `logout-button` | Auth utility blocks |
| Cart message | SC: `cart-message` | Promotional message in cart |
| Floating cart icon | SC: `floating-cart-icon` | Fixed position cart trigger |
| Line item sub-blocks | SC: 13 line item blocks | Granular line item display |
| Sort radio group | SC: radio-based sort | Alternative sort UI |

### Skip (WC-specific or does not apply)

| Block | Reason |
|-------|--------|
| `woocommerce/classic-shortcode` | WC backward compatibility only |
| `woocommerce/classic-template` | WC backward compatibility only |
| `woocommerce/page-content-wrapper` | WC template system specific |
| Products by attribute/category/tag blocks | Can be achieved with ShopApp default_filters |

---

## Implementation Roadmap Suggestion

### Phase 1 - Quick Wins (Low complexity, high impact)
1. Product Description block (planned)
2. Sale Badge block (planned)
3. ~~Product SKU block~~ **DONE**
4. Breadcrumbs block
5. Quantity Selector block
6. Category Title block
7. Category Description block
8. Product Results Count block

### Phase 2 - Important Features (Medium complexity)
9. Active Filters display
10. Express Payment block
11. Featured Product block
12. Store Notices block
13. Product Details/Tabs block
14. Accepted Payment Methods icons

### Phase 3 - Major Features (High complexity)
15. Cart Page (block-based with sub-blocks)
16. Order Confirmation blocks
17. Product Rating & Reviews system
18. Price Slider filter
19. Sticky Purchase bar

---

## Coverage Statistics

| Metric | Count |
|--------|-------|
| **FluentCart total blocks** | 68 (23 standalone + 22 ShopApp + 22 Checkout + 3 Carousel + 4 MediaCarousel + 1 RelatedProduct) |
| **WooCommerce total blocks** | ~160 |
| **SureCart total blocks** | ~139 |
| **FC unique blocks (not in WC or SC)** | 9 (carousels, pricing table, store logo, view switcher, buy section, manual discount, shipping tax, filter search integration) |
| **Must-Have missing (both WC+SC have)** | 9 blocks (was 10 — Product SKU now built) |
| **Nice-to-Have missing** | 20 blocks |
| **Consider Later** | 10 blocks |
| **FC checkout coverage vs WC** | ~90% (missing express payment, pickup, fees, additional info) |
| **FC product display coverage vs WC** | ~65% (missing reviews, description, sale badge, meta — SKU now covered) |
| **FC filter coverage vs WC** | ~70% (missing price slider, rating filter, active filters, chips) |
| **FC cart coverage vs WC** | ~20% (only mini-cart; full cart page is shortcode-based) |

---

## Review Status (2026-02-26)

All 68 blocks reviewed. Full report: [review-summary.md](review-summary.md)

| Severity | Count | Top Issues |
|----------|-------|-----------|
| **Critical** | 14 | Base class bug, missing render method, checkout $_GET/CSRF, duplicate paginator registration, shortcode injection |
| **Systemic** | 9 | No ErrorBoundary (~60 blocks), `{...props}` spread (~34), missing absint (~15), non-null save (~12) |
| **Warning** | 12 | Unescaped output, dead code, console.log, apiVersion mismatch, loose truthy checks |
| **Info** | ~15 | Unused imports, non-standard category names, dangerouslySetInnerHTML |

### P0 Fixes Needed
1. `BlockEditor.php:35` — `$isReactSupportAdded = false` → `true` (all blocks affected)
2. `ShopApp/InnerBlocks.php:493` — Add missing `renderProductSpinnerBlock()` method (fatal)
3. `CheckoutBlockEditor.php:80-81` — Whitelist `$_GET` params in form action
4. `CheckoutBlockEditor.php:122-135` — Add `wp_nonce_field()` to checkout form

Per-block reviews: [reviews/](reviews/)