# Review: fluent-cart/related-product

> **Reviewed:** 2026-02-26
> **Type:** Parent block with 1 InnerBlock (product-template)
> **PHP:** `app/Hooks/Handlers/BlockEditors/RelatedProduct/RelatedProductBlockEditor.php`
> **JSX:** `resources/admin/BlockEditor/RelatedProduct/RelatedProductBlockEditor.jsx`
> **InnerBlocks PHP:** `app/Hooks/Handlers/BlockEditors/RelatedProduct/InnerBlocks/InnerBlocks.php`
> **InnerBlock JSX:** `resources/admin/BlockEditor/RelatedProduct/Components/ProductTemplateBlock.jsx`

---

## Parent Block Checks

### A. PHP Registration

| # | Check | Status | Notes |
|---|---|---|---|
| A1 | Extends `BlockEditor` base class | PASS | |
| A2 | `$editorName = 'related-product'` | PASS | |
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

## InnerBlock: `fluent-cart/product-template`

### JSX Checks

| # | Check | Status | Notes |
|---|---|---|---|
| B1 | `registerBlockType('fluent-cart/product-template', ...)` | PASS | |
| B2 | `apiVersion: 3` | PASS | |
| B3 | `useBlockProps()` | PASS | |
| B4 | `<ErrorBoundary>` | FAIL | |
| B5 | `blocktranslate()` | PASS | Used for title, description, empty state |
| B6 | `save: () => null` | FAIL | Returns `<InnerBlocks.Content/>` |
| B7 | Category `fluent-cart` | PASS | |
| B9 | Icon | PASS | `Layout` icon |
| G1 | Parent restriction | PASS | `[parentBlockName]` → `fluent-cart/related-product` |
| G2 | `usesContext` | PASS | Extensive context array matching PHP |

### PHP Render Checks

| # | Check | Status | Notes |
|---|---|---|---|
| F1 | `Arr::get()` for attributes | PASS | |
| F2 | Graceful fallback | PASS | Returns empty when no related products |
| F2.1 | `absint()` on IDs | PASS | Uses `absint()` on `$postsPerPage`, `$productId`, `$columns` |
| F2.7 | No raw attribute interpolation | PASS | CSS variable value is integer-clamped |
| F3.1 | `get_block_wrapper_attributes()` | FAIL | Output wrapper does not use it |

### Issues

| Severity | Issue | Location |
|---|---|---|
| WARN | Missing `get_block_wrapper_attributes()` on rendered output | `InnerBlocks.php:156-189` |
| WARN | Non-null `save()` returns `<InnerBlocks.Content/>` | `ProductTemplateBlock.jsx:219-226` |
| WARN | No `<ErrorBoundary>` wrapper | `ProductTemplateBlock.jsx` edit function |
| INFO | Different registration pattern — no `CanEnqueue` trait, no `enqueueScripts()` (imported directly via JSX) | `InnerBlocks.php` |

### Notes

- Uses `ShopResource::getSimilarProducts()` for data retrieval
- Properly handles `default` and `custom` query types
- Correctly uses `setup_postdata($product->ID)` / `wp_reset_postdata()` in loop
- Product retrieval properly sanitized with `absint()` throughout
