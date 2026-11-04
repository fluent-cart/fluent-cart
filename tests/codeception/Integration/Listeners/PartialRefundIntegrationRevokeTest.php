<?php

namespace Tests\Integration\Listeners;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Listeners\IntegrationEventListener;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\Refund;
use FluentCart\App\Services\Payments\RefundPlanner;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\IntegrationsTestCase;

/**
 * A partial refund revokes only the product feeds of the lines it fully refunded.
 */
class PartialRefundIntegrationRevokeTest extends IntegrationsTestCase
{
    private string $provider;
    private $addon;
    private array $runs = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->provider = 'pr_' . wp_generate_password(6, false);
        $this->addon = $this->registerAddonInFilter($this->provider);
        add_action('fluent_cart/integration/run/' . $this->provider, [$this, 'captureRun']);
    }

    public function tearDown(): void
    {
        remove_action('fluent_cart/integration/run/' . $this->provider, [$this, 'captureRun']);
        remove_filter('fluent_cart/integration/order_integrations', $this->addon);
        parent::tearDown();
    }

    public function captureRun($args)
    {
        $this->runs[] = [
            'feed'   => (int) $args['integration_id'],
            'scope'  => $args['scope'],
            'hook'   => $args['trigger'],
            'revoke' => $args['is_revoke_hook'],
        ];
    }

    public function test_refunding_one_product_revokes_only_that_products_feed(): void
    {
        [$order, $txn, $items, $feeds] = $this->paidOrder([1, 1]);

        $this->refundItems($order, $txn, [[$items[0], 1]]);

        $this->assertSame(Status::PAYMENT_PARTIALLY_REFUNDED, Order::find($order->id)->payment_status);
        $this->assertSame([
            ['feed' => $feeds[0], 'scope' => 'product', 'hook' => 'order_partially_refunded', 'revoke' => 'yes'],
        ], $this->runs);
    }

    public function test_refunding_some_units_of_a_line_revokes_nothing_until_the_last_unit(): void
    {
        [$order, $txn, $items, $feeds] = $this->paidOrder([2, 1]);

        $this->refundItems($order, $txn, [[$items[0], 1]]);
        $this->assertSame([], $this->runs, 'One unit is still owned, so access stays');

        $this->refundItems(Order::find($order->id), $txn, [[$items[0], 1]]);
        $this->assertSame([
            ['feed' => $feeds[0], 'scope' => 'product', 'hook' => 'order_partially_refunded', 'revoke' => 'yes'],
        ], $this->runs);
    }

    public function test_refunding_one_variant_keeps_the_product_feed_while_another_variant_is_owned(): void
    {
        $product = (new ProductFactory())->createWithVariants([['variation_title' => 'V1'], ['variation_title' => 'V2']]);
        $variants = $product->variants->values();
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->withTransaction(['status' => Status::TRANSACTION_SUCCEEDED, 'total' => 20000])->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => 20000,
            'total_amount'   => 20000,
            'total_paid'     => 20000,
        ]);
        $itemIds = [];
        foreach ($variants as $variant) {
            $itemIds[] = (new OrderItemFactory())->create($order->id, [
                'post_id'    => $product->ID,
                'object_id'  => $variant->id,
                'unit_price' => 10000,
                'subtotal'   => 10000,
                'line_total' => 10000,
            ])->id;
        }
        $this->addProductFeed($product->ID, $this->provider, $this->revokeFeedConfig())->id;
        $v1Only = (int) $this->addProductFeed($product->ID, $this->provider, array_merge($this->revokeFeedConfig(), [
            'conditional_variation_ids' => [$variants[0]->id],
        ]))->id;
        $txn = OrderTransaction::query()->where('order_id', $order->id)->first();

        $this->refundItems(Order::find($order->id), $txn, [[$itemIds[0], 1]]);

        // V2 still grants the product feed; only the V1-only feed loses access.
        $this->assertSame([
            ['feed' => $v1Only, 'scope' => 'product', 'hook' => 'order_partially_refunded', 'revoke' => 'yes'],
        ], $this->runs);
    }

    public function test_amount_only_refund_revokes_nothing(): void
    {
        [$order, $txn] = $this->paidOrder([1, 1]);

        $plan = RefundPlanner::plan($order, ['transaction_id' => $txn->id, 'amount' => 1000], []);
        $this->assertNotWPError($plan);
        $result = (new Refund())->processRefund($txn, $plan['refund_amount'], $plan['refund_info'], $plan['refund_intent']);
        $this->assertNotWPError($result);

        $this->assertSame(Status::PAYMENT_PARTIALLY_REFUNDED, Order::find($order->id)->payment_status);
        $this->assertSame([], $this->runs);
    }

    public function test_money_left_on_a_spent_line_revokes_nothing_again(): void
    {
        [$order, $txn, $items] = $this->paidOrder([1, 1]);

        $plan = RefundPlanner::plan($order, ['transaction_id' => $txn->id, 'amount' => 5000], [['id' => $items[0], 'refund_qty' => 1]]);
        $this->assertNotWPError($plan);
        $this->assertNotWPError((new Refund())->processRefund($txn, $plan['refund_amount'], $plan['refund_info'], $plan['refund_intent']));
        $this->refundItems(Order::find($order->id), $txn, [[$items[0], 0]]);
        $this->assertSame(Status::PAYMENT_PARTIALLY_REFUNDED, Order::find($order->id)->payment_status);

        // A fresh listener, as the money-only refund arrives in its own request.
        $this->runs = [];
        (new IntegrationEventListener())->mapAllIntegrationActions([
            'order'              => Order::find($order->id),
            'new_refunded_items' => [['id' => $items[0], 'refund_qty' => 0]],
        ], 'order_partially_refunded');

        $this->assertSame([], $this->runs);
    }

    public function test_global_feed_is_kept_on_partial_refund_and_revoked_on_full_refund(): void
    {
        [$order, $txn, $items] = $this->paidOrder([1, 1], false);
        $global = $this->addGlobalFeed($this->provider, $this->revokeFeedConfig());

        $this->refundItems($order, $txn, [[$items[0], 1]]);
        $this->assertSame([], $this->runs, 'A partial refund must not revoke an order-wide feed');

        $this->refundItems(Order::find($order->id), $txn, [[$items[1], 1]]);
        $this->assertSame(Status::PAYMENT_REFUNDED, Order::find($order->id)->payment_status);
        $this->assertSame([
            ['feed' => (int) $global->id, 'scope' => 'global', 'hook' => 'order_fully_refunded', 'revoke' => 'yes'],
        ], $this->runs);
    }

    public function test_feed_without_revoke_option_does_not_run_on_partial_refund(): void
    {
        [$order, $txn, $items] = $this->paidOrder([1, 1], true, 'no');

        $this->refundItems($order, $txn, [[$items[0], 1]]);

        $this->assertSame([], $this->runs);
    }

    /**
     * @param int[] $quantities one product per entry
     */
    private function paidOrder(array $quantities, bool $withProductFeeds = true, string $revoke = 'yes'): array
    {
        $customer = (new CustomerFactory())->create();
        $unit = 10000;
        $total = $unit * array_sum($quantities);

        $order = (new OrderFactory())->withTransaction([
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => $total,
        ])->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => $total,
            'total_amount'   => $total,
            'total_paid'     => $total,
        ]);

        $itemIds = [];
        $feedIds = [];
        foreach ($quantities as $qty) {
            $product = (new ProductFactory())->create();
            $itemIds[] = (new OrderItemFactory())->create($order->id, [
                'post_id'    => $product->ID,
                'object_id'  => $product->variants->first()->id,
                'quantity'   => $qty,
                'unit_price' => $unit,
                'subtotal'   => $unit * $qty,
                'line_total' => $unit * $qty,
            ])->id;

            if ($withProductFeeds) {
                $feedIds[] = (int) $this->addProductFeed($product->ID, $this->provider, $this->revokeFeedConfig($revoke))->id;
            }
        }

        $txn = OrderTransaction::query()->where('order_id', $order->id)->first();

        return [Order::find($order->id), $txn, $itemIds, $feedIds];
    }

    private function revokeFeedConfig(string $revoke = 'yes'): array
    {
        return [
            'enabled'                => 'yes',
            'name'                   => 'Revoke feed',
            'event_trigger'          => ['order_paid_done'],
            'watch_on_access_revoke' => $revoke,
        ];
    }

    /**
     * Same path as the admin refund endpoint.
     *
     * @param array $lines [[itemId, qty], ...]
     */
    private function refundItems(Order $order, OrderTransaction $txn, array $lines): void
    {
        $refundedItems = array_map(function ($line) {
            return ['id' => $line[0], 'refund_qty' => $line[1]];
        }, $lines);

        $plan = RefundPlanner::plan($order, ['transaction_id' => $txn->id, 'amount' => 0], $refundedItems);
        $this->assertNotWPError($plan);

        $result = (new Refund())->processRefund($txn->fresh(), $plan['refund_amount'], $plan['refund_info'], $plan['refund_intent']);
        $this->assertNotWPError($result);
    }

    private function assertNotWPError($value): void
    {
        $this->assertFalse(is_wp_error($value), is_wp_error($value) ? $value->get_error_message() : '');
    }
}
