<?php

namespace FluentCart\App\Modules\Integrations;
use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Hooks\Scheduler\JobRunner;
use FluentCart\App\Hooks\Scheduler\Scheduler;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ScheduledAction;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;

class GlobalNotificationHandler
{
    protected static $customerCache = [];
    protected static $transactionCache = [];
    protected static $orderCache = [];

    protected function getCustomer($customerId)
    {
        if (isset(static::$customerCache[$customerId])) {
            return static::$customerCache[$customerId];
        }

        $customer = Customer::query()->find($customerId);
        if ($customer) {
            static::$customerCache[$customerId] = $customer;
        }

        return $customer;
    }

    protected function getTransaction($orderId)
    {
        if (isset(static::$transactionCache[$orderId])) {
            return static::$transactionCache[$orderId];
        }

        $transaction = OrderTransaction::query()
            ->where('order_id', $orderId)
            ->orderBy('id', 'desc')
            ->first();

        if ($transaction) {
            static::$transactionCache[$orderId] = $transaction;
        }

        return $transaction;
    }


    protected function getOrder($orderId)
    {
        if (isset(static::$orderCache[$orderId])) {
            return static::$orderCache[$orderId];
        }

        $order = Order::query()->find($orderId);
        if ($order) {
            static::$orderCache[$order->id] = $order;
        }

        return $order;
    }

    public function maybeHandleGlobalNotify($order, $customer, $targetHook, $group): void
    {
        //active feeds for orders
        $feeds = (new GlobalIntegrationSettings())->getNotificationFeeds();
        $this->triggerNotification($feeds, $order, $customer, $targetHook, $group);
    }

    public function triggerNotification($feeds, $order, $customer, $targetHook = '', $group = 'integration', $object_type = 'order_integration'): void
    {
        //trigger notification for each active feed
        $asyncFeeds = [];
        foreach ($feeds as $key => $feed) {
            if (isset($feed['enabled']) && (bool)$feed['enabled']) {
                $enabledTriggers = Arr::get($feed, 'feed.event_trigger', []);
                if (!$enabledTriggers || !in_array($targetHook, $enabledTriggers)) {
                    continue;
                }

                $feed = $this->processFeedData($order, $feed);

                $feedKey = Arr::get($feed, 'provider');
                if (!empty($feedKey)){
                    $action = 'fluent_cart/integration/integration_notify_' . $feedKey;
                    $scheduleActionData = [
                        'action'     => $action,
                        'object_id'    => $order['id'],
                        'object_type'  => $object_type,
                        'scheduled_at' => DateTime::gmtNow(),
                        'group'        => $group,
                        'data'         => json_encode([
                           'feed_id' => $feed['id']
                        ])
                    ];

                    if (apply_filters('fluent_cart/integration/notifying_async_' . $feedKey, true)) {
                        $asyncFeeds[] = $scheduleActionData;
                        $hook = 'fluent_cart/integration/schedule_feed';
                        (new JobRunner())->async($hook, $scheduleActionData);
                    } else {
                        do_action(
                            'fluent_cart/integration/integration_notify_' . $feedKey,
                            $feed,
                            $order,
                            $customer
                        );
                    }
                }                
            }
        }

        if (!$asyncFeeds) {
            do_action('fluent_cart/integrations/global_notify_completed', $order, $feeds);
        }
    }

    public function handleGlobalIntegration($action, $feed, $order, $customer, $transaction): void
    {
        $formatFeed = (new GlobalIntegrationSettings())->formatFeedData($feed);
        $parsedFeedData = $this->processFeedData($order, $formatFeed);
        do_action($action, $parsedFeedData, $order, $customer, $transaction);
    }

    protected function getFeed($queue, $feedId)
    {
        $query = $queue->object_type === 'order_integration'
            ? Meta::query()->where('id', $feedId)
            : ProductMeta::query()->where('id', $feedId);

        return $query->where('object_type', $queue->object_type)->first();
    }

    public function handleGlobalAsyncIntegration($queue): void
    {
        $queueData = is_string($queue->data) ? json_decode($queue->data) : $queue->data;

        if (!isset($queueData->feed_id)) {
            return;
        }

        $feed = $this->getFeed($queue, $queueData->feed_id);
        if (!$feed) {
            return;
        }
        $order = $this->getOrder($queue->object_id);
        if (!$order) {
            return;
        }
        $customer = $this->getCustomer($order->customer_id);
        $transaction = $this->getTransaction($order->id);

        ScheduledAction::query()->where('id', $queue->id)->update([
            'status' => Status::SCHEDULE_PROCESSING,
            'retry_count' => $queue->retry_count + 1,
            'scheduled_at' => DateTime::gmtNow()
        ]);

        $this->processNotification($queue->action, $feed, $order, $customer, $transaction);

        // complete the queue
        ScheduledAction::query()->where('id', $queue->id)->update([
            'status' => Status::SCHEDULE_COMPLETED,
        ]);
    }

    /**
     * Process notification through global integration manager
     *
     * @param string $action
     * @param Meta|ProductMeta $feed
     * @param Order $order
     * @param Customer|null $customer
     * @param OrderTransaction|null $transaction
     * @return void
     */
    protected function processNotification(string $action, $feed, Order $order, ?Customer $customer, ?OrderTransaction $transaction): void
    {
        $globalNotification = new GlobalNotificationHandler();
        $globalNotification->handleGlobalIntegration(
            $action, $feed, $order, $customer, $transaction
        );
    }

    public function processFeedData($order, $feed)
    {
        $userData = [
            'first_name'  => Arr::get($order, 'customer.first_name'),
            'last_name' => Arr::get($order, 'customer.last_name'),
            'email' => Arr::get($order, 'customer.email'),
            'phone' => Arr::get($order, 'customer.phone'),
            'country' => Arr::get($order, 'customer.country'),
            'city' => Arr::get($order, 'customer.city'),
            'state' => Arr::get($order, 'customer.state'),
            'postcode' => Arr::get($order, 'customer.postcode'),
        ];
        $feedDataUpdated = array_merge($feed['feed'], $userData);

        $feed['feed'] = $feedDataUpdated;
        return $feed;
    }

    public function processIntegrationAction($queueId): void
    {
        $queue = ScheduledAction::query()->find($queueId);
        if (!$queue || empty($queue->action)) {
            return;
        }
        $this->handleGlobalAsyncIntegration($queue);
    }

}