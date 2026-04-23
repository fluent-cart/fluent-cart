# Review: fluent-cart/products (ShopApp)

> **Reviewed:** 2026-02-26
> **Type:** Parent block with 22 InnerBlocks
> **PHP:** `app/Hooks/Handlers/BlockEditors/ShopApp/ShopAppBlockEditor.php`
> **JSX:** `resources/admin/BlockEditor/ShopApp/ShopAppBlockEditor.jsx`
> **InnerBlocks PHP:** `app/Hooks/Handlers/BlockEditors/ShopApp/InnerBlocks/InnerBlocks.php`
> **InnerBlocks JSX:** `resources/admin/BlockEditor/ShopApp/InnerBlocks/InnerBlocks.jsx`

---

## Parent Block Checks

### A. PHP Registration

| # | Check | Status | Notes |
|---|---|---|---|
| A1 | Extends `BlockEditor` base class | PASS | |
| A2 | `$editorName = 'products'` | PASS | |
| A3 | `getScripts()` returns correct source | PASS | |
| A4 | `getStyles()` returns correct SCSS path | PASS | |
| A5 | `localizeData()` uses `$this->getLocalizationKey()` | PASS | |
| A6 | Includes `fluent_cart_block_translation` | PASS | |
| A7 | `render()` method exists | PASS | |
| A8 | Registered in `actions.php` | PASS | Line 49 |

### B. JSX Editor

| # | Check | Status | Notes |
|---|---|---|---|
| B1 | `registerBlockType()` with correct slug | PASS | |
| B2 | `apiVersion: 3` | PASS | |
| B3 | `useBlockProps()` in edit | PASS | |
| B4 | `<ErrorBoundary>` wrapper | PASS | One of few blocks that has it |
| B5 | `blocktranslate()` for strings | PASS | |
| B6 | `save: () => null` | FAIL | Returns `<InnerBlocks.Content/>` — dynamic block should use null |
| B7 | Category `fluent-cart` | PASS | |
| B8 | Attributes with types/defaults | PASS | |
| B9 | Icon property | PASS | |
| B10 | No unused imports | PASS | |
| B11 | N/A | | Top-level block, no parent |

### D. Styling

| # | Check | Status | Notes |
|---|---|---|---|
| D1 | SCSS file exists | PASS | |
| D2 | `fct-` class convention | PASS | |
| D3 | Tailwind config exists | PASS | |
| D5 | Safelist no duplicates | FAIL | Duplicate `'xl'` in variants array |
| D6 | `@config` first line | PASS | |
| D7 | `@tailwind utilities` | PASS | |
| D8 | Shared extends imported | PASS | |
| D9 | `preflight: false` | PASS | |
| D10 | `darkMode: 'class'` | PASS | |
| D11 | Content paths match | PASS | |

### E. Build (vite.config.mjs)

| # | Check | Status | Notes |
|---|---|---|---|
| E1 | JSX entry in inputs | PASS | |
| E2 | SCSS entry in inputs | PASS | |
| E3 | InnerBlocks JSX entry | PASS | |

### F. Render

| # | Check | Status | Notes |
|---|---|---|---|
| F1 | `Arr::get()` for attributes | PASS | |
| F2 | Graceful fallback | PASS | |
| F3 | No direct DB queries | PASS | Uses Product model |

### G. InnerBlocks

| # | Check | Status | Notes |
|---|---|---|---|
| G1 | `skipInnerBlocks()` returns true | FAIL | Missing — should return true since PHP manually renders children |
| G3 | Default template defined | PASS | |

---

## InnerBlocks (22 child blocks)

### Registration Architecture

All 22 child blocks are registered via:
- **PHP:** Single `InnerBlocks` class with `getInnerBlocks()` returning block definitions and `register()` iterating them
- **JSX:** Single `InnerBlocks.jsx` entry point with `componentsMap` mapping names to imported components

Central registration loop sets `category: "product-elements"` (not `"fluent-cart"`) and `apiVersion: 3` for all blocks.

### Per-Block Summary

