# FluentCart Gutenberg Block Patterns & Architecture

> **Last Updated:** 2026-02-26
> **Purpose:** Single reference for building/reviewing blocks without re-reading source files.
> **Source of truth:** If code disagrees with this file, code wins.

---

## How FluentCart Blocks Differ From Standard WP

1. **No `block.json`** — All blocks registered via PHP `register_block_type()` with args array
2. **WP globals not imports** — `wp.element`, `wp.blockEditor`, `wp.blocks`, `wp.components` (NOT `@wordpress/*`). Exception: `apiFetch` and `addQueryArgs` use `@wordpress/` imports
3. **Localized data pipeline** — Block metadata from PHP `localizeData()` → `window.fluent_cart_{snake_case}_data`
4. **Custom i18n** — `blocktranslate()` backed by `window.fluent_cart_block_translation`, not `@wordpress/i18n`
5. **~90% dynamic blocks** — `save: () => null`, PHP render callback
6. **Vite** — Not wp-scripts/webpack. Uses `Vite::enqueueScript()` etc.
7. **InnerBlocks registered in a loop** — PHP array → JSX `componentsMap` → `forEach(registerBlockType)`
8. **`fct-` CSS prefix** — All custom classes: `fct-product-card`, `fct-inspector-control-wrap`

---

## Base Class: `BlockEditor`

**File:** `app/Hooks/Handlers/BlockEditors/BlockEditor.php`

```
BlockEditor (abstract)
├── uses CanEnqueue trait
├── const PARENT_BLOCK_DATA_NAME = 'fluent_cart_parent_block_data'
├── static $editorName (string) — block slug suffix
├── static $isReactSupportAdded (bool) — BUG: set to false instead of true
│
├── register()          — static entry: add_action('init', [static::make(), 'init'])
├── make()              — factory: new static()
├── init()              — hooks enqueue_block_editor_assets + enqueue_block_assets, calls register_block_type()
├── render()            — ABSTRACT: must implement
├── render_block()      — WP callback wrapper → calls render()
├── supports()          — default: {renaming:false, innerBlocks:true, align:true}
├── provideContext()    — default: null. Override to return ['fluent-cart/key' => 'attr_name']
├── useContext()        — default: null. Override to return ['fluent-cart/key', ...]
├── skipInnerBlocks()   — default: false. Override to true for manual inner rendering
├── getStyles()         — default: []. Override to return SCSS paths
├── getScripts()        — default: []. Override to return script definitions (from CanEnqueue)
├── localizeData()      — default: []. Override to return wp_localize_script data (from CanEnqueue)
├── getLocalizationKey()— auto: fluent_cart_{snake_case}_data (from CanEnqueue)
└── generateEnqueueSlug()— slugPrefix + '_' + editorName → snake_case
```

### Registration Flow (in `init()`)

```php
register_block_type($this->slugPrefix . '/' . static::getEditorName(), [
    'api_version'      => 3,
    'editor_script'    => $this->getScriptName(),
    'editor_css'       => $this->getStyleName(),
    'render_callback'  => [$this, 'render_block'],
    'provides_context' => $this->provideContext(),
    'uses_context'     => $this->useContext(),
    'supports'         => $this->supports(),
    'skip_inner_blocks'=> $this->skipInnerBlocks() ? true : (not set),
]);
```

---

## Canonical Block Template (PHP)

```php
class {Name}BlockEditor extends BlockEditor
{
    protected static string $editorName = '{kebab-case}';

    public function getScripts(): array
    {
        return [[
            'source'       => 'admin/BlockEditor/{Name}/{Name}BlockEditor.jsx',
            'dependencies' => ['wp-blocks', 'wp-components']
        ]];
    }

    protected function getStyles(): array
    {
        return ['admin/BlockEditor/{Name}/style/{kebab-case}-block-editor.scss'];
    }

    protected function localizeData(): array
    {
        return [
            $this->getLocalizationKey() => [
                'slug'  => $this->slugPrefix,
                'name'  => static::getEditorName(),
                'title' => __('Block Title', 'fluent-cart'),
                'description' => __('Block description.', 'fluent-cart'),
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg'),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null): string
    {
        $productId = absint(Arr::get($shortCodeAttribute, 'product_id', 0));
        $product = $productId
            ? Product::query()->with(['detail', 'variants'])->find($productId)
            : fluent_cart_get_current_product();

        if (!$product) return '';

        $wrapper_attributes = get_block_wrapper_attributes(['class' => 'fct-{kebab-case}']);

        ob_start();
        // render output...
        return ob_get_clean();
    }
}
```

---

## Canonical Block Template (JSX)

