<?php

namespace Tests\Integration\Hooks;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderMeta;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * The `fluent_cart/order_paid_async_private_handle` listener in
 * app/Hooks/actions.php is reachable from two places that can run at the same
 * time: the Action Scheduler worker enqueued by OrderPaid::afterDispatch(), and
 * the receipt-page AJAX nudge in IntegrationEventListener::runOrderActionsAjax().
 *
 * The `action_scheduler_id` order meta is what keeps `order_paid_done` firing
 * once. These tests pin that guard, including the case where a competitor claims
 * the order in the window between this handler's read and its write.
 */
class OrderPaidAsyncHandlerTest extends WPTestCase
{
    public function test_order_paid_done_fires_once_for_a_paid_order(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $order = Order::find($order->id);
        $order->updateMeta('action_scheduler_id', 12345);

        $fired = 0;
        $listener = function () use (&$fired) {
            $fired++;
        };
        add_action('fluent_cart/order_paid_done', $listener);

        do_action('fluent_cart/order_paid_async_private_handle', ['order_id' => $order->id]);

        remove_action('fluent_cart/order_paid_done', $listener);

        $this->assertSame(1, $fired, 'order_paid_done should fire exactly once for a paid order holding an action_scheduler_id');
        $this->assertFalse($order->getMeta('action_scheduler_id'), 'the handler should consume action_scheduler_id so a later run cannot fire again');
    }

    public function test_order_paid_done_does_not_fire_again_on_a_repeat_invocation(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $order = Order::find($order->id);
        $order->updateMeta('action_scheduler_id', 12345);

        $fired = 0;
        $listener = function () use (&$fired) {
            $fired++;
        };
        add_action('fluent_cart/order_paid_done', $listener);

        do_action('fluent_cart/order_paid_async_private_handle', ['order_id' => $order->id]);
        do_action('fluent_cart/order_paid_async_private_handle', ['order_id' => $order->id]);

        remove_action('fluent_cart/order_paid_done', $listener);

        $this->assertSame(1, $fired, 'a second dispatch of the same order must not fire order_paid_done again');
    }

    /**
     * Reproduces the concurrent case: the Action Scheduler worker and the
     * receipt-page AJAX nudge both reach the handler, and the competitor claims
     * `action_scheduler_id` after this invocation has already read it.
     *
     * The `query` filter fires immediately before the handler's own DELETE
     * reaches MySQL, which is exactly the window the losing caller must survive.
     */
    public function test_order_paid_done_does_not_fire_when_a_competitor_claims_the_order_first(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $order = Order::find($order->id);
        $order->updateMeta('action_scheduler_id', 12345);

        $fired = 0;
        $listener = function () use (&$fired) {
            $fired++;
        };
        add_action('fluent_cart/order_paid_done', $listener);

        $competitorWon = false;
        $competitor = function ($query) use (&$competitorWon, $order) {
            if ($competitorWon || stripos($query, 'delete') === false) {
                return $query;
            }
            if (strpos($query, 'fct_order_meta') === false || strpos($query, 'action_scheduler_id') === false) {
                return $query;
            }

            // Set before deleting: the delete below re-enters this same filter.
            $competitorWon = true;

            OrderMeta::query()
                ->where('order_id', $order->id)
                ->where('meta_key', 'action_scheduler_id')
                ->delete();

            return $query;
        };
        add_filter('query', $competitor);

        do_action('fluent_cart/order_paid_async_private_handle', ['order_id' => $order->id]);

        remove_filter('query', $competitor);
        remove_action('fluent_cart/order_paid_done', $listener);

        $this->assertTrue($competitorWon, 'the competing claim must have run, otherwise this test proves nothing');
        $this->assertSame(0, $fired, 'the caller that lost the race for action_scheduler_id must not fire order_paid_done');
    }
}
