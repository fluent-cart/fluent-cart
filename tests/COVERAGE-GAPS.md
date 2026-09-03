# Coverage-driven gap inventory

## Scope and method

This is a risk-ranked inventory of PHP files over 100 physical lines in
`app/` and `api/` that had no executable line hit by the installed local test
gate. It is an inventory of the gate in `tests/`, not a claim that no ad hoc or
`dev/wp-browser` test exists elsewhere in the repository.

The snapshot was captured on 2026-07-31 with PCOV 1.0.12 while running the
unchanged command `bash tests/bin/run-all.sh all`. PCOV was enabled only for
that diagnostic run and filtered to `app/` and `api/`; the default runner and
PHP configuration remain unchanged. A file is “touched” when at least one
executable line has a hit count above zero. The run stayed green, made no
outbound HTTP or mail calls, scheduled no cron or Action Scheduler work, and
preserved the protected counts of 5,140 Orders and 2,320 Customers.

Initial snapshot:

- 448 PHP files over 100 lines
- 270 touched by the local gate
- 178 zero-hit gaps

Phase 13 moved two pure calculation files into the guarded default tier, Phase
23 moved eight commerce files into exact-ID integration coverage, and Phase 29
moved five gateway processor/webhook files into fail-closed provider coverage.
The current inventory therefore has 163 remaining gaps and 285 touched files.
Those closures are recorded after the ranked list.

The tiers are the risk ranking:

- **P0 — commerce correctness:** money, checkout, stock, order, subscription,
  tax, payment, refund, or security behavior.
- **P1 — privileged/stateful operations:** admin/customer APIs, mutations,
  migrations, async work, and data access whose failure can corrupt state.
- **P2 — presentation/infrastructure:** rendering, email, PDF, S3, reports,
  builders, and integration surfaces that need a specialized harness.
- **P3 — low direct business risk:** static catalogs, translation payloads,
  views, and scoped third-party code.

Recommended routes use these labels:

- **Now:** cheap deterministic test in the existing guarded tier.
- **Contract:** request/response or authorization test with all writes denied.
- **Fixture:** disposable exact-ID database fixture with full restoration.
- **Provider:** stubbed provider/webhook harness; never live credentials/network.
- **Browser:** browser/render snapshot in a disposable store.
- **Infra:** isolated fake filesystem, mail, PDF, or installer adapter.
- **Migration:** disposable database snapshot; never the protected local data.
- **Low:** low-value generated, catalog, view, or vendored surface.

## P0 — commerce correctness

