# Review: fluent-cart/product-paginator (Global)

> **Reviewed:** 2026-02-26
> **Type:** Global InnerBlock — used in multiple parent blocks
> **Canonical Registration:** `resources/admin/BlockEditor/ShopApp/InnerBlocks/InnerBlocks.jsx` (line 81)
> **PHP Render:** `app/Hooks/Handlers/BlockEditors/ShopApp/InnerBlocks/InnerBlocks.php`

---

## Triple Registration Bug

This block is registered THREE times by three different InnerBlocks files:

| # | File | Parent | Status |
|---|---|---|---|
| 1 | `ShopApp/InnerBlocks/InnerBlocks.jsx:81` | `['fluent-cart/products', 'core/column']` | **CANONICAL** — loads first, wins |
| 2 | `ProductCarousel/InnerBlocks/InnerBlocks.jsx:54` | `['fluent-cart/product_carousel', 'core/column']` | **DEAD** — duplicate registration silently fails. Also wrong slug (underscore). |
| 3 | `MediaCarousel/InnerBlocks/InnerBlocks.jsx:44` | `['fluent-cart/media_carousel', 'core/column']` | **DEAD** — duplicate registration silently fails. Also wrong slug (underscore). |

### Impact

- Only the ShopApp registration takes effect. ProductCarousel and MediaCarousel copies are dead code.
- The ProductCarousel/MediaCarousel copies also have a **non-null `save()`** that returns JSX, and use **underscore parent slugs** (`product_carousel`, `media_carousel`) that don't match the actual parent block slugs (`product-carousel`, `media-carousel`).

### Fix

Remove the duplicate registrations from ProductCarousel and MediaCarousel InnerBlocks.jsx files. If the carousels need paginator functionality, either:
1. Add `fluent-cart/product-carousel` and `fluent-cart/media-carousel` to the ShopApp paginator's `parent` array, OR
2. Create separate paginator blocks with unique slugs (e.g., `fluent-cart/carousel-paginator`)

---

## Canonical Block Checks (ShopApp registration)

### B. JSX Editor

| # | Check | Status | Notes |
|---|---|---|---|
| B1 | `registerBlockType()` correct | PASS | |
| B2 | `apiVersion: 3` | PASS | Via central registration loop |
| B3 | `useBlockProps()` | PASS | |
| B4 | `<ErrorBoundary>` | FAIL | Missing |
| B5 | `blocktranslate()` | PASS | |
| B6 | `save: () => null` | WARN | Non-null save — returns InnerBlocks content |
| B8 | Attributes | NONE | No attributes defined |
| B11 | Parent restriction | PASS | `['fluent-cart/products', 'core/column']` |

### Child Blocks

The paginator has 2 child blocks:

| Block | Slug | Status |
|---|---|---|
| Paginator Info | `fluent-cart/product-paginator-info` | OK (minor unused import `useContext`) |
| Paginator Number | `fluent-cart/product-paginator-number` | OK (missing `blocktranslate()` for strings) |

### PHP Render

| # | Check | Status | Notes |
|---|---|---|---|
| F1 | `Arr::get()` | PASS | |
| F3 | Output escaping | **FAIL** | `$page` in `data-page` attribute and `$label` in HTML body not escaped (line 1263) |

### Critical Issues

| Severity | Issue | Location |
|---|---|---|
| CRIT | Unescaped `$page` in `data-page` attribute — needs `esc_attr()` | `ShopApp/InnerBlocks/InnerBlocks.php:1263` |
| CRIT | Unescaped `$label` in HTML body (non-SVG values need `esc_html()`) | `ShopApp/InnerBlocks/InnerBlocks.php:1263` |
| CRIT | Unreachable code — everything after `return ob_get_clean()` on line 1218 is dead | `ShopApp/InnerBlocks/InnerBlocks.php:1220-1236` |
| CRIT | Triple registration — only first wins, other 2 are dead | See table above |
