# Review: fluent-cart/checkout

> **Reviewed:** 2026-02-26
> **Type:** Parent block with 22 InnerBlocks
> **PHP:** `app/Hooks/Handlers/BlockEditors/Checkout/CheckoutBlockEditor.php`
> **JSX:** `resources/admin/BlockEditor/Checkout/CheckoutBlockEditor.jsx`
> **InnerBlocks PHP:** `app/Hooks/Handlers/BlockEditors/Checkout/InnerBlocks/InnerBlocks.php` (1025 lines)
> **InnerBlocks JSX:** `resources/admin/BlockEditor/Checkout/InnerBlocks/InnerBlocks.jsx`

---

## Parent Block Checks

### A. PHP Registration

| # | Check | Status | Notes |
|---|---|---|---|
| A1 | Extends `BlockEditor` base class | PASS | |
| A2 | `$editorName = 'checkout'` | PASS | |
| A3 | `getScripts()` returns correct source | PASS | |
| A4 | `getStyles()` returns correct SCSS path | PASS | |
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
| B5 | `blocktranslate()` for strings | PASS | |
| B6 | `save: () => null` | PASS | |
| B7 | Category `fluent-cart` | PASS | |
| B8 | Attributes with types/defaults | PASS | |
| B9 | Icon property | PASS | |

### D. Styling

| # | Check | Status | Notes |
|---|---|---|---|
| D5 | Safelist no duplicates | PASS | |
| D11 | Content paths match | WARN | Tailwind content path may point to PricingTable directory (copy-paste error) |

### Critical Issues (Parent Block)

#### CRIT: Unsanitized `$_GET` in form action URL
- **File:** `CheckoutBlockEditor.php:80-81`
- ```php
  $current_url = home_url(add_query_arg([], $wp->request));
  $current_url = add_query_arg($_GET, $current_url);
  ```
- Entire `$_GET` superglobal passed to `add_query_arg()`. While `esc_attr()` is applied via `RenderHelper::renderAtts()`, no parameter whitelisting.
- **Fix:** Whitelist specific expected GET params (`cart_hash`, `page_id`, etc.)

#### CRIT: No CSRF nonce in checkout form
- **File:** `CheckoutBlockEditor.php:122-135`
- `<form>` lacks `wp_nonce_field()`. Checkout likely uses AJAX with REST nonce (`X-WP-Nonce` header), but form itself has no CSRF protection.
- **Fix:** Add `wp_nonce_field('fluent_cart_checkout', '_fct_nonce')`

---

## InnerBlocks (22 child blocks)

### Registration Architecture

Same pattern as ShopApp: Single `InnerBlocks` class with `getInnerBlocks()` array and `register()` loop. JSX entry iterates `blockEditorData.blocks` with `componentsMap`. All attributes defined in JSX only (PHP block definitions have no `attributes` key).

### Per-Block Summary

