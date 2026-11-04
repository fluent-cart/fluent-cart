<?php

namespace Tests\Support\Traits;

use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\StoreManagedRenewal\Services\RenewalService;
use Tests\Support\FakeSystemGateway;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * Multi-step setup for store-managed (system) subscription charging.
 *
 * Every charge, retry and reconcile test needs the same chain before it can say
 * anything: customer, subscription product, paid parent order, order item, the
 * subscription itself, the initial succeeded charge a real checkout would have
 * stamped, and finally an open renewal invoice produced by the production
 * RenewalService. Built inline that is sixty lines before the first assertion.
 *
 * It lives here rather than in the test file because test files carry no helper
 * methods, and the chain is the trait pattern the setup table prescribes: it
 * builds through factories, returns the objects, and asserts nothing. The only
 * failure it raises is a fixture-integrity one — if the renewal invoice cannot
 * be created there is no subject to test, and a RuntimeException says so at the
 * point of breakage instead of leaving a null to surface later.
 */
trait BuildsSystemSubscriptionInvoices
{
    /**
     * @param array $subOverrides fields to override on the subscription row
     * @return array{customer: object, order: object, sub: Subscription, invoice: object}
     */
    protected function buildSystemSubscriptionWithInvoice(array $subOverrides = []): array
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())
            ->withVariantAttributes(['payment_type' => 'subscription'])
            ->create();
        $variant = $product->variants->first();

        $order = (new OrderFactory())->withAddresses()->create([
            'customer_id'    => $customer->id,
            'type'           => 'subscription',
            'status'         => 'completed',
            'payment_status' => 'paid',
            'payment_method' => FakeSystemGateway::SLUG,
            'total_amount'   => 2999,
            'total_paid'     => 2999,
        ]);

        (new OrderItemFactory())->create($order->id, [
            'post_id'      => $product->ID,
            'object_id'    => $variant->id,
            'payment_type' => 'subscription',
        ]);

        // Subscriptions committed past rollback by earlier DDL-bearing tests
        // (TC-058) keep their parent_order_id, which this fresh order's id can
        // reuse. PaymentInstance resolves a renewal's subscription with a bare
        // first() on parent_order_id, so a leaked row shadows this one and every
        // isSystem() gate downstream returns early. Clearing inside the calling
        // test's transaction rolls back with it.
        Subscription::query()->where('parent_order_id', $order->id)->delete();

        $sub = (new SubscriptionFactory())->create(array_merge([
            'customer_id'            => $customer->id,
            'parent_order_id'        => $order->id,
            'product_id'             => $product->ID,
            'variation_id'           => $variant->id,
            'collection_method'      => 'system',
            'current_payment_method' => FakeSystemGateway::SLUG,
            'next_billing_date'      => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ], $subOverrides));

        // The initial succeeded charge (real checkouts stamp subscription_id on it).
        (new OrderTransactionFactory())->create($order->id, [
            'subscription_id'  => $sub->id,
            'transaction_type' => 'charge',
            'status'           => 'succeeded',
            'total'            => 2999,
            'vendor_charge_id' => 'ch_fake_initial_' . $sub->id,
        ]);

        $invoices = RenewalService::createRenewalOrders(Subscription::query()->find($sub->id));

        if (is_wp_error($invoices) || empty($invoices)) {
            throw new \RuntimeException(
                'Fixture could not create a renewal invoice: '
                . (is_wp_error($invoices) ? $invoices->get_error_message() : 'no invoice returned')
            );
        }

        return [
            'customer' => $customer,
            'order'    => $order,
            'sub'      => Subscription::query()->find($sub->id),
            'invoice'  => $invoices[0],
        ];
    }

    /** Reload a subscription so meta written by production code is visible. */
    protected function reloadSubscription(Subscription $sub): Subscription
    {
        return Subscription::query()->find($sub->id);
    }
}
