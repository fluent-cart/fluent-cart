# CLAUDE.md

FluentCart is a WordPress eCommerce plugin with a custom database schema (NOT WooCommerce compatible). Built on the WPFluent Framework (Laravel-inspired ORM, routing, DI). Tech stack: PHP 7.4+, Vue 3 (admin), React 18 (Gutenberg blocks), Element Plus, Vite 6, Tailwind CSS.

## Build Commands

```bash
npm run dev              # Vite dev server with HMR (port 8880)
npm run build            # Production build
npm run build:zip        # Build + zip for distribution
npm run translate:all    # Extract translation strings
composer install         # PHP dependencies (includes WPFluent Framework)
```

## Architecture

- **Boot:** `fluent-cart.php` → `boot/app.php` → Application → `fluentcart_loaded` hook
- **Namespaces:** `FluentCart\App\` (app/), `FluentCart\Api\` (api/), `FluentCart\Dev\` (dev/)
- **REST API:** Namespace `fluent-cart/v2`, routes in `app/Http/Routes/` (api.php, frontend_routes.php, reports.php)
- **Database:** Custom `fct_*` tables (37 migrations). Product uses WordPress CPT `fluent-products` (posts table).
- **Controllers:** 55 total (37 main + 4 frontend + 11 reports + module controllers)
- **Policies:** 14 authorization policies with route meta permissions
- **Models:** 38 Eloquent-style models (Order, Product, Customer, Subscription, Cart, etc.)
- **Modules:** 16 in `app/Modules/` (PaymentMethods, Subscriptions, Shipping, Tax, StockManagement, Coupon, etc.)
- **Events/Listeners:** 25 events, 20 listeners for domain-driven side effects
- **Payment Gateways:** 7+ (Stripe, PayPal, Square, Airwallex, Paystack, COD, Razorpay)
- **Permissions:** 32 granular capabilities, 4 roles (super_admin, manager, worker, accountant)
- **Frontend:** Vue 3 admin SPA (50+ routes), 79 public JS files, 13+ Gutenberg blocks, 163+ Vite entry points
- **Scheduled Tasks:** Action Scheduler with 3 intervals (5min, hourly, daily)

### Deep Knowledge

See `.claude/skills/` for detailed references:
- `architecture.md` — Models, routes, modules, payment gateways, events, permissions
- `coding-patterns.md` — Controller, Resource API, Policy, Model, Event/Listener, Vue patterns
- `workflow-orders.md` — Order statuses, creation flow, transactions, refunds
- `workflow-products.md` — Product CPT, variations, attributes, stock management, bundles
- `workflow-subscriptions.md` — Subscription lifecycle, billing intervals, renewals
- `workflow-checkout.md` — Cart, checkout types, coupons, tax, shipping
- `workflow-frontend.md` — Admin SPA, REST client, public storefront, Gutenberg blocks, Vite
- `workflow-pdf-email.md` — PDF generation, email attachments, templates, E-Invoice/ZUGFeRD

### Existing Specialized Skills
- `subscription-implementation-fluent-cart/` — Implementing subscription support in payment gateways
- `pr-description/` — PR description generator

## Coding Rules

1. **Service-first architecture** — Business logic in `app/Services/` and `api/Resource/`, not controllers or models
2. **Resource API layer** — Controllers delegate CRUD to `api/Resource/` classes (static methods)
3. **Route meta permissions** — Policies use `hasRoutePermissions()` reading from `->meta(['permissions' => '...'])`
4. **GMT timestamps** — All dates stored in UTC via `DateTime::gmtNow()`
5. **Amounts in cents** — All prices stored as BIGINT. Use `Helper::toCent()` / `Helper::toDecimal()`
6. **Event-driven side effects** — Dispatch domain events (not direct mutations) for order/subscription lifecycle
7. **Vue 3 Composition API** — `<script setup>`, `translate()` for i18n, `<UserCan>` for permission gating
8. **Sanitize all input** — Use Request classes with `rules()` and `sanitize()` methods
9. **Hook prefix:** `fluentcart_` or `fluent_cart/`
10. **Text domain:** `fluent-cart`

## Global Helpers

```php
fluentCart($module)                               // App container
fluentCartUtil()                                  // Utility helper
fluent_cart_get_option($key, $default, $cache)     // Get option from fct_meta
fluent_cart_update_option($key, $value)            // Update option
fluent_cart_add_log($title, $content, $status, $info)  // Activity logging
```

## Git Workflow

- Main: `master` | Development: `development`
- Feature: `feat/name` | Fix: `fix/name` | Release: `release/x.y.z`

## Available Agents

See `AGENTS.md` for detailed agent instructions and manual QA flow.

When the situation matches a trigger below, automatically spawn the appropriate agent:

| Trigger | Command file |
|---------|-------------|
| Design/style admin UI components (Vue/SCSS in resources/admin/ or resources/styles/) | `.claude/commands/admin-design/apply.md` |
| Create/scaffold a new Gutenberg block | `.claude/commands/gutenberg-blocks/scaffold.md` |
| Test a block visually | `.claude/commands/gutenberg-blocks/test.md` |
| Review block changes | `.claude/commands/gutenberg-blocks/review.md` |
| Build assets or create ZIP | `.claude/commands/gutenberg-blocks/build.md` |
| Compare blocks | `.claude/commands/gutenberg-blocks/compare.md` |
| Write developer documentation (REST API, hooks, database models) | `.claude/commands/dev-docs/write.md` |
| Audit documentation for completeness | `.claude/commands/dev-docs/audit.md` |