```jsx
import {IconName} from "@/BlockEditor/Icons";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import InspectorSettings from "@/BlockEditor/{Name}/Components/InspectorSettings";
import ErrorBoundary from "@/BlockEditor/Components/ErrorBoundary";

const { useBlockProps } = wp.blockEditor;
const { registerBlockType } = wp.blocks;
const { useEffect, useState } = wp.element;

const blockEditorData = window.fluent_cart_{snake_case}_data;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: { src: IconName },
    category: "fluent-cart",        // or "fluent-cart-buttons" for buttons
    attributes: {
        product_id:  { type: ['string', 'number'], default: '' },
        query_type:  { type: 'string', default: 'default' },
        // ...block-specific attributes
    },
    edit: ({attributes, setAttributes}) => {
        const blockProps = useBlockProps();
        return (
            <div {...blockProps}>
                <ErrorBoundary>
                    <InspectorSettings attributes={attributes} setAttributes={setAttributes} />
                    {/* Block preview */}
                </ErrorBoundary>
            </div>
        );
    },
    save: () => null,   // Dynamic block — PHP renders frontend
});
```

---

## Canonical Inspector Settings

```jsx
const { InspectorControls } = wp.blockEditor;
const { RangeControl } = wp.components;
import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import CustomSelect from "@/BlockEditor/Components/CustomSelect";

const InspectorSettings = ({ attributes, setAttributes }) => {
    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--{block-name}">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        <EditorPanel title={blocktranslate('Settings')}>
                            <EditorPanelRow>
                                <span className="fct-inspector-control-label">{blocktranslate('Label')}</span>
                                <div className="actions">
                                    <CustomSelect
                                        options={[...]}
                                        defaultValue={attributes.some_attr}
                                        onChange={(value) => setAttributes({some_attr: value})}
                                    />
                                </div>
                            </EditorPanelRow>
                        </EditorPanel>
                    </div>
                </div>
            </div>
        </InspectorControls>
    );
};
```

---

## InnerBlocks Registration Pattern

### PHP (InnerBlocks class)

```php
class InnerBlocks
{
    use CanEnqueue;
    public static $parentBlock = 'fluent-cart/{parent-slug}';

    public static function register()
    {
        $self = new self();
        foreach ($self->getInnerBlocks() as $block) {
            register_block_type($block['slug'], [
                'apiVersion'      => 3,
                'title'           => $block['title'],
                'parent'          => array_merge($block['parent'] ?? [], [static::$parentBlock]),
                'render_callback' => $block['callback'],
                'supports'        => Arr::get($block, 'supports', []),
                'uses_context'    => Arr::get($block, 'uses_context', []),
            ]);
        }
        add_action('enqueue_block_editor_assets', fn() => $self->enqueueScripts());
    }

    public function getInnerBlocks(): array
    {
        return [
            [
                'title'       => __('Child Block', 'fluent-cart'),
                'slug'        => 'fluent-cart/{parent}-{child}',
                'callback'    => [$this, 'renderChildBlock'],
                'component'   => 'ChildBlockComponent',   // maps to JSX componentsMap key
                'icon'        => 'dashicon-name',
                'supports'    => static::textBlockSupport(),
                'parent'      => ['fluent-cart/{parent}-loop'],
                'uses_context'=> ['fluent-cart/some_key'],
            ],
        ];
    }

    // Reusable support presets
    public static function textBlockSupport(): array {
        return ['typography' => [...], 'spacing' => [...], 'color' => ['text' => true]];
    }
    public static function buttonBlockSupport(): array {
        return ['typography' => [...], 'spacing' => [...], 'color' => [...], 'border' => [...], 'shadow' => true];
    }
}
```

### JSX (InnerBlocks.jsx)

```jsx
import ChildBlock from './ChildBlock.jsx';
const blockEditorData = window['fluent_cart_{parent}_inner_blocks'];
const componentsMap = { ChildBlockComponent: ChildBlock };

blockEditorData.blocks.forEach(block => {
    const Component = componentsMap[block.component];
    registerBlockType(block.slug, {
        apiVersion: 3,
        category: "fluent-cart",
        title: block.title,
        icon: block.icon || null,
        parent: [...(block.parent || []), ...(Component?.parent || [])],
        edit: Component?.edit || (() => blocktranslate("No edit found")),
        save: Component?.save || (() => null),
        supports: Component?.supports || {},
        usesContext: Component?.usesContext || [],
        attributes: Component?.attributes || {},
    });
});
```

### Inner Block Component Shape

```jsx
const ChildBlock = {
    parent: ['fluent-cart/extra-parent'],   // optional, merged with PHP parent
    usesContext: ['fluent-cart/some_key'],
    attributes: { attr_name: { type: 'string', default: 'value' } },
    supports: { typography: {...} },
    edit: (props) => {
        const blockProps = useBlockProps();
        return <div {...blockProps}>...</div>;
    },
    save: () => null,
};
export default ChildBlock;
```

