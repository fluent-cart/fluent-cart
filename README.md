# FluentCart 🚀 - The New Era of WordPress eCommerce

**Faster, Lighter, Simpler, and 100% Open Source**

[![WordPress Plugin](https://img.shields.io/wordpress/plugin/v/fluent-cart?label=WP.org%20Version)](https://wordpress.org/plugins/fluent-cart/)
[![GPLv3 License](https://img.shields.io/badge/License-GPL%20v3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![Contributions Welcome](https://img.shields.io/badge/contributions-welcome-brightgreen.svg?style=flat)](https://github.com/fluent-cat/fluent-cart/issues)

Welcome to the official GitHub repository of **FluentCart** – the performance-first, fully open-source eCommerce solution built exclusively for modern WordPress.

We're reimagining what WordPress eCommerce can be: blazing-fast checkouts, zero bloat, no transaction fees, and a clean, scalable foundation that grows with your business.

Legacy solutions slow down as you scale. FluentCart was built from scratch with a custom database schema, efficient queries, and modern architecture so your store stays lightning-fast – even with thousands of orders.

And now? **The entire codebase is open source under GPL** – because the future of WordPress eCommerce belongs to the community ❤️

### ✨ Why FluentCart?

- **Performance First** – 3× faster order processing, separate shop tables, dynamic asset loading
- **Zero Bloat** – Everything you need in one lightweight plugin
- **Full-Featured Out of the Box** – Coupons, inventory, analytics, subscriptions, licenses, one-click checkout, headless-ready REST API
- **Developer-Friendly** – Tons of hooks, filters, webhooks, and full code access
- **Truly Open Source** – Audit, modify, extend, fork – it’s all yours
- **Built on the Fluent Ecosystem** – Trusted by 1.3M+ active installs across our plugins

### 🚀 Quick Start

#### Install from WordPress.org (recommended for production)
https://wordpress.org/plugins/fluent-cart/

#### Development Setup

```bash
# Clone the repository
git clone https://github.com/fluent-cart/fluent-cart.git
cd fluent-cart

# Install dependencies
npm i

# Start development server with hot-reload
npm run dev
```

#### Production Build

```bash
npm i
npm run build
```

#### Production Build with zip

```bash
npm i
npm run build:zip
```

#### To list transaltion strings for frontend use
```bash
npm run translate:all
```

### 🧪 Running Tests

```bash
npm run test:unit          # fast, no WordPress — pure PHP helpers and calculators
npm run test:integration   # models, services, and hooks against a real WP + DB
npm run test:functional    # REST API contract: routes, permissions, payloads
npm run test:php           # all three, in sequence
```

The PHP suites run through Codeception in `dev/wp-browser/`. The first run bootstraps
itself — it installs the Composer dependencies, creates `tests/.env` from `.env.example`,
and generates the Codeception actor classes. You don't need to set anything up by hand.

`test:unit` works immediately: it loads no WordPress and opens no database connection.

> **Integration and Functional need a database, and they destroy it.** Both install
> WordPress into `TEST_DB_NAME` and **drop its tables** on every run. The first time you
> invoke either, the runner creates `tests/.env` from the template and then **stops** —
> the file it just wrote contains placeholders, not your setup. Point `WORDPRESS_ROOT_DIR`
> and `TEST_DB_*` at a throwaway database, then run the command again.

Set `FLUENTCART_NO_TEST_INSTALL=1` to disable the bootstrap and fail with instructions
instead (useful for CI images that pre-bake dependencies).

#### Passing options and running a single test

npm swallows flags unless you separate them with `--`. Everything after `--` is handed
straight to the underlying command:

```bash
npm run test:unit -- --html                  # HTML report → dev/wp-browser/tests/_output/report.html
npm run test:integration -- --steps          # print each step as it runs
npm run test:functional -- --debug           # verbose: includes failed request/response bodies

# Run one file, one class, or one method
npm run test:unit -- tests/Unit/Helpers/MoneyHelperTest.php
npm run test:functional -- Rest/Orders/CreateOrderCest
npm run test:functional -- Rest/Orders/CreateOrderCest:adminCanCreateOrderWithValidItems
```

Without `--`, npm treats the flag as its own config and drops it — `npm run test:unit --html`
prints `Unknown cli config "--html"` and runs the suite with no report.

> `test:php` chains the three suites with `&&`, so it **takes no arguments** — anything you
> pass lands on the last suite as an npm flag and is silently ignored. Run the suites
> individually when you need options.

#### Browser tests

End-to-end tests drive a real browser against a running FluentCart site:

```bash
npm run test:browser              # Chromium (default)
npm run test:browser:firefox
npm run test:browser:safari       # Safari's engine (WebKit)
npm run test:browser:chrome

npm run test:browser -- admin/orders          # one suite
npm run test:browser -- admin/orders/search   # one test
npm run test:browser -- --safari admin/tax    # engine + filter together
```

Firefox and WebKit are downloaded automatically the first time you ask for them — the
run pauses, fetches the engine (~80MB), and carries on. Nothing to install up front.
Chromium comes from your existing Playwright cache or system Chrome and never downloads.

```bash
npm run test:browser:install                       # pre-fetch both engines deliberately
FLUENTCART_NO_BROWSER_INSTALL=1 npm run test:browser:firefox   # never auto-download
```

Set the target site in `dev/browser/.env` (copy `.env.example`), or the runner will
prompt for it:

```
WP_URL=your-site.test
WP_USER=admin
WP_PASS=your-password
```

> **Point browser tests at a production-mode site** — one with `'env' => 'production'`
> in `config/app.php` and built assets (`npm run build`). In dev mode the admin bundle
> is served from the Vite dev server over plain HTTP; if your site is HTTPS, Safari
> blocks those assets as mixed content and the admin app never boots. Chrome and Firefox
> allow it, which is why the suites can pass there and fail under Safari on the same site.

### 🤝 Contributing

FluentCart is now **100% community-driven**! We welcome contributions of all sizes – from typo fixes to entire new payment gateways.

We especially love help with:
- Writing Tests
- New features & enhancements
- Performance optimizations
- Documentation & translations improvements
- Bug fixes & testing

**How to contribute:**

1. Fork the repo
2. Create a branch: `git checkout -b feat/your-amazing-feature`
3. Commit: `git commit -m "feat: add amazing feature"`
4. Push & open a Pull Request

Read the contribution guidelines in [CONTRIBUTING.md](CONTRIBUTING.md) for more details.

New to open source? No problem – we’re super friendly and happy to guide you!

### 📚 Resources

- **Dev Documentation** – https://dev.fluentcart.com
- **User Documentation** – https://docs.fluentcart.com/
- **Issues** – https://github.com/fluent-cart/fluen-tcart/issues
- **Discussions** – https://community.wpmanageninja.com/portal/space/fluent-cart/home
- **Website** – https://fluentcart.com

### Community

- Follow us on X/Twitter: [@FluentCart](https://twitter.com/fluentcart)
- Use hashtag `#FluentCart` to show off your store!

### License

FluentCart is proudly licensed under **GNU GPLv3** – just like WordPress itself.

---

**Together, let’s build the fastest, most joyful WordPress eCommerce platform the world has ever seen.**

Made with ❤️ by the [WPManageNinja](https://wpmanageninja.com) team and **you** – the incredible WordPress community.

**Star this repo if you believe in open-source eCommerce!** ⭐  
Let’s go! 🚀
