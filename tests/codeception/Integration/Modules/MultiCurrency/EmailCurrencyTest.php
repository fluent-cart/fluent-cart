<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Services\Email\EmailNotificationMailer;
use FluentCart\App\Services\Email\EmailNotifications;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\EmailNotificationConfigFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * A whole email renders in its order's currency (MC-KI-04): the default
 * body, the order header and the smartcodes, whether it is sent from a
 * storefront request in another currency or from cron / CLI. Base USD.
 */
class EmailCurrencyTest extends DatabaseTestCase
{
    private EmailNotificationConfigFactory $configFactory;
    private array $sentMail = [];
    private \Closure $mailCapture;

    public function setUp(): void
    {
        parent::setUp();
        $this->configFactory = new EmailNotificationConfigFactory();
        $this->configFactory->reset();
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'is_default_body' => 'yes',
            'active'          => 'yes',
        ]);

        $this->sentMail = [];
        $this->mailCapture = function ($null, array $atts) {
            $this->sentMail[] = $atts;
            return true;
        };
        add_filter('pre_wp_mail', $this->mailCapture, 10, 2);
    }

    public function tearDown(): void
    {
        remove_filter('pre_wp_mail', $this->mailCapture, 10);
        $this->configFactory->reset();
        parent::tearDown();
    }

    public function test_a_default_body_email_sent_from_a_back_office_context_uses_the_orders_currency(): void
    {
        $order = $this->gbpOrder();

        (new EmailNotificationMailer())->mailEmailsOfEvent('order_paid', ['order' => $order, 'customer' => $order->customer]);

        $this->assertCount(1, $this->sentMail);
        $message = html_entity_decode($this->sentMail[0]['message']);
        $this->assertStringContainsString('£39.95', $message);
        $this->assertStringNotContainsString('$39.95', $message);
        $this->assertSame('USD', CurrencyContext::current(), 'the order currency applies only while rendering');
    }

    public function test_a_default_body_email_sent_while_the_visitor_browses_in_jpy_uses_the_orders_currency(): void
    {
        $order = $this->gbpOrder();

        CurrencyContext::withCurrency('JPY', function () use ($order) {
            (new EmailNotificationMailer())->mailEmailsOfEvent('order_paid', ['order' => $order, 'customer' => $order->customer]);
        });

        $this->assertCount(1, $this->sentMail);
        $message = html_entity_decode($this->sentMail[0]['message']);
        $this->assertStringContainsString('£39.95', $message);
        $this->assertStringNotContainsString('¥', $message);
    }

    public function test_an_async_email_by_name_uses_the_orders_currency(): void
    {
        $order = $this->gbpOrder();

        (new EmailNotificationMailer())->mailByEmailName('order_paid_customer', ['order' => $order, 'customer' => $order->customer]);

        $this->assertCount(1, $this->sentMail);
        $message = html_entity_decode($this->sentMail[0]['message']);
        $this->assertStringContainsString('£39.95', $message);
        $this->assertStringNotContainsString('$39.95', $message);
    }

    public function test_the_test_email_path_renders_the_default_body_in_the_orders_currency(): void
    {
        // The test_email CLI command renders with these two calls directly
        $order = $this->gbpOrder();
        $data = ['order' => $order, 'customer' => $order->customer];

        $notification = EmailNotifications::formatNotification(EmailNotifications::getNotification('order_paid_customer'), $data);
        list($body) = (new EmailNotificationMailer())->parseEmailContent($notification, $data);

        $body = html_entity_decode($body);
        $this->assertStringContainsString('£39.95', $body);
        $this->assertStringNotContainsString('$39.95', $body);
    }

    public function test_a_pro_block_body_is_parsed_in_the_orders_currency(): void
    {
        $order = $this->gbpOrder();
        $seen = null;
        $recorder = function ($parsed) use (&$seen) {
            $seen = CurrencyContext::current();
            return $parsed;
        };
        add_filter('fluent_cart/parse_email_block_content', $recorder, 10, 1);

        try {
            CurrencyContext::withCurrency('JPY', function () use ($order) {
                (new EmailNotificationMailer())->parseEmailContent(
                    ['body' => '<p>block</p>', 'is_custom' => true, 'subject' => 'S', 'to' => 'a@example.com'],
                    ['order' => $order, 'customer' => $order->customer]
                );
            });
        } finally {
            remove_filter('fluent_cart/parse_email_block_content', $recorder, 10);
        }

        $this->assertSame('GBP', $seen);
    }

    public function test_an_email_with_a_subscription_and_no_order_uses_the_subscriptions_currency(): void
    {
        $order = $this->gbpOrder();
        $product = (new ProductFactory())->create();
        $subscription = (new SubscriptionFactory())->create([
            'customer_id'     => $order->customer_id,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => $product->variants->first()->id,
            'currency'        => 'GBP',
        ]);
        $subscription = Subscription::query()->find($subscription->id);

        $seen = CurrencyContext::withCurrencyOf(['subscription' => $subscription, 'order' => null], function () {
            return CurrencyContext::current();
        });

        $this->assertSame('GBP', $seen);
    }

    private function gbpOrder(): Order
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'  => $customer->id,
            'currency'     => 'GBP',
            'payment_status' => 'paid',
            'subtotal'     => 3995,
            'total_amount' => 3995,
            'total_paid'   => 3995,
            'config'       => ['fx' => CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL])],
        ]);
        (new OrderItemFactory())->create($order->id, ['unit_price' => 3995, 'subtotal' => 3995, 'line_total' => 3995]);

        return Order::query()->with(['order_items', 'customer', 'orderTaxRates'])->find($order->id);
    }
}
