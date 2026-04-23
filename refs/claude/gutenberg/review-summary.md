# FluentCart Gutenberg Block Review — Summary

> **Generated:** 2026-02-26
> **Blocks Reviewed:** 68 | **Critical Issues:** 14 | **Warnings:** 21 | **Info:** ~15
> **Review Checklist:** `.claude/commands/gutenberg-blocks/review.md` (sections A–H, 70+ checks per block)

---

## Coverage

| Category | Count | Review File |
|---|---|---|
| Standalone / Top-Level (Parents) | 5 | [products-shopapp](reviews/products-shopapp.md), [checkout](reviews/checkout.md), [product-carousel](reviews/product-carousel.md), [media-carousel](reviews/media-carousel.md), [related-product](reviews/related-product.md) |
| Standalone / Top-Level (Leaf) | 18 | Individual files in `reviews/` |
| ShopApp InnerBlocks | 22 | Inside [products-shopapp](reviews/products-shopapp.md) |
| Checkout InnerBlocks | 22 | Inside [checkout](reviews/checkout.md) |
| Product Carousel InnerBlocks | 3 | Inside [product-carousel](reviews/product-carousel.md) |
| Media Carousel InnerBlocks | 4 | Inside [media-carousel](reviews/media-carousel.md) |
| Related Product InnerBlocks | 1 | Inside [related-product](reviews/related-product.md) |
| Global (multi-parent) | 1 | [product-paginator](reviews/product-paginator.md) |
| **Total** | **~68** | |

---

## Review File Index

### Parent Blocks (InnerBlocks inside)

| File | Block | InnerBlocks |
|---|---|---|
| [products-shopapp.md](reviews/products-shopapp.md) | `fluent-cart/products` | 22 child blocks (filter, loop, paginator, loader, etc.) |
| [checkout.md](reviews/checkout.md) | `fluent-cart/checkout` | 22 child blocks (name, address, payment, summary, etc.) |
| [product-carousel.md](reviews/product-carousel.md) | `fluent-cart/product-carousel` | 3 child blocks (loop, controls, pagination) |
| [media-carousel.md](reviews/media-carousel.md) | `fluent-cart/media-carousel` | 4 child blocks (loop, image, controls, pagination) |
| [related-product.md](reviews/related-product.md) | `fluent-cart/related-product` | 1 child block (product-template) |
| [product-info.md](reviews/product-info.md) | `fluent-cart/product-info` | Container for leaf blocks (no registered InnerBlocks) |

### Standalone Blocks

| File | Block |
|---|---|
| [product-card.md](reviews/product-card.md) | `fluent-cart/product-card` |
| [product-gallery.md](reviews/product-gallery.md) | `fluent-cart/product-gallery` |
| [product-title.md](reviews/product-title.md) | `fluent-cart/product-title` |
| [product-image.md](reviews/product-image.md) | `fluent-cart/product-image` |
| [price-range.md](reviews/price-range.md) | `fluent-cart/price-range` |
| [excerpt.md](reviews/excerpt.md) | `fluent-cart/excerpt` |
| [buy-section.md](reviews/buy-section.md) | `fluent-cart/buy-section` |
| [buy-now-button.md](reviews/buy-now-button.md) | `fluent-cart/buy-now-button` |
| [add-to-cart-button.md](reviews/add-to-cart-button.md) | `fluent-cart/add-to-cart-button` |
| [mini-cart.md](reviews/mini-cart.md) | `fluent-cart/mini-cart` |
| [search-bar.md](reviews/search-bar.md) | `fluent-cart/fluent-products-search-bar` |
| [pricing-table.md](reviews/pricing-table.md) | `fluent-cart/product-pricing-table` |
| [customer-profile.md](reviews/customer-profile.md) | `fluent-cart/customer-profile` |
| [customer-dashboard-button.md](reviews/customer-dashboard-button.md) | `fluent-cart/customer-dashboard-button` |
| [store-logo.md](reviews/store-logo.md) | `fluent-cart/store-logo` |
| [stock.md](reviews/stock.md) | `fluent-cart/stock` |
| [product-sku.md](reviews/product-sku.md) | `fluent-cart/product-sku` |
| [product-categories-list.md](reviews/product-categories-list.md) | `fluent-cart/product-categories-list` |

### Global Blocks (used as InnerBlock in multiple parents)

| File | Block | Parents |
|---|---|---|
| [product-paginator.md](reviews/product-paginator.md) | `fluent-cart/product-paginator` | ShopApp, ProductCarousel*, MediaCarousel* (*duplicate registrations — bug) |

---

## Critical Issues (14)

Issues that cause fatal errors, security vulnerabilities, or broken block functionality.

