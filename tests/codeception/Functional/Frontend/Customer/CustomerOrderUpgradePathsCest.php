<?php

namespace Tests\Functional\Frontend\Customer;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\MetaFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Route: GET /fluent-cart/v2/customer-profile/orders/{order_uuid}
 * Controller: CustomerOrderController::orderDetails
 *
 * Each onetime order item carries `has_upgrade_paths` so the customer dashboard's
 * single-order view can surface the Upgrade button for one-time purchases — the
 * subscription flow already surfaces upgrades through the subscription views.
 */
class CustomerOrderUpgradePathsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    public function paidOnetimeItemWithUpgradePathIsFlagged(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);

        $basic = (new ProductFactory())->create();
        $bundle = (new ProductFactory())->create();
        $basicVariant = $basic->variants->first();
        $bundleVariant = $bundle->variants->first();

        (new MetaFactory())->createVariantUpgradePath($basicVariant->id, [$bundleVariant->id], false, 100);

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        (new OrderItemFactory())->create($order->id, [
            'post_id'   => $basic->ID,
            'object_id' => $basicVariant->id,
        ]);

        [$status, $body] = $I->get('/fluent-cart/v2/customer-profile/orders/' . (string) $order->uuid);

        Assert::assertSame(200, $status, 'own order details must return HTTP 200. Body: ' . wp_json_encode($body));
        $items = $body['order']['order_items'] ?? [];
        Assert::assertNotEmpty($items, 'order details must include order items');
        Assert::assertTrue(
            (bool) ($items[0]['has_upgrade_paths'] ?? false),
            'paid onetime item whose variation has an upgrade path must be flagged has_upgrade_paths'
        );
    }

    public function onetimeItemWithoutUpgradePathIsNotFlagged(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);

        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        (new OrderItemFactory())->create($order->id, [
            'post_id'   => $product->ID,
            'object_id' => $variant->id,
        ]);

        [$status, $body] = $I->get('/fluent-cart/v2/customer-profile/orders/' . (string) $order->uuid);

        Assert::assertSame(200, $status, 'own order details must return HTTP 200. Body: ' . wp_json_encode($body));
        $items = $body['order']['order_items'] ?? [];
        Assert::assertNotEmpty($items, 'order details must include order items');
        Assert::assertFalse(
            (bool) ($items[0]['has_upgrade_paths'] ?? true),
            'onetime item without any upgrade path must not be flagged'
        );
    }

    public function unpaidOrderItemIsNotFlaggedEvenWithUpgradePath(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);

        $basic = (new ProductFactory())->create();
        $bundle = (new ProductFactory())->create();
        $basicVariant = $basic->variants->first();
        $bundleVariant = $bundle->variants->first();

        (new MetaFactory())->createVariantUpgradePath($basicVariant->id, [$bundleVariant->id]);

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);
        (new OrderItemFactory())->create($order->id, [
            'post_id'   => $basic->ID,
            'object_id' => $basicVariant->id,
        ]);

        [$status, $body] = $I->get('/fluent-cart/v2/customer-profile/orders/' . (string) $order->uuid);

        Assert::assertSame(200, $status, 'own order details must return HTTP 200. Body: ' . wp_json_encode($body));
        $items = $body['order']['order_items'] ?? [];
        Assert::assertNotEmpty($items, 'order details must include order items');
        Assert::assertFalse(
            (bool) ($items[0]['has_upgrade_paths'] ?? true),
            'unpaid order must not offer upgrades — the upgrade handler only accepts paid orders'
        );
    }

    public function subscriptionItemIsNotFlaggedEvenWithUpgradePath(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);

        $basic = (new ProductFactory())->create();
        $bundle = (new ProductFactory())->create();
        $basicVariant = $basic->variants->first();
        $bundleVariant = $bundle->variants->first();

        (new MetaFactory())->createVariantUpgradePath($basicVariant->id, [$bundleVariant->id]);

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
            'type'           => Status::ORDER_TYPE_SUBSCRIPTION,
        ]);
        (new OrderItemFactory())->create($order->id, [
            'post_id'      => $basic->ID,
            'object_id'    => $basicVariant->id,
            'payment_type' => 'subscription',
            'other_info'   => ['payment_type' => 'subscription'],
        ]);

        [$status, $body] = $I->get('/fluent-cart/v2/customer-profile/orders/' . (string) $order->uuid);

        Assert::assertSame(200, $status, 'own order details must return HTTP 200. Body: ' . wp_json_encode($body));
        $items = $body['order']['order_items'] ?? [];
        Assert::assertNotEmpty($items, 'order details must include order items');
        Assert::assertFalse(
            (bool) ($items[0]['has_upgrade_paths'] ?? true),
            'subscription items must not be flagged — their upgrade lives on the subscription page'
        );
    }

    public function partiallyRefundedOrderItemIsStillFlagged(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);

        $basic = (new ProductFactory())->create();
        $bundle = (new ProductFactory())->create();
        $basicVariant = $basic->variants->first();
        $bundleVariant = $bundle->variants->first();

        (new MetaFactory())->createVariantUpgradePath($basicVariant->id, [$bundleVariant->id]);

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED,
        ]);
        (new OrderItemFactory())->create($order->id, [
            'post_id'   => $basic->ID,
            'object_id' => $basicVariant->id,
        ]);

        [$status, $body] = $I->get('/fluent-cart/v2/customer-profile/orders/' . (string) $order->uuid);

        Assert::assertSame(200, $status, 'own order details must return HTTP 200. Body: ' . wp_json_encode($body));
        $items = $body['order']['order_items'] ?? [];
        Assert::assertNotEmpty($items, 'order details must include order items');
        Assert::assertTrue(
            (bool) ($items[0]['has_upgrade_paths'] ?? false),
            'partially refunded order still qualifies — the pro upgrade handler accepts it'
        );
    }

    public function childLineItemIsNotFlaggedEvenWithUpgradePath(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);

        $basic = (new ProductFactory())->create();
        $bundle = (new ProductFactory())->create();
        $basicVariant = $basic->variants->first();
        $bundleVariant = $bundle->variants->first();

        (new MetaFactory())->createVariantUpgradePath($basicVariant->id, [$bundleVariant->id]);

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        (new OrderItemFactory())->create($order->id, [
            'post_id'   => $basic->ID,
            'object_id' => $basicVariant->id,
            'line_meta' => ['parent_item_id' => 99999],
        ]);

        [$status, $body] = $I->get('/fluent-cart/v2/customer-profile/orders/' . (string) $order->uuid);

        Assert::assertSame(200, $status, 'own order details must return HTTP 200. Body: ' . wp_json_encode($body));
        $items = $body['order']['order_items'] ?? [];
        Assert::assertNotEmpty($items, 'order details must include order items');
        Assert::assertFalse(
            (bool) ($items[0]['has_upgrade_paths'] ?? true),
            'child line items (line_meta.parent_item_id) must not be flagged'
        );
    }

    private function seedCurrentCustomer(int $userId): Customer
    {
        $wpUser = get_userdata($userId);
        return (new CustomerFactory())->create([
            'email'   => $wpUser->user_email,
            'user_id' => $userId,
        ]);
    }
}
