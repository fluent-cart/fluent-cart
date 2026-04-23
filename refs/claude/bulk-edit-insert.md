# Bulk Edit & Bulk Insert — Architecture Summary

## Overview

Two spreadsheet-style pages for managing products in bulk:
- **Bulk Edit** (`/products/bulk-edit`) — Load existing products, edit inline, save changes
- **Bulk Insert** (`/products/bulk-insert`) — Create new products via CSV import or manual entry

Both share a ResizeableColumns table component, virtual scrolling, collapse/expand for variable products, and the same column layout.

---

## File Map

### Frontend

| File | Purpose |
|------|---------|
| `resources/admin/Modules/Products/BulkEdit/BulkEdit.vue` | Bulk Edit page (Vue SFC) |
| `resources/admin/Modules/Products/BulkInsert/BulkInsert.vue` | Bulk Insert page (Vue SFC) |
| `resources/admin/Modules/Products/BulkInsert/ResizeableColumns.vue` | Shared resizable table component (used by both) |
| `resources/admin/Modules/Products/BulkInsert/Importer.vue` | CSV import dialog (Insert only) |
| `resources/admin/Models/BulkEditModel.js` | Edit state management — extends `ProductBaseModel` |
| `resources/admin/Models/BulkInsetModel.js` | Insert state management — extends `ProductBaseModel` (note: filename typo "Inset") |
| `resources/admin/Bits/Components/Attachment/BulkMediaPicker.vue` | Thumbnail media picker for gallery/variant images |
| `resources/admin/Bits/Components/Inputs/WpEditor.vue` | WordPress TinyMCE editor wrapper (used in Description/Short Description modals) |

### Backend

| File | Purpose |
|------|---------|
| `app/Http/Controllers/ProductController.php` | Controller methods: `bulkEditFetch`, `bulkUpdate`, `bulkInsert` |
| `app/Services/BulkProductUpdateService.php` | Edit service — fetch formatted products, update chunks |
| `app/Services/BulkProductInsertService.php` | Insert service — create products with variants, categories, media |

### Routes

All under `fluent-cart/v2/products/` prefix:

| Method | Endpoint | Controller | Permission |
|--------|----------|-----------|------------|
| `GET` | `/bulk-edit-data` | `bulkEditFetch` | `products/edit` |
| `POST` | `/bulk-update` | `bulkUpdate` | `products/edit` |
| `POST` | `/bulk-insert` | `bulkInsert` | `products/create` |

### Vue Routes

| Name | Path | Component | Permission |
|------|------|-----------|------------|
| `product_bulk_insert` | `/products/bulk-insert` | `BulkInsert.vue` | `products/create` |
| `product_bulk_edit` | `/products/bulk-edit` | `BulkEdit.vue` | `products/edit` |

---

## Spreadsheet Columns (both pages)

| # | Column | Edit | Insert | Applies To |
|---|--------|------|--------|-----------|
| 1 | Title | text input | text input | Product row + Variant rows (variation_title) |
| 2 | Image / Media | BulkMediaPicker | BulkMediaPicker (+ URL tab) | Product gallery + Variant media |
| 3 | SKU | text input | text input | Simple products (variant[0]) + Variant rows |
| 4 | Categories | el-select (multi, filterable, allow-create) | same | Product row only |
| 5 | Description | WpEditor modal (click to open) | same | Product row only |
| 6 | Short Description | WpEditor modal (click to open) | same | Product row only |
| 7 | Status | el-select (publish/draft) | el-select (from options) | Product row only |
| 8 | Product Type | readonly label | el-select (physical/digital) | Product row only |
| 9 | Pricing Type | readonly label | el-select (simple/variable) | Product row only |
| 10 | Payment Type | el-select (onetime/subscription) | same | Simple variant[0] + Variant rows |
| 11 | Interval | el-select (yearly...daily) | same | When payment_type=subscription |
| 12 | Trial Days | number input | same | When payment_type=subscription |
| 13 | Best Price | number input | same | Simple variant[0] + Variant rows |
| 14 | Compare-at Price | number input | same | Simple variant[0] + Variant rows |
| 15 | Track Quantity | el-switch | el-switch | Product row only (detail.manage_stock) |
| 16 | Stock | number input | number input | When manage_stock=1 |
| 17 | Actions | — (inline in Title) | Delete button | Product row only |