| # | Issue | Scope | Location | Priority |
|---|---|---|---|---|
| C1 | **Base class bug:** `$isReactSupportAdded = false` should be `true` — React support re-enqueued on every call | ALL blocks | `app/Hooks/Handlers/BlockEditors/BlockEditor.php:35` | P0 |
| C2 | **Missing `renderProductSpinnerBlock()` method** — PHP fatal error on frontend render | ShopApp spinner | `app/Hooks/Handlers/BlockEditors/ShopApp/InnerBlocks/InnerBlocks.php:493` | P0 |
| C3 | **Unsanitized `$_GET`** passed directly to checkout form `action` URL via `add_query_arg()` | Checkout parent | `app/Hooks/Handlers/BlockEditors/Checkout/CheckoutBlockEditor.php:80-81` | P0 |
| C4 | **No CSRF nonce** in checkout `<form>` (no `wp_nonce_field()`) | Checkout parent | `app/Hooks/Handlers/BlockEditors/Checkout/CheckoutBlockEditor.php:122-135` | P0 |
| C5 | **Duplicate `product-paginator`** registered 3x (ShopApp, ProductCarousel, MediaCarousel) — only first registration wins, others silently fail | 3 InnerBlocks files | See [product-paginator.md](reviews/product-paginator.md) | P1 |
| C6 | **Wrong parent slug** `product_carousel` (underscore) instead of `product-carousel` (hyphen) — paginator can never appear as child | ProductCarousel | `resources/admin/BlockEditor/ProductCarousel/InnerBlocks/InnerBlocks.jsx:58` | P1 |
| C7 | **Wrong parent slug** `media_carousel` (underscore) instead of `media-carousel` (hyphen) | MediaCarousel | `resources/admin/BlockEditor/MediaCarousel/InnerBlocks/InnerBlocks.jsx:47` | P1 |
| C8 | **Dead cache logic:** `$needsReset \|\| true` always re-writes transient on every page load | ShopApp loop | `app/Hooks/Handlers/BlockEditors/ShopApp/InnerBlocks/InnerBlocks.php:875` | P1 |
| C9 | **Unescaped paginator output:** `$page` in `data-page` attr and `$label` in HTML without `esc_attr()`/`esc_html()` | ShopApp paginator | `app/Hooks/Handlers/BlockEditors/ShopApp/InnerBlocks/InnerBlocks.php:1263` | P1 |
| C10 | **Swapped imports** `ProductButtonBlockEditor` ↔ `ProductExcerptBlockEditor` (dead code but confusing) | ProductCarousel | `resources/admin/BlockEditor/ProductCarousel/InnerBlocks/InnerBlocks.jsx:4-5` | P2 |
| C11 | **Missing `absint()`** on `$productIds` array before `whereIn()` query | ProductCarousel loop | `app/Hooks/Handlers/BlockEditors/ProductCarousel/InnerBlocks/InnerBlocks.php:360` | P1 |
| C12 | **Missing `absint()`** on `$productId` from block context | MediaCarousel loop | `app/Hooks/Handlers/BlockEditors/MediaCarousel/InnerBlocks/InnerBlocks.php:254` | P1 |
| C13 | **Unsanitized transient key** — raw `$attributes['wp_client_id']` used as transient key without `sanitize_key()` | ShopApp loop | `app/Hooks/Handlers/BlockEditors/ShopApp/InnerBlocks/InnerBlocks.php:857` | P2 |
| C14 | **Shortcode injection risk** — raw attribute values interpolated into shortcode strings without sanitization | ProductCard, PricingTable, CustomerProfile | Multiple `*BlockEditor.php` render methods | P1 |

---

## Systemic Issues (9)

Patterns that affect ALL or MOST blocks. Not repeated in individual review files.

| # | Issue | Affected | Severity | Notes |
|---|---|---|---|---|
| S1 | **No `<ErrorBoundary>`** wrapper in edit components | ~60 of 68 blocks | Warning | Only a few top-level blocks use it. All InnerBlocks lack it. |
| S2 | **`{...props}` spread on DOM elements** leaks React internals (`setAttributes`, `clientId`, etc.) to the DOM | ~34 InnerBlocks (ShopApp + Checkout) | Warning | Causes React console warnings. Only `blockProps` should be spread. |
| S3 | **Missing `absint()`** on product/post ID attributes in PHP render methods | ~15 blocks | Warning | IDs from block attributes are user-controlled. |
| S4 | **Category `"product-elements"`** instead of `"fluent-cart"` in InnerBlocks registration loops | ShopApp (22) + Carousel InnerBlocks | Info | May cause blocks to appear in wrong category or not appear at all if category not registered. |
| S5 | **Non-null `save()`** on dynamic blocks — returns `<InnerBlocks.Content/>` instead of `() => null` | ~12 container/loop blocks | Warning | Causes block validation errors when save markup changes. PHP render callback is the actual frontend output. |
| S6 | **Missing `get_block_wrapper_attributes()`** in PHP render methods | ~5 blocks | Warning | Block supports (spacing, typography, color) won't apply on frontend. |
| S7 | **`dangerouslySetInnerHTML`** used for price/button HTML from internal API | ~4 blocks | Info | Low risk (trusted internal API data), but worth noting. |
| S8 | **Missing `blocktranslate()`** for hardcoded English strings in JSX | ~11 ShopApp InnerBlocks | Warning | Breaks i18n. E.g., "Apply Filter", "Product Filters" hardcoded. |
| S9 | **Unused imports** across many blocks (`useContext`, `InspectorControls`, `ToggleControl`, `blockEditorData`, etc.) | ~15 blocks | Info | Dead code / bundle bloat. |

