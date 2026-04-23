# Review: fluent-cart/media-carousel

> **Reviewed:** 2026-02-26
> **Type:** Parent block with 4 InnerBlocks (+1 duplicate paginator)
> **PHP:** `app/Hooks/Handlers/BlockEditors/MediaCarousel/MediaCarouselBlockEditor.php`
> **JSX:** `resources/admin/BlockEditor/MediaCarousel/MediaCarouselBlockEditor.jsx`
> **InnerBlocks PHP:** `app/Hooks/Handlers/BlockEditors/MediaCarousel/InnerBlocks/InnerBlocks.php`
> **InnerBlocks JSX:** `resources/admin/BlockEditor/MediaCarousel/InnerBlocks/InnerBlocks.jsx`

---

## Parent Block Checks

### A. PHP Registration

| # | Check | Status | Notes |
|---|---|---|---|
| A1 | Extends `BlockEditor` base class | PASS | |
| A2 | `$editorName = 'media-carousel'` | PASS | |
| A3–A8 | Standard checks | PASS | All pass |

### B. JSX Editor

| # | Check | Status | Notes |
|---|---|---|---|
| B1–B5 | Standard checks | PASS | |
| B6 | `save: () => null` | FAIL | Returns `<InnerBlocks.Content/>` |
| B7 | Category `fluent-cart` | PASS | |

### G. InnerBlocks

| # | Check | Status | Notes |
|---|---|---|---|
| G1 | `skipInnerBlocks()` returns true | FAIL | Missing |

---

## InnerBlocks (4 child blocks + 1 duplicate)

### Critical Issues

#### CRIT: Duplicate `product-paginator` registration
- **File:** `resources/admin/BlockEditor/MediaCarousel/InnerBlocks/InnerBlocks.jsx:44`
- Third registration of `fluent-cart/product-paginator` (ShopApp and ProductCarousel also register it). Silently fails.
- See [product-paginator.md](product-paginator.md)

#### CRIT: Wrong parent slug (underscore vs hyphen)
- **File:** `resources/admin/BlockEditor/MediaCarousel/InnerBlocks/InnerBlocks.jsx:47`
- Parent `fluent-cart/media_carousel` (underscore) vs actual `fluent-cart/media-carousel` (hyphen)

#### CRIT: Missing `absint()` on `$productId`
- **File:** `app/Hooks/Handlers/BlockEditors/MediaCarousel/InnerBlocks/InnerBlocks.php:254`
- `$productId = Arr::get($block->context, 'fluent-cart/product_id');` used without `absint()`

### Per-Block Summary

| # | Block Slug | B2 | B3 | B4 | B5 | B6 | B10 | G1 | PHP | Issues |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | `media-carousel-loop` | FAIL | OK | FAIL | OK | FAIL | OK | OK | WARN | B2 (apiVersion 2), B4, B6, PHP (absint) |
| 2 | `media-carousel-product-image` | FAIL | OK | FAIL | OK | OK | OK | OK | OK | B2, B4 |
| 3 | `media-carousel-controls` | FAIL | OK | FAIL | WARN | OK | FAIL | OK | N/A | B2, B4, B10 (`useContext` unused) |
| 4 | `media-carousel-pagination` | FAIL | OK | FAIL | WARN | OK | OK | OK | N/A | B2, B4 |

### Warning Issues

| Issue | Location |
|---|---|
| `apiVersion: 2` in InnerBlocks registration (should be 3) | `InnerBlocks.jsx:30` |
| Loose truthy check `!$hasControls` instead of `!== 'yes'` for controls/pagination | `InnerBlocks.php:260-266` |
| `useContext` imported but unused | `CarouselControlsBlock.jsx:4` |
| Non-null `save()` returns JSX in MediaCarouselLoopBlock | `MediaCarouselLoopBlock.jsx:221-227` |
| `$wrapper_attributes` echoed with raw `echo` (safe by WP convention, but lacks phpcs comment) | `InnerBlocks.php:181` |
| Category `"product-elements"` in registration loop | `InnerBlocks.jsx:30` |