**Key differences (Insert vs Edit):**
- Insert: Product Type & Pricing Type are **editable** el-selects (can change fulfillment_type and variation_type)
- Edit: Product Type & Pricing Type are **readonly** labels
- Insert: Has delete button per row + "Add Variant" button inside variant blocks
- Edit: Has inline save button (green checkmark icon) **inside the Title column** — appears when row is dirty, always visible in sticky column without horizontal scrolling
- Insert: Media picker has `show-url-tab` prop for external URLs
- Insert: Prices stored on `detail.item_price` / `detail.compare_price`
- Edit: Prices stored on `variants[0].item_price` / `variants[0].compare_price` (decimal, converted from cents)

---

## Key Features

### Virtual Scrolling (both pages)
- `ROW_HEIGHT = 43px`, `containerHeight = 800px`, `BUFFER = 10 rows`
- Groups computed per product (product row + expanded variant rows)
- Spacer `<tr>` elements above/below visible range
- Scroll handler throttled at 100ms

### Infinite Scroll (Edit only)
- `INFINITE_SCROLL_THRESHOLD = 200px` from bottom
- Triggers `loadProducts(true)` (append mode)
- Checks `loading` and `pagination.hasMore` to prevent double-fetch
- Loading indicator at bottom when fetching next page

### Collapse/Expand (both pages)
- Variable products show a chevron button with variant count when collapsed
- Uses `Animation` component with `accordion` prop for smooth transition
- `collapsedProducts` ref (Set) tracks collapsed product IDs

