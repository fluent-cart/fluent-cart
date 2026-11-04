<?php

namespace Tests\Functional\Frontend\Checkout;

use Codeception\Example;
use FluentCart\Framework\Support\Arr;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;

class AddressSelectOwnershipCest
{
    use PlacesCheckoutOrders;

    public function _after(FunctionalTester $I): void
    {
        $I->logout();
    }

    /**
     * @example {"missing": false}
     * @example {"missing": true}
     */
    public function rejectedAddressLeavesTheWholeCartUnchanged(FunctionalTester $I, Example $example): void
    {
        (new CustomerFactory())->createForUser($I->loginAs('customer'));
        $otherCustomer = (new CustomerFactory())->create();
        $address = (new CustomerAddressFactory())->create($otherCustomer->id);
        $addressId = $address->id;
        if ($example['missing']) {
            $address->delete();
        }
        $cart = (new CartFactory())->create([
            'customer_id' => $otherCustomer->id,
            'checkout_data' => ['form_data' => ['billing_country' => 'CA', 'billing_state' => 'ON']],
        ]);
        $before = $cart->fresh()->getAttributes();

        [, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'update_address_select',
            'fct_cart_hash' => $cart->cart_hash,
            'customer_address_id' => $addressId,
        ]);

        Assert::assertSame('Address not found', $body['message'] ?? '', 'A foreign address must look the same as a missing address.');
        Assert::assertSame($before, $cart->fresh()->getAttributes(), 'Rejection must not change any persisted cart fields.');
    }

    /**
     * @example {"type": "billing"}
     * @example {"type": "shipping"}
     */
    public function customerCanSelectTheirOwnAddress(FunctionalTester $I, Example $example): void
    {
        $customer = (new CustomerFactory())->createForUser($I->loginAs('customer'));
        $type = $example['type'];
        $address = (new CustomerAddressFactory())->create($customer->id, ['type' => $type]);
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1000])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]], ['customer_id' => $customer->id]);

        [, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'update_address_select',
            'fct_cart_hash' => $cart->cart_hash,
            'customer_address_id' => $address->id,
        ]);

        Assert::assertSame('Address Attached', $body['message'] ?? '', 'Owned addresses must still be accepted.');
        $form = Arr::get($cart->fresh()->checkout_data, 'form_data', []);
        Assert::assertSame((int) $address->id, (int) Arr::get($form, $type . '_address_id'), 'The selected address ID must be saved.');
        Assert::assertSame('US', Arr::get($form, $type . '_country'), 'The selected country must be saved.');
        Assert::assertSame('NY', Arr::get($form, $type . '_state'), 'The selected state must be saved.');
        if ($type === 'billing') {
            Assert::assertSame((int) $address->id, (int) Arr::get($form, 'shipping_address_id'), 'Same-address checkout must still copy billing to shipping.');
        }
    }
}
