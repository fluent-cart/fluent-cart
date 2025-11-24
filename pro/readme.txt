=== FluentCart Pro ===
Contributors: wpmanageninja, techjewel
Tags: ecommerce, cart, checkout, subscriptions, payments
Requires at least: 6.7
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell Subscriptions, Physical Products, Digital Downloads easier than ever. Built for performance, scalability, and flexibility.

== Description ==
Meet FluentCart. It’s a performance-first, self-hosted eCommerce platform for WordPress. Build your ideal store, whether you sell physical products, subscriptions, downloads, licenses, or all of them. No third-party dependencies, no platform lock-in, and no transaction fees. Just a powerful store on your terms.

[youtube https://www.youtube.com/watch?v=meMM6Nq6laE]

👉 Official Website Link: [Official Website](https://fluentcart.com/)
👉 Join Our Community: [FluentCart Community](https://community.wpmanageninja.com/portal)
👉 Official 5 Minutes Guide: [Getting started with FluentCart](https://fluentcart.com/fluentcart-101/)

== Installation ==
This section describes how to install the plugin and get it working.


OR

1. Upload the plugin files to the `/wp-content/plugins/fluent-cart-pro` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the \'Plugins\' screen in WordPress
3. Use the `FluentCart` -> `Settings` screen to configure the plugin

== Frequently Asked Questions ==

= Can I sell physical and digital products together? =

Absolutely. FluentCart supports hybrid product models with inventory, downloads, licenses, and even installment billing.

= How easy is it to set up and use? =

Very easy. Installation is just like any other WordPress plugin. FluentCart comes with an intuitive interface so you can quickly configure your store settings, add products, and connect payment gateways—all without needing technical expertise.

= Can I sell unlimited products, and how well does it scale? =

There are no limitations on the number of products or orders. FluentCart is built for scalability—how well it performs depends on your hosting infrastructure. With a good hosting setup, your store can scale comfortably.

= Does FluentCart support subscriptions and recurring billing? =

Yes. FluentCart handles subscriptions natively with support for upgrades, downgrades, billing cycles, and trial periods. No transaction fees on Free or Pro.

= Can I customize FluentCart to match my brand? =

Absolutely. FluentCart includes customizable Gutenberg blocks and supports custom CSS for advanced styling. You can even add action buttons to custom WordPress patterns, making it easy to align the cart with your brand’s visual identity.

= Is FluentCart compatible with my current WordPress theme? =

Yes. FluentCart is built using standard WordPress best practices and is compatible with any properly coded theme. It will automatically inherit your theme’s styles unless you choose to override them.

= What payment methods are supported? =
FluentCart supports major global payment options, including Stripe, PayPal, and credit cards. You can also integrate custom payment gateways using webhooks and extend functionality as needed.

= Will FluentCart Charge Fees? =

Never. Even in the free version, simple subscriptions are free and there’s no transaction fee on our end.

= Do I need any paid services to use FluentCart? =
No. FluentCart is fully self-hosted. You connect directly to Stripe or PayPal without middleman services or extra transaction fees.

= Can I customize the checkout and product layouts? =

Yes. FluentCart templates are overrideable, and it supports full visual editing in Gutenberg and Bricks Builder.

= Will store development be expensive with FluentCart? =

Absolutely not. Everything is already built and ready to be placed on your site with minimal coding knowledge needed.


== Changelog ==

= 1.3.1 (Nov 19, 2025) =
- Hotfix: License API Issues Fixed

= 1.3.0 (Nov 19, 2025) =
- Introducing Paystack Payment Gateway
- Added Quarterly and Half-Yearly subscription billing intervals
- Coupons now supports email based restrictions
- Introducing REST API Doc: https://dev.fluentcart.com/restapi/
- Security: Performed a paid third-party security audit (Patchstack) as part of ongoing hardening efforts.
- Improved Translation support for multiple languages
- Imroved Reporting performance and data accuracy
- Refreshed the checkout page design and optimized payment method re-rendering.
- Better Multi-Site Support
- Improvement on Invoicing & Taxes
- Added new hooks and filters for developers
- Bug fixes and Imrovements

= 1.2.6 (Oct 29, 2025) =
- Adds More currency formatting options
- Adds Multiple tax rates on checkout
- Adds Compound tax rates calculation
- Adds Accessibility improvements
- Adds Payment gateway reorder for checkout page
- Adds EU tax home country override
- Adds Date time and number translation
- Adds UTM reports
- Adds Accessibility on checkout
- Adds Gateway logo and label customization
- Adds Order_by filter to ShopAppBlock
- Adds SortBy Filter to ShopAppBlock
- Adds Product Price Block support to ProductInfoBlock
- Adds Order_paid_done hook
- Adds More context to fluent_cart/checkout/prepare_other_data hook
- Adds Customization Hooks in Thank You page
- Adds Customization Hooks in checkout page 
- Adds Button style support for ShopApp Block
- Adds Link toggle and target option to Product Title Block
- Adds Missing translation strings
- Adds Mollie payment gateway
- Fixes Missing currency sign for new currencies
- Fixes Currency formatting issue for old thousand separator
- Fixes Subscription details for pricing type simple
- Fixes Setup fee displaying when disabled
- Fixes Tax name for AU set as "ABN"
- Fixes Buy now button style issue
- Fixes Product Excerpt style not working
- Fixes Inventory validation issue on default variation first load
- Fixes Always showing 'in-stock' in ShopApp and Product Single
- Fixes Quantity 10k leads to broken empty state
- Fixes JS event not calling after removing the last item
- Fixes Billing and Shipping address webhook issue
- Fixes Payment validation error message not showing
- Fixes Selected product not saving in ProductGallery and BuySection blocks
- Fixes Broken product gallery block
- Fixes Report colors issue for comparison
- Fixes Report child page navigation
- Fixes Loader not showing in product Modal
- Fixes VAT not showing in receipt

= 1.2.3 (Oct 22, 2025) =
- Added LifterLMS integration
- Added LearnDash integration
- Fixed Webhook Config Issue
- Adds CSS variables on cart drawer/shop page
- Adds Refactor class name on frontend page
- Add Total on cart drawer
- Adds Product name on admin create order items
- Adds New hooks for single product and shop page products
- Adds New hook (fluent_cart/hide_unnecessary_decimals)
- Fixes Product comapre at price issue
- Fixes Variation rearrange update issue
- Fixes Console error and shipping method issue
- Fixes Validation message issue when deleting an order
- Fixes Static dollar sign appearing in price range
- Fixes Free Shipping issue that destroyed cart
- Fixes Undefined property issue on product page
- Fixes Exception property issue
- Fixes Remove force POST request validation for IPN
- Fixes Translation strings issue for all modules
- Fixes Payment method not showing issue on stripe
