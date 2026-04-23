# Sale Badge Block — Implementation Plan

**Block:** `fluent-cart/sale-badge`
**Date:** 2026-02-22
**Status:** Implemented

---

## 1. Overview

A dynamic badge that shows when a product is on sale. Supports multiple visual styles (badge, ribbon, tag), configurable positioning when overlaid on product images/cards, and shows either custom text ("Sale!") or a computed discount percentage ("-20% OFF").

**Key behavior:** Returns empty string when the product is NOT on sale — the badge simply disappears from the frontend.

---

## 2. Data Model — How "On Sale" Works

### Price storage
- All prices stored in **cents as DOUBLE** in `fct_product_variations` table
- `item_price` = current/sale price (e.g., 999 = $9.99)
- `compare_price` = original "was" price (e.g., 1499 = $14.99)
- `fct_product_details` has `min_price` / `max_price` (range of `item_price` across variants) — NO compare_price at product level

### Sale condition (used consistently across the entire codebase)
```
variant.compare_price > variant.item_price  AND  variant.compare_price > 0
```

References:
- `ProductQuery.php:134-138` — `on_sale` filter: `WHERE item_price < compare_price`
- `ProductCardRender.php:175` — `$firstVariant->compare_price > $minPrice`
- `ProductRenderer.php:1329-1332` — `$comparePrice <= $variant->item_price` → not on sale
- `ProductVariationResource.php:139-140` — write gate: compare_price < item_price gets stored as 0

### Sale detection by product type
- **Simple products** (`variation_type === 'simple'`): Check first variant's `compare_price > item_price`
- **Variable products** (`simple_variation` / `advance_variation`): Product is "on sale" if ANY variant has `compare_price > item_price`. For discount %, use the best (highest) discount across variants.

### Discount calculation
```php
$discountPercent = round((($compare_price - $item_price) / $compare_price) * 100);
```
No existing helper — must be computed inline (consistent with rest of codebase).

---

## 3. Where the Badge Can Be Used

### Parent block compatibility matrix

| Parent Block | Slug | InnerBlocks? | Overlay? | Sale Badge placement |
|---|---|---|---|---|
| **Product Image** (standalone) | `fluent-cart/product-image` | Yes (PHP renders) | Yes — `position: absolute` overlay div | Badge overlays on image |
| **ShopApp Product Image** | `fluent-cart/shopapp-product-image` | Yes (`<InnerBlocks/>` in JSX) | Yes — same overlay pattern | Badge overlays on image in shop loop |
| **Product Info** | `fluent-cart/product-info` | Yes (`<InnerBlocks>` in JSX) | No | Inline badge in product layout |
| **ShopApp Product Loop** | `fluent-cart/shopapp-product-loop` | Yes (`useInnerBlocksProps`) | No | Inline badge in loop card |
| **Product Carousel** | `fluent-cart/product-carousel` | Yes | No | Inline badge in carousel card |
| **Product Card** | `fluent-cart/product-card` | **NO** — renders via shortcode | N/A | **NOT supported as inner block** |
| **Standalone** | (no parent) | N/A | N/A | User picks product manually |

### Changes made to existing blocks

#### a. Product Image — `pointer-events-none` fix
**File:** `resources/admin/BlockEditor/ProductImage/ProductImageBlockEditor.jsx`

Moved `pointer-events-none` from the overlay `<div>` wrapping `<InnerBlocks>` to the `<img>` element. This makes InnerBlocks (Sale Badge) interactive/selectable in the editor while the image itself doesn't steal clicks.

```jsx
// BEFORE — InnerBlocks not clickable
<img className="w-full aspect-square object-cover rounded-md ..." />
<div className="absolute inset-0 pointer-events-none">
    <InnerBlocks ... />
</div>

// AFTER — InnerBlocks clickable, image ignores clicks
<img className="w-full aspect-square object-cover rounded-md ... pointer-events-none" />
<div className="absolute inset-0">
    <InnerBlocks ... />
</div>
```

