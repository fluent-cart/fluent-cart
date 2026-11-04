<?php

namespace Tests\Integration\Services\Filter;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\Filter\SubscriptionFilter;
use FluentCart\App\Services\Filter\OrderFilter;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * The Orders and Subscriptions lists tell installment plans apart from open-ended subscriptions, as the reports do.
 */
class InstallmentListTest extends DatabaseTestCase
{
    private array $orders = [];
    private array $subs = [];

    public function setUp(): void
    {
        parent::setUp();
        Order::query()->delete();
        Subscription::query()->delete();
        $cid = (new CustomerFactory())->create()->id;
        $order = function ($type, $parentId = 0) use ($cid) {
            return (new OrderFactory())->create([
                'customer_id' => $cid, 'type' => $type, 'parent_id' => $parentId, 'currency' => 'USD',
                'payment_status' => Status::PAYMENT_PAID, 'total_amount' => 1000, 'total_paid' => 1000,
            ])->id;
        };
        $sub = function ($parentOrderId, $billTimes, $status = Status::SUBSCRIPTION_ACTIVE) use ($cid) {
            return (new SubscriptionFactory())->create([
                'customer_id' => $cid, 'parent_order_id' => $parentOrderId, 'product_id' => 9301, 'variation_id' => 93010,
                'bill_times' => $billTimes, 'bill_count' => $billTimes ? 2 : 5, 'status' => $status,
            ])->id;
        };

        $this->orders['one_time'] = $order(Status::ORDER_TYPE_PAYMENT);
        $this->orders['subscription'] = $order(Status::ORDER_TYPE_SUBSCRIPTION);
        $this->subs['recurring'] = $sub($this->orders['subscription'], 0);
        $this->orders['renewal'] = $order(Status::ORDER_TYPE_RENEWAL, $this->orders['subscription']);
        $this->orders['installment'] = $order(Status::ORDER_TYPE_SUBSCRIPTION);
        $this->subs['installment'] = $sub($this->orders['installment'], 3, Status::SUBSCRIPTION_COMPLETED);
        $this->orders['installment_payment'] = $order(Status::ORDER_TYPE_RENEWAL, $this->orders['installment']);

        // The money each paid order took, as the subscription counts its payments
        $this->charge($this->orders['subscription'], $this->subs['recurring']);
        $this->charge($this->orders['renewal'], $this->subs['recurring']);
        $this->charge($this->orders['installment'], $this->subs['installment']);
        $this->charge($this->orders['installment_payment'], $this->subs['installment']);
    }

    private function charge($orderId, $subscriptionId, int $total = 1000, string $status = Status::TRANSACTION_SUCCEEDED): void
    {
        (new OrderTransactionFactory())->create((int) $orderId, [
            'subscription_id' => (int) $subscriptionId, 'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status' => $status, 'total' => $total,
        ]);
    }

    private function orderIds(array $args): array
    {
        $ids = array_map(function ($order) {
            return (int) $order->id;
        }, OrderFilter::make($args + ['per_page' => 50])->paginate()->items());
        sort($ids);

        return $ids;
    }

    private function ids(array $keys, array $from): array
    {
        $ids = array_map(function ($key) use ($from) {
            return (int) $from[$key];
        }, $keys);
        sort($ids);

        return $ids;
    }

    public function test_order_rows_carry_the_report_sale_type(): void
    {
        $rows = OrderFilter::make(['with' => ['admin_order_list'], 'per_page' => 50])->paginate()->items();
        $types = [];
        foreach ($rows as $row) {
            $types[(int) $row->id] = $row->sale_type;
        }

        foreach (['one_time' => 'payment', 'subscription' => 'subscription', 'renewal' => 'renewal', 'installment' => 'installment', 'installment_payment' => 'installment_payment'] as $key => $saleType) {
            $this->assertSame($saleType, $types[$this->orders[$key]], $key);
        }
    }

