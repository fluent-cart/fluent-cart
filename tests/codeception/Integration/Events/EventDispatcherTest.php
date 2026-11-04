<?php

namespace Tests\Integration\Events;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Events\Order\OrderCreated;
use FluentCart\App\Listeners\Activity;
use FluentCart\App\Models\Activity as ActivityModel;
use FluentCart\App\Models\Order;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

class EventDispatcherTest extends WPTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
    }

    // Spec 1: Activity always appended as last listener
    public function test_dispatch_always_invokes_activity_listener(): void
    {
        $before = ActivityModel::query()->count();

        $event = new FakeEvent();
        $event->activityInfo = ['title' => 'Fake Dispatch Sentinel'];
        $event->dispatch();

        $this->assertSame([FakeListenerA::class, FakeListenerB::class], $event->executedListeners);
        $log = ActivityModel::query()
            ->where('title', 'Fake Dispatch Sentinel')
            ->orderBy('id', 'DESC')
            ->first();
        $this->assertNotNull($log, 'Activity listener must append and create log row');
        $this->assertSame($before + 1, ActivityModel::query()->count());
    }

    // Spec 2: beforeDispatch called before listeners
    public function test_dispatch_calls_before_dispatch_before_listeners(): void
    {
        $event = new FakeEvent();
        $event->dispatch();

        $this->assertNotNull($event->beforeAt);
        $this->assertNotEmpty($event->listenerTimes);
        $this->assertLessThan($event->listenerTimes[0], $event->beforeAt);
    }

    // Spec 3: afterDispatch called after listeners
    public function test_dispatch_calls_after_dispatch_after_listeners(): void
    {
        $event = new FakeEvent();
        $event->dispatch();

        $this->assertNotNull($event->afterAt);
        $this->assertNotEmpty($event->listenerTimes);
        $this->assertGreaterThan(end($event->listenerTimes), $event->afterAt);
    }

    // Spec 4: do_action fires when autoFireHook=true and hook set
    public function test_dispatch_fires_do_action_when_auto_fire_true(): void
    {
        $fired = [];
        add_action('fluent_cart/fake_event_hook', function ($payload) use (&$fired) {
            $fired[] = $payload;
        });

        $event = new FakeEvent();
        $event->hook = 'fluent_cart/fake_event_hook';
        $event->autoFireHook = true;
        $event->dispatch();

        $this->assertCount(1, $fired);
        $this->assertSame(['ping' => 'pong'], $fired[0]);
    }

    // Spec 5: do_action skipped when autoFireHook=false
    public function test_dispatch_skips_do_action_when_auto_fire_false(): void
    {
        $fired = false;
        add_action('fluent_cart/fake_event_hook_2', function () use (&$fired) {
            $fired = true;
        });

        $event = new FakeEvent();
        $event->hook = 'fluent_cart/fake_event_hook_2';
        $event->autoFireHook = false;
        $event->dispatch();

        $this->assertFalse($fired);
    }

    // Spec 6: do_action skipped when hook empty
    public function test_dispatch_skips_do_action_when_hook_empty(): void
    {
        // Prove via: no exception when hook=''; afterDispatch runs
        $event = new FakeEvent();
        $event->hook = '';
        $event->autoFireHook = true;
        $firedBefore = did_action('');
        $event->dispatch();

        $this->assertNotNull($event->afterAt, 'afterDispatch still runs when the hook name is empty');
        $this->assertSame($firedBefore, did_action(''), 'no action fires for an empty hook name');
    }

    // Spec 7: shouldCreateActivity default true
    public function test_should_create_activity_default_true(): void
    {
        $event = new FakeEvent();
        $this->assertTrue($event->shouldCreateActivity());
    }

    // Spec 8: getEventInfoForActivity default empty
    public function test_get_event_info_for_activity_default_empty(): void
    {
        $event = new FakeEvent();
        $this->assertSame([], $event->getEventInfoForActivity());
    }

    // Spec 9: Activity no-ops when shouldCreateActivity=false
    public function test_activity_skips_log_when_should_create_activity_false(): void
    {
        $before = ActivityModel::query()->count();

        $event = new FakeEvent();
        $event->createActivity = false;
        $event->dispatch();

        $this->assertSame($before, ActivityModel::query()->count());
    }

    // Spec 10: Activity writes module_type and module_id from event model
    public function test_activity_writes_module_type_and_id_from_event_model(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        (new OrderCreated($order, null, $customer))->dispatch();

        $log = ActivityModel::query()
            ->where('module_type', Order::class)
            ->where('module_id', $order->id)
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(Order::class, $log->module_type);
        $this->assertSame($order->id, (int) $log->module_id);
    }

    // Spec 11: Activity title from humanized short class name
    public function test_activity_title_is_humanized_event_short_name(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        (new OrderCreated($order, null, $customer))->dispatch();

        $log = ActivityModel::query()
            ->where('module_type', Order::class)
            ->where('module_id', $order->id)
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertSame('Order Created', $log->title);
    }

    // Spec 12: getEventInfoForActivity overrides title/content
    public function test_activity_uses_overrides_from_get_event_info(): void
    {
        $event = new FakeEvent();
        $event->activityInfo = [
            'title'   => 'Custom Title',
            'content' => 'Custom Content',
            'status'  => 'info',
        ];
        $event->dispatch();

        $log = ActivityModel::query()
            ->where('title', 'Custom Title')
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Custom Title', $log->title);
        $this->assertSame('Custom Content', $log->content);
        $this->assertSame('info', $log->status);
    }

    // Spec 13: Activity records user_id and created_by from current user
    public function test_activity_records_current_user_id_and_created_by(): void
    {
        $userId = static::factory()->user->create([
            'role'         => 'administrator',
            'display_name' => 'Event Bot',
        ]);
        wp_set_current_user($userId);

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        (new OrderCreated($order, null, $customer))->dispatch();

        $log = ActivityModel::query()
            ->where('module_type', Order::class)
            ->where('module_id', $order->id)
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertSame($userId, (int) $log->user_id);
        $this->assertSame('Event Bot', $log->created_by);
    }
}

class FakeEvent extends EventDispatcher
{
    public string $hook = 'fluent_cart/fake_event';
    public bool $autoFireHook = false;
    public array $executedListeners = [];
    public array $listenerTimes = [];
    public ?float $beforeAt = null;
    public ?float $afterAt = null;
    public bool $createActivity = true;
    public array $activityInfo = [];

    protected array $listeners = [
        FakeListenerA::class,
        FakeListenerB::class,
    ];

    public function beforeDispatch()
    {
        $this->beforeAt = microtime(true);
        usleep(1);
    }

    public function afterDispatch()
    {
        usleep(1);
        $this->afterAt = microtime(true);
    }

    public function toArray(): array
    {
        return ['ping' => 'pong'];
    }

    public function getActivityEventModel()
    {
        return null;
    }

    public function shouldCreateActivity(): bool
    {
        return $this->createActivity;
    }

    public function getEventInfoForActivity(): array
    {
        return $this->activityInfo;
    }
}

class FakeListenerA
{
    public static function handle($event)
    {
        usleep(1);
        $event->executedListeners[] = self::class;
        $event->listenerTimes[] = microtime(true);
    }
}

class FakeListenerB
{
    public static function handle($event)
    {
        usleep(1);
        $event->executedListeners[] = self::class;
        $event->listenerTimes[] = microtime(true);
    }
}