#### b. Product Image — `allowedBlocks` for InnerBlocks
**File:** `resources/admin/BlockEditor/ProductImage/ProductImageBlockEditor.jsx`

`<InnerBlocks>` already has an `allowedBlocks` array that includes `'fluent-cart/sale-badge'`.

#### c. No parent constraint on Sale Badge
Since this is a standalone top-level block, it does NOT have a `parent` constraint. It can be used anywhere. The inspector adapts based on context:
- **Position & Style panel** only shown inside visual containers (product-image, shopapp-product-image)
- **Product picker** only shown when standalone (not inside a product context)

### NOT modified (and why)
- **ProductImageBlockEditor.php** — Already renders ALL inner blocks generically; no allowedBlocks filter in PHP
- **ShopApp InnerBlocks.php** — shopapp-product-image already accepts any inner block
- **ProductCardBlockEditor** — Does NOT support InnerBlocks (uses shortcode). Sale badge for product cards would require a separate enhancement to the shortcode render — out of scope

---

## 4. Block Attributes

```jsx
attributes: {
    // Badge content
    badge_text: {
        type: 'string',
        default: 'Sale!',
    },
    show_percentage: {
        type: 'boolean',
        default: false,
    },
    percentage_text: {
        type: 'string',
        default: '-{percent}%',  // Template — {percent} replaced with calculated value
    },

    // Price source
    price_source: {
        type: 'string',
        default: 'default_variant',  // 'default_variant' | 'best_discount'
    },

    // Visual style (only relevant inside card/image containers)
    badge_style: {
        type: 'string',
        default: 'badge',  // 'badge' | 'ribbon' | 'tag'
    },
    badge_position: {
        type: 'string',
        default: 'top-left',  // 'top-left' | 'top-right' | 'bottom-left' | 'bottom-right'
    },

    // Product context
    product_id: {
        type: ['string', 'number'],
        default: '',
    },
}
```

**Note:** `query_type` and `inside_product_info` attributes are NOT used. They were removed because:
- `isInsideProductInfo` and `isInsideVisualContainer` are computed live via `useSelect` in the editor — no need to persist as attributes
- PHP `render()` uses `product_id`-first pattern which doesn't need them (see Section 6)

### Price source setting

| Option | `price_source` value | Behavior |
|---|---|---|
| **Default Variant** | `default_variant` | Uses the product's default variant (via `default_variation_id`). Sale = that variant's `compare_price > item_price`. Discount % from that single variant. Best for simple products. |
| **Best Discount** | `best_discount` | Scans ALL variants. Sale = any variant on sale. Discount % = highest discount found. Best for variable products where you want to advertise the best deal. |

Note: `fct_product_details` has `min_price`/`max_price` (range of `item_price` across variants) but NO `compare_price`. So sale detection always comes from `fct_product_variations`.

---

## 5. PHP Block Supports

