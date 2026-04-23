# FluentCart Admin Design System

A comprehensive guide to the design tokens, patterns, and components used in the FluentCart admin panel (Vue 3 SPA).

**Stack:** Tailwind CSS 3.4 + Element Plus + Custom SCSS  
**Font:** Inter (Google Fonts)  
**Scoping:** `#fct_admin_app_wrapper` (Tailwind `important` selector)  
**Dark Mode:** Class-based (`darkMode: 'class'`), Tailwind `dark:` prefix  
**Preflight:** Disabled (WordPress admin compatibility)

---

## Design Philosophy

**Functional, not decorative.** Built for store managers processing orders, not visitors browsing a storefront.

### Principles

1. **Content-dense** — Show more data in less space. Compact 72px table rows, tight 20px card padding, small badges. No excessive whitespace.
2. **Quiet chrome, loud signals** — The UI shell is neutral grays. Color speaks only when it means something: green = paid, red = failed, yellow = pending. If everything is colorful, nothing stands out.
3. **One way to do it** — One card component, one badge, one table style. No variants for the sake of variety. Consistency reduces cognitive load.
4. **Dark mode is not optional** — Every surface, every border, every text color has a `dark:` counterpart. It's a requirement, not a feature.
5. **WordPress citizen** — No CSS resets that break WP admin. Preflight disabled. Specificity handled by the wrapper selector, not `!important`.
6. **Tokens, not values** — Never type a hex color or pixel value. Everything comes from the token files. If you need a color that doesn't exist, the answer is usually that you don't need it.
7. **Reuse, don't rebuild** — Check `Bits/Components/` before writing any UI. The component probably already exists.

### Color Intent

| Role | Colors | Motto |
|------|--------|-------|
| **Text** | `system-dark` → `system-light` | Four shades: heading, body, secondary, muted. That's enough. |
| **Surfaces** | `white` / `neutral-50` / `gray-25` | Cards are white. Backgrounds are light gray. Hover is slightly darker. |
| **Borders** | `gray-divider` / `gray-outline` | Dividers are soft (`#EAECF0`). Input borders are slightly stronger (`#D6DAE1`). |
| **Status** | `success` / `warning` / `red` / `blue` | Each has a full scale. Badges use `100` bg + `800` text. Never mix status colors for decoration. |
| **Navigation** | `primary-500` (`#253241`) | The dark navy. Used for the logo bg, primary buttons, and dark surfaces. It's the brand anchor. |
| **Accent** | `blue-500` (`#017EF3`) | Links and informational highlights only. Not for buttons or backgrounds. |
| **Data viz** | `report-*` palette | 16 distinct colors for charts. Never use these in UI chrome. |

### Typography Intent

**Inter** at `0.02em` letter-spacing everywhere. Six sizes (12–28px), four weights (400–700). The hierarchy is:
- **Semibold + xl** = page titles (one per page)
- **Medium + base** = card headers (section containers)
- **Medium + sm** = labels and table headers (form fields, columns)
- **Normal + sm** = body text (descriptions, cell content)
- **Normal + xs** = meta (timestamps, hints, footers)

If you need a font size between these, you don't — pick the nearest one.

---

## Table of Contents

