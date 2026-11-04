<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\StoreManagedRenewal\Services\RenewalService;
use FluentCart\App\Services\Email\EmailNotifications;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeSystemGateway;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\Traits\ConfiguresStoreOrderMode;

/**
 * SCF-01: the upcoming automatic charge email — registration, one-send-per-invoice
 * on system invoice creation, manual-invoice exclusion, and filter suppression.
 */
class UpcomingChargeEmailTest extends DatabaseTestCase
{
    use ConfiguresStoreOrderMode;

    private CustomerFactory $customerFactory;
    private ProductFactory $productFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;
    private OrderTransactionFactory $txnFactory;
    private SubscriptionFactory $subscriptionFactory;

    /** @var array Captured wp_mail payloads */
    private array $capturedMail = [];

    /** @var callable */
    private $mailInterceptor;

    public function setUp(): void
    {
        parent::setUp();

        $this->customerFactory = new CustomerFactory();
        $this->productFactory = new ProductFactory();
        $this->orderFactory = new OrderFactory();
        $this->itemFactory = new OrderItemFactory();
        $this->txnFactory = new OrderTransactionFactory();
        $this->subscriptionFactory = new SubscriptionFactory();

        FakeSystemGateway::register();

        // OrderFactory creates live-mode orders; renewals only run when the
        // store mode matches.
        $this->setStoreOrderMode('live');

        $this->capturedMail = [];
        $this->mailInterceptor = function ($null, $atts) {
            $this->capturedMail[] = $atts;
            return true; // short-circuit real sending
        };
        add_filter('pre_wp_mail', $this->mailInterceptor, 10, 2);
    }

    public function tearDown(): void
    {
        remove_filter('pre_wp_mail', $this->mailInterceptor, 10);
        FakeSystemGateway::remove();
        parent::tearDown();
    }

    private function makeSubscriptionSetup(string $collectionMethod, string $email): array
    {
        $customer = $this->customerFactory->create(['email' => $email]);
        $product = $this->productFactory
            ->withVariantAttributes(['payment_type' => 'subscription'])
            ->create();
        $variant = $product->variants->first();

        $order = $this->orderFactory->withAddresses()->create([
            'customer_id'    => $customer->id,
            'type'           => 'subscription',
            'status'         => 'completed',
            'payment_status' => 'paid',
            'payment_method' => FakeSystemGateway::SLUG,
        ]);

        $this->itemFactory->create($order->id, [
            'post_id'      => $product->ID,
            'object_id'    => $variant->id,
            'payment_type' => 'subscription',
        ]);

        $sub = $this->subscriptionFactory->create([
            'customer_id'            => $customer->id,
            'parent_order_id'        => $order->id,
            'product_id'             => $product->ID,
            'variation_id'           => $variant->id,
            'collection_method'      => $collectionMethod,
            'current_payment_method' => FakeSystemGateway::SLUG,
            'next_billing_date'      => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ]);

        $sub->updateMeta('active_payment_method', [
            'vendor_method_id' => 'pm_fake',
            'method'           => 'card',
            'details'          => ['brand' => 'visa', 'last_4' => '4242', 'payment_method_id' => 'pm_fake'],
        ]);

        return ['customer' => $customer, 'order' => $order, 'sub' => $sub];
    }

    private function upcomingChargeMails(): array
    {
        return array_values(array_filter($this->capturedMail, function ($mail) {
            return strpos((string) ($mail['subject'] ?? ''), 'Upcoming automatic payment') !== false;
        }));
    }

    // =========================================================================
    // Registration
    // =========================================================================

    public function test_notification_registered_default_on_with_resolvable_template(): void
    {
        $notifications = EmailNotifications::getNotifications();

        $this->assertArrayHasKey('system_upcoming_charge_customer', $notifications);
        $entry = $notifications['system_upcoming_charge_customer'];

        $this->assertSame('system_upcoming_charge', $entry['event']);
        $this->assertSame('system_subscription', $entry['group']);
        $this->assertSame('customer', $entry['recipient']);
        $this->assertSame('yes', $entry['settings']['active']);

        $templateFile = FLUENTCART_PLUGIN_PATH
            . 'app/Views/emails/' . str_replace('.', '/', $entry['template_path']) . '.php';
        $this->assertFileExists($templateFile);
    }

    // =========================================================================
    // One notice per system invoice, with the promised content
    // =========================================================================

    public function test_system_invoice_creation_sends_exactly_one_notice_with_amount_and_card(): void
    {
        ['sub' => $sub, 'customer' => $customer] = $this->makeSubscriptionSetup('system', 'scf4-upcoming@example.test');

        $invoices = RenewalService::createRenewalOrders(Subscription::query()->find($sub->id));
        $this->assertFalse(is_wp_error($invoices));

        $notices = $this->upcomingChargeMails();
        $this->assertCount(1, $notices, 'exactly one upcoming-charge email per system invoice');

        $mail = $notices[0];
        $this->assertStringContainsString($customer->email, implode(',', (array) $mail['to']));

        $body = (string) $mail['message'];
        $this->assertStringContainsString('29.99', $body, 'body must state the charge amount');
        $this->assertStringContainsString('visa ***4242', $body, 'body must state the saved card');
        $this->assertStringContainsString($sub->item_name, $body);
        $this->assertStringNotContainsString('days from now', $body, 'copy must state a date, never a countdown');
    }

    public function test_manual_invoice_does_not_send_the_notice(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup('manual', 'scf4-manual@example.test');

        $invoices = RenewalService::createRenewalOrders(Subscription::query()->find($sub->id));
        $this->assertFalse(is_wp_error($invoices));
        $this->assertSame('pending', $invoices[0]->payment_status);

        $this->assertCount(0, $this->upcomingChargeMails(), 'manual invoices keep the pay-now flow only');
        $this->assertNotEmpty($this->capturedMail, 'the manual invoice still sends its own pay-now email');
    }

    // =========================================================================
    // Suppression filter
    // =========================================================================

    public function test_suppression_filter_blocks_the_notice(): void
    {
        ['sub' => $sub] = $this->makeSubscriptionSetup('system', 'scf4-suppressed@example.test');

        add_filter('fluent_cart/subscriptions/upcoming_charge_notification', '__return_false');
        $invoices = RenewalService::createRenewalOrders(Subscription::query()->find($sub->id));
        remove_filter('fluent_cart/subscriptions/upcoming_charge_notification', '__return_false');

        $this->assertFalse(is_wp_error($invoices));
        $this->assertSame('payment_scheduled', $invoices[0]->payment_status, 'invoice creation itself is unaffected');
        $this->assertCount(0, $this->upcomingChargeMails());
    }
}