| Path | Lines | What it does | Why zero-hit in the local gate | Worth testing / route |
|---|---:|---|---|---|
| `app/Events/Order/OrderRefund.php` | 149 | Carries refund event state through order hooks | Refund dispatch is excluded without isolated payment/order state | **Fixture:** event payload and idempotent listener contract |
| `app/Services/Payments/PaymentReceipt.php` | 248 | Builds payment receipt data from orders and transactions | Needs paid-order fixtures not created by the local gate | **Fixture:** exact money/status projection |
| `api/Orders.php` | 156 | Order REST operations and route callbacks | Protected orders and mutating paths are deliberately avoided | **Contract/Fixture:** denial plus disposable exact-order CRUD |
| `api/OrderItems.php` | 123 | Order-item API operations | Writes child rows beneath protected orders | **Fixture:** child ownership, totals, and deletion boundaries |
| `api/OrderMetaApi.php` | 147 | Reads and writes plugin-managed order metadata | Meta mutation needs an owned disposable order | **Fixture:** namespace/type preservation round-trip |
| `api/Confirmation.php` | 120 | Checkout/order confirmation response surface | Requires a completed checkout/order identity | **Fixture:** UUID ownership and response-shape tests |
| `api/PaymentMethods.php` | 109 | Exposes available checkout payment methods | Gateway boot/config state is not enabled in the safe gate | **Contract:** stub method registry and public response shape |
| `api/Resource/AppliedCouponResource.php` | 133 | Serializes applied coupon money and conditions | Resource is reached only through checkout/order payloads | **Now:** construct value rows and assert exact serialization |
| `app/Services/Coupon/CouponServiceAdmin.php` | 208 | Admin coupon mutation and management logic | Writes coupon records and related metadata | **Fixture:** full-row create/update restoration tests |
| `app/Modules/Subscriptions/Http/Controllers/SubscriptionController.php` | 496 | Admin subscription lifecycle endpoints | Pause/cancel/renew actions mutate billing state | **Fixture:** inert subscription transition contracts |
| `app/Modules/Subscriptions/Services/Filter/SubscriptionFilter.php` | 265 | Filters and paginates subscription lists | No disposable subscription list profile enters this service | **Fixture:** query-count, ownership, and pagination edges |
| `app/Modules/Subscriptions/Services/SubscriptionManagementMode.php` | 175 | Selects allowed subscription-management behavior | Needs store mode, subscription, and capability combinations | **Now/Fixture:** deterministic policy matrix |
| `app/Http/Controllers/PaymentMethodController.php` | 260 | Admin payment-method configuration endpoints | Mutates gateway settings and may invoke provider validation | **Contract/Provider:** permissions and stubbed settings adapter |
| `app/Modules/PaymentMethods/Cod/CodHandler.php` | 111 | Cash-on-delivery checkout/payment handler | Checkout handler is not invoked without creating an order | **Fixture:** inert COD acceptance and status transitions |
| `app/Modules/PaymentMethods/PayPalGateway/Processor.php` | 1044 | PayPal checkout, capture, and subscription processing | Requires external provider behavior and payment mutation | **Provider:** comprehensive fake-client state machine |
| `app/Modules/PaymentMethods/PayPalGateway/API/Webhook.php` | 153 | Receives and dispatches PayPal webhooks | Signature/provider callbacks are excluded from local requests | **Provider:** signed fixture payload replay and idempotency |
| `app/Modules/PaymentMethods/PayPalGateway/SubscriptionManager.php` | 388 | Manages PayPal subscription changes and cancellation | Would alter remote and local subscription state | **Provider/Fixture:** fake remote plus owned local subscription |
| `app/Modules/PaymentMethods/PayPalGateway/API/API.php` | 520 | Low-level PayPal API client operations | Network and credentials are fail-closed | **Provider:** transport fake, error mapping, no live calls |
| `app/Modules/PaymentMethods/PayPalGateway/API/DccApplies.php` | 156 | Determines PayPal card-processing eligibility | Provider/account capability response is absent locally | **Now/Provider:** deterministic eligibility response matrix |
| `app/Modules/PaymentMethods/PayPalGateway/API/PayPalPartner.php` | 289 | PayPal partner referral and onboarding API | External onboarding and credentials are prohibited | **Provider:** request construction and error mapping |
| `app/Modules/PaymentMethods/PayPalGateway/ConnectConfig.php` | 212 | Stores and resolves PayPal connection configuration | Writes secrets/options and may validate remotely | **Provider:** option sandbox plus redacted config contract |
| `app/Modules/PaymentMethods/PayPalGateway/PayPalHelper.php` | 337 | PayPal amount, status, and payload helpers | Loaded only with the gateway module | **Now:** extract pure mapping/amount boundary cases |
| `app/Modules/PaymentMethods/StripeGateway/Processor.php` | 869 | Stripe payment-intent and checkout processing | Requires provider calls and order/payment mutations | **Provider:** fake Stripe client and inert order fixtures |
| `app/Modules/PaymentMethods/StripeGateway/Webhook/Webhook.php` | 329 | Verifies and dispatches Stripe webhook events | Signed external events are not sent by the local gate | **Provider:** signature fixture replay and duplicate handling |
| `app/Modules/PaymentMethods/StripeGateway/SubscriptionsManager.php` | 244 | Coordinates Stripe subscription lifecycle | Would mutate remote and local subscription state | **Provider/Fixture:** fake remote state transition matrix |
| `app/Modules/PaymentMethods/StripeGateway/API/API.php` | 238 | Stripe API facade | Network/credentials are fail-closed | **Provider:** transport fake and exception normalization |
| `app/Modules/PaymentMethods/StripeGateway/API/ApiRequest.php` | 183 | Builds and executes Stripe HTTP requests | Outbound HTTP is intercepted before execution | **Provider:** request construction against a fake transport |
| `app/Modules/PaymentMethods/StripeGateway/Connect/ConnectConfig.php` | 281 | Manages Stripe Connect account configuration | Writes options/secrets and performs remote checks | **Provider:** option sandbox and redaction assertions |
| `app/Modules/PaymentMethods/StripeGateway/Plan.php` | 214 | Maps product billing plans to Stripe price data | Gateway module and remote-price path are not entered | **Now/Provider:** pure interval/amount mapping first |
| `app/Modules/PaymentMethods/StripeGateway/StripeHelper.php` | 261 | Stripe currency, status, and payload helpers | Loaded only during Stripe flows | **Now:** deterministic zero-decimal/status mappings |
| `app/Modules/PaymentMethods/StripeGateway/SwitchCustomerMethod.php` | 306 | Switches a Stripe customer's saved payment method | Requires customer secrets and remote mutation | **Provider:** fake customer/method transaction |
| `app/Modules/PaymentMethods/StripeGateway/UpdateCustomerPaymentMethod.php` | 187 | Updates stored Stripe payment-method references | Remote and local customer mutation are excluded | **Provider/Fixture:** fake provider plus owned customer meta |
| `app/Modules/PaymentMethods/PromoGateways/Pro/PromoGatewaySettings.php` | 254 | Configures promotional/pro gateway behavior | Optional Pro gateway is not enabled in the gate | **Contract:** settings schema and capability denial |
| `app/Modules/MCP/Support/WriteGuard.php` | 213 | Authorizes and rate-limits MCP write operations | Security path needs user/nonces/transient isolation | **Now:** capability, nonce, and rate-limit boundary matrix |
| `app/Services/AuthService.php` | 236 | Customer authentication and session/token behavior | Login/session mutation is outside read-only smoke | **Fixture:** isolated customer auth and replay boundaries |
| `app/Http/Controllers/FrontendControllers/CustomerController.php` | 495 | Customer portal profile, order, and account actions | Authenticated customer mutations need disposable identities | **Contract/Fixture:** two-owner authorization matrix |
| `api/CustomerAddress.php` | 179 | Customer address CRUD endpoints | Writes addresses under protected customers | **Fixture:** owned address CRUD and cross-owner denial |
| `api/Customers.php` | 182 | Customer REST operations | Protected customer records cannot be mutated | **Contract/Fixture:** denial plus disposable customer CRUD |
| `api/User.php` | 180 | WordPress-user/customer account API | User creation and role/session changes are excluded | **Fixture:** disposable user linkage and permission matrix |
| `app/Http/Controllers/WebController/FileDownloader.php` | 149 | Authorizes and streams protected downloads | Needs owned order/customer/download grants and file IO | **Fixture/Infra:** authorization matrix with fake file stream |
| `api/Resource/FrontendResource/OrderDownloadPermissionResource.php` | 121 | Serializes customer download grants | No owned downloadable-order fixture reaches the resource | **Fixture:** expiry/count/ownership serialization |
| `app/Services/CustomPayment/PaymentItem.php` | 140 | Represents custom payment items and totals | Custom payment path is not instantiated by the gate | **Now:** exact amount/type/value-object boundaries |
| `app/Services/Tax/AdminOrderTaxService.php` | 174 | Recalculates taxes on admin-edited orders | Mutates protected order items/tax rows | **Fixture:** owned-order recalculation and rounding |

