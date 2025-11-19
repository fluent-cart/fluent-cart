# 🚀 Contributing to FluentCart

First off — **thank you**! ❤️  
Every single contribution, no matter how small, helps shape the future of open-source WordPress eCommerce. Whether you're fixing a typo, optimizing a query, adding a new payment gateway, or just opening an issue — you’re making FluentCart better for everyone.

We’re thrilled you’re here!

## Our Vision
A **blazing-fast**, **bloat-free**, **fully extensible**, and **100% open-source** eCommerce platform that feels like magic on WordPress — built and owned by the community.

## Code of Conduct
This project follows the WordPress Community Code of Conduct. Be respectful, inclusive, and kind. We’re all here to build something awesome together.

## How to Contribute

### 1. Found a Bug?
- Check the [Issues](https://github.com/fluent-cart/fluent-cart/issues) first to avoid duplicates.
- Can’t find it? Open a new issue using the **Bug Report** template.
- Include steps to reproduce, expected vs actual behavior, screenshots, and environment details (WP version, PHP version, etc.).

### 2. Have a Feature Idea?
- Open a [Discussion](https://github.com/fluent-cart/fluent-cart/discussions) first for big ideas — let’s align before writing code!
- Small improvements? Jump straight to a Pull Request.

### 3. Want to Code? (You’re our favorite kind of person 🤩)

#### Local Development Setup

```bash
# 1. Fork & clone the repo
git clone https://github.com/fluent-cart/fluent-cart.git
cd fluent-cart

# 2. Install dependencies
npm i

# 3. Start watching for changes (hot-reload)
npm run dev

# 4. For production build
npm run build
```

- Develop inside a standard WordPress installation.
- Activate FluentCart like any other plugin.
- Use tools like **Query Monitor** and **Log Viewer** to catch performance regressions.


#### Branch Naming
```
feat/payment-stripe-v2
fix/cart-session-bug
docs/readme-update
translate/fr-fr
```

#### Commit Message Convention
We loosely follow Conventional Commits:
```
feat: add PayPal Express gateway
fix: prevent double order creation on reload
perf: optimize product query by 40%
docs: update contribution guide
```

#### Pull Request Guidelines
1. Fork → Create branch → Code → Commit → Push
2. Open a PR against `master`
3. Fill out the PR template (it’ll appear automatically)
4. At least **one approval** + passing tests = merge!
5. We squash & merge to keep history clean

#### Testing
- Write unit tests when possible
- Always test on a clean WP install
- Performance matters! Avoid adding heavy queries or assets

### Areas Where We Especially Need Help
- Writing Tests
- New features & enhancements
- Performance optimizations
- Documentation & translations improvements
- Bug fixes & testing

### First-Timer? We’ve Got You!
Look for issues labeled:
- `good first issue`
- `help wanted`
- `beginner-friendly`

We’ll personally guide you through your first PR. Promise.

### Questions?
- Open a GitHub Discussion
- Ask in an issue
- Ping us on Twitter/X: [@FluentCart](https://twitter.com/fluentcart)

---

**Every contribution gets a shoutout** in release notes (unless you opt out).  
You’re not just writing code — you’re helping thousands of store owners succeed.

Welcome to the FluentCart family! Let’s build the future of WordPress eCommerce — together. 🚀

Made with endless gratitude by the FluentCart team and **you**.
