<?php

namespace FluentCart\App\Services\Email;

use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Collection;
use FluentCart\Framework\Support\Str;

class EmailPreviewService
{
    public function getPreviewData(string $template): array
    {
        $order = $this->getDummyOrder();
        $subscription = $this->getDummySubscription($order);
        $customer = $order->customer;
        $transaction = $order->getLatestTransaction();

        $previewData = [
            'order'        => $order,
            'subscription' => $subscription,
            'customer'     => $customer,
            'transaction'  => $transaction,
            'reminder'     => [
                'stage'          => 'before_0',
                'due_at'         => DateTime::gmtNow()->addDays(2)->format('Y-m-d H:i:s'),
                'due_amount'     => 4900,
                'payment_link'   => '#',
                'order_ref'      => $order->invoice_no,
                'billing_date'   => DateTime::gmtNow()->addDays(30)->format('Y-m-d H:i:s'),
                'trial_end_date' => DateTime::gmtNow()->addDays(3)->format('Y-m-d H:i:s'),
            ],
        ];

        if (Str::startsWith($template, 'subscription.')) {
            $previewData['transaction'] = $subscription->getLatestTransaction();

            if (Str::contains($template, 'trial_end')) {
                $previewData['reminder']['stage'] = 'trial_end_3';
            }

            if (Str::contains($template, 'canceled')) {
                $previewData['reason'] = __('canceled on customer request', 'fluent-cart');
                $previewData['subscription'] = $this->getDummySubscription($previewData['order']);
            }
        }

        return $previewData;
    }

    private function getDummyCustomer(): object
    {
        return (object) [
            'full_name'  => 'John Doe',
            'first_name' => 'John',
            'last_name'  => 'Doe',
            'email'      => 'john.doe@example.com',
        ];
    }

    private function getDummyTransaction(): object
    {
        return new class {
            public $created_at = '';
            public $total = 4900;
            public $vendor_charge_id = 'ch_preview_123';
            public $order = null;

            public function getPaymentMethodText()
            {
                return 'card';
            }
        };
    }

    private function getDummyOrder(): object
    {
        $customer = $this->getDummyCustomer();
        $transaction = $this->getDummyTransaction();

        return new class($customer, $transaction, new Collection()) {
            public $id = 1001;
            public $uuid = 'preview-order-uuid';
            public $invoice_no = 'FC-1001';
            public $customer;
            public $order_items;
            public $subscriptions;
            public $orderTaxRates;
            public $billing_address;
            public $shipping_address;
            public $fulfillment_type = 'digital';
            public $subtotal = 4900;
            public $manual_discount_total = 0;
            public $coupon_discount_total = 0;
            public $shipping_tax = 0;
            public $shipping_total = 0;
            public $tax_total = 0;
            public $tax_behavior = 2;
            public $total_amount = 4900;
            public $total_paid = 0;
            public $total_refund = 0;
            public $payment_status = 'pending';
            private $latestTransaction;

            public function __construct($customer, $transaction, $emptyCollection)
            {
                $this->customer = $customer;
                $this->latestTransaction = $transaction;
                $this->order_items = new Collection([
                    [
                        'post_title'      => 'Sample Product',
                        'quantity'         => 1,
                        'title'            => 'Standard Plan',
                        'payment_type'     => 'onetime',
                        'payment_info'     => '',
                        'formatted_total'  => '$49.00',
                    ],
                ]);
                $this->subscriptions = $emptyCollection;
                $this->orderTaxRates = $emptyCollection;
                $this->billing_address = null;
                $this->shipping_address = null;
            }

            public function getViewUrl($type = 'customer')
            {
                return '#';
            }

            public function getLatestTransaction()
            {
                return $this->latestTransaction;
            }

            public function getDownloads($scope = 'email')
            {
                return [];
            }

            public function getLicenses($with = ['product', 'productVariant'])
            {
                return new Collection([]);
            }
        };
    }

    private function getDummySubscription($order): object
    {
        $customer = $order->customer ?? $this->getDummyCustomer();
        $transaction = $this->getDummyTransaction();

        return new class($customer, $transaction, $order) {
            public $id = 2001;
            public $uuid = 'preview-subscription-uuid';
            public $item_name = 'Sample Subscription';
            public $billing_interval = 'yearly';
            public $next_billing_date;
            public $recurring_total = 4900;
            public $status = 'active';
            public $trial_ends_at;
            public $customer;
            public $order;
            private $latestTransaction;

            public function __construct($customer, $transaction, $order)
            {
                $this->customer = $customer;
                $this->latestTransaction = $transaction;
                $this->order = $order;
                $this->next_billing_date = \FluentCart\App\Services\DateTime\DateTime::gmtNow()->addDays(30)->format('Y-m-d H:i:s');
                $this->trial_ends_at = \FluentCart\App\Services\DateTime\DateTime::gmtNow()->addDays(3)->format('Y-m-d H:i:s');
            }

            public function getLatestTransaction()
            {
                return $this->latestTransaction;
            }

            public function getViewUrl($type = 'customer')
            {
                return '#';
            }

            public function getReactivateUrl()
            {
                return '#';
            }
        };
    }
}