1. [Color System](#1-color-system)
2. [Typography](#2-typography)
3. [Spacing](#3-spacing)
4. [Border Radius](#4-border-radius)
5. [Shadows](#5-shadows)
6. [Breakpoints](#6-breakpoints)
7. [Component Patterns](#7-component-patterns)
8. [Element Plus Overrides](#8-element-plus-overrides)
9. [Dark Mode](#9-dark-mode)
10. [File Structure](#10-file-structure)
11. [Usage Guidelines](#11-usage-guidelines)

---

## 1. Color System

**Source:** `resources/styles/tailwind/extends/color.js`

### CSS Custom Properties (Root)

```css
--color-primary:   #4F66F5
--color-success:   #22C55E
--color-neutral:   #666978
--color-secondary: #60646B
--color-warning:   #FAA619
--text-color:      var(--color-neutral)
--body-bg:         #F3F5FA
--body-bg-dark:    #11171D
```

### Semantic System Colors

Used for body text, labels, and UI chrome:

| Token | Value | Usage |
|-------|-------|-------|
| `system-dark` | `#2F3448` | Headings, primary text |
| `system-mid` | `#565865` | Body text, descriptions |
| `system-dark-light` | `#696778` | Secondary text |
| `system-light` | `#9D9FAC` | Muted/placeholder text |

### Neutral Scale

Page backgrounds, borders, and subtle surfaces:

| Token | Value | Usage |
|-------|-------|-------|
| `bg-neutral-lite` | `#F6F7FA` | Alternate row bg |
| `neutral-50` | `#F8F9FC` | Table header bg |
| `neutral-200` | `#DADDE7` | Subtle borders |
| `neutral-500` | `#E3E6EF` | Dividers |
| `neutral-1000` | `#868A98` | Disabled text |
| `neutral-1100` | `#666978` | Secondary text (= system color) |
| `neutral-1200` | `#1B1F35` | Near-black text |

### Gray Scale

Form controls, outlines, dividers:

| Token | Value | Usage |
|-------|-------|-------|
| `gray-25` | `#F9FAFB` | Hover bg, subtle surfaces |
| `gray-50` | `#F5F6F7` | Input disabled bg |
| `gray-outline` | `#D6DAE1` | Input/button borders |
| `gray-divider` | `#EAECF0` | Card/section dividers |
| `gray-neutral` | `#FAFAFA` | Neutral bg |

### Primary (Dark/Navigation)

Navigation, dark UI surfaces:

| Token | Value | Usage |
|-------|-------|-------|
| `primary-400` | `#2C3C4E` | Nav active bg |
| `primary-500` | `#253241` | Sidebar bg, primary buttons |
| `primary-600` | `#1C2732` | Sidebar hover |
| `primary-700` | `#151D26` | Dark mode card bg |
| `primary-800` | `#11171D` | Dark mode body bg |

### Status Colors

Each has a full 8-shade scale (25-800). Core values:

| Status | Base (500) | Light (100) | Dark (800) | Usage |
|--------|-----------|-------------|------------|-------|
| **Success** | `#189877` | `#D1EAE4` | `#116A53` | Completed, Paid, Active |
| **Warning** | `#F58E07` | `#FDE8CD` | `#AC6305` | Pending, Processing |
| **Red/Danger** | `#F04438` | `#F8D6CE` | `#9B2406` | Failed, Cancelled, Errors |
| **Blue/Info** | `#017EF3` | `#CCE5FD` | `#0158AA` | Links, Information |
| **Secondary** | `#6B3CEB` | `#E1D8FB` | `#4B2AA5` | Accent, Feature highlights |

### Badge Color Pattern

Badges use `100` shade for bg and `800` shade for text:

```
success: bg-success-100 + text-success-800
warning: bg-warning-100 + text-warning-800
danger:  bg-red-100     + text-red-800
info:    bg-gray-100    + text-system-dark
blue:    bg-blue-100    + text-blue-800
primary: bg-dark-100    + text-dark-800
```

### Report/Chart Palette

16 colors for data visualization:

| Token | Value |
|-------|-------|
| `report-royal_blue` | `#4D6EF5` |
| `report-medium_turquoise` | `#33D3BB` |
| `report-hot_pink` | `#FB4BA3` |
| `report-deep_sky_blue` | `#47C2FF` |
| `report-medium_slate_blue` | `#855EF8` |
| `report-golden_rod` | `#F6B51E` |
| `report-sea_green` | `#23A682` |
| `report-coral` | `#FF8447` |

---

## 2. Typography

**Source:** `resources/styles/tailwind/extends/fontSize.js`  
**Font Family:** `Inter` (weights 200-900)  
**Letter Spacing:** `0.02em` globally on `#wpbody-content *`

### Font Size Scale

| Token | Size | Tailwind Class |
|-------|------|----------------|
| `xs` | 12px | `text-xs` |
| `sm` | 14px | `text-sm` |
| `base` | 16px | `text-base` |
| `lg` | 18px | `text-lg` |
| `xl` | 20px | `text-xl` |
| `2sm` | 28px | `text-2sm` |

### Typography Hierarchy

| Element | Classes | Example |
|---------|---------|---------|
| Page title | `text-xl font-semibold text-system-dark` | "Orders", "Products" |
| Card header | `text-base font-medium text-system-dark` | Card section titles |
| Section title | `text-sm font-semibold text-system-dark` | Subsection headings |
| Form label | `text-sm font-medium text-system-dark` | Input labels |
| Body text | `text-sm text-system-mid` | Descriptions, content |
| Small/meta | `text-xs text-system-light` | Timestamps, hints |
| Table header | `text-sm font-medium` | Column headers (compact-table) |
| Table cell | `text-sm text-system-dark` | Cell content |

### Font Weights Used

| Weight | Class | Usage |
|--------|-------|-------|
| 400 | `font-normal` | Body text, descriptions |
| 500 | `font-medium` | Labels, card headers, buttons |
| 600 | `font-semibold` | Page titles, bold headings |
| 700 | `font-bold` | Emphasized headings |

---

## 3. Spacing

**Source:** `resources/styles/tailwind/extends/spacing.js`  
**Base unit:** 4px  
**Half steps:** 2px increments available

| Token | Value | Common Use |
|-------|-------|------------|
| `0.5` | 2px | Micro gaps |
| `1` | 4px | Icon padding |
| `1.5` | 6px | Tight padding |
| `2` | 8px | Small gaps, table cell padding |
| `2.5` | 10px | Standard gap |
| `3` | 12px | Input padding-x |
| `4` | 16px | Button padding-x, medium gaps |
| `5` | 20px | Card body/header padding |
| `6` | 24px | Section gaps |
| `7.5` | 30px | Card bottom margin |
| `8` | 32px | Large gaps |
| `10` | 40px | Page section spacing |
| `12` | 48px | Large component height |
| `180` | 180px | Fixed widths (sidebar) |

### Common Spacing Patterns

```
Card padding:      p-5 (20px)
Card header:       px-5 pt-5 (20px)
Card gap:          mb-7.5 (30px between cards)
Table cell:        px-2 py-3.5
Button padding:    py-[7px] px-4 (default)
Form gap:          gap-5 (20px)
Icon button:       p-0 (content-sized)
Section gap:       gap-2.5 to gap-5
```

---

## 4. Border Radius

**Source:** `resources/styles/tailwind/extends/borderRadius.js`

| Token | Value | Tailwind | Usage |
|-------|-------|----------|-------|
| `xs` | 4px | `rounded-xs` | Small buttons, tags |
| `sm` | 6px | `rounded-sm` | Badges, small cards |
| `DEFAULT` | 8px | `rounded` | Cards, modals, inputs |
| `lg` | 16px | `rounded-lg` | Large panels |
| `xl` | 18px | `rounded-xl` | Hero sections |
| circle | 9999px | `rounded-[9999px]` | Avatars, circular buttons |
| `full` | 9999px | `rounded-full` | Pill badges |

---

## 5. Shadows

No centralized shadow tokens — shadows are defined inline per component:

| Pattern | Value | Usage |
|---------|-------|-------|
| Subtle | `0px 1px 2px 0px rgba(10, 13, 20, 0.03)` | Cards at rest |
| Standard | `0px 4px 16px -2px rgba(27, 37, 51, 0.06)` | Save bar, floating panels |
| Layered | `0 6px 10px 0 rgba(14,18,27,0.06), 0 2px 4px 0 rgba(14,18,27,0.03)` | Tabs, elevated surfaces |
| Menu | `0 0 20px rgba(28, 39, 50, 0.08)` | Dropdowns, menus |
| Focus ring | `0 0 0 2px rgba(209, 234, 228, 1)` | Active states |
| None | `shadow-none` | Default cards, inputs |

---

## 6. Breakpoints

**Standard Tailwind + custom additions:**

| Token | Width | Usage |
|-------|-------|-------|
| `sm` | 640px | Mobile landscape |
| `md` | 768px | Tablet |
| `lg` | 1024px | Desktop |
| `xl` | 1280px | Wide desktop |
| `1xl` | 1360px | Custom: medium-wide |
| `2xl` | 1536px | Custom: large |
| `3xl` | 1920px | Custom: full HD |

### Responsive SCSS Breakpoints (max-width)

Used in `responsive.scss` (1064 lines):
- 1024px, 1023px, 960px, 782px, 768px, 640px, 600px, 480px, 425px
- Utility classes: `.show-on-mobile`, `.hide-on-mobile`

---

## 7. Component Patterns

### Cards

**SCSS:** `resources/styles/tailwind/card.scss` | **Class:** `.fct-card`  
**Vue:** `Bits/Components/Card/` | **Import:** `import * as Card from "@/Bits/Components/Card/Card.js"`

| Sub-component | Props |
|---------------|-------|
| `Card.Container` | `border` (Boolean) |
| `Card.Header` | `title`, `text`, `border_bottom`, `icon`, `title_size`, `img` — Slots: `title`, `action` |
| `Card.Body` | — |
| `Card.Footer` | — |
| `ContentCard` | `title`, `show_heading` |

```vue
<Card.Container class="overflow-hidden">
  <Card.Header :title="$t('Products')" title_size="small" border_bottom>
    <template #action><el-button>Add</el-button></template>
  </Card.Header>
  <Card.Body>content</Card.Body>
</Card.Container>
```

```html
<!-- Raw HTML equivalent -->
<div class="fct-card fct-card-border">
  <div class="fct-card-header border-bottom">
    <h3 class="fct-card-header-title">Title</h3>
  </div>
  <div class="fct-card-body">
    <!-- content -->
  </div>
  <div class="fct-card-footer">
    <!-- actions -->
  </div>
</div>
```

| Part | Classes |
|------|---------|
| Container | `rounded bg-white mb-7.5 dark:bg-dark-700 border-none` |
| With border | `+ border border-solid border-gray-divider dark:border-dark-500` |
| Header | `flex items-center justify-between px-5 pt-5 gap-2` |
| Header with divider | `+ pb-5 border-b border-gray-divider` |
| Body | `p-5` |
| Footer | `px-6 py-4 border-t border-gray-divider` |
| Success variant | `border-success-100` + header `bg-success-25` |

### Badges

**SCSS:** `resources/styles/tailwind/badge.scss` | **Class:** `.badge`  
**Vue:** `Bits/Components/Badge.vue` | **Import:** `import Badge from "@/Bits/Components/Badge.vue"`  
**Props:** `status`, `text`, `hideIcon`, `size`, `highContrast`, `type`, `icon`  
**Status values:** `completed`, `paid`, `active`, `publish`, `shipped`, `success`, `failed`, `error`, `canceled`, `pending`, `unpaid`, `processing`, `scheduled`, `on-hold`, `inactive`, `draft`, `dispute`, `licensed`, `succeeded`, `expired`, `partially_paid`, `warning`, `future`, `disabled`, `beta`

```vue
<Badge :status="order.status"/>
<Badge :status="order.payment_status" size="small"/>
<Badge status="completed" :highContrast="true"/>
```

```html
<!-- Raw HTML equivalent -->
<span class="badge success">Completed</span>
<span class="badge warning">Pending</span>
<span class="badge danger">Failed</span>
<span class="badge info">Draft</span>
<span class="badge blue">Published</span>
```

| Base | `inline-flex items-center gap-1 capitalize text-xs font-medium py-1 px-2 rounded-[6px]` |
|------|---|
| Variants | `success`, `warning`, `danger`, `primary`, `blue`, `info` |
| High contrast | `is-high-contrast` (uses 200/800 shades instead of 100/800) |
| Variant badge | `.fct-variant-badge` — pill style, `text-[11px] rounded-full bg-blue-100` |

### Icon Buttons

**File:** `resources/styles/tailwind/icon-button.scss`  
**Class:** `.icon-button`

| Size | Class | Dimensions | Icon Size |
|------|-------|------------|-----------|
| Tiny | `.tiny` | 24x24px | 12x12px |
| X-Small | `.x-small` | 22x22px | 13x13px |
| Small | `.small` | 28x28px | 15x15px |
| Medium (default) | — | 36x36px | 16x16px |
| Large | `.large` | 48x48px | 22x22px |

Base: `bg-white border border-gray-outline text-system-mid rounded`  
Circle: add `.is-circle` for `rounded-[9999px]`  
Color variants: `primary`, `blue`, `danger`, `success`, `warning`, `secondary`, `info`, `transparent`, `white`, `dark`  
Modifiers: `[soft]`, `[outline]`

### Buttons (Element Plus Overrides)

**File:** `resources/styles/tailwind/element-plus/button.scss`

| Size | Height | Padding | Font |
|------|--------|---------|------|
| X-Small | 28px | `py-1.5 px-3` | 12px |
| Small | 32px | `py-1.5 px-3` | 12px |
| Default | 36px | `py-[7px] px-4` | 14px |
| Large | 48px | `py-[13px] px-6` | 16px |

Primary button: `bg-primary-500 text-white hover:bg-primary-600`  
Default: `bg-white border-gray-outline text-system-dark`  
All buttons: `focus:shadow-none` (removes EP default focus ring)

### Inputs (Element Plus Overrides)

**File:** `resources/styles/tailwind/element-plus/inputs.scss`

| Size | Height | Padding |
|------|--------|---------|
| X-Small | 28px | `py-[3px] px-2` |
| Small | 32px | `px-2.5` |
| Default | 36px | `py-[7px] px-3` |
| Large | 48px | `py-[13px] px-4 text-base` |

Border: `border-gray-outline rounded`  
Focus: `border-primary-500`  
Disabled: `bg-gray-50 border-gray-outline`  
Error: `border-red-500 shadow-none`  
Label: `text-sm font-medium text-system-dark`  
Required asterisk: `absolute top-0 -left-2 text-red-500`

### Tables (Element Plus Overrides)

**File:** `resources/styles/tailwind/element-plus/table.scss`  
**Two variants:** Standard (default `el-table`) and Compact (`.compact-table`). Currently all list pages use compact.

| Part | Style |
|------|-------|
| Table class | `.compact-table` on `el-table` (all list pages) |
| Header bg | `neutral-50` / dark: `gray-700` |
| Header text | `text-sm font-medium` (14px/500) |
| Header `.cell` padding | `p-2` (8px), first cell `pl-5` (20px), last cell `pr-5` (20px) |
| Header `th` height | 38px |
| Body `td` padding | `p-0` |
| Body `.cell` | `p-0 h-full` (height: 72px) |
| Body `.table-cell` | `min-h-[72px] flex items-center px-2`, first cell `pl-6` (24px), last cell `pr-6` (24px) |
| Body text | `text-sm text-system-dark` |
| Row hover | `bg-gray-25` / dark: `bg-dark-600` |
| Borders | `border-gray-divider` / dark: `border-dark-400 border-opacity-60` |
| Ultra-compact | `.full-compact` modifier: `min-h-[56px]` |

### Settings Sidebar Navigation

**SCSS:** `resources/styles/tailwind/setting.scss` | **Class:** `.fct-settings-nav-container`  
**Vue:** `Modules/Settings/SettingsView.vue`

A collapsible sidebar used on the Settings page. Contains parent items with icons and optional child sub-menus with a vertical connector line.

| Part | Classes |
|------|---------|
| Container | `bg-white w-[260px] border-r border-gray-divider` / dark: `bg-dark-700 border-dark-400` |
| Collapsed | `w-[72px]` — text hidden, icons centered |
| Expanded (hover) | `w-[260px]` — text re-shown on hover when collapsed |
| Nav list | `p-4 overflow-x-hidden` |
| Nav item | `mb-1.5` (6px gap) |
| Link | `min-h-[36px] py-2 px-2.5 gap-2.5 rounded text-sm font-medium text-system-mid` |
| Link hover | `bg-primary-25 text-primary-500` / dark: `bg-dark-600 text-gray-50` |
| Link active | `bg-primary-25 text-system-dark` / dark: `bg-dark-400 text-gray-50` |
| Icon | `w-4.5 h-4.5` (18×18px) |
| Chevron | `w-2.5 h-2.5` — visible only on active parent |
| Child wrap | `pl-6.5 my-1` with `::before` vertical line (`left-4.5 w-[2px] bg-gray-100`) |
| Child link | `text-sm font-medium text-system-mid` — no bg default |
| Child active | `::before` left bar `w-0.5 bg-primary-500` |
| Collapse button | `py-3 px-5 border-b border-gray-divider` with Window icon |

```vue
<!-- Usage in SettingsView.vue -->
<div class="fct-settings-nav-container" :class="{ 'is-collapsed': collapsed, 'is-expanded': expanded }">
  <ul class="fct-settings-nav">
    <li class="fct-settings-nav-item" :class="{ 'fct-settings-nav-item-active': isActive }">
      <router-link :to="route.url" class="fct-settings-nav-link">
        <DynamicIcon :name="route.icon"/>
        <span class="fct-settings-nav-link-text">
          {{ route.name }}
          <DynamicIcon name="ChevronRight" class="fct-settings-nav-link-icon"/>
        </span>
      </router-link>
      <!-- Child sub-menu -->
      <Animation :visible="isActive" accordion class="fct-settings-nav-child-wrap">
        <ul class="fct-settings-nav-child-list">
          <li class="fct-settings-nav-item" :class="{ 'fct-settings-nav-item-active': isChildActive }">
            <router-link :to="child.url" class="fct-settings-nav-link">{{ child.name }}</router-link>
          </li>
        </ul>
      </Animation>
    </li>
  </ul>
</div>
```

### Modals/Dialogs (Element Plus Overrides)

**File:** `resources/styles/tailwind/element-plus/modal.scss`

| Part | Style |
|------|-------|
| Overlay | `rgba(2, 13, 23, 0.6)` + `backdrop-filter: blur(2px)` |
| Container | `w-[560px] rounded` (8px radius) |
| Header | `py-4 px-5 border-b border-gray-divider` |
| Header title | `text-base font-semibold text-system-dark` |
| Body | `p-5` |
| Footer (slot) | `p-5 border-t border-gray-divider` |
| Footer (inline) | `.dialog-footer` inside body: `-mx-5 px-5 pt-4 mt-5 border-t border-gray-divider` |
| Close button | `w-7.5 h-7.5` (30×30px), relative positioned in header flex |
| Dark mode | `dark:bg-dark-600` container, `dark:border-dark-400` borders |

**Modal Types:**

| Type | Example | Footer Pattern |
|------|---------|---------------|
| Confirmation | Delete order, discard changes | Cancel + Destructive action |
| Form (standard) | Add New Product | Single submit button (right-aligned) |
| Form (material) | Create New Customer | Cancel + Submit buttons |

**Form Input Patterns in Modals:**

- **Standard labels** (`el-form label-position="top"`): Label above input, used in Add Product modal
- **Material inputs** (`MaterialInput.vue`): Floating placeholder labels inside bordered inputs, stacked without gaps (`.fct-compact-form`), used in Create Customer modal
- **Type selector cards** (`.fct-product-item-selector`): Radio-card style with icon + text + dot indicator

**Inline Footer Pattern (`.dialog-footer`):**
Most modals place the footer inside `el-dialog__body` using `.dialog-footer` class instead of the `#footer` template slot. This class uses negative margins (`-mx-5`) to extend the border separator full-width:

```scss
.dialog-footer {
  @apply text-right block border-t border-gray-divider pt-4 mt-5 -mx-5 px-5;
}
```

---

## 8. Element Plus Overrides

All Element Plus customization lives in `resources/styles/tailwind/element-plus/`:

| File | Components Covered |
|------|-------------------|
| `base.scss` | Imports ~75 EP components + CSS variable overrides |
| `button.scss` | Button sizing, colors, variants |
| `inputs.scss` | Input, Select, Checkbox, Radio, Switch, Textarea, ColorPicker |
| `table.scss` | Table header, cells, borders, responsive |
| `modal.scss` | Dialog, Drawer overlay and sizing |
| `badge.scss` | Badge content color |
| + others | Tabs, Pagination, Loading, Popover, Dropdown, etc. |

### Key EP CSS Variable Overrides

```scss
--table-header-bg:          theme('colors.neutral.50');
--table-cell-bg-lighter:    theme('colors.bg-neutral-lite');
--el-color-success:         theme('colors.success.500');
--el-color-primary:         theme('colors.primary.500');
--el-text-color-secondary:  theme('colors.neutral.1100');
--el-text-color-regular:    theme('colors.neutral.1200');
--el-color-warning:         theme('colors.orange.400');
```

---

## 9. Dark Mode

### Strategy

- Toggle class on wrapper: `<div id="fct_admin_app_wrapper" class="dark">`
- All components use Tailwind `dark:` variants
- Body background switches via CSS variable: `--body-bg-dark: #11171D`

### Dark Mode Color Mapping

| Light | Dark | Usage |
|-------|------|-------|
| `bg-white` | `dark:bg-dark-700` | Cards |
| `bg-[var(--body-bg)]` | `dark:bg-dark-800` | Page background |
| `text-system-dark` | `dark:text-gray-50` | Primary text |
| `text-system-mid` | `dark:text-gray-300` | Body text |
| `border-gray-divider` | `dark:border-dark-500` | Card borders |
| `border-gray-outline` | `dark:border-dark-400` | Input borders |
| `bg-gray-25` | `dark:bg-dark-600` | Hover states |
| Badge `bg-{color}-100` | `dark:bg-{color}-800` | Status badges |
| Badge `text-{color}-800` | `dark:text-white` | Badge text |

---

## 10. File Structure

```
resources/styles/tailwind/
├── style.css                    ← MAIN ENTRY (imports everything)
├── base.css                     ← CSS variables, font import, resets
├── reset.scss                   ← Element resets
├── responsive.scss              ← All media queries (1064 lines)
├── admin-tailwind.config.js     ← Tailwind theme configuration
│
├── extends/                     ← Design tokens
│   ├── color.js                 ← 196 lines, all color scales
│   ├── spacing.js               ← 0-18 scale (4px base)
│   ├── fontSize.js              ← 6 font sizes
│   └── borderRadius.js          ← 5 radius values
│
├── element-plus/                ← EP component overrides (22 files)
│   ├── base.scss                ← Imports + CSS vars
│   ├── button.scss              ← Button variants
│   ├── inputs.scss              ← Form controls
│   ├── table.scss               ← Table styling
│   ├── modal.scss               ← Dialog/Drawer
│   └── ...
│
└── [52 custom SCSS files]       ← Component-specific styles
    ├── card.scss
    ├── badge.scss
    ├── icon-button.scss
    ├── header.scss
    ├── sidebar.scss
    ├── save-bar.scss
    ├── form.scss
    ├── product.scss
    ├── order.scss
    ├── subscription.scss
    ├── dashboard-stat-widget.scss
    ├── activity.scss
    ├── fluid-tab.scss
    └── ...
```

### Vue Component Styling

- **95% use `<style scoped>`** — prevents style leakage
- **No external SCSS imports** in Vue files — all compiled via Vite/PostCSS
- Inline styles are rare — only for dynamic positioning

**`@apply` rules:**
- **DO** use `@apply` in global SCSS files (`resources/styles/tailwind/*.scss`) — these are processed by PostCSS with full Tailwind context
- **DO NOT** use `@apply` in Vue `<style scoped>` blocks — scoped styles are processed in isolation by Vite and cannot resolve Tailwind utility classes. Use Tailwind utility classes directly in the template instead.

```vue
<!-- ✅ CORRECT: Tailwind classes in template -->
<span class="inline-flex items-center text-system-light dark:text-gray-400">

<!-- ❌ WRONG: @apply in scoped style -->
<style scoped lang="scss">
.my-class {
  @apply inline-flex items-center text-system-light; /* Will fail */
}
</style>
```

If you need complex styling with pseudo-selectors or nesting that can't be expressed as utility classes, add a global SCSS file in `resources/styles/tailwind/` and import it in `style.css`.

---

## 11. Usage Guidelines

### Class Naming Convention

- **Custom components:** `fct-` prefix (e.g., `fct-card`, `fct-gallery-wrap`, `fct-alert`)
- **Status/state:** `is-` prefix (e.g., `is-required`, `is-circle`, `is-small`)
- **Element Plus:** Use default component names, style via SCSS overrides
- **Tailwind:** Utility-first for layout, spacing, and simple styling

### When to Use What

| Need | Use |
|------|-----|
| Layout, spacing, colors | Tailwind utility classes |
| Complex component with states | Custom `.fct-*` SCSS class |
| Form controls (input, select, etc.) | Element Plus components |
| Status display | `.badge` with variant class |
| Action buttons | Element Plus `<el-button>` |
| Icon-only actions | `.icon-button` with size class |
| Data tables | Element Plus `<el-table>` |

### Grid System

Dynamic grid using CSS custom properties:

```html
<div class="grid grid-cols-dynamic" style="--grid-columns: 3">
  <div class="col-span-dynamic" style="--col-span: 2">Wide</div>
  <div>Normal</div>
</div>
```

Responsive variants: `sm-dynamic`, `md-dynamic`, `lg-dynamic`

### Do's and Don'ts

**Do:**
- Use existing token values from `extends/` files
- Use `@apply` in global SCSS files (`resources/styles/tailwind/*.scss`)
- Use Tailwind utility classes directly in Vue templates
- Prefix custom classes with `fct-`
- Always include `dark:` variants
- Use Element Plus components for form controls and data display

**Don't:**
- Use `@apply` in Vue `<style scoped>` blocks — it can't resolve Tailwind classes
- Add arbitrary color values — use the color scales
- Create new spacing values outside the 4px grid
- Use `!important` (the `#fct_admin_app_wrapper` important selector handles specificity)
- Add inline styles except for dynamic values (widths, positions)
- Use preflight/reset styles (disabled for WP admin compatibility)

---

## 12. Vue Component Reference

All shared components live in `resources/admin/Bits/Components/`.

### Import Patterns

```vue
<!-- Named export (Card, FluidTab) -->
import * as Card from "@/Bits/Components/Card/Card.js";
import * as Fluid from "@/Bits/Components/FluidTab/FluidTab.js";

<!-- Direct import (most components) -->
import Badge from "@/Bits/Components/Badge.vue";
import Alert from "@/Bits/Components/Alert.vue";
import SaveBar from "@/Bits/Components/SaveBar.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import Pagination from "@/Bits/Components/Pagination.vue";
import NumberInput from "@/Bits/Components/Inputs/NumberInput.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import Animation from "@/Bits/Components/Animation.vue";
```

### Full Component Table

| Component | Path | Key Props | SCSS Class |
|-----------|------|-----------|------------|
| `Card.Container` | `Card/Card.vue` | `border` | `.fct-card` |
| `Card.Header` | `Card/CardHeader.vue` | `title, text, border_bottom, title_size, icon, img` | `.fct-card-header` |
| `Card.Body` | `Card/CardBody.vue` | — | `.fct-card-body` |
| `Card.Footer` | `Card/CardFooter.vue` | — | `.fct-card-footer` |
| `ContentCard` | `Card/ContentCard.vue` | `title, show_heading` | `.fct-content-card` |
| `Badge` | `Badge.vue` | `status, text, size, highContrast, type, icon` | `.badge` |
| `IconButton` | `Buttons/IconButton.vue` | `title, size, tag, circle, bg, hover, border, href, to` | `.icon-button` |
| `Alert` | `Alert.vue` | `type (info\|success\|warning\|error), content, closable` | `.fct-alert` |
| `SaveBar` | `SaveBar.vue` | `isActive, saveButtonText, discardButtonText, loading` | `.fct-save-bar` |
| `FluidTab` | `FluidTab/FluidTab.vue` | `activeIndex, hasIcon, small` | `.fct-fluid-tab` |
| `FluidTabItem` | `FluidTab/FluidTabItem.vue` | `label, requiredConfirmation, onClick` | `.fct-fluid-tab-item` |
| `NumberInput` | `Inputs/NumberInput.vue` | `min, max, item, isDisabled` | `.fct-number-input` |
| `MediaInput` | `Inputs/MediaInput.vue` | `modelValue, multiple, fullWidth, title` | — |
| `Gallery` | `Attachment/Gallery.vue` | `attachments, featured_image_id` | `.fct-gallery-wrap` |
| `CopyToClipboard` | `CopyToClipboard.vue` | `showMode, text, tooltipText, buttonText` | — |
| `LabelHint` | `LabelHint.vue` | `title, content, placement, required` | `.fct-label-hint` |
| `Pagination` | `Pagination.vue` | `pagination, pagerSizes, hide_on_single, layout` | — |
| `UserCan` | `Permission/UserCan.vue` | `permission (String\|Array), permissionType` | — |
| `Animation` | `Animation.vue` | `visible, duration, fade, accordion` | — |
| `StatCard` | `Stats/OrderStat/Components/StatCard.vue` | `stat, statRanges, currentRange, loading` | `.fct-dashboard-stat-widget` |
| `StepCard` | `StepCard/StepCard.vue` | `title, text, active, url` | — |
| `ProFeatureNotice` | `ProFeatureNotice.vue` | — | `.pro-feature` |
| `ConvertedTime` | `ConvertedTime.vue` | `date` | — |
| `Heading` | `Layout/Heading.vue` | — | `.page-heading` |
| `PageHeading` | `Layout/PageHeading.vue` | `title, breadcrumbs` | `.single-page-header` |
| `FormGroup` | `FormGroup.vue` | `label, required` | `.fct-form-group` |
| `Empty` | `Table/Empty.vue` | `title` | — |

### Usage Examples

```vue
<!-- Permission-gated card with badge -->
<UserCan permission="orders/manage">
  <Card.Container>
    <Card.Header :title="$t('Orders')" border_bottom>
      <template #action>
        <Badge :status="order.status"/>
      </template>
    </Card.Header>
    <Card.Body>...</Card.Body>
  </Card.Container>
</UserCan>

<!-- Save bar -->
<SaveBar :isActive="hasChanges ? 'is-active' : ''"
         @save="handleSave" @discard="handleDiscard"
         :loading="saving" :saveButtonText="$t('Update')"/>

<!-- Tabs -->
<FluidTab>
  <FluidTabItem v-for="tab in tabs" :key="tab.key"
                :class="tab.key === activeTab ? 'active' : ''"
                @click="activeTab = tab.key">
    {{ tab.label }}
  </FluidTabItem>
</FluidTab>

<!-- Form with label hint -->
<LabelHint :title="$t('Tax Rate')" :content="$t('Enter the tax percentage')" :required="true"/>
<el-input v-model="form.tax_rate"/>

<!-- Alert -->
<Alert type="warning" :content="$t('This action cannot be undone')"/>
```

---

## Quick Reference Card

```
Colors:     system-dark (#2F3448) | system-mid (#565865) | system-light (#9D9FAC)
Borders:    gray-outline (#D6DAE1) | gray-divider (#EAECF0)
Status:     success-500 (#189877) | warning-500 (#F58E07) | red-500 (#F04438)
Accent:     blue-500 (#017EF3) | secondary-500 (#6B3CEB)
Dark bg:    primary-500 (#253241) | dark-700 (#151D26) | dark-800 (#11171D)

Font:       Inter, 12/14/16/18/20/28px
Weights:    400 (normal) | 500 (medium) | 600 (semibold) | 700 (bold)
Spacing:    4px base, 0.5 steps (2px), max 72px (18), special 180px
Radius:     4 (xs) | 6 (sm) | 8 (default) | 16 (lg) | 18 (xl) | 9999 (circle)
Buttons:    28/32/36/48px heights
Inputs:     28/32/36/48px heights

Card:       rounded bg-white p-5 mb-7.5 | dark: bg-dark-700
Badge:      text-xs py-1 px-2 rounded-[6px] | bg-{color}-100 text-{color}-800
Table:      header: neutral-50 text-xs | body: py-3.5 text-sm
Modal:      w-[560px] rounded | overlay: blur(2px) rgba(2,13,23,0.6)
```