```php
public function supports(): array
{
    return [
        'html'                 => false,
        'align'                => ['left', 'center', 'right'],
        'color'                => [
            'text'       => true,
            'background' => true,
            'gradients'  => true,
        ],
        'typography'           => [
            'fontSize'                      => true,
            '__experimentalFontWeight'      => true,
            '__experimentalLetterSpacing'   => true,
            '__experimentalTextTransform'   => true,
            '__experimentalDefaultControls' => [
                'fontSize' => true,
            ],
        ],
        'spacing'              => [
            'padding' => true,
            'margin'  => true,
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

This gives the user full control over colors, pill/rounded shapes (border-radius), padding, font size, shadows, etc. — all via standard WordPress block supports.

---

## 6. PHP Render Logic

```php
public function render(array $shortCodeAttribute, $block = null): string
{
    // Enqueue frontend styles
    Vite::enqueueStyle(
        'fluent-cart-sale-badge',
        'admin/BlockEditor/SaleBadge/style/sale-badge-block-editor.scss'
    );

    // 1. Validate attributes
    $badgeStyle = Arr::get($shortCodeAttribute, 'badge_style', 'badge');
    if (!in_array($badgeStyle, ['badge', 'ribbon', 'tag'], true)) {
        $badgeStyle = 'badge';
    }

    $badgePosition = Arr::get($shortCodeAttribute, 'badge_position', 'top-left');
    if (!in_array($badgePosition, ['top-left', 'top-right', 'bottom-left', 'bottom-right'], true)) {
        $badgePosition = 'top-left';
    }

    $priceSource = Arr::get($shortCodeAttribute, 'price_source', 'default_variant');
    if (!in_array($priceSource, ['default_variant', 'best_discount'], true)) {
        $priceSource = 'default_variant';
    }

    $showPercentage = !empty($shortCodeAttribute['show_percentage']);
    $badgeText = sanitize_text_field(Arr::get($shortCodeAttribute, 'badge_text', __('Sale!', 'fluent-cart')));
    $percentageText = sanitize_text_field(Arr::get($shortCodeAttribute, 'percentage_text', '-{percent}%'));

    // 2. Get product — explicit product_id takes priority, then current context
    $productId = absint(Arr::get($shortCodeAttribute, 'product_id', 0));
    if ($productId) {
        $product = Product::query()->with(['detail', 'variants'])->find($productId);
    } else {
        $product = fluent_cart_get_current_product();
    }

    if (!$product || !$product->variants || $product->variants->isEmpty()) {
        return '';
    }

    // 3. Determine sale status based on price_source setting
    $isOnSale = false;
    $discountPercent = 0;

    if ($priceSource === 'default_variant') {
        $defaultVariantId = $product->detail?->default_variation_id ?? null;
        $variant = $defaultVariantId
            ? ($product->variants->firstWhere('id', $defaultVariantId) ?? $product->variants->first())
            : $product->variants->first();

        if ($variant && $variant->compare_price > $variant->item_price && $variant->compare_price > 0) {
            $isOnSale = true;
            $discountPercent = round((($variant->compare_price - $variant->item_price) / $variant->compare_price) * 100);
        }
    } else {
        // best_discount — scan all variants, use highest discount
        foreach ($product->variants as $variant) {
            if ($variant->compare_price > $variant->item_price && $variant->compare_price > 0) {
                $isOnSale = true;
                $discount = round((($variant->compare_price - $variant->item_price) / $variant->compare_price) * 100);
                if ($discount > $discountPercent) {
                    $discountPercent = $discount;
                }
            }
        }
    }

    // 4. If not on sale, render nothing
    if (!$isOnSale) {
        return '';
    }

    // 5. Build badge text
    if ($showPercentage && $discountPercent > 0) {
        $displayText = str_replace('{percent}', $discountPercent, $percentageText);
    } else {
        $displayText = $badgeText;
    }

    // 6. Build CSS classes (values are pre-validated via in_array above)
    $classes = ['fct-sale-badge'];
    $classes[] = 'fct-sale-badge--' . esc_attr($badgeStyle);
    $classes[] = 'fct-sale-badge--' . esc_attr($badgePosition);

    $wrapper_attributes = get_block_wrapper_attributes([
        'class' => implode(' ', $classes),
    ]);

    return sprintf(
        '<span %s>%s</span>',
        $wrapper_attributes,
        esc_html($displayText)
    );
}
```

### Key design decisions:
- **`product_id`-first retrieval** — explicit product_id takes priority over context, allowing theme template users to override which product is shown even inside a product context
- **Null-safe operator** — `$product->detail?->default_variation_id` guards against null detail
- Iterates ALL variants to find the best discount (not just first variant)
- Returns `''` when not on sale — badge disappears
- Uses `<span>` not `<div>` — inline element that works in any context
- `get_block_wrapper_attributes()` handles all block support styles automatically
- Position/style classes applied via CSS classes, not inline styles
- Enqueues styles via `Vite::enqueueStyle()` for frontend rendering

---

## 7. JSX Editor Component

### Editor behavior
- Detects parent context using two separate `useSelect` hooks:
  - `isInsideProductInfo` — any product context block (for product picker visibility)
  - `isInsideVisualContainer` — image/card containers (for position/style controls)
- When inside a product container: uses `useSingleProductData()` for product data
- When standalone: shows product picker in inspector
- **Always shows the badge in the editor** (even when product not selected) so user can style it — shows placeholder text "Sale!" or sample "-20%"
- When product IS selected and is on sale: shows actual computed text
- When product IS selected but NOT on sale: shows badge with dimmed opacity + "Not on sale" note

### `computeSaleInfo` helper
A standalone function (outside the component) that computes `{ isOnSale, discountPercent }` from product data and `price_source` setting. Used with `useMemo` for performance.

### No attribute sync useEffect
Unlike older blocks (Excerpt, ProductCard), Sale Badge does NOT sync `query_type` / `inside_product_info` attributes. These are computed live via `useSelect` and are not persisted as block attributes. The PHP render uses `product_id`-first retrieval which doesn't need them.

### Inspector controls — conditional visibility

```jsx
// Detect if inside a visual container (for showing position/style controls)
const isInsideVisualContainer = useSelect((select) => {
    const { getBlockParents, getBlockName } = select(blockEditorStore);
    const parents = getBlockParents(clientId);
    return parents.some((parentId) => {
        const name = getBlockName(parentId);
        return [
            'fluent-cart/product-image',
            'fluent-cart/shopapp-product-image',
        ].includes(name);
    });
}, [clientId]);

