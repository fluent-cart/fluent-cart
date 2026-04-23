# Review: fluent-cart/product-carousel

> **Reviewed:** 2026-02-26
> **Type:** Parent block with 3 InnerBlocks (+1 duplicate paginator)
> **PHP:** `app/Hooks/Handlers/BlockEditors/ProductCarousel/ProductCarouselBlockEditor.php`
> **JSX:** `resources/admin/BlockEditor/ProductCarousel/ProductCarouselBlockEditor.jsx`
> **InnerBlocks PHP:** `app/Hooks/Handlers/BlockEditors/ProductCarousel/InnerBlocks/InnerBlocks.php`
> **InnerBlocks JSX:** `resources/admin/BlockEditor/ProductCarousel/InnerBlocks/InnerBlocks.jsx`

---

## Parent Block Checks

### A. PHP Registration

| # | Check | Status | Notes |
|---|---|---|---|
| A1 | Extends `BlockEditor` base class | PASS | |
| A2 | `$editorName = 'product-carousel'` | PASS | |
| A3 | `getScripts()` correct | PASS | |
| A4 | `getStyles()` correct | PASS | |
| A5 | `localizeData()` uses `$this->getLocalizationKey()` | PASS | |
| A6 | Includes `fluent_cart_block_translation` | PASS | |
| A7 | `render()` method exists | PASS | |
| A8 | Registered in `actions.php` | PASS | |

### B. JSX Editor

| # | Check | Status | Notes |
|---|---|---|---|
| B1 | `registerBlockType()` with correct slug | PASS | |
| B2 | `apiVersion: 3` | PASS | |
| B3 | `useBlockProps()` in edit | PASS | |
| B4 | `<ErrorBoundary>` wrapper | PASS | |
| B5 | `blocktranslate()` | PASS | |
| B6 | `save: () => null` | FAIL | Returns `<InnerBlocks.Content/>` |
| B7 | Category `fluent-cart` | PASS | |

### G. InnerBlocks

| # | Check | Status | Notes |
|---|---|---|---|
| G1 | `skipInnerBlocks()` returns true | FAIL | Missing — should return true since PHP manually renders children |

---

## InnerBlocks (3 child blocks + 1 duplicate)

### Critical Issues

#### CRIT: Swapped imports
- **File:** `resources/admin/BlockEditor/ProductCarousel/InnerBlocks/InnerBlocks.jsx:4-5`
- `ProductButtonBlockEditor` imported from `ProductExcerptBlock.jsx` (the Excerpt component)
- `ProductExcerptBlockEditor` imported from `ProductButtonBlock.jsx` (the Button component)
- Currently **dead code** — only Loop, Controls, Pagination are used. But will cause bugs if someone adds title/price/button/excerpt children.

#### CRIT: Duplicate `product-paginator` registration
- **File:** `resources/admin/BlockEditor/ProductCarousel/InnerBlocks/InnerBlocks.jsx:54`
- Registers `fluent-cart/product-paginator` which is already registered by ShopApp. Only first registration wins.
- See [product-paginator.md](product-paginator.md)

#### CRIT: Wrong parent slug (underscore vs hyphen)
- **File:** `resources/admin/BlockEditor/ProductCarousel/InnerBlocks/InnerBlocks.jsx:58`
- Parent is `fluent-cart/product_carousel` (underscore) but actual parent slug is `fluent-cart/product-carousel` (hyphen)
- Paginator can **never** appear as child of ProductCarousel in editor inserter

#### CRIT: Missing `absint()` on `$productIds`
- **File:** `app/Hooks/Handlers/BlockEditors/ProductCarousel/InnerBlocks/InnerBlocks.php:360-364`
- `$productIds = Arr::get($block->context, 'fluent-cart/product_ids', []);` passed directly to `whereIn()` without sanitization
- **Fix:** `$productIds = array_map('absint', (array) Arr::get($block->context, 'fluent-cart/product_ids', []));`

### Per-Block Summary

| # | Block Slug | B3 | B4 | B5 | B6 | B10 | G1 | PHP | Issues |
|---|---|---|---|---|---|---|---|---|---|
| 1 | `product-carousel-loop` | OK | FAIL | OK | FAIL | WARN | OK | WARN | B4, B6, B10 (`useMemo` unused), PHP (absint) |
| 2 | `product-carousel-controls` | OK | FAIL | WARN | OK | FAIL | OK | OK | B4, B10 (`useContext` unused) |
| 3 | `product-carousel-pagination` | OK | FAIL | WARN | OK | OK | OK | OK | B4 |

### Warning Issues

| Issue | Location |
|---|---|
| Duplicate ReactSupport.js enqueue (`if (... \|\| true)` always true) | `InnerBlocks.php:517,528` |
| `useMemo` imported but never used | `ProductCarouselLoopBlock.jsx:15` |
| Module-level mutable `lastChanged` variable shared across instances | `ProductCarouselLoopBlock.jsx:17` |
| `useContext` imported but unused | `CarouselControlsBlock.jsx:4` |
| Missing `get_block_wrapper_attributes()` in `renderProductCarouselLoop()` | `InnerBlocks.php:345-426` |
| Non-null `save()` returns JSX with `<InnerBlocks.Content/>` | `ProductCarouselLoopBlock.jsx:177-184` |
