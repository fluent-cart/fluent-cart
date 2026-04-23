# Review: fluent-cart/product-info

> **Reviewed:** 2026-02-26
> **Type:** Parent container (no registered InnerBlocks — accepts any child block)
> **PHP:** `app/Hooks/Handlers/BlockEditors/ProductInfoBlockEditor.php`
> **JSX:** `resources/admin/BlockEditor/ProductInfo/ProductInfoBlockEditor.jsx`

---

## Checks

### A. PHP Registration

| # | Check | Status | Notes |
|---|---|---|---|
| A1 | Extends `BlockEditor` | PASS | |
| A2 | `$editorName = 'product-info'` | PASS | |
| A3–A8 | Standard checks | PASS | |

### B. JSX Editor

| # | Check | Status | Notes |
|---|---|---|---|
| B1 | `registerBlockType()` correct | PASS | |
| B2 | `apiVersion: 3` | PASS | |
| B3 | `useBlockProps()` | PASS | |
| B4 | `<ErrorBoundary>` | PASS | |
| B5 | `blocktranslate()` | PASS | |
| B6 | `save: () => null` | FAIL | Returns `<InnerBlocks.Content/>` |
| B7 | Category `fluent-cart` | PASS | |

### D. Styling

| # | Check | Status | Notes |
|---|---|---|---|
| D5 | Safelist no duplicates | FAIL | Duplicate `'xl'` in variants array |

### F1. Product Retrieval

| # | Check | Status | Notes |
|---|---|---|---|
| F1.5 | Single-product container: `setup_postdata()` before inner blocks, `wp_reset_postdata()` after | PASS | |

### Issues

| Severity | Issue | Location |
|---|---|---|
| WARN | Non-null `save()` returns `<InnerBlocks.Content/>` | JSX save function |
| INFO | Duplicate `'xl'` in Tailwind safelist variants | Style config |