### Dirty Tracking & Save State (Edit only)
- `dirty` Set in BulkEditModel tracks modified product IDs
- `savingIds` Set tracks products currently being saved (per-chunk, not all at once)
- `savedIds` Set tracks products that have been successfully saved in this session
- `markDirty(product)` called on every input/change
- Dirty rows get orange background (`#FFF9F3`)
- **3-state status icons** in the Title column (always visible in sticky column):
  1. **Syncing** (`savingIds`): Spinning `<Loading>` icon, gray — row inputs disabled
  2. **Saved** (`savedIds`): Green circle-check SVG icon — row inputs disabled (non-editable, won't be saved again)
  3. **Dirty** (unsaved): Green checkmark inline save button (`.bulk-inline-save`)
- `isRowDisabled(product)` = `isSaving(product) || isSaved(product)` — disabled while saving or after saved
- During bulk save, already-saved products (`savedIds`) are skipped (not re-sent to server)
- `savedIds` cleared on fresh fetch (new filter/search), persist during session
- Global "Save Changes (N)" button in header

### Advanced Filter (Edit only)
- Reuses `AdvancedFilter` component from product list page
- Toggle via `el-switch` (advanced/simple)
- `BulkEditModel` implements adapter interface: `isUsingAdvanceFilter()`, `getAdvanceFilterOptions()`, `applyAdvancedFilter()`, etc.
- Filter options from `window.fluentCartAdminApp.filter_options.product_filter_options.advance`
- Also has `FilterTabs` for quick status/type filtering

### Search (Edit only)
- Search bar with `el-input` (clearable, enter to search)
- Hint: "Search by Id, product title or variation title"
- `BulkEditModel` search methods: `openSearch()`, `closeSearch()`, `search()`

### CSV Import (Insert only)
- `Importer.vue` component handles CSV parsing
- Can concat or replace existing products
- Fires `on-data-populated` event with parsed products

### WpEditor Modal (both pages)
- Description and Short Description fields open WordPress TinyMCE editor in a modal dialog
- Uses `el-dialog` with `WpEditor` component
- `v-if="editorModal.visible"` ensures TinyMCE reinitializes each time
- WpEditor uses `@update` event (not v-model)
- Cancel/Save buttons in footer

### BulkMediaPicker (both pages)
- Compact thumbnail that opens WordPress media library
- `v-model` binds to gallery array or variant media array
- Insert page has `show-url-tab` prop for external image URLs

### Resizable Columns (both pages)
- `ResizeableColumns.vue` — fixed `table-layout` with draggable column resizers
- First column is sticky (left: 0, z-index: 70)
- Resizer hit area spans 800px height, visible indicator on header hover
- Dark mode support via unscoped styles

---

## Data Flow

### Bulk Edit
```
User loads page
  → BulkEditModel.fetchProducts() → GET /products/bulk-edit-data
  → BulkProductUpdateService.fetchForBulkEdit()
  → ProductFilter::fromRequest() → paginate()
  → formatProductForEdit() → prices / 100, attach categories
  → Response: { products, total, per_page, page }

User edits cell → markDirty(product)

User clicks Save
  → BulkEditModel.saveProducts()
  → Filter out already-saved products (savedIds)
  → Split remaining dirty products into chunks of 10
  → For each chunk:
    → Add chunk IDs to savingIds (spinning icon, inputs disabled)
    → POST /products/bulk-update { products: [...] }
    → On success: move IDs to savedIds (green check, stay disabled)
    → Remove chunk IDs from savingIds
  → BulkProductUpdateService.updateChunk()
  → updateSingleProduct() per product:
    - ProductResource::update() for variants/detail (handles price * 100)
    - wp_update_post() for title/content/excerpt/status
    - ProductDetail update for manage_stock
    - Gallery via update_post_meta()
    - Variant media via ProductVariationResource::setImage()
    - Categories via syncCategories() (supports path strings, term IDs, objects)
```

### Bulk Insert
```
User imports CSV or clicks "Add Product"
  → Products added to local array (BulkInsetModel.data.products)

User clicks "Save All Products"
  → BulkInsetModel.saveProducts() → chunks of 10
  → POST /products/bulk-insert { products: [...] }
  → BulkProductInsertService.insertChunk()
  → insertSingleProduct() per product:
    - wp_insert_post() for WP post
    - ProductDetail::create() for detail record
    - createVariant() per variant (or createDefaultVariant() for simple)
    - assignCategories() with hierarchy support ("Parent > Child")
    - Gallery via update_post_meta()
    - Variant media via ProductVariationResource::setImage()
```

---

## Saving Mechanics

### Chunked Saves (both)
- Products split into chunks of max 10
- Each chunk sent as single POST request
- Database transaction per chunk (START TRANSACTION / COMMIT / ROLLBACK)
- Progress bar shown during save (`el-progress`)
- Progress text: "Saving X/Y..."

### Per-Chunk Visual Feedback (Edit only)
During bulk save (`saveProducts()`), each chunk is processed sequentially with visual feedback:
1. **Pre-filter**: Already-saved products (`savedIds`) are excluded before chunking
2. **Before chunk request**: Current chunk's IDs added to `savingIds` → spinning icon, inputs disabled
3. **After chunk success**: IDs moved from `savingIds` to `savedIds`, removed from `dirty` → green check icon, inputs stay disabled
4. **After chunk error**: IDs removed from `savingIds`, stay in `dirty` → back to dirty state
5. **Session persistence**: `savedIds` persists until a fresh fetch (new filter/search/tab change)

### Per-Row Save (Edit only)
- `saveProduct(product)` sends single product in array
- Uses same `savingIds` → `savedIds` flow as bulk save
- Green checkmark button (`.bulk-inline-save`) inside Title column — shows loading spinner while saving
- On success: moves to `savedIds`, row becomes non-editable with green circle-check icon

---

## CSS Architecture

### Input Styling (unified across both pages)

```css
.bulk-cell-input {
  @apply w-full px-3 text-sm border border-solid border-transparent bg-transparent outline-none;
  min-height: 38px;  /* auto-height: allows rows to expand for multi-select tags etc. */
  box-sizing: border-box;
  color: var(--fct-text-system-dark, #101828);
}
.bulk-editor-cell {
  min-height: 38px;  /* auto-height for clickable description/excerpt cells */
}
/* hover: border-color divider */
/* focus: border-color primary, bg white — no box-shadow, no border-radius */
```

### el-select Styling (both pages — scoped)
Both pages have scoped `:deep()` styles to flatten el-select to match cell inputs:
- `border-radius: 0`, transparent bg, `box-shadow: none`
- `.el-select__wrapper`: `min-height: 38px` (auto-height for multi-select tags)
- Hover: divider border, Focus: primary border + white bg

### Row Styling
- Product rows: 42px height, `vertical-align: middle`, bottom border, hover bg
- Variant rows: same + gray background
- Dirty rows (Edit): orange background `#FFF9F3`
- Saving rows (Edit): inputs `opacity-60`, `pointer-events: none`
- Saved rows (Edit): same disabled styling as saving rows
- Dark mode: unscoped styles for all row/cell variants

### Status Icons (Edit only)
```css
.bulk-status-icon {
  @apply flex items-center justify-center shrink-0;
  width: 20px; height: 20px; margin-right: 4px;
}
.bulk-status-icon.is-syncing { color: #667085; }  /* gray spinning icon */
.bulk-status-icon.is-saved { color: #12B76A; }    /* green circle-check icon */
```

### Sticky Column
- First column (Title) is sticky left with z-index
- `::after` pseudo-element creates gradient shadow on right edge
- Both `th` and `td` get sticky treatment

---

## BulkEditModel API (FilterTabs/AdvancedFilter adapter)

The model implements these methods to be compatible with the shared filter components:

```js
// Tab methods
getTabs()                    // { all, publish, draft, physical, digital }
getTabsCount()               // 5
getSelectedTab()             // current selectedView
handleTabChanged(viewKey)    // switch tab, refetch

// Search methods
isSearching()                // data.searching !== false
openSearch() / closeSearch() // toggle search bar
search()                     // trigger fetch
getSearchHint()              // placeholder text

// Advanced filter methods
isUsingAdvanceFilter()       // filterType === 'advanced'
isAdvanceFilterEnabled()     // checks window config
getAdvanceFilterOptions()    // from fluentCartAdminApp
onFilterTypeChanged(type)    // switch filter mode
applyAdvancedFilter()        // fetch with filters
addAdvanceFilterGroup()      // push empty group
removeAdvanceFilterGroup(i)  // splice group
clearAdvanceFilter()         // reset to [[]]

// Stub methods (required by FilterTabs)
getToggleableColumns()       // []
getSortableColumns()         // []
```

---

## Price Handling

- **Database**: Prices stored as **BIGINT in cents** (e.g., 1999 = $19.99)
- **Edit page**: Backend divides by 100 before sending → frontend shows decimal → backend multiplies by 100 on save (via `ProductResource::update`)
- **Insert page**: Frontend sends raw values → `BulkProductInsertService::sanitizePrice()` converts with `absint()`
- **Edit variant prices**: `variants[0].item_price` / `variants[0].compare_price` (decimal)
- **Insert variant prices**: `detail.item_price` / `detail.compare_price` (raw)

---

## Category Handling

Both pages use the same pattern:
1. On mount, fetch category tree: `GET products/fetch-term`
2. Flatten tree into path strings: `"Clothing > T-Shirts"`
3. `el-select` with `multiple`, `filterable`, `allow-create`
4. Backend resolves path strings to term IDs, creating missing terms
5. `wp_set_post_terms()` to assign

Categories stored on product as `categories: ["Clothing > T-Shirts", "Sale"]` (array of path strings).

---

## Unique Client IDs Plan (`_cid`)

### Problem

Bulk Insert tracks products by **array index** (`savingIndices`, `savedIndices` are `Set<index>`). This is fragile:
- Adding/removing/duplicating products shifts indices, breaking state tracking
- Backend errors reference chunk-local index (0-9), not the original product
- Cannot show per-product error messages after save failures
- No way to retry specific failed products reliably

### Solution

Assign a stable `_cid` (client ID) to every product and variation using a **simple monotonic counter** (`_cid_1`, `_cid_2`, ...). Zero dependencies, deterministic, session-scoped.

**New file:** `resources/admin/utils/cid.js`
```js
let counter = 0;
export function generateCid() {
  return '_cid_' + (++counter);
}
```

### Assignment Points

| Location | What gets `_cid` |
|----------|-----------------|
| `ProductBaseModel.js` `getDummyVariation()` | Every new variation |
| `BulkInsetModel.js` `populateDummyProduct()` | Every new product |
| `BulkInsert.vue` `duplicateProduct()` | Clone product + each cloned variant |
| `BulkInsert.vue` `duplicateVariant()` | Cloned variant |
| `Importer.vue` `populateWooCommerceData()` | Each imported product + variant |
| `Importer.vue` `populateData()` | Each imported product + variant |

### State Tracking Changes

| Before | After |
|--------|-------|
| `savingIndices` (Set\<index\>) | `savingIds` (Set\<_cid\>) |
| `savedIndices` (Set\<index\>) | `savedIds` (Set\<_cid\>) |
| — | `productErrors` (Map\<_cid, string\>) |

### Backend Changes

`BulkProductInsertService::insertChunk()` — extract `_cid` from each product, include in response:
- `created` changes from `[int]` to `[{_cid: string, id: int}]`
- `errors` adds `_cid` field alongside existing `index`/`title`/`message`

### Error UI

- Error products get red background (`#FEF3F2`) + red warning icon with tooltip
- Products with errors remain editable (not disabled like saved ones)
- Errors cleared at start of next save attempt