## P1 — privileged and stateful operations

| Path | Lines | What it does | Why zero-hit in the local gate | Worth testing / route |
|---|---:|---|---|---|
| `app/Http/Requests/ProductRequest.php` | 457 | Validates and sanitizes product mutations | Current validation cases do not construct its full payload | **Contract:** malformed/boundary payload matrix without writes |
| `app/Http/Requests/FluentMetaRequest.php` | 176 | Validates generic plugin meta mutations | Meta write endpoint is excluded without an owned object | **Contract:** namespace, type, and sanitization matrix |
| `app/Http/Requests/FrontendRequests/CustomerRequests/CustomerProfileRequest.php` | 142 | Validates customer profile updates | Authenticated profile mutation is not run | **Contract:** names, addresses, and hostile input boundaries |
| `app/Http/Requests/SchedulingSettingsRequest.php` | 107 | Validates scheduling configuration | Scheduling option updates are intercepted | **Contract:** interval/timezone/input boundaries |
| `app/Http/Controllers/TaxClassController.php` | 123 | Admin tax-class CRUD | Tax configuration writes are excluded | **Fixture:** disposable tax class CRUD and permissions |
| `app/Modules/Shipping/Http/Controllers/ShippingClassController.php` | 152 | Admin shipping-class CRUD | Shipping configuration mutation is not exercised | **Fixture:** exact-ID CRUD and response shape |
| `app/Modules/Shipping/Http/Controllers/ShippingZoneController.php` | 162 | Admin shipping-zone CRUD | Zone/rate writes need disposable configuration | **Fixture:** precedence, duplicate, and permission cases |
| `app/Modules/Shipping/Http/Requests/ShippingZoneRequest.php` | 104 | Validates shipping-zone payloads | Controller write path is excluded | **Contract:** country/state/rate boundary matrix |
| `app/Helpers/ProductAdminHelper.php` | 339 | Admin product payload and persistence helpers | Product mutation paths use broader fixtures than current CRUD | **Fixture:** variation/download/meta preservation |
| `app/Helpers/StatusHelper.php` | 372 | Defines and maps order/payment/subscription statuses | Many mappings load only inside untested stateful flows | **Now:** exhaustive status and invalid-value contract |
| `app/Helpers/UtmHelper.php` | 154 | Captures and normalizes attribution parameters | No checkout/customer request carries UTM data | **Now:** hostile, empty, length, and normalization inputs |
| `app/Hooks/CLI/OrderCloneCommand.php` | 327 | Clones orders through WP-CLI | Would duplicate protected commerce records | **Fixture:** clone an exact owned order in isolation |
| `app/Models/Query/QueryParser.php` | 114 | Converts nested query conditions into ORM clauses | Dynamic relation/operator combinations are absent | **Fixture:** allow-listed operators and injection boundaries |
| `app/Modules/Data/ProductQuery.php` | 229 | Builds public/admin product queries | Current list profiling enters different query services | **Fixture:** visibility, stock, taxonomy, and pagination |
| `app/Services/OrdersQuery.php` | 172 | Builds filtered order queries | Protected-order query combinations are not constructed | **Fixture:** owned rows, scopes, sorting, and injection edges |
| `app/Services/Filter/LicenseFilter.php` | 202 | Filters license lists | Pro license list endpoint is not called | **Fixture:** discriminator, ownership, and pagination |
| `app/Services/Filter/LicenseSiteFilter.php` | 166 | Filters activated license sites | Needs license/site fixtures and Pro module state | **Fixture:** cross-license isolation and search escaping |
| `app/Services/Filter/OrderBumpFilter.php` | 185 | Filters order-bump records | Optional order-bump data is absent from the local fixtures | **Fixture:** ownership, status, and pagination |
| `app/Services/Async/DummyProductService.php` | 369 | Creates demo/dummy product data asynchronously | Background product creation is intentionally blocked | **Fixture:** disposable database and fake queue |
| `app/Services/Async/ImageAttachService.php` | 143 | Attaches remote/local images to products | Requires media writes and possibly remote retrieval | **Infra/Fixture:** fake media adapter and owned product |
| `app/Services/BulkProductInsertService.php` | 536 | Performs bulk product/variation insertion | High-volume posts and custom-table writes are excluded | **Fixture:** disposable DB, chunk and rollback boundaries |
| `app/Services/PluginInstaller/BackgroundInstaller.php` | 182 | Installs/activates companion plugins in background | Plugin filesystem and scheduler mutation are blocked | **Infra:** fake filesystem/upgrader and queue assertions |
| `app/Modules/WooCommerceMigrator/Services/BaseMigrationService.php` | 173 | Shared migration batching, cursors, and bookkeeping | WooCommerce and destructive migration state are absent | **Migration:** disposable source/target snapshot |
| `app/Modules/WooCommerceMigrator/Services/CustomerMigrationService.php` | 375 | Migrates WooCommerce customers and addresses | Would create customer rows and needs WC schema | **Migration:** edge identities, duplicates, resumability |
| `app/Modules/WooCommerceMigrator/Services/OrderMigrationService.php` | 882 | Migrates WooCommerce orders, items, and money | Would create many protected commerce rows | **Migration:** full snapshot, totals, idempotency, rollback |
| `app/Modules/WooCommerceMigrator/WooCommerceMigratorCli.php` | 1078 | CLI orchestration for WooCommerce migration | Requires WC install and large destructive workflow | **Migration:** command contract over disposable databases |
| `api/Resource/SubscriptionResource.php` | 123 | Serializes subscription records | Subscription admin route is not exercised | **Fixture:** status, billing, customer, and money shape |
| `api/Resource/FrontendResource/OrderResource.php` | 122 | Serializes portal order summaries | Existing UUID guards deny before success serialization | **Fixture:** owned order response contract |
| `api/Resource/FrontendResource/CustomerResource.php` | 284 | Serializes portal customer/account data | No authenticated customer success path enters it | **Fixture:** privacy-safe shape and optional fields |
| `api/Resource/FrontendResource/FluentMetaResource.php` | 107 | Serializes frontend plugin meta | Portal meta success path is absent | **Fixture:** type and privacy filtering |
| `api/Resource/FrontendResource/OrderAddressResource.php` | 136 | Serializes portal billing/shipping addresses | No owned portal order response reaches it | **Fixture:** missing/partial/international address shapes |
| `api/Resource/OrderAddressResource.php` | 124 | Serializes admin order addresses | Admin order success response is not run | **Fixture:** exact field and null normalization |
| `api/Resource/ProductDownloadResource.php` | 136 | Serializes product download metadata | Downloadable-product fixture is absent | **Fixture:** file privacy, limits, and optional fields |
| `api/Resource/AttrTermResource.php` | 351 | Serializes product attribute terms | Attribute term API success path is not exercised | **Fixture:** term/meta/ordering response contract |
| `app/Modules/Turnstile/TurnstileBoot.php` | 204 | Registers and verifies Turnstile protection | External verification and frontend form flow are absent | **Provider:** fake verification response and fail-closed cases |
| `app/Services/WpMetaHelper.php` | 126 | Reads/writes WordPress meta through shared helpers | Current fixtures do not call this helper directly | **Fixture:** ownership, scalar/array, and delete semantics |

