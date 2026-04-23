# Product Description Block — Implementation Plan

**Block:** `fluent-cart/product-description`
**Date:** 2026-02-22
**Status:** Implemented (reviewed & cleaned up)

---

## 1. Overview

Displays the full `post_content` of a FluentCart product. This is the rich HTML description set in the WordPress editor, rendered with `wpautop()` for paragraph formatting.

**Key behavior:**
- Returns empty string when no product is found or `post_content` is empty
- Renders trusted WordPress post content as HTML (no additional escaping)
- Supports full typography, color, spacing, border, and shadow block supports

---

## 2. Block Attributes

```jsx
attributes: {
    product_id: {
        type: ['string', 'number'],
        default: '',
    },
}
```

**Minimal attributes by design.** Unlike older blocks, this does NOT use `query_type` or `inside_product_info` attributes. Parent context detection (`isInsideProductInfo`) is computed live via `useSelect` in the editor — not persisted. The PHP `render()` uses the `product_id`-first retrieval pattern which doesn't need them.

---

## 3. Product Retrieval Pattern

### PHP render — `product_id`-first

```php
$productId = absint(Arr::get($shortCodeAttribute, 'product_id', 0));

if ($productId) {
    $product = Product::query()->find($productId);
} else {
    $product = fluent_cart_get_current_product();
}
```

**Why this order:**
- If a user in a theme template explicitly picks a product, that choice takes priority — even if the block is inside a product context
- When inside a product container (Product Info, Carousel, etc.), the editor hides the product picker → `product_id` stays empty → `fluent_cart_get_current_product()` is used naturally
- Eliminates the need for `query_type` / `inside_product_info` attributes entirely

### JSX editor — context-aware data fetching

```jsx
useEffect(() => {
    if (singleProductData?.product) {
        setSelectedProduct(singleProductData.product);       // Context product
    } else if (!isInsideProductInfo && attributes.product_id) {
        fetchProduct();                                       // Explicit product_id
    } else {
        setSelectedProduct({});                               // Clear stale state
    }
}, [attributes.product_id, singleProductData?.product]);
```

Three-branch pattern ensures stale previews are cleared when data source changes.

---

## 4. PHP Block Supports

```php
public function supports(): array
{
    return [
        'html'                 => false,
        'align'                => true,
        'typography'           => [
            'fontSize'                      => true,
            'lineHeight'                    => true,
            '__experimentalFontFamily'      => true,
            '__experimentalFontWeight'      => true,
            '__experimentalFontStyle'       => true,
            '__experimentalTextTransform'   => true,
            '__experimentalTextDecoration'  => true,
            '__experimentalLetterSpacing'   => true,
            '__experimentalDefaultControls' => [
                'fontSize'   => true,
                'lineHeight' => true,
                'fontWeight' => true,
            ],
        ],
        'color'                => [
            'text'       => true,
            'background' => true,
            'link'       => true,
            'gradients'  => true,
        ],
        'spacing'              => [
            'margin'  => true,
            'padding' => true,
        ],
        '__experimentalBorder' => [
            'color'  => true,
            'radius' => true,
            'style'  => true,
            'width'  => true,
        ],
        'shadow'               => true,
    ];
}
```

Full typography support including font family, weight, style, transform, decoration, letter spacing — more comprehensive than most blocks. Also includes `link` color support since description content contains links.

---

## 5. PHP Render Logic

```php
public function render(array $shortCodeAttribute, $block = null)
{
    AssetLoader::loadSingleProductAssets();

    $productId = absint(Arr::get($shortCodeAttribute, 'product_id', 0));

    if ($productId) {
        $product = Product::query()->find($productId);
    } else {
        $product = fluent_cart_get_current_product();
    }

    if (!$product || empty($product->post_content)) {
        return '';
    }

    $wrapper_attributes = get_block_wrapper_attributes([
        'class' => 'fct-product-description',
    ]);

    return sprintf(
        '<div %s>%s</div>',
        $wrapper_attributes,
        wpautop($product->post_content)
    );
}
```

### Key design decisions:
- **`AssetLoader::loadSingleProductAssets()`** — loads shared single-product frontend assets
- **`wpautop($product->post_content)`** — converts double newlines to `<p>` tags. This is trusted WordPress post content — no additional escaping needed (re-escaping with `esc_html()` or `wp_kses_post()` would break the HTML formatting)
- **`get_block_wrapper_attributes()`** — handles all block support styles and escaping automatically
- **No `with()` eager loading** — `post_content` is on the Product model itself, no relations needed
- **No style enqueue** — uses `AssetLoader` instead of `Vite::enqueueStyle()` since the SCSS is minimal (utilities only)

---

## 6. JSX Editor Component

### Editor behavior
- Detects parent context using `useSelect` (same parent list as Excerpt, PriceRange, etc.)
- When inside a product container: uses `useSingleProductData()` for product data, hides product picker
- When standalone: shows product picker in inspector
- Renders `post_content` via `dangerouslySetInnerHTML` with a comment noting it's trusted WP content
- Empty state shows "Product Description" placeholder text

### Inspector controls — conditional visibility

**Only when `isInsideProductInfo` is false (standalone) — Product panel:**
- Product Picker (SelectProductModal)
- Selected product chip with title
- Description preview (stripped HTML, truncated to 120 chars with conditional `'...'`)

**When inside a product context:**
- Inspector is hidden entirely (no settings needed — product comes from context)

**Block supports (automatic via WP):**
- Typography (font family, size, weight, style, line height, transform, decoration, letter spacing)
- Colors (text, background, link, gradients)
- Spacing (margin, padding)
- Border (color, radius, style, width)
- Shadow