// Detect if inside any product context (for product picker visibility)
const isInsideProductInfo = useSelect((select) => {
    const { getBlockParents, getBlockName } = select(blockEditorStore);
    const parents = getBlockParents(clientId);
    return parents.some((parentId) => {
        const name = getBlockName(parentId);
        return [
            'fluent-cart/product-info',
            'fluent-cart/products',
            'fluent-cart/shopapp-product-container',
            'fluent-cart/shopapp-product-loop',
            'fluent-cart/product-carousel',
            'fluent-cart/product-image',
        ].includes(name);
    });
}, [clientId]);
```

### Inspector panel layout

**Always visible — Badge Settings panel:**
- Badge Text (TextControl) — custom text when not using percentage
- Show Discount Percentage (ToggleControl)
- Percentage Text Template (TextControl) — only when show_percentage is true, e.g., "-{percent}%"
- Price Source (SelectControl) — "Default Variant" or "Best Discount (All Variants)"
- Sale status indicator — green "On Sale — X% discount" or red "Product is not on sale"

**Only when `isInsideVisualContainer` is true — Position & Style panel:**
- Badge Style (SelectControl) — Badge / Ribbon / Tag
- Badge Position (SelectControl) — Top Left / Top Right / Bottom Left / Bottom Right

**Only when `isInsideProductInfo` is false (standalone) — Product panel:**
- Product Picker (SelectProductModal)
- Selected product chip display

**Block supports (automatic via WP — no custom code needed):**
- Colors (text + background) — in Styles tab
- Typography (font size, weight, letter spacing, text transform) — in Styles tab
- Spacing (padding, margin) — in Styles tab
- Border (radius, width, color, style) — in Styles tab
- Shadow — in Styles tab

---

## 8. SCSS Styles

Uses `@apply` for Tailwind classes per project conventions:

```scss
@config "./sale-badge-block-editor.config.js";
@tailwind utilities;

