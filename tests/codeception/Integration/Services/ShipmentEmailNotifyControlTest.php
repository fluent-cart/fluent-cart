<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Services\Email\EmailNotifications;
use FluentCart\App\Services\ShipmentService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\EmailNotificationConfigFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * The per-shipment "Notify customer" checkbox is the only switch for the
 * shipment-created email; the store setting cannot turn it off.
 */
class ShipmentEmailNotifyControlTest extends WPTestCase
{
    private const SHIPMENT_SUBJECT = 'Your shipment is on the way #INV-7001 📦';

    private $mails = [];
    private $mailFilter;
    private $configFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->configFactory = new EmailNotificationConfigFactory();
        $this->configFactory->reset();
        $this->mailFilter = function ($null, $atts) {
            $this->mails[] = $atts;
            return true;
        };
        add_filter('pre_wp_mail', $this->mailFilter, PHP_INT_MAX, 2);
    }

    public function tearDown(): void
    {
        remove_filter('pre_wp_mail', $this->mailFilter, PHP_INT_MAX);
        $this->configFactory->reset();
        parent::tearDown();
    }

    private function paidOrder(): Order
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->withAddresses()->withItems([[
            'post_id'            => 0,
            'object_id'          => 0,
            'post_title'         => 'QA Mug',
            'title'              => 'Default',
            'quantity'           => 2,
            'unit_price'         => 1000,
            'subtotal'           => 2000,
            'line_total'         => 2000,
            'payment_type'       => 'onetime',
            'fulfillment_type'   => 'physical',
            'fulfilled_quantity' => 0,
        ]])->create([
            'customer_id'      => $customer->id,
            'fulfillment_type' => 'physical',
            'shipping_status'  => 'unshipped',
            'status'           => 'processing',
            'payment_status'   => 'paid',
        ]);
        Order::query()->where('id', $order->id)->update(['invoice_no' => 'INV-7001']);

        return Order::query()->with(['customer', 'order_items'])->find($order->id);
    }

    private function shipAll(Order $order, string $notify): Shipment
    {
        $item = $order->order_items->first();
        $this->mails = [];

        $shipment = ShipmentService::createShipment(Order::query()->with('customer')->find($order->id), [
            'carrier_slug'    => 'manual',
            'carrier_name'    => 'QA Express',
            'tracking_number' => 'QX101',
            'tracking_url'    => 'https://track.example.test/QX101',
            'notify_customer' => $notify,
            'items'           => [['order_item_id' => $item->id, 'qty' => 2]],
        ]);
        $this->assertInstanceOf(Shipment::class, $shipment, is_wp_error($shipment) ? $shipment->get_error_message() : '');
        $this->assertSame('shipped', Order::query()->find($order->id)->shipping_status, 'the shipment completes the order');

        return $shipment;
    }

    private function sentSubjects(): array
    {
        return array_map(function ($mail) {
            return (string) $mail['subject'];
        }, $this->mails);
    }

    public function test_saved_setting_off_still_sends_the_shipment_email_when_notify_is_ticked(): void
    {
        $this->configFactory->withNotificationConfig('order_shipment_created_customer', ['active' => 'no']);

        $this->shipAll($this->paidOrder(), 'yes');

        $this->assertSame([self::SHIPMENT_SUBJECT], $this->sentSubjects());
        $this->assertStringContainsString('QX101', $this->mails[0]['message']);
    }

    public function test_notify_unticked_sends_no_customer_email(): void
    {
        $this->shipAll($this->paidOrder(), 'no');

        $this->assertSame([], $this->sentSubjects());
    }

    public function test_default_settings_send_one_shipment_email_and_no_shipped_duplicate(): void
    {
        $this->shipAll($this->paidOrder(), 'yes');

        $this->assertSame([self::SHIPMENT_SUBJECT], $this->sentSubjects());
    }

    public function test_shipment_email_has_no_store_level_toggle(): void
    {
        $notification = EmailNotifications::getDefaultNotifications()['order_shipment_created_customer'];

        $this->assertSame('no', $notification['manage_toggle']);
        $this->assertNotEmpty($notification['toggle_label']);
    }

    public function test_shipment_email_is_listed_for_its_event_even_when_saved_off(): void
    {
        $this->configFactory->withNotificationConfig('order_shipment_created_customer', ['active' => 'no']);

        $this->assertContains(
            'order_shipment_created_customer',
            EmailNotifications::activeNotificationNamesOfEvent('order_shipment_created')
        );
    }
}