    public function test_installment_orders_carry_their_payment_number(): void
    {
        $cid = Order::query()->value('customer_id');
        $second = (new OrderFactory())->create([
            'customer_id' => $cid, 'type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => $this->orders['installment'], 'currency' => 'USD',
            'payment_status' => Status::PAYMENT_PAID, 'total_amount' => 1000, 'total_paid' => 1000,
        ])->id;
        $this->charge($second, $this->subs['installment']);
        $rows = [];
        foreach (OrderFilter::make(['with' => ['admin_order_list'], 'per_page' => 50])->paginate()->items() as $row) {
            $rows[(int) $row->id] = [(int) $row->installment_number, (int) $row->installment_total];
        }

        // The plan's first order is payment 1 of 3, its renewals follow in order
        $this->assertSame([1, 3], $rows[$this->orders['installment']]);
        $this->assertSame([2, 3], $rows[$this->orders['installment_payment']]);
        $this->assertSame([3, 3], $rows[$second]);
        $this->assertSame([0, 0], $rows[$this->orders['renewal']], 'A recurring renewal has no installment number');
    }

    public function test_a_trial_sign_up_is_not_payment_one(): void
    {
        $cid = Order::query()->value('customer_id');
        $order = function ($type, $paid, $parentId = 0) use ($cid) {
            return (new OrderFactory())->create([
                'customer_id' => $cid, 'type' => $type, 'parent_id' => $parentId, 'currency' => 'USD',
                'payment_status' => Status::PAYMENT_PAID, 'total_amount' => $paid, 'total_paid' => $paid,
            ])->id;
        };
        $plan = function ($parentOrderId) use ($cid) {
            return (new SubscriptionFactory())->create([
                'customer_id' => $cid, 'parent_order_id' => $parentOrderId, 'product_id' => 9302, 'variation_id' => 93020, 'bill_times' => 3,
            ]);
        };
        // A free trial takes no money at sign-up; a real trial's signup fee is marked as not a cycle
        $freeTrial = $order(Status::ORDER_TYPE_SUBSCRIPTION, 0);
        $freeTrialPlan = $plan($freeTrial);
        $freeTrialFirst = $order(Status::ORDER_TYPE_RENEWAL, 1000, $freeTrial);
        $this->charge($freeTrialFirst, $freeTrialPlan->id);
        $signupFee = $order(Status::ORDER_TYPE_SUBSCRIPTION, 500);
        $signupFeePlan = $plan($signupFee);
        $signupFeePlan->updateMeta('billed_cycles_deduction', 1);
        $this->charge($signupFee, $signupFeePlan->id, 500);
        $signupFeeFirst = $order(Status::ORDER_TYPE_RENEWAL, 1000, $signupFee);
        $this->charge($signupFeeFirst, $signupFeePlan->id);

        $rows = [];
        foreach (OrderFilter::make(['with' => ['admin_order_list'], 'per_page' => 50])->paginate()->items() as $row) {
            $rows[(int) $row->id] = [(int) $row->installment_number, (int) $row->installment_total];
        }

        // No charge at sign-up: no payment number, so the list keeps the "Installment sign-up" label
        $this->assertSame(0, $rows[$freeTrial][0], 'The trial sign-up has no payment number');
        $this->assertSame([1, 3], $rows[$freeTrialFirst]);
        $this->assertSame([0, 3], $rows[$signupFee]);
        $this->assertSame([1, 3], $rows[$signupFeeFirst]);
    }

    public function test_a_voided_renewal_invoice_takes_no_payment_number(): void
    {
        $cid = Order::query()->value('customer_id');
        // Pausing a store-billed plan voids its open renewal invoice; the order stays, unpaid
        $voided = (new OrderFactory())->create([
            'customer_id' => $cid, 'type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => $this->orders['installment'], 'currency' => 'USD',
            'payment_status' => Status::PAYMENT_FAILED, 'total_amount' => 1000, 'total_paid' => 0,
        ])->id;
        $this->charge($voided, $this->subs['installment'], 1000, Status::TRANSACTION_FAILED);
        $paid = (new OrderFactory())->create([
            'customer_id' => $cid, 'type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => $this->orders['installment'], 'currency' => 'USD',
            'payment_status' => Status::PAYMENT_PAID, 'total_amount' => 1000, 'total_paid' => 1000,
        ])->id;
        $this->charge($paid, $this->subs['installment']);
        $rows = [];
        foreach (OrderFilter::make(['with' => ['admin_order_list'], 'per_page' => 50])->paginate()->items() as $row) {
            $rows[(int) $row->id] = (int) $row->installment_number;
        }

        $this->assertSame(3, $rows[$paid], 'The paid renewal after a voided invoice is payment 3 of 3');
        $this->assertGreaterThan(0, $voided);
    }