| # | Block Slug | B3 | B4 | B5 | B6 | B8 | B10 | G1 | PHP | Issues |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | `shopapp-product-title` | OK | FAIL | OK | OK | OK | OK | OK | OK | B4 |
| 2 | `shopapp-product-excerpt` | OK | FAIL | OK | OK | NONE | WARN | OK | OK | B4, B8, B10 |
| 3 | `shopapp-product-price` | OK | FAIL | FAIL | OK | NONE | OK | OK | OK | B4, B5, B8 |
| 4 | `shopapp-product-image` | OK | FAIL | FAIL | WARN | NONE | OK | OK | WARN | B4, B5, B6, B8 |
| 5 | `shopapp-product-buttons` | OK | FAIL | OK | OK | NONE | OK | OK | OK | B4, B8 |
| 6 | `shopapp-product-container` | OK | FAIL | OK | WARN | OK | OK | OK | OK | B4, B6 |
| 7 | `shopapp-product-filter` | WARN | FAIL | OK | WARN | OK | OK | OK | OK | B3, B4, B6 |
| 8 | `shopapp-product-view-switcher` | OK | FAIL | OK | OK | NONE | WARN | OK | N/A | B4, B8, B10 |
| 9 | `shopapp-product-filter-sort-by` | OK | FAIL | OK | OK | NONE | WARN | OK | N/A | B4, B8, B10 |
| 10 | `shopapp-product-filter-search-box` | WARN | FAIL | OK | OK | NONE | WARN | OK | OK | B3, B4, B8, B10 |
| 11 | `shopapp-product-filter-filters` | WARN | FAIL | FAIL | OK | NONE | WARN | OK | OK | B3, B4, B5, B8, B10 |
| 12 | `shopapp-product-filter-button` | WARN | FAIL | FAIL | WARN | NONE | OK | OK | OK | B3, B4, B5, B6, B8 |
| 13 | `shopapp-product-filter-apply-button` | OK | FAIL | FAIL | OK | NONE | OK | OK | OK | B4, B5, B8 |
| 14 | `shopapp-product-filter-reset-button` | OK | FAIL | FAIL | OK | NONE | OK | OK | OK | B4, B5, B8 |
| 15 | `shopapp-product-action-container` | OK | FAIL | FAIL | WARN | NONE | WARN | OK | OK | B4, B5, B6, B8, B10 |
| 16 | `shopapp-product-no-result` | OK | FAIL | FAIL | WARN | NONE | OK | OK | OK | B4, B5, B6, B8 |
| 17 | `shopapp-product-loop` | OK | FAIL | FAIL | WARN | OK | WARN | OK | WARN | B4, B5, B6, B10, PHP |
| 18 | `product-paginator` (inline) | OK | FAIL | OK | WARN | NONE | OK | OK | OK | B4, B6, B8 — See [product-paginator.md](product-paginator.md) |
| 19 | `product-paginator-info` | OK | FAIL | OK | OK | NONE | WARN | OK | OK | B4, B8, B10 |
| 20 | `product-paginator-number` | OK | FAIL | FAIL | OK | NONE | OK | OK | OK | B4, B5, B8 |
| 21 | `shopapp-product-loader` | OK | FAIL | FAIL | WARN | NONE | OK | OK | OK | B4, B5, B6, B8 |
| 22 | `shopapp-product-spinner` | OK | FAIL | FAIL | WARN | NONE | OK | OK | **CRIT** | B4, B5, B6, B8, **CRIT-PHP** |

### Critical Issues (in this block group)

#### CRIT: Missing `renderProductSpinnerBlock()` method
- **File:** `app/Hooks/Handlers/BlockEditors/ShopApp/InnerBlocks/InnerBlocks.php:493`
- Block references `[$this, 'renderProductSpinnerBlock']` as callback but **no such method exists**
- Will cause PHP fatal error when block renders on frontend
- **Fix:** Add the method, or rename callback to an existing method

#### CRIT: Dead cache logic `$needsReset || true`
- **File:** `app/Hooks/Handlers/BlockEditors/ShopApp/InnerBlocks/InnerBlocks.php:875`
- `|| true` makes the entire `$needsReset` check dead code — transient is always re-written on every page load
- **Fix:** Remove `|| true`

#### CRIT: Unsanitized transient key
- **File:** `app/Hooks/Handlers/BlockEditors/ShopApp/InnerBlocks/InnerBlocks.php:857`
- `$blockId = 'fct_product_loop_client_' . $attributes['wp_client_id'];` — user-controlled, no `sanitize_key()`
- **Fix:** `sanitize_key($attributes['wp_client_id'])`

#### CRIT: Unescaped paginator output
- **File:** `app/Hooks/Handlers/BlockEditors/ShopApp/InnerBlocks/InnerBlocks.php:1263`
- `data-page="' . $page . '">' . $label . '</li>'` — missing `esc_attr($page)` and `esc_html($label)`

### Warning Issues

| Issue | Location |
|---|---|
| `console.log({blocks})` left in production | `ProductLoopBlock.jsx:145` |
| `blockProps` computed but unused in ProductFilterBlock | `ProductFilter/ProductFilterBlock.jsx:44` |
| `renderTitle` returns bare `'not found'` string (not escaped, not translated) | `InnerBlocks.php:731` |
| Unreachable code after `return ob_get_clean()` in paginator | `InnerBlocks.php:1220` |
| Module-level mutable `lastChanged` variable | `ProductLoopBlock.jsx:17` |
| Dead `setFilters` function in ProductFilterSearchBlock | `ProductFilterSearchBlock.jsx` |
| `useBlockProps.save()` called but result discarded (returns null) | `ProductFilterSearchBlock.jsx:33`, `ProductFilterFilters.jsx:18` |
| `default_filter_options` attribute has no `type` | `ProductFilterBlock.jsx:19` |
| ProductFilterViewSwitcherBlock: `InspectorControls`, `InnerBlocks`, `ToggleControl`, `TextControl` imported but unused | `ProductFilterViewSwitcherBlock.jsx` |
| ProductFilterSortByBlock: same unused imports | `ProductFilterSortByBlock.jsx` |

### Systemic (all 22 blocks in this group)

- **No `<ErrorBoundary>`** — none of the 22 child blocks wrap edit in ErrorBoundary
- **`{...props}` spread on DOM** — 12 blocks spread full `props` onto native elements
- **Category `"product-elements"`** — all registered under non-standard category
- **No `attributes` definition** — 13 blocks export empty or no attributes
- **Missing `blocktranslate()`** — 11 blocks have hardcoded English strings
- **Non-null `save()`** — 8 blocks return `<InnerBlocks.Content/>` instead of `null`