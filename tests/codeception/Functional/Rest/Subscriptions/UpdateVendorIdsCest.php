<?php

namespace Tests\Functional\Rest\Subscriptions;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsSubscriptions;

/**
 * Routes: PUT  /fluent-cart/v2/orders/{order}/subscriptions/{subscription}/vendor-ids
 *         POST /fluent-cart/v2/orders/{order}/subscriptions/{subscription}/verify-vendor-ids
 * Controller: SubscriptionController::updateVendorIds / ::verifyVendorIds
 * Request: app/Http/Requests/UpdateVendorIdsRequest.php
 *
 * The service-level gates (collection method, terminal status, uniqueness) are covered by
 * Integration/Services/SubscriptionVendorIdsTest. This Cest covers what only the HTTP layer
 * can answer: the request class's sanitization boundary and the verify endpoint's behaviour
 * on a gateway that does not implement the lookup.
 */
class UpdateVendorIdsCest
{
    use BuildsSubscriptions;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        // Opt-in feature; the default-off path is covered in the Integration test.
        add_filter('fluent_cart/subscription/vendor_id_editing_enabled', '__return_true');
    }

    public function _after(FunctionalTester $I): void
    {
        remove_filter('fluent_cart/subscription/vendor_id_editing_enabled', '__return_true');
    }

    private function buildAutomatic(array $overrides = []): array
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain(array_merge([
            'status'                 => Status::SUBSCRIPTION_ACTIVE,
            'collection_method'      => Status::SUBSCRIPTION_METHOD_AUTOMATIC,
            'current_payment_method' => 'offline_payment',
            'vendor_subscription_id' => 'sub_original',
            'vendor_customer_id'     => 'cus_original',
        ], $overrides));

        return [$order, $subscription];
    }

    private function vendorIdsUrl($order, $subscription): string
    {
        return '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/vendor-ids';
    }

    public function adminCanCorrectVendorIds(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $subscription] = $this->buildAutomatic();

        [$status, $body] = $I->put($this->vendorIdsUrl($order, $subscription), [
            'data' => [
                'vendor_subscription_id' => 'sub_corrected',
                'vendor_customer_id'     => 'cus_corrected',
            ],
        ]);

        Assert::assertSame(200, $status, 'vendor id correction must succeed on an active automatic subscription. Body: ' . wp_json_encode($body));

        $fresh = Subscription::query()->find($subscription->id);
        Assert::assertSame('sub_corrected', $fresh->vendor_subscription_id);
        Assert::assertSame('cus_corrected', $fresh->vendor_customer_id);
    }

    /**
     * Both ids are interpolated into gateway API paths, so a value carrying a slash or a
     * space must be refused at the request boundary rather than at the call site.
     */
    public function malformedVendorIdIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $subscription] = $this->buildAutomatic();

        foreach (['sub_../../admin', 'sub with space'] as $badId) {
            [$status, $body] = $I->put($this->vendorIdsUrl($order, $subscription), [
                'data' => ['vendor_subscription_id' => $badId],
            ]);

            Assert::assertSame(422, $status, 'malformed vendor id "' . $badId . '" must fail validation. Body: ' . wp_json_encode($body));
        }

        Assert::assertSame('sub_original', Subscription::query()->find($subscription->id)->vendor_subscription_id);
    }

    public function overlongVendorIdIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $subscription] = $this->buildAutomatic();

        // fct_subscriptions stores both ids as VARCHAR(45).
        [$status, $body] = $I->put($this->vendorIdsUrl($order, $subscription), [
            'data' => ['vendor_subscription_id' => str_repeat('a', 46)],
        ]);

        Assert::assertSame(422, $status, '46-character vendor id must fail maxLength:45. Body: ' . wp_json_encode($body));
        Assert::assertSame('sub_original', Subscription::query()->find($subscription->id)->vendor_subscription_id);
    }

    public function storeBilledSubscriptionIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $subscription] = $this->buildAutomatic([
            'collection_method' => Status::SUBSCRIPTION_METHOD_MANUAL,
        ]);

        [$status, $body] = $I->put($this->vendorIdsUrl($order, $subscription), [
            'data' => ['vendor_subscription_id' => 'sub_corrected'],
        ]);

        Assert::assertNotSame(200, $status, 'a store-billed subscription has no gateway ids to correct. Body: ' . wp_json_encode($body));
        Assert::assertSame('sub_original', Subscription::query()->find($subscription->id)->vendor_subscription_id);
    }

    /**
     * With the opt-in filter off, both endpoints stay closed to a super admin — the
     * routes exist but the model gate refuses before anything is written or any
     * gateway is contacted.
     */
    public function bothEndpointsAreClosedWhenTheFilterIsOff(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $subscription] = $this->buildAutomatic();

        remove_filter('fluent_cart/subscription/vendor_id_editing_enabled', '__return_true');

        [$updateStatus, $updateBody] = $I->put($this->vendorIdsUrl($order, $subscription), [
            'data' => ['vendor_subscription_id' => 'sub_corrected'],
        ]);

        [$verifyStatus, $verifyBody] = $I->post(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/verify-vendor-ids',
            ['data' => ['vendor_subscription_id' => 'sub_original']]
        );

        add_filter('fluent_cart/subscription/vendor_id_editing_enabled', '__return_true');

        Assert::assertNotSame(200, $updateStatus, 'the update endpoint must refuse while the feature is opted out. Body: ' . wp_json_encode($updateBody));
        Assert::assertNotSame(200, $verifyStatus, 'the verify endpoint must refuse while the feature is opted out. Body: ' . wp_json_encode($verifyBody));
        Assert::assertSame('sub_original', Subscription::query()->find($subscription->id)->vendor_subscription_id);
    }

    /**
     * offline_payment does not implement verifyVendorSubscription, so the abstract default
     * must surface as a clean error response rather than a fatal.
     */
    public function verifyOnUnsupportedGatewayReturnsCleanError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $subscription] = $this->buildAutomatic();

        [$status, $body] = $I->post(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/verify-vendor-ids',
            ['data' => ['vendor_subscription_id' => 'sub_original', 'vendor_customer_id' => 'cus_original']]
        );

        Assert::assertNotSame(500, $status, 'an unimplemented gateway lookup must not fatal. Body: ' . wp_json_encode($body));
        Assert::assertGreaterThanOrEqual(400, $status, 'an unimplemented gateway lookup must report an error. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('message', (array) $body);
    }
}