.fct-sale-badge {
    @apply inline-block font-semibold leading-none whitespace-nowrap z-[2] pointer-events-none;

    // === Badge style (default) ===
    &--badge {
        @apply px-2.5 py-1 rounded text-[13px];
    }

    // === Ribbon style ===
    &--ribbon {
        @apply py-1 pl-2.5 pr-5 text-xs rounded-none;
        clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 50%, calc(100% - 10px) 100%, 0 100%);

        &.fct-sale-badge--top-right,
        &.fct-sale-badge--bottom-right {
            @apply pr-2.5 pl-5;
            clip-path: polygon(10px 0, 100% 0, 100% 100%, 10px 100%, 0 50%);
        }
    }

    // === Tag style ===
    &--tag {
        @apply px-2.5 py-1 rounded-sm text-[13px] relative;

        &::before {
            @apply absolute top-1/2 -left-1.5 -translate-y-1/2 w-0 h-0;
            content: '';
            border-top: 8px solid transparent;
            border-bottom: 8px solid transparent;
            border-right: 6px solid currentColor;
        }

        &.fct-sale-badge--top-right::before,
        &.fct-sale-badge--bottom-right::before {
            @apply left-auto -right-1.5;
            border-right: none;
            border-left: 6px solid currentColor;
        }
    }

    // === Position classes ===
    &--top-left     { @apply absolute top-2 left-2; }
    &--top-right    { @apply absolute top-2 right-2; }
    &--bottom-left  { @apply absolute bottom-2 left-2; }
    &--bottom-right { @apply absolute bottom-2 right-2; }
}

// Default colors when user hasn't set custom colors via block supports
.fct-sale-badge:not([class*="has-background"]) {
    @apply bg-red-600 text-white;
}

// Inspector panel sale status indicator
.fct-sale-badge-status {
    @apply py-1.5 px-3 rounded text-xs;
    &--on-sale     { @apply bg-green-50 text-green-800; }
    &--not-on-sale { @apply bg-red-50 text-red-800; }
}
```

### Note on positioning
The `position: absolute` on position classes works because:
- `product-image` block wraps inner blocks in a `position: relative` container with a `position: absolute; inset: 0` overlay div
- When the badge is NOT inside such a container, `position: absolute` has no effect unless there's a positioned ancestor — in practice it just renders inline normally

---

## 9. Files Created

| # | File | Purpose |
|---|---|---|
| 1 | `app/Hooks/Handlers/BlockEditors/SaleBadgeBlockEditor.php` | PHP class — registration, supports, render callback |
| 2 | `resources/admin/BlockEditor/SaleBadge/SaleBadgeBlockEditor.jsx` | Block registration, edit component, context detection, `computeSaleInfo` helper |
| 3 | `resources/admin/BlockEditor/SaleBadge/Components/InspectorSettings.jsx` | Inspector panel — badge text, style, position, product picker, sale status indicator |
| 4 | `resources/admin/BlockEditor/SaleBadge/style/sale-badge-block-editor.scss` | Badge styles, positions, visual presets, inspector status indicator |
| 5 | `resources/admin/BlockEditor/SaleBadge/style/sale-badge-block-editor.config.js` | Tailwind config for this block |

## 10. Files Modified

| # | File | Change |
|---|---|---|
| 1 | `app/Hooks/actions.php` | Add `SaleBadgeBlockEditor::register();` |
| 2 | `vite.config.mjs` | Add JSX + SCSS entry points |
| 3 | `resources/admin/BlockEditor/Icons.jsx` | Add `Tag` icon (tag SVG from Hugeicons) |
| 4 | `resources/admin/BlockEditor/ProductImage/ProductImageBlockEditor.jsx` | Move `pointer-events-none` from InnerBlocks overlay div to `<img>` element; `sale-badge` already in `allowedBlocks` |

### NOT modified (and why)
- **ProductImageBlockEditor.php** — Already renders ALL inner blocks generically; no allowedBlocks filter
- **ShopApp InnerBlocks.php** — The shopapp-product-image block already accepts any inner block (no allowedBlocks in JSX)
- **ProductCardBlockEditor** — Does NOT support InnerBlocks (uses shortcode). Sale badge for product cards would require a separate enhancement to the shortcode render — out of scope for this block
- **ProductInfoBlockEditor.jsx** — Not modified (sale badge can be inserted into any InnerBlocks container without being in `allowedBlocks`)

---

## 11. Registration

### actions.php entry
```php
\FluentCart\App\Hooks\Handlers\BlockEditors\SaleBadgeBlockEditor::register();
```

### vite.config.mjs entries
```js
    // Sale Badge
    'resources/admin/BlockEditor/SaleBadge/SaleBadgeBlockEditor.jsx',
    'resources/admin/BlockEditor/SaleBadge/style/sale-badge-block-editor.scss',