---

## Three Render Patterns

### A. Direct HTML (most common)

```php
public function render(array $attr, $block = null): string
{
    $productId = absint(Arr::get($attr, 'product_id', 0));
    $product = $productId ? Product::find($productId) : fluent_cart_get_current_product();
    if (!$product) return '';

    $wrapper = get_block_wrapper_attributes(['class' => 'fct-{name}']);
    ob_start(); ?>
    <div <?php echo $wrapper; ?>>
        <h2><?php echo esc_html($product->post_title); ?></h2>
    </div>
    <?php return ob_get_clean();
}
```

### B. Shortcode Delegation

```php
public function render(array $attr, $block = null): string
{
    $productId = absint(Arr::get($attr, 'product_id', 0));
    return "[fc_shortcode_name product_id='" . $productId . "']";
}
```

### C. Renderer Class Delegation

```php
public function render(array $attr, $block = null): string
{
    $renderer = new SomeRenderer($data);
    ob_start();
    $renderer->renderBlock($attr);
    return ob_get_clean();
}
```

---

## Product Retrieval Pattern (Leaf Blocks)

```php
// Priority: explicit product_id → fallback to current product in loop
$productId = absint(Arr::get($attr, 'product_id', 0));
$product = $productId
    ? Product::query()->with(['detail', 'variants'])->find($productId)
    : fluent_cart_get_current_product();

if (!$product) return '';
```

**Container blocks** (ShopApp, Carousel, RelatedProduct) call `setup_postdata($product->ID)` inside their loop and `wp_reset_postdata()` after — this makes `fluent_cart_get_current_product()` work for child blocks.

---

## Shared Components

**Directory:** `resources/admin/BlockEditor/Components/`

| Component | Import | Props | Use When |
|---|---|---|---|
| `ErrorBoundary` | `@/BlockEditor/Components/ErrorBoundary` | `{ children }` | Wrap every edit component's return |
| `EditorPanel` | `@/BlockEditor/Components/EditorPanel` | `{ title, children }` | Group inspector settings |
| `EditorPanelRow` | `@/BlockEditor/Components/EditorPanelRow` | `{ children, className }` | Each setting row in inspector |
| `CustomSelect` | `@/BlockEditor/Components/CustomSelect` | `{ options, defaultValue, onChange, isMulti, customKeys }` | Dropdown with search |
| `ServerSidePreview` | `@/BlockEditor/Components/ServerSidePreview` | `{ block, attributes }` | Iframe preview of PHP render |
| `ColorPickerField` | `@/BlockEditor/Components/ColorPickerField` | `{ label, value, onChange }` | Color picker with clear button |
| `Input` | `@/BlockEditor/Components/Input` | `{ placeholder, name, type, icon, onInput }` | Text input with optional icon |
| `SearchableSelect` | `@/BlockEditor/Components/SearchableSelect` | `{ value, onChange }` | Product search via REST API |
| `BlockEditorControl` | `@/BlockEditor/Components/BlockEditorControl` | `{ title, children, initialOpen }` | Alternative to EditorPanel |
| `SelectProductModal` | `@/BlockEditor/Components/ProductPicker/SelectProductModal` | (modal) | Product selection modal |
| `SelectVariationModal` | `@/BlockEditor/Components/ProductPicker/SelectVariationModal` | (modal) | Variation selection modal |

---

## Icons

**File:** `resources/admin/BlockEditor/Icons.jsx`

Available exports:
`Cart`, `Cross`, `SearchBar`, `Search`, `ColorPickerIcon`, `Empty`, `CaretRight`, `Delete`, `Tag`, `Product`, `ProductSearch`, `CustomerDashboard`, `Checkout`, `ProductCard`, `ProductGallery`, `ProductInfo`, `BuySection`, `PricingTableIcon`, `Excerpt`, `PriceRange`, `Title`, `ArrowLeft`, `ShoppingCart`, `ExpandUpDown`, `Edit`, `ShoppingBag`, `CategoriesList`, `ShoppingBagAlt`, `StoreLogo`, `ButtonIcon`, `UserIcon`, `MediaCarousel`, `ProductImage`, `RelatedProduct`, `Sku`, `Layout`

---

## Context Flow

### ShopApp provides → child blocks consume

```
ShopAppBlockEditor provides:
├── fluent-cart/paginator
├── fluent-cart/per_page
├── fluent-cart/enable_filter
├── fluent-cart/product_box_grid_size
├── fluent-cart/view_mode
├── fluent-cart/filters
├── fluent-cart/default_filters
├── fluent-cart/order_type
├── fluent-cart/order_by
├── fluent-cart/live_filter
├── fluent-cart/price_format
└── fluent-cart/enable_wildcard_filter

Consumers:
├── product-container, product-loop, no-result, loader, spinner → paginator, per_page, enable_filter, grid_size, view_mode
├── product-price → price_format
├── product-filter, view-switcher → view_mode, enable_filter
├── filter-sort-by → live_filter
└── paginator-info → paginator, per_page
```

