<?php

namespace Tests\Integration\Services;

use FluentCart\App\Events\Subscription\SubscriptionRenewalFailed;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Webhook\IPN;
use FluentCart\App\Services\Email\EmailNotifications;
use FluentCart\App\Services\Email\EmailPreviewService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\EmailNotificationConfigFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\Traits\ConfiguresStripeTestMode;

class SubscriptionRenewalFailedEmailTest extends WPTestCase
{
    use ConfiguresStripeTestMode;

    private Order $order;
    private Subscription $subscription;
    private array $mail = [];
    private $captureMail;

    public function setUp(): void
    {
        parent::setUp();
        (new EmailNotificationConfigFactory())->withNotificationConfig('subscription_renewal_failed_admin', ['active' => 'yes']);
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())->create();
        $this->order = (new OrderFactory())->withTransaction()->create([
            'customer_id' => $customer->id,
            'type' => 'subscription',
            'subtotal' => 49900,
            'coupon_discount_total' => 19960,
            'total_amount' => 29940,
            'total_paid' => 29940,
            'payment_status' => 'paid',
        ]);
        (new OrderItemFactory())->create($this->order->id, [
            'post_id' => $product->ID,
            'object_id' => $product->variants->first()->id,
            'payment_type' => 'subscription',
            'unit_price' => 49900,
            'subtotal' => 49900,
            'discount_total' => 19960,
            'line_total' => 29940,
        ]);
        (new OrderItemFactory())->create($this->order->id, ['post_title' => 'Unrelated one-time purchase']);
        $this->subscription = (new SubscriptionFactory())->create([
            'customer_id' => $customer->id,
            'parent_order_id' => $this->order->id,
            'product_id' => $product->ID,
            'variation_id' => $product->variants->first()->id,
            'item_name' => 'Annual CRM subscription',
            'billing_interval' => 'yearly',
            'recurring_amount' => 49900,
            'recurring_total' => 49900,
        ]);
        $this->mail = [];
        $this->captureMail = function ($result, $args) {
            $this->mail[] = $args;
            return true;
        };
        add_filter('pre_wp_mail', $this->captureMail, 10, 2);
    }

    public function tearDown(): void
    {
        remove_filter('pre_wp_mail', $this->captureMail, 10);
        (new EmailNotificationConfigFactory())->reset();
        parent::tearDown();
    }

    /** @dataProvider renewalAmounts */
    public function test_failed_renewal_emails_use_the_renewal_amount_instead_of_the_initial_receipt(int $amount, int $tax, string $expected): void
    {
        $this->subscription->update(['recurring_total' => $amount, 'recurring_amount' => $amount - $tax, 'recurring_tax_total' => $tax]);

        (new SubscriptionRenewalFailed($this->subscription, $this->order, $this->order->customer, 'Card declined'))->dispatch();

        $this->assertCount(2, $this->mail, 'Both customer and admin receive the failure email');
        foreach ($this->mail as $mail) {
            $this->assertStringContainsString($expected, $mail['message'], 'The summary states the renewal amount, including recurring discounts and tax');
            $this->assertStringNotContainsString('299.40', $mail['message'], 'The initial payment is not presented as the failed renewal');
            $this->assertStringNotContainsString('199.60', $mail['message'], 'The first-payment discount is not repeated');
            $this->assertStringNotContainsString('Unrelated one-time purchase', $mail['message'], 'Only the renewing subscription is summarized');
        }
        $this->assertSame(29940, (int) $this->order->fresh()->total_amount, 'Rendering leaves the original order total unchanged');
        $this->assertSame(19960, (int) $this->order->fresh()->coupon_discount_total, 'Rendering preserves the historical discount');
    }

    public function renewalAmounts(): array
    {
        return [
            'first-payment discount' => [49900, 0, '499.00'],
            'recurring discount' => [39920, 0, '399.20'],
            'renewal with tax' => [59880, 9980, '598.80'],
        ];
    }

    /** @dataProvider invoiceAmounts */
    public function test_stripe_failure_emails_use_the_failed_invoice_amount_and_currency(int $amount, string $currency, string $expected): void
    {
        $this->enableStripeTestMode();
        $this->order->update(['mode' => 'test']);
        $this->subscription->update(['current_payment_method' => 'stripe', 'vendor_subscription_id' => 'sub_renewalemail']);
        $requests = [];
        $http = function ($result, $args, $url) use ($amount, $currency, &$requests) {
            $requests[] = $url;
            if ($url !== 'https://api.stripe.com/v1/invoices/in_renewalemail') {
                return new \WP_Error('unexpected_request', $url);
            }
            return [
                'response' => ['code' => 200],
                'body' => wp_json_encode([
                    'id' => 'in_renewalemail',
                    'subscription' => 'sub_renewalemail',
                    'amount_due' => $amount,
                    'currency' => $currency,
                ]),
                'headers' => [],
                'cookies' => [],
            ];
        };
        add_filter('pre_http_request', $http, 10, 3);
        try {
            (new IPN())->handleInvoicePaymentFailed([
                'order' => $this->order,
                'event' => (object) ['livemode' => false, 'data' => (object) ['object' => (object) ['id' => 'in_renewalemail']]],
            ]);
        } finally {
            remove_filter('pre_http_request', $http, 10);
        }

        $this->assertCount(1, $requests, 'The handler reads the failed invoice without making additional requests');
        $this->assertCount(2, $this->mail, 'The gateway event reaches both failure emails');
        foreach ($this->mail as $mail) {
            $body = html_entity_decode($mail['message'], ENT_QUOTES, 'UTF-8');
            $this->assertStringContainsString($expected, $body, 'The invoice amount overrides the stored recurring amount in its own currency');
            $this->assertStringNotContainsString('299.40', $body, 'No original payment total leaks into the renewal summary');
            $this->assertStringNotContainsString('199.60', $body, 'No original discount leaks into the renewal summary');
        }
    }

    public function invoiceAmounts(): array
    {
        return [
            'discounted invoice' => [44910, 'usd', '$449.10'],
            'invoice currency differs from store' => [49900, 'eur', '€499.00'],
            'zero-decimal currency' => [499, 'jpy', '¥499'],
            'zero is not a missing amount' => [0, 'usd', '$0.00'],
        ];
    }

    public function test_an_actual_renewal_order_takes_precedence_over_the_subscription_amount(): void
    {
        $renewal = (new OrderFactory())->create([
            'customer_id' => $this->order->customer_id,
            'parent_id' => $this->order->id,
            'type' => 'renewal',
            'currency' => 'USD',
            'subtotal' => 49900,
            'coupon_discount_total' => 9980,
            'total_amount' => 39920,
        ]);

        (new SubscriptionRenewalFailed($this->subscription, $renewal, $renewal->customer, 'Card declined'))->dispatch();

        $this->assertCount(2, $this->mail, 'The actual renewal order can be supplied by existing gateway callers');
        foreach ($this->mail as $mail) {
            $this->assertStringContainsString('399.20', $mail['message'], 'The renewal invoice keeps its own discounted amount');
            $this->assertStringNotContainsString('499.00', $mail['message'], 'The stored subscription price does not replace the invoice amount');
        }
    }

    public function test_paypal_failure_emails_use_the_subscription_amount_without_a_renewal_invoice(): void
    {
        $this->subscription->update(['current_payment_method' => 'paypal', 'vendor_subscription_id' => 'I-RENEWALMAIL']);

        (new \FluentCart\App\Modules\PaymentMethods\PayPalGateway\IPN())->processSubscriptionPaymentFailed([
            'paypal_subscription' => ['id' => 'I-RENEWALMAIL', 'billing_info' => ['failed_payments_count' => 1]],
            'webhook_event_id' => 'WH-RENEWALMAIL',
        ]);

        $this->assertCount(2, $this->mail, 'The existing PayPal event signature still sends both emails');
        foreach ($this->mail as $mail) {
            $this->assertStringContainsString('499.00', $mail['message'], 'The PayPal fallback uses the recurring amount');
            $this->assertStringNotContainsString('299.40', $mail['message'], 'The original discounted receipt is not reused');
        }
    }

    public function test_paypal_failure_emails_use_the_failed_payment_amount_when_paypal_reports_it(): void
    {
        $this->subscription->update(['current_payment_method' => 'paypal', 'vendor_subscription_id' => 'I-RENEWALMAIL']);

        (new \FluentCart\App\Modules\PaymentMethods\PayPalGateway\IPN())->processSubscriptionPaymentFailed([
            'paypal_subscription' => ['id' => 'I-RENEWALMAIL', 'billing_info' => [
                'failed_payments_count' => 1,
                'last_failed_payment'   => ['amount' => ['value' => '549.00', 'currency_code' => 'USD']],
            ]],
            'webhook_event_id' => 'WH-RENEWALMAIL-AMOUNT',
        ]);

        $this->assertCount(2, $this->mail, 'Both failure emails are sent');
        foreach ($this->mail as $mail) {
            $this->assertStringContainsString('549.00', $mail['message'], 'PayPal\'s failed amount overrides the stored recurring amount');
            $this->assertStringNotContainsString('499.00', $mail['message'], 'The stored recurring amount is not shown');
        }
    }

    public function test_fallback_amount_uses_the_subscription_currency_not_the_parent_order_currency(): void
    {
        $this->order->update(['currency' => 'USD']);
        $this->subscription->update(['currency' => 'EUR', 'current_payment_method' => 'paypal', 'vendor_subscription_id' => 'I-RENEWALMAIL']);

        (new \FluentCart\App\Modules\PaymentMethods\PayPalGateway\IPN())->processSubscriptionPaymentFailed([
            'paypal_subscription' => ['id' => 'I-RENEWALMAIL', 'billing_info' => ['failed_payments_count' => 1]],
            'webhook_event_id' => 'WH-RENEWALMAIL-CURRENCY',
        ]);

        $this->assertCount(2, $this->mail, 'Both failure emails are sent');
        foreach ($this->mail as $mail) {
            $body = html_entity_decode($mail['message'], ENT_QUOTES, 'UTF-8');
            $this->assertStringContainsString('€499.00', $body, 'The subscription amount is shown in the subscription currency');
            $this->assertStringNotContainsString('$499.00', $body, 'The parent order currency is not applied to the subscription amount');
        }
    }

    public function test_failure_email_previews_render_the_renewal_summary(): void
    {
        foreach (['customer', 'admin'] as $recipient) {
            $data = (new EmailPreviewService())->getPreviewData('subscription.renewal_failed.' . $recipient);
            $data['subscription']->recurring_total = 49900;
            $notification = EmailNotifications::getNotification('subscription_renewal_failed_' . $recipient);
            $formatted = EmailNotifications::formatNotification($notification, $data);

            $this->assertStringContainsString('499.00', $formatted['body'], 'The preview renders its sample subscription amount');
            $this->assertStringContainsString('Renewal Summary', $formatted['body'], 'The preview includes the new summary');
        }
    }
}
