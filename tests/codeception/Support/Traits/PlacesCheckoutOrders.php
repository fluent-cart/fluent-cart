<?php

namespace Tests\Support\Traits;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\App\Models\TaxClass;
use FluentCart\App\Models\TaxRate;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use Tests\Support\Doubles\CheckoutGateway;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\Factory\TaxRateFactory;
use Tests\Support\FunctionalTester;

/**
 * Checkout flow builder for Functional/Frontend/Checkout/* Cests (Campaign 1,
 * TC-101..107). Registers the in-process stub gateway, seeds an instant cart
 * from product variations, and produces the billing payload `place-order`
 * expects — so each Cest states only what differs per test.
 *
 * Trait methods follow the testing skill's factory/trait rules — they build
 * state via factories, return the built object, and carry no assertions.
 */
trait PlacesCheckoutOrders
{
    /**
     * Payment method slug the stub gateway registers under. Cests send it as
     * `_fct_pay_method`.
     */
    protected string $stubGatewaySlug = 'wp_browser_stub';

    /**
     * Register the stub gateway for the current test. Call from `_before`.
     */
    protected function registerStubCheckoutGateway(): void
    {
        GatewayManager::getInstance()->register($this->stubGatewaySlug, new CheckoutGateway());
    }

    /**
     * Detach the stub gateway again. Call from `_after` so the shared
     * GatewayManager singleton does not leak the stub into other Cests.
     */
    protected function removeStubCheckoutGateway(): void
    {
        $gateway = GatewayManager::getInstance($this->stubGatewaySlug);
        if ($gateway) {
            remove_filter('fluent_cart/transaction/url_' . $this->stubGatewaySlug, [$gateway, 'getTransactionUrl'], 10);
            remove_filter('fluent_cart/subscription/url_' . $this->stubGatewaySlug, [$gateway, 'getSubscriptionUrl'], 10);
        }
        GatewayManager::getInstance()->remove($this->stubGatewaySlug);
        CheckoutGateway::$forcedPaymentResult = null;
    }

    /**
     * Make every following stub payment fail the way a gateway create call
     * fails server-side: `finalizeOrder()` then marks the transaction failed and
     * answers HTTP 422 with the error message. Cleared by
     * removeStubCheckoutGateway() or acceptStubPayments().
     */
    protected function declineStubPayments(string $message = 'Card declined by wp-browser stub'): void
    {
        CheckoutGateway::$forcedPaymentResult = new \WP_Error('stub_declined', $message);
    }

    /**
     * Make every following stub payment answer success WITHOUT completing the
     * cart or touching the transaction — what a redirect gateway does when it
     * hands the shopper off and the charge is still pending. The cart stays
     * `intended`, so the same cart can be submitted again.
     */
    protected function leaveStubPaymentsPending(): void
    {
        CheckoutGateway::$forcedPaymentResult = [
            'status'      => 'success',
            'message'     => 'Handed off by wp-browser stub, charge still pending',
            'redirect_to' => 'https://example.test/gateway-pending',
        ];
    }

    /**
     * Let the stub gateway succeed again after declineStubPayments().
     */
    protected function acceptStubPayments(): void
    {
        CheckoutGateway::$forcedPaymentResult = null;
    }

    /**
     * Seed a stock-managed product (detail + variation both flagged) with the
     * given shelf count and price, and return its variation. `$variantOverrides`
     * can flip it out of stock (`stock_status`, `available`) or change the
     * fulfillment type.
     *
     * @param array<string, mixed> $variantOverrides extra `fct_product_variations` columns
     * @param array<string, mixed> $detailOverrides extra `fct_product_details` columns
     */
    protected function buildStockedVariation(int $priceCents, int $available, array $variantOverrides = [], array $detailOverrides = []): ProductVariation
    {
        return (new ProductFactory())
            ->withDetailAttributes(array_merge(['manage_stock' => 1, 'stock_availability' => 'in-stock'], $detailOverrides))
            ->withVariantAttributes(array_merge([
                'item_price'   => $priceCents,
                'manage_stock' => 1,
                'stock_status' => 'in-stock',
                'total_stock'  => $available,
                'available'    => $available,
                'on_hold'      => 0,
                'committed'    => 0,
            ], $variantOverrides))
            ->create()
            ->variants
            ->first();
    }

    /**
     * Seed an instant-checkout cart holding the given variation lines. The
     * dispatched request resolves the cart from the `fct_cart_hash` it is sent
     * (the Functional module resets the CartCheckoutHelper instance before
     * every dispatch — TESTABILITY-DEBT #14), so nothing is pre-bound here and
     * checkout-time coupon revalidation is exactly what production runs.
     *
     * @param array<int, array{0: ProductVariation, 1: int}> $lines [[variation, quantity], ...]
     * @param array<string, mixed> $cartOverrides extra `fct_carts` columns (user_id, customer_id, email, coupons, ...)
     */
    protected function buildInstantCart(FunctionalTester $I, array $lines, array $cartOverrides = []): Cart
    {
        $items = [];
        foreach ($lines as $line) {
            $items[] = CartHelper::generateCartItemFromVariation($line[0], (int) $line[1]);
        }

        $cart = (new CartFactory())
            ->withCartItems($items)
            ->create(array_merge(['cart_group' => 'instant'], $cartOverrides));

        return $cart;
    }