## P2 — presentation, reporting, and infrastructure

| Path | Lines | What it does | Why zero-hit in the local gate | Worth testing / route |
|---|---:|---|---|---|
| `app/Services/Report/RetentionSnapshotService.php` | 1039 | Builds retention/cohort snapshots | Needs broad historical subscription/order fixtures | **Fixture:** small deterministic cohorts plus query budget |
| `app/Services/Report/Concerns/Subscription/CanCalculateMrr.php` | 273 | Calculates subscription MRR series | Current report inventory covers order aggregates only | **Fixture:** interval/status/currency time series |
| `app/Services/Report/Concerns/Subscription/CanCalculateChurnRateTrend.php` | 262 | Calculates churn-rate trends | Historical subscription transitions are absent | **Fixture:** cohort denominators and zero boundaries |
| `app/Services/Report/Concerns/Subscription/CanCalculateChurnRevenue.php` | 210 | Calculates churned recurring revenue | Requires historical subscription payment state | **Fixture:** cancellation timing and currency isolation |
| `app/Services/Report/Concerns/Subscription/CanCalculateSubscriptionCountTrend.php` | 233 | Calculates subscription count trends | No deterministic historical subscription dataset exists | **Fixture:** period/status boundary matrix |
| `app/Services/PDF/PdfGeneratorService.php` | 194 | Generates PDF documents | PDF engine, fonts, and file output are outside the gate | **Infra:** temp filesystem plus text/render verification |
| `app/Services/PDF/ReceiptPdfTemplateService.php` | 259 | Builds receipt PDF templates and data | Needs paid-order fixtures and PDF renderer | **Infra/Fixture:** snapshot data and generated PDF |
| `app/Services/PDF/DefaultPdfStructures.php` | 320 | Defines default PDF layout structures | Loaded only by PDF generation | **Now/Infra:** schema/default invariants |
| `app/Services/FileSystem/Drivers/S3/S3Driver.php` | 281 | Coordinates S3 filesystem operations | No credentials/network; driver is never initialized | **Infra:** fake S3 client contract |
| `app/Services/FileSystem/Drivers/S3/S3ConnectionVerify.php` | 805 | Verifies S3 credentials, regions, and access | External S3 calls are prohibited | **Infra:** recorded fake responses and error mapping |
| `app/Services/FileSystem/Drivers/S3/S3BucketCreator.php` | 220 | Creates/configures S3 buckets | Remote destructive operation is excluded | **Infra:** request contract with fake client |
| `app/Services/FileSystem/Drivers/S3/S3BucketList.php` | 186 | Lists accessible S3 buckets | Requires remote credentials | **Infra:** pagination and error response fixtures |
| `app/Services/FileSystem/Drivers/S3/S3FileDeleter.php` | 129 | Deletes S3 objects | Remote destructive operation is excluded | **Infra:** exact-key fake deletion, never live |
| `app/Services/FileSystem/Drivers/S3/S3FileList.php` | 263 | Lists S3 objects and folders | Requires remote bucket access | **Infra:** prefix/delimiter/pagination fixtures |
| `app/Services/FileSystem/Drivers/S3/S3FileUploader.php` | 200 | Uploads objects to S3 | Remote writes and filesystem payloads are excluded | **Infra:** stream/metadata/error fake-client tests |
| `app/Services/Email/Mailer.php` | 196 | Sends FluentCart transactional mail | Mail is intercepted and no success mail flow is invoked | **Infra/Fixture:** captured mail payloads, no delivery |
| `app/Services/Email/EmailPreviewService.php` | 240 | Renders email previews | Admin preview endpoint is not called | **Infra:** deterministic HTML/text snapshot |
| `app/Services/Email/BlockEditorHelper.php` | 326 | Converts block-editor email content | Block parsing/render setup is absent | **Now:** representative block trees and escaping |
| `app/Services/Email/ConditionPresets.php` | 193 | Defines email automation conditions | Loaded only by email editor/automation UI | **Now:** stable schema and operator defaults |
| `app/Services/Renderer/CheckoutRenderer.php` | 1034 | Renders the public checkout UI | Browser checkout flow is not installed | **Browser:** accessibility and state snapshots |
| `app/Services/Renderer/Receipt/ReceiptRenderer.php` | 1138 | Renders receipt content and order details | Needs paid owned-order view context | **Browser/Fixture:** money, escaping, and ownership |
| `app/Services/Renderer/Receipt/ThankYouRender.php` | 1388 | Renders thank-you/confirmation pages | Successful checkout is intentionally absent | **Browser/Fixture:** UUID ownership and output snapshots |
| `app/Services/Renderer/TaxRenderer.php` | 964 | Renders tax controls and totals | No browser checkout/admin render path | **Browser:** inclusive/exclusive and accessibility snapshots |
| `app/Services/Renderer/ShopAppRenderer.php` | 711 | Boots and renders the public shop application | The installed browser tier is admin-only and does not mount the public shop | **Browser:** bootstrap payload and render smoke |
| `app/Services/Renderer/ModalCheckoutRenderer.php` | 760 | Renders modal checkout markup | Browser/modal checkout flow is absent | **Browser:** focus, escaping, and breakpoint snapshots |
| `app/Services/Renderer/CartRenderer.php` | 619 | Renders full cart markup | Public browser cart is not exercised | **Browser:** empty/discount/tax/variation snapshots |
| `app/Services/Renderer/AddressSelectRenderer.php` | 520 | Renders address-selection controls | Authenticated checkout/customer UI is absent | **Browser:** ownership, international fields, accessibility |
| `app/Services/Renderer/AdvancedVariationRenderer.php` | 485 | Renders complex product variation selectors | Product browser fixtures and interaction tier are absent | **Browser:** option state, stock, and price snapshots |
| `app/Services/Renderer/PricingTableRenderer.php` | 483 | Renders product pricing tables | No frontend render tier enters it | **Browser:** plan/interval/empty-state snapshots |
| `app/Services/Renderer/CartSummaryRender.php` | 458 | Renders cart total summaries | Checkout/cart browser path is absent | **Browser:** exact subtotal/discount/tax output |
| `app/Services/Renderer/FormFieldRenderer.php` | 387 | Renders shared form fields | Render methods are not invoked by WP-CLI tests | **Browser/Now:** escaping and accessibility snapshots |
| `app/Services/Renderer/CartItemRenderer.php` | 369 | Renders cart line items | No populated frontend cart render | **Browser:** variation, quantity, discount, escaping |
| `app/Services/Renderer/ProductFilterRender.php` | 352 | Renders product filter controls | Public shop browser path is absent | **Browser:** URL state, labels, and empty options |
| `app/Services/Renderer/PackageDescriptionRenderer.php` | 265 | Renders package/variation descriptions | Product detail browser path is absent | **Browser:** rich-content escaping/sanitization |
| `app/Services/Renderer/ProductCategoriesListRenderer.php` | 263 | Renders category lists | Taxonomy frontend render is not exercised | **Browser:** hierarchy, empty, and URL snapshots |
| `app/Services/Renderer/SearchBarRenderer.php` | 199 | Renders product search controls | Browser shop tier is absent | **Browser:** query escaping and accessible labels |
| `app/Services/Renderer/CartDrawerRenderer.php` | 187 | Renders cart drawer shell | JavaScript/browser cart interactions are absent | **Browser:** focus and open/close snapshots |
| `app/Services/Renderer/MiniCartRenderer.php` | 159 | Renders compact cart UI | Public browser cart is absent | **Browser:** empty/count/amount snapshots |
| `app/Services/Renderer/ShippingMethodsRender.php` | 155 | Renders shipping method choices | Checkout shipping state is absent | **Browser/Fixture:** selected/unavailable/rate output |
| `app/Services/Renderer/StoreLogoRenderer.php` | 138 | Renders configured store branding | Frontend renderer path is not invoked | **Now/Browser:** URL escaping and fallback output |
| `app/Services/Renderer/VatFieldRenderer.php` | 132 | Renders VAT input fields | Tax-enabled browser checkout is absent | **Browser:** country visibility and accessibility |
| `app/Services/FrontendView.php` | 123 | Resolves and renders frontend views | WP-CLI tests do not dispatch page templates | **Browser:** template resolution and escaping |
| `app/Modules/Templating/BlockTemplates/TemplateParts/ProductModalTemplatePart.php` | 374 | Registers/renders product modal template part | Block template lifecycle is absent | **Browser:** registration and rendered structure |
| `app/Modules/Templating/BlockTemplates/ProductCategoryTemplate.php` | 115 | Provides product-category block template | Theme/block template discovery is not run | **Browser:** registration and fallback precedence |
| `app/Modules/Templating/BlockTemplates/ProductPageTemplate.php` | 103 | Provides product-page block template | Theme/block template discovery is not run | **Browser:** registration and fallback precedence |
| `app/Modules/Templating/Bricks/DynamicData.php` | 114 | Supplies Bricks dynamic product data | Bricks is not installed in the test runtime | **Browser:** adapter contract under a fake builder |
| `app/Modules/Templating/Bricks/Elements/ProductsCollection.php` | 815 | Bricks product collection element | Optional builder is absent | **Browser:** fake builder render and query contract |
| `app/Modules/Templating/Bricks/Elements/BuySection.php` | 442 | Bricks product purchase element | Optional builder is absent | **Browser:** variation/stock/price render snapshots |
| `app/Modules/Templating/Bricks/Elements/ProductGallery.php` | 292 | Bricks product gallery element | Optional builder is absent | **Browser:** empty/image/alt-text rendering |
| `app/Modules/Templating/Bricks/Elements/ProductStock.php` | 272 | Bricks stock-status element | Optional builder is absent | **Browser:** stock state and escaping |
| `app/Modules/Templating/Bricks/Elements/ProductTitle.php` | 139 | Bricks product-title element | Optional builder is absent | **Browser:** title escaping and link behavior |
| `app/Modules/Templating/Bricks/Elements/PriceRange.php` | 131 | Bricks price-range element | Optional builder is absent | **Browser:** zero/discount/variation prices |
| `app/Modules/Templating/Bricks/Elements/ProductContent.php` | 113 | Bricks product-content element | Optional builder is absent | **Browser:** sanitized rich content |
| `app/Modules/Templating/Bricks/Elements/ProductShortDescription.php` | 112 | Bricks short-description element | Optional builder is absent | **Browser:** empty and sanitized content |
| `app/Services/ShortCodeParser/Parsers/OrderParser.php` | 965 | Resolves order smartcodes | No email/receipt smartcode success fixture enters it | **Fixture:** exhaustive field, null, and privacy matrix |
| `app/Services/ShortCodeParser/ShortcodeParser.php` | 417 | Dispatches shortcode/smartcode parsing | Email/template render flow is absent | **Now/Fixture:** parser grammar and unknown-token behavior |
| `app/Services/ShortCodeParser/SmartCodeParser.php` | 265 | Parses modern smartcode expressions | Template rendering does not invoke it | **Now:** malformed, nested, and escaping cases |
| `app/Services/ShortCodeParser/Parsers/ItemParser.php` | 180 | Resolves order-item smartcodes | Needs owned item fixtures and template context | **Fixture:** item money/product/variation fields |
| `app/Services/ShortCodeParser/Parsers/BaseParser.php` | 154 | Shared smartcode parser behavior | Only reached through untested template flows | **Now:** probe subclass for fallback/formatting |
| `app/Services/ShortCodeParser/Parsers/SettingsParser.php` | 125 | Resolves store-setting smartcodes | Template flow does not invoke it | **Now:** allowed keys, missing values, escaping |
| `app/Services/Theme/ColorPaletteGenerator.php` | 149 | Derives UI color palettes | Theme configuration path is not called | **Now:** deterministic color and contrast boundaries |