    public function test_order_tabs_keep_installments_apart(): void
    {
        $this->assertSame($this->ids(['renewal'], $this->orders), $this->orderIds(['active_view' => 'renewal']));
        $this->assertSame($this->ids(['subscription'], $this->orders), $this->orderIds(['active_view' => 'subscription']));
        $this->assertSame($this->ids(['installment', 'installment_payment'], $this->orders), $this->orderIds(['active_view' => 'installment']));
    }

    public function test_order_type_filter_offers_the_sale_types(): void
    {
        $filter = $this->advancedOption(OrderFilter::advanceFilterOptions(), 'type');
        $this->assertArrayHasKey('installment_payment', $filter['options']);

        $only = Order::query();
        $filter['callback']($only, ['operator' => 'in', 'value' => ['installment_payment']]);
        $this->assertSame([(int) $this->orders['installment_payment']], array_map('intval', $only->pluck('id')->toArray()));

        $without = Order::query();
        $filter['callback']($without, ['operator' => 'not_in', 'value' => ['installment', 'installment_payment']]);
        $this->assertSame($this->ids(['one_time', 'subscription', 'renewal'], $this->orders), $this->sorted($without->pluck('id')->toArray()));
    }

    public function test_filters_read_every_operator_like_the_engine(): void
    {
        // As BaseFilter reads a single-value column: not_in_all excludes each value, in_all needs all of them at once
        $order = $this->advancedOption(OrderFilter::advanceFilterOptions(), 'type');
        $excludeAll = Order::query();
        $order['callback']($excludeAll, ['operator' => 'not_in_all', 'value' => ['renewal']]);
        $this->assertNotContains((int) $this->orders['renewal'], $this->sorted($excludeAll->pluck('id')->toArray()));
        $inAll = Order::query();
        $order['callback']($inAll, ['operator' => 'in_all', 'value' => ['renewal', 'payment']]);
        $this->assertSame([], $inAll->pluck('id')->toArray());

        $plan = $this->advancedOption(SubscriptionFilter::advanceFilterOptions(), 'plan_type');
        $notInstallment = Subscription::query();
        $plan['callback']($notInstallment, ['operator' => 'not_in_all', 'value' => ['installment']]);
        $this->assertSame([(int) $this->subs['recurring']], $this->sorted($notInstallment->pluck('id')->toArray()));
    }

    public function test_an_order_type_filter_saved_before_the_sale_types_uses_them(): void
    {
        // Saved before this change: the item carries no filter_type, so it would compare the raw type column
        $json = json_encode([[['source' => ['order', 'type'], 'operator' => 'in', 'value' => ['installment_payment']]]]);
        $parse = new \ReflectionMethod(OrderFilter::class, 'parseSearchGroups');
        $parse->setAccessible(true);
        $groups = $parse->invoke(OrderFilter::make([]), $json);

        $this->assertSame('custom', $groups[0]['order'][0]['filter_type']);
    }

    public function test_subscription_tabs_and_plan_type_filter(): void
    {
        $tab = function ($view) {
            return $this->sorted(array_map(function ($sub) {
                return $sub->id;
            }, SubscriptionFilter::make(['active_view' => $view, 'per_page' => 50])->paginate()->items()));
        };
        $this->assertSame([(int) $this->subs['installment']], $tab('installment'));
        $this->assertSame([(int) $this->subs['installment']], $tab('completed'));

        $filter = $this->advancedOption(SubscriptionFilter::advanceFilterOptions(), 'plan_type');
        $recurring = Subscription::query();
        $filter['callback']($recurring, ['operator' => 'in', 'value' => ['recurring']]);
        $this->assertSame([(int) $this->subs['recurring']], $this->sorted($recurring->pluck('id')->toArray()));
    }

    private function advancedOption(array $groups, string $value): array
    {
        foreach ($groups as $group) {
            foreach ($group['children'] ?? [] as $child) {
                if ($child['value'] === $value) {
                    return $child;
                }
            }
        }
        $this->fail("No advanced filter option '{$value}'");
    }

    private function sorted(array $ids): array
    {
        $ids = array_map('intval', $ids);
        sort($ids);

        return $ids;
    }
}