    /**
     * The minimal valid `place-order` body for a US billing address paid via
     * the stub gateway. Override any key per test.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function checkoutPayload(string $cartHash, array $overrides = []): array
    {
        return array_merge([
            'fct_cart_hash'      => $cartHash,
            '_fct_pay_method'    => $this->stubGatewaySlug,
            'billing_full_name'  => 'Happy Buyer',
            'billing_first_name' => 'Happy',
            'billing_last_name'  => 'Buyer',
            'billing_email'      => 'happy-buyer@example.test',
            'billing_address_1'  => '20 Checkout Way',
            'billing_city'       => 'New York',
            'billing_state'      => 'NY',
            'billing_postcode'   => '10001',
            'billing_country'    => 'US',
            'ship_to_different'  => 'no',
            'agree_terms'        => 'yes',
        ], $overrides);
    }

    /**
     * Seed a physical (shippable) product and return its variation. Detail and
     * variation both carry `fulfillment_type = physical`, which is what makes
     * `Cart::requireShipping()` true and the order `physical` / `unshipped`.
     *
     * @param array<string, mixed> $variantOverrides extra `fct_product_variations` columns
     * @param array<string, mixed> $detailOverrides extra `fct_product_details` columns
     */
    protected function buildPhysicalVariation(int $priceCents, array $variantOverrides = [], array $detailOverrides = []): ProductVariation
    {
        return (new ProductFactory())
            ->withDetailAttributes(array_merge(['fulfillment_type' => 'physical'], $detailOverrides))
            ->withVariantAttributes(array_merge([
                'item_price'       => $priceCents,
                'fulfillment_type' => 'physical',
            ], $variantOverrides))
            ->create()
            ->variants
            ->first();
    }

    /**
     * Seed one enabled shipping method inside a fresh zone. Defaults: a US
     * zone and a flat rate of 5 (major units) charged once per order.
     *
     * @param array<string, mixed> $methodOverrides `fct_shipping_methods` columns (amount, settings, states, title, ...)
     * @param array<string, mixed> $zoneOverrides `fct_shipping_zones` columns (region, name, ...)
     */
    protected function buildShippingMethod(array $methodOverrides = [], array $zoneOverrides = []): ShippingMethod
    {
        $zone = (new ShippingZoneFactory())->create(array_merge(['region' => 'US'], $zoneOverrides));

        return (new ShippingMethodFactory())->create(array_merge([
            'zone_id'  => (int) $zone->id,
            'type'     => 'flat_rate',
            'amount'   => 5,
            'settings' => ['configure_rate' => 'per_order'],
        ], $methodOverrides));
    }

    /**
     * Seed a tax rate on the `standard` tax class — the calculator's fallback
     * for every product without a variant-level class — for one country.
     * Creates the standard class when the test database has none.
     *
     * @param array<string, mixed> $overrides extra `fct_tax_rates` columns (state, for_shipping, is_compound, ...)
     */
    protected function buildStandardTaxRate(string $country, string $rate, array $overrides = []): TaxRate
    {
        $standard = TaxClass::query()->where('slug', 'standard')->first();
        if (!$standard) {
            $standard = TaxClass::query()->create([
                'title' => 'Standard',
                'slug'  => 'standard',
                'meta'  => ['priority' => 10, 'categories' => []],
            ]);
        }

        return (new TaxRateFactory())->create(array_merge([
            'class_id' => (int) $standard->id,
            'country'  => $country,
            'rate'     => $rate,
            'name'     => $country . ' Sales Tax',
        ], $overrides));
    }

    /**
     * The `save_checkout_data` AJAX call the storefront fires while the shopper
     * fills in the address and picks a shipping method — the same patch that
     * writes `form_data` / `shipping_data`, recalculates shipping shares and
     * tax onto the cart lines, and so leaves the cart exactly as `place-order`
     * later finds it. Dispatch it with `$I->checkoutAjax(...)`.
     *
     * @param array<string, mixed> $extra further checkout fields to patch
     * @return array<string, mixed>
     */
    protected function checkoutPatchParams(string $cartHash, string $country, string $state, int $shippingMethodId, array $extra = []): array
    {
        return array_merge([
            'fc_checkout_action' => 'save_checkout_data',
            'fct_cart_hash'      => $cartHash,
            'billing_country'    => $country,
            'billing_state'      => $state,
            'billing_city'       => 'Patch City',
            'billing_postcode'   => '10001',
            'billing_address_1'  => '20 Checkout Way',
            'ship_to_different'  => 'no',
            'shipping_method_id' => $shippingMethodId,
        ], $extra);
    }

    /**
     * The two fields the checkout form posts for the shopper's shipping choice:
     * the checked radio (`fc_shipping_method`) and the hidden mirror
     * (`fc_selected_shipping_method`), which the server rewrites each time it
     * saves the shopper's choice. Validation checks the mirror against the
     * methods available to the address, and the order is priced from it (TC-070).
     *
     * @return array<string, int>
     */
    protected function shippingChoice(int $shippingMethodId): array
    {
        return [
            'fc_shipping_method'          => $shippingMethodId,
            'fc_selected_shipping_method' => $shippingMethodId,
        ];
    }

    /**
     * Hold the checkout lock for `$cartHash` from a SECOND database session,
     * the way a still-running submission of the same cart would. Returns the
     * connection; keep it alive until releaseCheckoutLock(). The lock name
     * mirrors `CheckoutApi::acquireCartLock()`; if production renames it the
     * dispatched request simply succeeds and the contention test goes red.
     */
    protected function holdCheckoutLock(string $cartHash): \wpdb
    {
        global $wpdb;
        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $other->suppress_errors(true);
        $other->query($other->prepare('SELECT GET_LOCK(%s, %d)', 'fct_checkout_' . md5($wpdb->prefix . $cartHash), 1));

        return $other;
    }

    /**
     * Release the lock taken by holdCheckoutLock() and close that session.
     */
    protected function releaseCheckoutLock(\wpdb $other, string $cartHash): void
    {
        global $wpdb;
        $other->query($other->prepare('SELECT RELEASE_LOCK(%s)', 'fct_checkout_' . md5($wpdb->prefix . $cartHash)));
        $other->close();
    }
}