```

---

## 12. Product Retrieval Pattern

### Why `product_id`-first (not context-first)

The PHP `render()` checks `product_id` attribute **before** `fluent_cart_get_current_product()`:

```php
$productId = absint(Arr::get($shortCodeAttribute, 'product_id', 0));
if ($productId) {
    $product = Product::query()->with(['detail', 'variants'])->find($productId);
} else {
    $product = fluent_cart_get_current_product();
}
```

**Why this order matters:** If a user building a theme template places the Sale Badge block inside a product context but wants to show a *different* product's sale status, their explicit `product_id` choice must be honored. Context-first would silently ignore their selection.

**How the editor handles this naturally:**
- Inside a product context (Product Image, Carousel, etc.) → editor hides the product picker → `product_id` stays empty → `fluent_cart_get_current_product()` is used
- Standalone (theme template, custom page) → editor shows product picker → user picks a product → `product_id` is set → that product is used

This eliminates the need for `query_type` / `inside_product_info` attributes entirely.

---

## 13. Edge Cases

| Scenario | Behavior |
|---|---|
| Product not on sale | Badge returns `''` — nothing rendered |
| No product selected (standalone, no ID) | Badge returns `''` |
| Product with multiple variants, some on sale | Badge shows — uses highest discount % if showing percentage |
| `compare_price == item_price` | Not on sale (consistent with codebase: `>` not `>=`) |
| `compare_price == 0` | Not on sale (compare_price stored as 0 when <= item_price) |
| Variable product, all variants different discounts | Shows badge — percentage uses best (highest) discount |
| Badge inside product-image | Positioned absolutely via CSS classes |
| Badge standalone (no positioned parent) | `position: absolute` ignored, renders inline |
| Ribbon style on right side | `clip-path` mirrored automatically via CSS |
| User removes custom colors via block supports | Falls back to red/white default (`:not([class*="has-background"])` selector) |
| `$product->detail` is null | Null-safe operator `?->` prevents crash, falls back to first variant |
| User picks explicit product inside a product context | `product_id` takes priority over context product |

---

## 14. Security Checklist

- [x] `badge_style` validated with `in_array()` whitelist: `['badge', 'ribbon', 'tag']`
- [x] `badge_position` validated with `in_array()` whitelist: `['top-left', 'top-right', 'bottom-left', 'bottom-right']`
- [x] `price_source` validated with `in_array()` whitelist: `['default_variant', 'best_discount']`
- [x] `product_id` validated with `absint()`
- [x] `badge_text` sanitized with `sanitize_text_field()`
- [x] `percentage_text` sanitized with `sanitize_text_field()`
- [x] Output escaped with `esc_html()` for badge display text
- [x] CSS class values escaped with `esc_attr()` despite pre-validation
- [x] `get_block_wrapper_attributes()` handles wrapper escaping
- [x] JSX uses optional chaining on product/variant data (`response?.product`, `product?.detail?.default_variation_id`)
- [x] No `dangerouslySetInnerHTML` needed (all text content)
- [x] `setAttributes()` sanitizes product ID: `String(product.ID)`
- [x] Null-safe operator on `$product->detail?->default_variation_id`

---

## 15. Testing Results

Visually tested in Gutenberg editor (page 2249):

1. **Inside Product Image** — Inspector shows Badge Settings + Position & Style panels, product picker hidden. Badge renders in overlay.
2. **Standalone** — Inspector shows Badge Settings + Product panel, Position & Style hidden. Product picker works.
3. **Block selection** — After `pointer-events-none` fix, Sale Badge is selectable inside Product Image overlay.
4. **Cleanup** — Test blocks removed, undo stack cleared.

Screenshots saved to:
- `.playwright-mcp/sale-badge-inside-product-image-inspector.png`
- `.playwright-mcp/sale-badge-standalone-inspector.png`