### Icon
Uses `Description` icon from `Icons.jsx` — a document with 3 text lines. Distinct from the `Excerpt` icon (document with 1 bottom line) to avoid confusion in the block inserter.

---

## 7. SCSS & Tailwind

### SCSS (minimal)
```scss
@config "./product-description-block-editor.config.js";
@tailwind utilities;
```

No custom styles needed — the block renders trusted HTML content and all visual styling comes from WordPress block supports (typography, colors, spacing, border, shadow).

### Tailwind config
```js
module.exports = {
    darkMode: 'class',
    content: ['./resources/admin/BlockEditor/ProductDescription/**/*.*'],
    corePlugins: { preflight: false },
    theme: {
        extend: {
            colors: colors,
            borderRadius: borderRadius,
        },
        spacing: spacing,
        fontSize: fontSize,
    },
}
```

Clean config — no unnecessary safelist, fontFamily overrides, or grid extends (those were removed during review cleanup).

---

## 8. Files

### Created (pre-existing block — files were already present)
| # | File | Purpose |
|---|---|---|
| 1 | `app/Hooks/Handlers/BlockEditors/ProductDescriptionBlockEditor.php` | PHP class — registration, supports, render callback |
| 2 | `resources/admin/BlockEditor/ProductDescription/ProductDescriptionBlockEditor.jsx` | Block registration, edit component, context detection |
| 3 | `resources/admin/BlockEditor/ProductDescription/Components/InspectorSettings.jsx` | Inspector panel — product picker with description preview |
| 4 | `resources/admin/BlockEditor/ProductDescription/style/product-description-block-editor.scss` | Tailwind utilities import |
| 5 | `resources/admin/BlockEditor/ProductDescription/style/product-description-block-editor.config.js` | Tailwind config |

### Modified during review cleanup
| # | File | Change |
|---|---|---|
| 1 | `ProductDescriptionBlockEditor.jsx` | Removed `query_type`/`inside_product_info` attributes, removed attribute sync `useEffect`, added `?.` on API response, removed empty `.finally()`, changed icon to `Description`, `save: () => null` |
| 2 | `ProductDescriptionBlockEditor.php` | Simplified `render()` to `product_id`-first pattern, removed `inside_product_info`/`query_type` validation |
| 3 | `product-description-block-editor.scss` | Removed `@tailwind base;` (conflicted with WP styles), removed Google Fonts `@import` |
| 4 | `product-description-block-editor.config.js` | Removed unused safelist, fontFamily overrides, gridTemplateColumns/gridColumn extends |
| 5 | `resources/admin/BlockEditor/Icons.jsx` | Added `Description` icon (document with 3 lines) |

### Registration (pre-existing)
- `app/Hooks/actions.php` line 70: `ProductDescriptionBlockEditor::register();`
- `vite.config.mjs` lines 77-78: JSX + SCSS entries

---

## 9. Comparison with Sibling Block (Excerpt)

ProductDescription and Excerpt are structurally similar (both display product text content). After cleanup, ProductDescription is better in several areas:

| Aspect | ProductDescription | Excerpt |
|---|---|---|
| `inside_product_info` default | `'no'` (matches PHP whitelist) | `'-'` (not in `['yes', 'no']` whitelist) |
| `useEffect` attribute sync | Removed entirely (not needed) | Uses conditional spread (BAD pattern) |
| Data-fetching cleanup | Three-branch with `else { setSelectedProduct({}) }` | No cleanup branch (stale previews persist) |
| API response safety | `response?.product` (optional chaining) | `response.product` (can throw) |
| SCSS | Clean: `@config` + `@tailwind utilities` only | Has `@tailwind base` + Google Fonts import |
| Tailwind config | Clean: only used extends | Has unused safelist, fontFamily, grid extends |
| PHP input validation | `in_array()` for enums (now removed — not needed) | No validation |
| Icon | Distinct `Description` icon | Shared `Excerpt` icon |
| `save` function | `save: () => null` | `save: function (props)` (unused param) |
| Empty `.finally()` | Removed | Still present |

**Note:** Excerpt could benefit from the same cleanup applied to ProductDescription.

---

## 10. Security Checklist

- [x] `product_id` validated with `absint()`
- [x] `post_content` rendered with `wpautop()` — trusted WP content, not re-escaped
- [x] `get_block_wrapper_attributes()` handles wrapper escaping
- [x] JSX uses optional chaining on API response (`response?.product`)
- [x] `dangerouslySetInnerHTML` used only for trusted WP `post_content` (with comment)
- [x] `setAttributes()` sanitizes product ID: `String(selectedProduct.ID)`
- [x] Text truncation in inspector correctly handles short content (no false `'...'`)
- [x] No `query_type`/`inside_product_info` attributes to validate (removed)

---

## 11. Edge Cases

| Scenario | Behavior |
|---|---|
| No product selected (standalone, no ID) | Shows "Product Description" placeholder |
| Product with empty `post_content` | Returns `''` — nothing rendered |
| Product with rich HTML content (images, lists, embeds) | Renders faithfully via `wpautop()` |
| Block inside product context | Uses context product, hides product picker |
| Block standalone in theme template | Shows product picker, uses explicit `product_id` |
| User picks explicit product inside product context | `product_id` takes priority over context |
| Description shorter than 120 chars in inspector preview | Shown without `'...'` ellipsis |
| Description longer than 120 chars in inspector preview | Truncated with `'...'` appended |