## P3 — static, view, and third-party surfaces

| Path | Lines | What it does | Why zero-hit in the local gate | Worth testing / route |
|---|---:|---|---|---|
| `app/Services/Localization/i18n/locale-info.php` | 3981 | Static locale/address metadata catalog | Catalog is loaded only by locale UI/API paths | **Low:** schema lint, representative locale sentinels |
| `app/Services/Localization/i18n/currency-info.php` | 951 | Static currency metadata catalog | Current currency helper uses a different loaded source path | **Low:** schema, uniqueness, exponent sentinels |
| `app/Services/Localization/i18n/phone.php` | 269 | Static country calling-code catalog | Phone UI/validation path is absent | **Low:** schema and representative codes |
| `app/Services/Translations/block-editor-translation.php` | 465 | Block-editor translation payload | Block editor is not booted | **Low:** key/schema lint |
| `app/Services/Translations/customer-profile-translation.php` | 355 | Customer portal translation payload | Portal JavaScript bootstrap is not rendered | **Low:** required-key/schema lint |
| `app/Views/emails/parts/items_table.php` | 790 | Email item-table view | No transactional email render is invoked | **Low/Infra:** snapshot through Mailer harness |
| `app/Views/emails/parts/subscription_item.php` | 421 | Email subscription-item view | No subscription email render is invoked | **Low/Infra:** snapshot through Mailer harness |
| `app/Views/paypal/authenticate.php` | 386 | PayPal onboarding/authentication view | Provider onboarding UI is absent | **Low/Provider:** escaped config snapshot |
| `app/Views/emails/digest.php` | 210 | Digest email view | Digest scheduling/mail is excluded | **Low/Infra:** deterministic render snapshot |
| `app/Views/admin/admin_menu.php` | 195 | Admin app shell view | WP-CLI route tests do not render admin pages | **Low/Browser:** bootstrap and escaping smoke |
| `app/Views/paypal/manual-connect.php` | 159 | Manual PayPal connection view | Provider setup UI is absent | **Low/Provider:** redaction and escaping snapshot |
| `app/Views/emails/subscription/charge_failed/customer.php` | 142 | Customer charge-failure email view | Payment failure mail flow is not invoked | **Low/Infra:** captured-mail snapshot |
| `app/Views/emails/parts/addresses.php` | 131 | Email address view partial | No email render path is invoked | **Low/Infra:** international/missing address snapshot |
| `app/Views/frontend/customer_app.php` | 122 | Customer portal application shell | Browser/customer app tier is absent | **Low/Browser:** bootstrap payload and escaping |
| `app/Services/Libs/Emogrifier/scoped-vendor/composer/ClassLoader.php` | 579 | Scoped Composer autoloader | Runtime uses other autoloaded paths during the gate | **Low:** vendored upstream; smoke only on upgrade |
| `app/Services/Libs/Emogrifier/scoped-vendor/composer/InstalledVersions.php` | 364 | Scoped Composer package metadata API | No Emogrifier package introspection occurs | **Low:** vendored upstream |
| `app/Services/Libs/Emogrifier/scoped-vendor/symfony/css-selector/Parser/Parser.php` | 353 | Parses CSS selectors for email inlining | Email CSS inlining path is absent | **Low:** vendored upstream; integration snapshot |
| `app/Services/Libs/Emogrifier/scoped-vendor/symfony/css-selector/XPath/Translator.php` | 230 | Translates CSS selectors to XPath | Email CSS inlining path is absent | **Low:** vendored upstream |
| `app/Services/Libs/Emogrifier/scoped-vendor/tijsverkoyen/css-to-inline-styles/src/CssToInlineStyles.php` | 200 | Inlines CSS into email HTML | No rich HTML email render occurs | **Low/Infra:** one integration snapshot, not unit duplication |
| `app/Services/Libs/Emogrifier/scoped-vendor/symfony/css-selector/XPath/Extension/NodeExtension.php` | 197 | Translates selector nodes to XPath | Email CSS inlining path is absent | **Low:** vendored upstream |
| `app/Services/Libs/Emogrifier/scoped-vendor/symfony/css-selector/XPath/Extension/HtmlExtension.php` | 187 | Adds HTML-aware selector translation | Email CSS inlining path is absent | **Low:** vendored upstream |
| `app/Services/Libs/Emogrifier/scoped-vendor/symfony/css-selector/XPath/Extension/FunctionExtension.php` | 171 | Translates CSS selector functions | Email CSS inlining path is absent | **Low:** vendored upstream |
| `app/Services/Libs/Emogrifier/scoped-vendor/symfony/css-selector/Parser/TokenStream.php` | 167 | Streams CSS selector tokens | Email CSS inlining path is absent | **Low:** vendored upstream |
| `app/Services/Libs/Emogrifier/scoped-vendor/tijsverkoyen/css-to-inline-styles/src/Css/Rule/Processor.php` | 123 | Processes CSS rules for inlining | Email CSS inlining path is absent | **Low:** vendored upstream |
| `app/Services/Libs/Emogrifier/scoped-vendor/symfony/css-selector/XPath/Extension/PseudoClassExtension.php` | 122 | Translates CSS pseudo-classes | Email CSS inlining path is absent | **Low:** vendored upstream |
| `app/Services/Libs/Emogrifier/scoped-vendor/symfony/css-selector/XPath/Extension/AttributeMatchingExtension.php` | 119 | Translates CSS attribute selectors | Email CSS inlining path is absent | **Low:** vendored upstream |
| `app/Services/Libs/Emogrifier/scoped-vendor/symfony/css-selector/Parser/Token.php` | 111 | Represents CSS selector tokens | Email CSS inlining path is absent | **Low:** vendored upstream |
| `app/Services/Libs/Emogrifier/scoped-vendor/symfony/css-selector/XPath/XPathExpr.php` | 111 | Represents translated XPath expressions | Email CSS inlining path is absent | **Low:** vendored upstream |

