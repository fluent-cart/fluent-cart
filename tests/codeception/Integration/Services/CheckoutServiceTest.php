<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Services\CheckoutService;
use FluentCart\Framework\Support\Collection;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;

class CheckoutServiceTest extends DatabaseTestCase
{
    // =========================================================================
    // Spec 86: Constructor categorizes onetime vs subscriptions
    // =========================================================================

    public function test_constructor_categorizes_onetime_and_subscription_items(): void
    {
        $onetimeItem = CartFactory::makeCartItem(['unit_price' => 1000]);
        $subItem = CartFactory::makeSubscriptionItem(['unit_price' => 2000]);

        $service = new CheckoutService([$onetimeItem, $subItem]);

        $this->assertCount(1, $service->onetime);
        $this->assertCount(1, $service->subscriptions);
    }

    // =========================================================================
    // Spec 87: Constructor categorizes physical vs digital
    // =========================================================================

    public function test_constructor_categorizes_physical_and_digital_items(): void
    {
        $digitalItem = CartFactory::makeCartItem(['fulfillment_type' => 'digital']);
        $physicalItem = CartFactory::makePhysicalItem();

        $service = new CheckoutService([$digitalItem, $physicalItem]);

        $this->assertCount(1, $service->physicalItems);
        $this->assertCount(1, $service->digitalItems);
    }

    // =========================================================================
    // Spec 88: count is set to total items
    // =========================================================================

    public function test_constructor_sets_count(): void
    {
        $items = [
            CartFactory::makeCartItem(),
            CartFactory::makeCartItem(),
            CartFactory::makeCartItem(),
        ];

        $service = new CheckoutService($items);
        $this->assertSame(3, $service->count);
    }

    // =========================================================================
    // Spec 89: Accepts Collection instances
    // =========================================================================

    public function test_constructor_accepts_collection(): void
    {
        $items = new Collection([
            CartFactory::makeCartItem(),
            CartFactory::makeSubscriptionItem(),
        ]);

        $service = new CheckoutService($items);
        $this->assertSame(2, $service->count);
        $this->assertCount(1, $service->onetime);
        $this->assertCount(1, $service->subscriptions);
    }

    // =========================================================================
    // Spec 90-91: isAllPhysical
    // =========================================================================

    public function test_is_all_physical_returns_true_when_all_physical(): void
    {
        $items = [
            CartFactory::makePhysicalItem(),
            CartFactory::makePhysicalItem(),
        ];

        $service = new CheckoutService($items);
        $this->assertTrue($service->isAllPhysical());
    }

    public function test_is_all_physical_returns_false_with_any_digital(): void
    {
        $items = [
            CartFactory::makePhysicalItem(),
            CartFactory::makeCartItem(['fulfillment_type' => 'digital']),
        ];

        $service = new CheckoutService($items);
        $this->assertFalse($service->isAllPhysical());
    }

    // =========================================================================
    // Spec 92-93: isAllDigital
    // =========================================================================

    public function test_is_all_digital_returns_true_when_all_digital(): void
    {
        $items = [
            CartFactory::makeCartItem(['fulfillment_type' => 'digital']),
            CartFactory::makeCartItem(['fulfillment_type' => 'digital']),
        ];

        $service = new CheckoutService($items);
        $this->assertTrue($service->isAllDigital());
    }

    public function test_is_all_digital_returns_false_with_any_physical(): void
    {
        $items = [
            CartFactory::makeCartItem(['fulfillment_type' => 'digital']),
            CartFactory::makePhysicalItem(),
        ];

        $service = new CheckoutService($items);
        $this->assertFalse($service->isAllDigital());
    }

    // =========================================================================
    // Spec 94-95: hasDigitalProduct, hasPhysicalProduct
    // =========================================================================

    public function test_has_digital_product(): void
    {
        $service = new CheckoutService([CartFactory::makeCartItem(['fulfillment_type' => 'digital'])]);
        $this->assertTrue($service->hasDigitalProduct());

        $service2 = new CheckoutService([CartFactory::makePhysicalItem()]);
        $this->assertFalse($service2->hasDigitalProduct());
    }

    public function test_has_physical_product(): void
    {
        $service = new CheckoutService([CartFactory::makePhysicalItem()]);
        $this->assertTrue($service->hasPhysicalProduct());

        $service2 = new CheckoutService([CartFactory::makeCartItem(['fulfillment_type' => 'digital'])]);
        $this->assertFalse($service2->hasPhysicalProduct());
    }

    // =========================================================================
    // Spec 96: Empty array
    // =========================================================================

    public function test_constructor_with_empty_array(): void
    {
        $service = new CheckoutService([]);
        $this->assertSame(0, $service->count);
        $this->assertEmpty($service->onetime);
        $this->assertEmpty($service->subscriptions);
        $this->assertEmpty($service->physicalItems);
        $this->assertEmpty($service->digitalItems);
    }
}
