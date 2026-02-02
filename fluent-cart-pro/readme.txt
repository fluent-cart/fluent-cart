=== FluentCart Pro ===
Contributors: wpmanageninja, techjewel
Tags: ecommerce, cart, checkout, subscriptions, payments
Requires at least: 6.7
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3.9
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

= 1.3.9 (Jan 28, 2026) = 
- Adds Mercado Pago gateway (one-time payments)
- Adds Ghost product checkout
- Adds Gutenberg block: Add to Cart
- Adds Shortcode [fluent_cart_checkout_button]
- Adds Shortcode [fluent_cart_add_to_cart_button]
- Fixes IPN issues for some third-party gateways
- Fixes Dashboard styling issues
- Improves security

= 1.3.8 (Jan 23, 2026) =
- Adds Instant checkout feature
- Adds Product Button block (Guttenberg)
- Adds Product duplicate feature
- Adds Copy variation ID option in variation context menu
- Fixes S3 driver directory seperator issue
- Improves JS file size optimization

= 1.3.7 (Jan 20, 2026) =
- Adds Support for frontend templates
- Adds Order UUID / hash filter
- Adds Stripe metadata hook
- Adds Hook for autocomplete digital orders (default enabled)
- Fixes Hide consent section for stripe subscription
- Fixes Security issue in license APIs
- Fixes Product variation IDs not updating in DownloadFile
- Fixes ShopApp block list view & pagination issue
- Fixes Cart icon in body setting not working
- Fixes GroupKey bug in reports
- Fixes License rendering issue on customer profile
- Fixes Checkout empty state issue
- Fixes Address validation message and input label mismatch
- Fixes Missing required symbol for “Full Name” in checkout
- Improves Translation support for receipt page
- Improves Frontend loader UI
- Improves Cart item count sync between backend and UI badge
- Improves Stripe subscription price update event handling
- Improves Validation error handling and messaging
- Improves Retention report components
- Improves Checkout, product, and loader styles
- Improves Checkout field defaults and labels
- Improves Text change: “Half year” → “Six month”

= 1.3.6 (Jan 08, 2026) =
- Fixes FSE theme support
- Fixes Checkout Agree Terms and Conditions issue
- Fixes Product Min-Max pricing issue
- Fixes Buy now section position issue
- Fixes Shortcode issue in cart and checkout page
- Fixes Subscription related order issue
- Fixes Checkout page broken on Breakdance builder

= 1.3.4 (Jan 06, 2026) =
- Adds Bundle products
- Adds Stripe hosted checkout
- Adds Stripe appearance customizations support
- Adds Razorpay payment gateway addon (onetime )
- Adds 100% recurring discount
- Adds Order reference to Stripe metadata
- Adds New currency Ghanaian Cedi (GHS)
- Adds Turnstile invisible captcha
- Adds Email notification for offline payment
- Adds Items information in stripe metadata
- Adds WP user creation
- Adds Subscription retention & Cohort report
- Fixes Double confirmation email issue
- Fixes Order bump with subscription products
- Fixes NO_SHIPPING for paypal subscription issue
- Fixes Amount precision issue for paypal
- Fixes Update button issue for affiliate in coupon
- Fixes Checkout missing company name store issue
- Fixes Conflicts with Divi-5 Builder issue
- Fixes Customer last purchase invalid date issue
- Fix Downloads handling for object-based order
- Fixes S3 empty file validation issue
- Fixes downloadable file issue and empty file visibility
- Fixes Get paypal plan api endpoints issue
- Fixes Variation View Image & Text issue for Gutenberg
- Enhanced Development hooks to customize checkout button text
- Enhanced Translations for different modules
- Enhanced More development related hooks and modules

= 1.3.3 (Dec 03, 2025) =
- Fixes Authorize.net compatibility issue with PHP versions

= 1.3.2 (Dec 02, 2025) =
- Adds Private Product Status
- Adds Authorize.net payment gateway
- Adds Recurring discount coupon
- Adds Checkout block
- Adds Product variation customization hooks
- Adds Thank You page payment instructions
- Fixes Handling of zero-decimal currency for Stripe
- Fixes Hookable customer profile menu & icon issue
- Fixes Coupon priority issue
- Fixes Coupon calculation issues
- Fixes Report card design issue
- Fixes Group key SQL security issue
- Fixes EU VAT renderer issue on initial load
- Fixes Variation title not showing for bump product
- Fixes Wrong Stripe canceled_at date
- Updates Reports graph design
- Updates Gateway customization design
- Updates Addon gateway management for future updates

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