## Closed in Phase 13

| Path | Lines | What it does | Why it was zero-hit | Closure and proof |
|---|---:|---|---|---|
| `app/Modules/MCP/Support/PaymentProjector.php` | 155 | Projects finite and perpetual billing onto date buckets | Only an ad hoc `dev/mcp-tests` script covered it; the installed gate never loaded it | Added an exact finite-cap/perpetual-horizon test. A `+1` finite-cent mutation failed with `9901` versus `9900`, then was restored. |
| `app/Modules/MCP/Support/ProductFinancialsCalculator.php` | 396 | Computes currency-isolated product subscription financials | Only an ad hoc developer script covered it; the installed gate never loaded it | Added mixed finite/perpetual and currency boundaries. Halving MRR failed with `1250` versus `2500`, then was restored. |

## Closed in Phase 23

| Path | Closure |
|---|---|
| `api/Checkout/CheckoutApi.php` | Anonymous exact-ID cart checkout through an inert gateway asserts 1001-cent subtotal + 1-cent exclusive tax = 1002 cents, with a pending unpaid Order and no transport. |
| `app/Helpers/CheckoutProcessor.php` | Two-line draft adjustment asserts 1998 conserved line cents and a 2004-cent payable total including exact shipping/tax boundaries. |
| `app/Helpers/AdminOrderProcessor.php` | The pre-write calculation boundary asserts two line discounts and the exact 2000-cent admin total against an inert Order canary. |
| `app/Modules/Tax/TaxCalculator.php` | Exact Product/TaxRate fixtures cover postcode match/mismatch, inclusive/exclusive tax, and item `2` versus subtotal `1` half-cent rounding. |
| `app/Services/Coupon/Concerns/CanCalculateLineTotal.php` | Exact cap and line-total assertions cover the passing path; a parked known failure exposes the 1001-to-1000-cent multi-line remainder loss. |
| `app/Services/Coupon/Concerns/CanValidateCoupon.php` | The exact minimum boundary passes at 1001 cents and rejects 1000 cents. |
| `app/Services/Payments/Refund.php` | A 1001-cent partial refund is recorded once; duplicate provider identity returns the same row and leaves parent/order totals unchanged. |
| `app/Listeners/UpdateStock.php` | Exact stock moves `10/0/0 → 7/0/3 → 7/3/0 → 8/2/0` across reserve, commit, and one-unit partial restore. |