### ProductCarousel provides

```
ProductCarouselBlockEditor provides:
├── fluent-cart/carousel_settings
├── fluent-cart/product_ids
├── fluent-cart/has_controls
└── fluent-cart/has_pagination
```

### MediaCarousel provides

```
MediaCarouselBlockEditor provides:
├── fluent-cart/query_type
├── fluent-cart/carousel_settings
├── fluent-cart/product_id
├── fluent-cart/variation_ids
├── fluent-cart/has_controls
└── fluent-cart/has_pagination
```

### RelatedProduct provides

```
RelatedProductBlockEditor provides:
├── fluent-cart/related_product_ids
├── fluent-cart/related_products
├── fluent-cart/product_id
├── fluent-cart/order_by
├── fluent-cart/query_type
├── fluent-cart/related_by_categories
├── fluent-cart/related_by_brands
└── fluent-cart/columns
```

---

## Tailwind Config Template

**File:** `resources/admin/BlockEditor/{Name}/style/{kebab-case}-block-editor.config.js`

```js
import colors from '../../../../styles/tailwind/extends/color'
import spacing from "../../../../styles/tailwind/extends/spacing";
import fontSize from "../../../../styles/tailwind/extends/fontSize";
import borderRadius from "../../../../styles/tailwind/extends/borderRadius";

module.exports = {
    darkMode: 'class',
    content: ['./resources/admin/BlockEditor/{Name}/**/*.*'],
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

---

## SCSS Template

**File:** `resources/admin/BlockEditor/{Name}/style/{kebab-case}-block-editor.scss`

```scss
@config "./{kebab-case}-block-editor.config.js";
@tailwind utilities;

.fct-{kebab-case} {
  @apply relative ...;
}
```

---

## Vite Entries

Each block needs 1–3 entries in `vite.config.mjs` `inputs` array:

```js
'resources/admin/BlockEditor/{Name}/{Name}BlockEditor.jsx',           // always
'resources/admin/BlockEditor/{Name}/style/{kebab-case}-block-editor.scss',  // if has styles
'resources/admin/BlockEditor/{Name}/InnerBlocks/InnerBlocks.jsx',     // if has InnerBlocks
```

---

## Supports Presets

### Text block (titles, excerpts, labels)

```php
[
    'html' => false,
    'typography' => [
        'fontSize' => true, 'lineHeight' => true,
        '__experimentalFontFamily' => true, '__experimentalFontWeight' => true,
        '__experimentalFontStyle' => true, '__experimentalTextTransform' => true,
        '__experimentalTextDecoration' => true, '__experimentalLetterSpacing' => true,
        '__experimentalDefaultControls' => ['fontSize' => true, 'lineHeight' => true],
    ],
    'color' => ['text' => true, 'background' => true, 'link' => true, 'gradients' => true],
    'spacing' => ['margin' => true, 'padding' => true],
    '__experimentalBorder' => ['color' => true, 'radius' => true, 'style' => true, 'width' => true],
    'shadow' => true,
]
```

### Button block (buy, add-to-cart, submit)

Same as text plus:
```php
'color' => ['text' => true, 'background' => true, 'link' => true, 'gradients' => true],
```

---

## Security Checklist (Quick Reference)

### PHP Render

| Input Type | Sanitization |
|---|---|
| IDs | `absint()` |
| Enums (yes/no, grid/list) | `in_array($val, [...allowed], true)` with default fallback |
| Numbers | `max(min, min(max, absint(...)))` |
| Free text | `sanitize_text_field()` |
| Booleans | Treat as enum: `in_array($val, ['yes', 'no'], true)` |

### PHP Output

| Context | Escaping |
|---|---|
| Block wrapper | `get_block_wrapper_attributes()` (self-escaping) |
| Text in HTML body | `esc_html()` |
| Text in HTML attribute | `esc_attr()` |
| URLs | `esc_url()` |
| Shortcode/renderer output | Don't double-escape (they handle own escaping) |
| Trusted WP content | `wpautop()` or `apply_filters('the_content', ...)` — NOT `esc_html()` |

### JSX

| Concern | Pattern |
|---|---|
| API data access | Optional chaining: `response?.product?.title` |
| HTML rendering | Avoid `dangerouslySetInnerHTML`; if needed, comment the trusted source |
| Attribute storage | Sanitize in `setAttributes()` (e.g., IDs → string of digits) |
| Null guards | Always guard before rendering: `{value && <span>{value}</span>}` |