---

## Warning Issues (12)

Block-specific issues that should be fixed but aren't security-critical.

| # | Issue | Block | Location |
|---|---|---|---|
| W1 | `$buttonClass` echoed without `esc_attr()` | MiniCart | `MiniCartRenderer.php` |
| W2 | `apiVersion: 2` instead of `3` in registration loop | MediaCarousel InnerBlocks | `MediaCarousel/InnerBlocks/InnerBlocks.jsx:30` |
| W3 | Loose truthy check `!$hasControls` instead of `!== 'yes'` | MediaCarousel loop render | `MediaCarousel/InnerBlocks/InnerBlocks.php:260-266` |
| W4 | Duplicate ReactSupport.js enqueue (`if (... \|\| true)`) | ProductCarousel InnerBlocks | `ProductCarousel/InnerBlocks/InnerBlocks.php:517,528` |
| W5 | `renderDirectCheckoutButton` builds data but never outputs HTML | PricingTable renderer | `PricingTableRenderer.php` |
| W6 | Unreachable code after `return ob_get_clean()` in paginator render | ShopApp InnerBlocks | `ShopApp/InnerBlocks/InnerBlocks.php:1220` |
| W7 | `console.log({blocks})` left in production code | ShopApp ProductLoop | `ShopApp/InnerBlocks/ProductLoopBlock.jsx:145` |
| W8 | Missing `get_block_wrapper_attributes()` in `renderCheckoutSummaryFooter` | Checkout summary-footer | `Checkout/InnerBlocks/InnerBlocks.php:829` |
| W9 | `renderTitle` returns unescaped untranslated bare string `'not found'` | ShopApp title render | `ShopApp/InnerBlocks/InnerBlocks.php:731` |
| W10 | `blockProps` computed with className but never used (second bare `useBlockProps()` call instead) | ShopApp ProductFilter | `ShopApp/InnerBlocks/ProductFilter/ProductFilterBlock.jsx:44` |
| W11 | Module-level mutable `lastChanged` variable shared across block instances | ProductCarouselLoop, ShopApp ProductLoop | `ProductCarouselLoopBlock.jsx:17`, `ProductLoopBlock.jsx:17` |
| W12 | `shortcode_parse_atts()` merges arbitrary wrapper attrs into `<button>` element | Checkout submit button render path | `CheckoutRenderer.php:714-725` |

---

## Priority Fix Order

### P0 — Fix immediately (fatal errors, security)

1. **C1** — `BlockEditor.php:35` → change `static::$isReactSupportAdded = false` to `true` (1-line fix, affects ALL blocks)
2. **C2** — Add missing `renderProductSpinnerBlock()` method to `ShopApp/InnerBlocks/InnerBlocks.php` (fatal error on frontend)
3. **C3** — Whitelist specific `$_GET` params in `CheckoutBlockEditor.php:80-81` instead of passing entire superglobal
4. **C4** — Add `wp_nonce_field()` to checkout form in `CheckoutBlockEditor.php`

### P1 — Fix soon (broken blocks, XSS, data safety)

5. **C5/C6/C7** — Remove duplicate `product-paginator` registrations from ProductCarousel + MediaCarousel; fix underscore→hyphen in parent slugs
6. **C8** — Remove `|| true` from transient check in `ShopApp/InnerBlocks/InnerBlocks.php:875`
7. **C9** — Escape paginator `$page` with `esc_attr()` and `$label` with `esc_html()` (except trusted SVG)
8. **C11/C12/S3** — Add `absint()` on all product/post ID attributes across ~15 blocks
9. **C14** — Sanitize shortcode attribute interpolation in ProductCard, PricingTable, CustomerProfile

### P2 — Fix when convenient (code quality, consistency)

10. **C10** — Fix swapped Button/Excerpt imports in ProductCarousel InnerBlocks
11. **C13** — Sanitize transient key with `sanitize_key()`
12. **S1** — Add `<ErrorBoundary>` wrappers to all edit components (~60 blocks)
13. **S2** — Remove `{...props}` spread from DOM elements in ~34 InnerBlocks
14. **S5** — Change non-null `save()` to `() => null` on ~12 dynamic blocks
15. **W1–W12** — Individual warning fixes (see table above)

### P3 — Nice to have (cleanup)

16. **S4** — Standardize category to `"fluent-cart"` across InnerBlocks registration loops
17. **S8** — Wrap hardcoded English strings in `blocktranslate()` for i18n
18. **S9** — Remove unused imports across ~15 blocks

---

## Stats

| Metric | Value |
|---|---|
| Total blocks reviewed | 68 |
| Critical issues | 14 |
| Systemic issues | 9 |
| Warning issues | 12 |
| Info issues | ~15 |
| Blocks with zero issues | 0 (all fail at least S1 — ErrorBoundary) |
| Most impactful single fix | C1 — base class `$isReactSupportAdded` (all blocks) |
| Most urgent security fix | C3/C4 — checkout `$_GET` + CSRF nonce |
| Most visible broken functionality | C2 — missing spinner render (fatal), C5-C7 — paginator bugs |