## Strengthened in Phase 24

The four Phase 19 mutation survivors now have direct equality/sibling cases:
trial-signup coupon metadata, pending/intended non-collecting revenue,
projection-horizon equality, and DailyScheduler cutoff equality. These files
were already in the touched inventory, so this phase strengthens assertion
quality without changing the zero-hit count.

## Closed in Phase 27

| Path | Closure |
|---|---|
| `api/Checkout/CheckoutApi.php` | The opt-in full tier completes anonymous checkout in a fresh WordPress process, captures the inserted pending Order immediately, and proves 2002-cent subtotal + 200-cent exclusive tax = 2202 cents through the inert gateway boundary. |
| `app/Modules/StockManagement/StockManagement.php` | Guest checkout reserves exactly two units (`10/0/0 → 8/0/2`), while the admin full-refund route restores one owned reserved unit (`9/0/1 → 10/0/0`) and a repeated full-refund request changes nothing. |
| `app/Services/Coupon/DiscountService.php` | Checkout revalidates a real owned fixed coupon, persists its 101-cent discount in the OrderItem, Order, and applied-coupon ledger, increments use count once, and sends exactly 900 cents to the inert gateway. |
| `app/Http/Controllers/OrderController.php` / `app/Services/Payments/Refund.php` | The real admin refund route records one 1001-cent full refund, restores stock, returns a clean rejection on repeat, and preserves the exact post-first-refund money, row-count, and inventory snapshot. |

