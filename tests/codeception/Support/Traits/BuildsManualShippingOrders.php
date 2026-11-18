<?php

namespace Tests\Support\Traits;

use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\TaxClassFactory;
use Tests\Support\Factory\TaxRateFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use FluentCart\App\Models\ShippingMethod;

trait BuildsManualShippingOrders
{
    protected function manualShippingMethod(array $attributes = []): ShippingMethod
    {
        $zone = (new ShippingZoneFactory())->create(['region' => 'US']);
        return (new ShippingMethodFactory())->create(array_merge([
            'zone_id' => $zone->id,
            'title' => 'Standard delivery',
            'amount' => 10,
            'settings' => ['configure_rate' => 'per_order'],
        ], $attributes));
    }

    protected function enableManualShippingTax(FunctionalTester $I): void
    {
        $I->armTaxModule(['enable_tax' => 'yes', 'tax_inclusion' => 'excluded', 'tax_calculation_basis' => 'billing']);
        $class = (new TaxClassFactory())->create(['title' => 'Standard', 'slug' => 'standard']);
        (new TaxRateFactory())->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10', 'for_shipping' => null]);
    }

    protected function manualShippingPayload(int $shipping = 1000, array $otherInfo = []): array
    {
        $otherInfo = array_merge(['payment_type' => 'onetime', 'tax_class' => 'standard'], $otherInfo);
        $customer = (new CustomerFactory())->create(['email' => 'manual-shipping@example.test']);
        $address = (new CustomerAddressFactory())->create($customer->id, ['country' => 'US', 'is_primary' => 1]);
        $variant = (new ProductFactory())
            ->withDetailAttributes(['fulfillment_type' => 'physical'])
            ->withVariantAttributes(['item_price' => 10000, 'fulfillment_type' => 'physical', 'other_info' => $otherInfo])
            ->create()->variants->first();

        return [
            'customer_id' => $customer->id,
            'billing_address_id' => $address->id,
            'shipping_total' => $shipping,
            'order_items' => [[
                'post_id' => $variant->post_id,
                'object_id' => $variant->id,
                'quantity' => 1,
                'unit_price' => 10000,
                'fulfillment_type' => 'physical',
                'payment_type' => 'onetime',
                'other_info' => $otherInfo,
            ]],
        ];
    }
}
