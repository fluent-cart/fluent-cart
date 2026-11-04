<?php

namespace Tests\Support\Traits;

use FluentCart\App\Models\Order;
use FluentCart\App\Services\ShortCodeParser\Parsers\ItemParser;
use FluentCart\App\Services\ShortCodeParser\Parsers\SubscriptionParser;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * Subjects for the smartcode parser tests.
 *
 * A parser reads whatever relations its accessors touch, so the order it is
 * handed has to arrive with all of them loaded — a lazily loaded relation reads
 * as absent and the parser silently renders an empty string. Building that
 * order, and the two lightweight parser wrappers, is setup shared across the
 * file's seventy-odd tests, and test files carry no helper methods of their own.
 *
 * Everything here builds through factories, returns the subject, and asserts
 * nothing.
 */
trait BuildsSmartCodeParserFixtures
{
    /** An order with addresses, eager-loaded the way the parsers expect. */
    protected function buildParserOrder(array $overrides = []): Order
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())
            ->withAddresses()
            ->create(array_merge(['customer_id' => $customer->id], $overrides));

        return Order::query()
            ->with(['customer', 'order_items', 'orderTaxRates', 'subscriptions', 'billing_address', 'shipping_address'])
            ->find($order->id);
    }

    /** An ItemParser over a bare item array, with no owning order. */
    protected function buildItemParser(array $item): ItemParser
    {
        return new ItemParser(['item' => $item, 'order' => null]);
    }

    /** A SubscriptionParser over a plain object, the shape the parser expects. */
    protected function buildSubscriptionParser(array $subscription): SubscriptionParser
    {
        return new SubscriptionParser(['subscription' => (object) $subscription]);
    }
}