| # | Block Slug | B3 | B4 | B5 | B6 | B8 | B10 | G1 | PHP F3.1 | Issues |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | `checkout-name-fields` | OK | FAIL | OK | OK | NONE | OK | OK | OK | B4 |
| 2 | `checkout-create-account-field` | OK | FAIL | OK | OK | OK | OK | OK | OK | B4 |
| 3 | `checkout-address-fields` | OK | FAIL | OK | WARN | NONE | OK | OK | OK | B4, B6 |
| 4 | `checkout-billing-address-field` | OK | FAIL | OK | OK | OK | OK | OK | OK | B4 |
| 5 | `checkout-shipping-address-field` | OK | FAIL | OK | OK | OK | OK | OK | OK | B4 |
| 6 | `checkout-ship-to-different-field` | OK | FAIL | OK | OK | OK | OK | OK | OK | B4 |
| 7 | `checkout-shipping-methods` | OK | FAIL | OK | OK | NONE | OK | OK | OK | B4 |
| 8 | `checkout-payment-methods` | OK | FAIL | OK | OK | NONE | WARN | OK | OK | B4, B10 |
| 9 | `checkout-agree-terms-field` | OK | FAIL | OK | OK | OK | OK | OK | OK | B4 |
| 10 | `checkout-submit-button` | OK | FAIL | OK | OK | NONE | OK | OK | OK | B4 |
| 11 | `checkout-order-notes-field` | OK | FAIL | OK | OK | OK | OK | OK | OK | B4 |
| 12 | `checkout-summary` | OK | FAIL | OK | WARN | NONE | WARN | OK | OK | B4, B6, B10 |
| 13 | `checkout-order-summary` | OK | FAIL | OK | OK | NONE | WARN | OK | OK | B4, B10 |
| 14 | `checkout-summary-footer` | OK | FAIL | OK | WARN | NONE | WARN | OK | **FAIL** | B4, B6, B10, **F3.1** |
| 15 | `checkout-subtotal` | OK | FAIL | OK | OK | NONE | WARN | OK | OK | B4, B10 |
| 16 | `checkout-shipping` | OK | FAIL | OK | OK | NONE | OK | OK | OK | B4 |
| 17 | `checkout-coupon` | OK | FAIL | OK | OK | NONE | OK | OK | OK | B4 |
| 18 | `checkout-manual-discount` | OK | FAIL | OK | OK | NONE | OK | OK | OK | B4 |
| 19 | `checkout-tax` | OK | FAIL | OK | OK | NONE | OK | OK | OK | B4 |
| 20 | `checkout-shipping-tax` | OK | FAIL | OK | OK | NONE | OK | OK | OK | B4 |
| 21 | `checkout-total` | OK | FAIL | OK | OK | NONE | OK | OK | OK | B4 |
| 22 | `checkout-order-bump` | OK | FAIL | OK | OK | OK | OK | OK | OK | B4 |

### Block-Specific Issues

#### WARN: Missing `get_block_wrapper_attributes()` in `renderCheckoutSummaryFooter`
- **File:** `Checkout/InnerBlocks/InnerBlocks.php:829`
- Wrapper uses hardcoded `class="fct_summary_items"` instead of `get_block_wrapper_attributes()`
- Block supports (spacing, typography, color) won't apply. Custom classes from editor ignored.
- **Fix:** `$atts = get_block_wrapper_attributes(['class' => 'fct_summary_items']);`

#### WARN: `shortcode_parse_atts()` merges arbitrary wrapper attrs into `<button>`
- **File:** `app/Services/Renderer/CheckoutRenderer.php:714-725`
- `renderCheckoutButton($atts)` parses `get_block_wrapper_attributes()` output with `shortcode_parse_atts()` then blindly merges into button attributes via `array_merge()`. Could overwrite security-sensitive attributes.

#### INFO: Unused `blockEditorData` variable
- **Files:** `CheckoutSummaryBlock.jsx:3`, `CheckoutSummaryFooterBlock.jsx:3`, `CheckoutSubtotalBlock.jsx:4`
- `window.fluent_cart_checkout_data` assigned but never referenced in these 3 files.

### Attribute Escaping (PHP Render) — Adequate

All user-editable text attributes are passed through either `esc_html()` or `wp_kses_post()` via `FormFieldRenderer::renderField()` and `FormFieldRenderer::renderSection()`. No raw attribute output found.

### Systemic (all 22 blocks in this group)

- **No `<ErrorBoundary>`** — all 22 child blocks lack it
- **`{...props} {...blockProps}` double-spread** — all 22 blocks spread both `props` and `blockProps` on root element. Only `blockProps` should be spread.
- **No attributes defined** — 13 of 22 blocks export no attributes (purely presentational — some acceptable)
- **Non-null `save()`** — 3 container blocks (`checkout-address-fields`, `checkout-summary`, `checkout-summary-footer`) return `<InnerBlocks.Content/>` — intentional for InnerBlocks containers