## Closed in Phase 29

| Path | Closure |
|---|---|
| `app/Modules/PaymentMethods/StripeGateway/Processor.php` | Fail-closed integration cases assert exact USD and JPY intent payloads plus unchanged 429 error mapping without live transport. |
| `app/Modules/PaymentMethods/PayPalGateway/Processor.php` | A canned provider client asserts exact amount, currency, item, identity, and 429 error payloads without network access. |
| `app/Modules/PaymentMethods/StripeGateway/SubscriptionsManager.php` | An exact owned subscription covers successful provider synchronization, validation identity, and state preservation after provider failure. |
| `app/Modules/PaymentMethods/StripeGateway/Webhook/Webhook.php` | Canned checkout, unknown-event, replay, and cancel-before-create payloads cover dispatch ordering; executable FIX-PLAN #30 separately exposes missing signature authentication. |
| `app/Modules/PaymentMethods/PayPalGateway/API/Webhook.php` | Canned signature success/failure, cancellation replay, unknown-event, and cancel-before-create cases prove rejection, dispatch, and idempotency without live PayPal calls. |

## Recommended next tranche

The next safest high-value work is not “raise coverage everywhere.” It is:

1. Add pure probes for `StatusHelper`, `UtmHelper`, PayPal/Stripe mapping helpers,
   coupon line totals, `PaymentItem`, PDF defaults, smartcode parsing, and color
   palette generation.
2. Extend the fail-closed gateway fakes to remaining lifecycle and provider
   error shapes; keep live credentials and transport prohibited.
3. Extend browser coverage to public cart/checkout surfaces using the same
   fail-closed process guard and exact-ID cleanup model.
4. Test WooCommerce migration only against disposable source and target
   databases; it must never run against this protected store.
