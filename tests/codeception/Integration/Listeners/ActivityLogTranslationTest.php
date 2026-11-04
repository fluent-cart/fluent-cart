<?php

namespace Tests\Integration\Listeners;

use FluentCart\App\Events\Order\OrderCreated;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Order;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * Activity entries are stored as rendered text, so they must be translated
 * when written (ticket 3632, finding 18).
 */
class ActivityLogTranslationTest extends WPTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    private array $german = [
        'Order Created' => 'Bestellung erstellt',
        'On Hold'       => 'In Wartestellung',
        'Canceled'      => 'Storniert',
    ];

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        add_filter('gettext', [$this, 'translate'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('gettext', [$this, 'translate'], 10);
        parent::tearDown();
    }

    public function translate($translation, $text, $domain)
    {
        if ($domain === 'fluent-cart' && isset($this->german[$text])) {
            return $this->german[$text];
        }

        return $translation;
    }

    public function test_event_title_is_translated_but_module_name_stays_english(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        (new OrderCreated($order, null, $customer))->dispatch();

        $log = Activity::query()
            ->where('module_type', Order::class)
            ->where('module_id', $order->id)
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Bestellung erstellt', $log->title);
        $this->assertSame('Order', $log->module_name);
    }

    public function test_status_change_message_uses_status_labels_not_keys(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);

        (new StatusHelper($order))->changeOrderStatus(
            Status::ORDER_CANCELED,
            Status::PAYMENT_PENDING,
            $order->payment_method_title,
            $order->payment_method
        );

        $log = Activity::query()
            ->where('module_id', $order->id)
            ->where('title', 'Order status updated')
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Order status has been updated from In Wartestellung to Storniert', $log->content);
    }

    public function test_unknown_status_key_falls_back_to_the_key(): void
    {
        $this->assertSame('Storniert', Status::getOrderStatusLabel(Status::ORDER_CANCELED));
        $this->assertSame('some-custom-status', Status::getOrderStatusLabel('some-custom-status'));
    }
}
