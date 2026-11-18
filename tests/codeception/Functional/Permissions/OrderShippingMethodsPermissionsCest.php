<?php

namespace Tests\Functional\Permissions;

use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Traits\AdjustsRolePermissions;
use Tests\Support\Traits\BuildsManualShippingOrders;

class OrderShippingMethodsPermissionsCest
{
    use AdjustsRolePermissions;
    use BuildsManualShippingOrders;

    public function creatorsAndManagersCanLoadEnabledShippingMethods(FunctionalTester $I): void
    {
        $enabled = $this->manualShippingMethod();
        $disabled = $this->manualShippingMethod(['is_enabled' => 0]);
        foreach (['orders/create', 'orders/manage'] as $permission) {
            $remove = $this->setRolePermissions('worker', [$permission]);
            try {
                $I->loginAsWorker();
                [$status, $body] = $I->get('/fluent-cart/v2/orders/shipping_methods', ['country_code' => 'US']);
                Assert::assertSame(200, $status, $permission . ': ' . wp_json_encode($body));
                $methods = array_merge($body['shipping_methods'], $body['other_shipping_methods']);
                $ids = array_map('intval', array_column($methods, 'id'));
                Assert::assertContains((int) $enabled->id, $ids, 'The enabled method is available to ' . $permission);
                Assert::assertNotContains((int) $disabled->id, $ids, 'Disabled methods remain excluded.');
            } finally {
                $remove();
            }
        }
    }

    public function usersWithoutEitherPermissionCannotLoadShippingMethods(FunctionalTester $I): void
    {
        $remove = $this->setRolePermissions('worker', ['orders/view']);
        try {
            $I->loginAsWorker();
            [$status, $body] = $I->get('/fluent-cart/v2/orders/shipping_methods');
            Assert::assertSame(403, $status, 'Viewing orders does not allow shipping method lookup.');
            Assert::assertSame('rest_forbidden', $body['code'], 'The request is rejected by the permission policy.');
            Assert::assertArrayNotHasKey('shipping_methods', $body, 'Denied callers receive no methods.');
        } finally {
            $remove();
        }
        $I->logout();
        [$status, $body] = $I->get('/fluent-cart/v2/orders/shipping_methods');
        Assert::assertSame(401, $status, 'Guests cannot load shipping methods.');
        Assert::assertArrayNotHasKey('shipping_methods', $body, 'Guests receive no methods.');
    }

    public function createPermissionDoesNotAllowEditingExistingOrders(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'note' => 'Unchanged']);
        $remove = $this->setRolePermissions('worker', ['orders/create']);
        try {
            $I->loginAsWorker();
            [$status] = $I->post('/fluent-cart/v2/orders/' . $order->id, ['note' => 'Changed']);
            Assert::assertSame(403, $status, 'Method lookup access does not grant order editing.');
            Assert::assertSame('Unchanged', $order->fresh()->note, 'The denied edit leaves the order unchanged.');
        } finally {
            $remove();
        }
    }

}
