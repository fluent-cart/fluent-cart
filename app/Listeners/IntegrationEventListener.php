<?php

namespace FluentCart\App\Listeners;

use FluentCart\App\App;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ScheduledAction;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;

class IntegrationEventListener
{

    protected $pushedIntegrationsCache = [];

    public function registerHooks()
    {
        $hooks = [
            'order_paid_done',
            'order_status_changed_to_canceled',
            'order_fully_refunded',
            'subscription_activated',
            'subscription_canceled',
            'subscription_renewed',
            'subscription_eot',
            'subscription_expired_validity',
            'shipping_status_changed_to_shipped',
            'shipping_status_changed_to_delivered'
        ];

        foreach ($hooks as $hook) {
            add_action('fluent_cart/' . $hook, function ($data) use ($hook) {
                $this->mapAllIntegrationActions($data, $hook);
            }, 11, 1);
        }

        add_action('fluent_cart/init_order_async_runner', [$this, 'initOrderAsyncRunner'], 10, 1);
        add_action('fluent_cart/after_receipt_first_time', [$this, 'addJsToReceipt'], 10, 1);

        add_action('wp_ajax_fluent_cart_run_order_actions', [$this, 'runOrderActionsAjax'], 10, 1);
        add_action('wp_ajax_nopriv_fluent_cart_run_order_actions', [$this, 'runOrderActionsAjax'], 10, 1);

    }

    public function mapAllIntegrationActions($data, $hook)
    {
        $order = Arr::get($data, 'order', null);
        if (!$order) {
            return;
        }

        $addOns = apply_filters('fluent_cart/integration/order_integrations', []);

        $addOns = array_filter($addOns, function ($addon) {
            return Arr::get($addon, 'enabled', false);
        });

        if (!$addOns) {
            return;
        }

        $validFeeds = [];

        $productIds = [];
        $variantIds = [];

        foreach ($order->order_items as $item) {
            $productIds[] = $item->post_id;
            $variantIds[] = $item->object_id;
        }

        $productIds = array_filter(array_unique($productIds));
        $variantIds = array_filter(array_unique($variantIds));

        if (!empty($productIds)) {
            $productBasedFeeds = ProductMeta::query()->whereIn('object_id', $productIds)
                ->where('object_type', 'product_integration')
                ->get();

            foreach ($productBasedFeeds as $feed) {
                $formatted = $this->formatIntegrationFeed($feed, $hook, $addOns, 'product', $order->id);
                if ($formatted) {
                    $targetVariationIds = array_filter((array)Arr::get($formatted, 'feed.conditional_variation_ids', []));
                    if ($targetVariationIds && !array_intersect($targetVariationIds, $variantIds)) {
                        continue;
                    }

                    $validFeeds[] = $formatted;
                }
            }
        }

        // sort valid feeds by priority
        if ($validFeeds) {
            usort($validFeeds, function ($a, $b) {
                return $a['priority'] <=> $b['priority'];
            });
        }

        // get global feeds
        $globalIntegrations = Meta::query()->whereIn('object_type', ['order_integration'])
            ->get();

        $globalValidFeeds = [];
        foreach ($globalIntegrations as $feed) {
            $formatted = $this->formatIntegrationFeed($feed, $hook, $addOns, 'global', $order->id);
            if (!$formatted) {
                continue;
            }
            $globalValidFeeds[] = $formatted;
        }

        if ($globalValidFeeds) {
            usort($globalValidFeeds, function ($a, $b) {
                return $a['priority'] <=> $b['priority'];
            });
        }

        // merge both valid feeds
        $validFeeds = array_merge($validFeeds, $globalValidFeeds);
        if (empty($validFeeds)) {
            return;
        }

        // The reason, we want to do this, we don't want to run the same integration feed multiple times
        // in the single request.
        // For example, if the same integration feed is set to run on multiple hooks, we
        // don't want to run it multiple times in the same request.
        // So, we will check if the integration feed is already run for this hook.
        foreach ($validFeeds as $index => $validFeed) {
            $uuid = $validFeed['uuid'] ?? null;
            if ($uuid) {
                if (isset($this->pushedIntegrationsCache[$uuid])) {
                    unset($validFeeds[$index]);
                }
                $this->pushedIntegrationsCache[$uuid] = true;
            }
        }

        if ($hook == 'order_paid_done') {
            $realTimeActions = $validFeeds;
            $backgroundActions = [];
        } else if (apply_filters('fluent_cart/integration/run_all_actions_on_async', false, ['order' => $order, 'hook' => $order])) {
            $backgroundActions = $validFeeds;
            $realTimeActions = [];
        } else {
            $realTimeActions = array_filter($validFeeds, function ($feed) {
                return !$feed['async'];
            });

            $backgroundActions = array_filter($validFeeds, function ($feed) {
                return $feed['async'];
            });
        }

        if ($backgroundActions) {
            $actions = [];

            foreach ($backgroundActions as $feed) {
                $actions[] = Arr::only($feed, ['integration_id', 'priority', 'scope', 'is_revoke_hook']);
            }

            $backgroundData = [
                'scheduled_at' => current_time('mysql'),
                'action'       => 'fluent_cart/integration/run_order_actions',
                'status'       => 'pending',
                'group'        => 'order_integration',
                'object_id'    => $order->id,
                'object_type'  => 'order',
                'data'         => [
                    'hook'    => $hook,
                    'actions' => $actions,
                ]
            ];

            $scheduledAction = ScheduledAction::query()->create($backgroundData);
            as_enqueue_async_action('fluent_cart/init_order_async_runner', [$scheduledAction->id], 'fluent-cart');
        }

        if (!$realTimeActions) {
            return;
        }

        $order = Order::with(['order_items', 'customer', 'shipping_address', 'billing_address'])
            ->find($order->id);

        if (!$order) {
            return;
        }

        $data = (array)$data;
        $data['order'] = $order;
        if ($order->type === 'subscription' || $order->type === 'renewal') {
            $subscription = Subscription::query()->where('parent_order_id', $order->id)->first();
            if ($subscription) {
                $data['subscription'] = $subscription;
            }
        }

        // trigger all valid feeds
        foreach ($realTimeActions as $integrationArray) {
            $integrationArray['order'] = $order;
            $integrationArray['event_data'] = $data;

            try {
                do_action('fluent_cart/integration/run/' . $integrationArray['provider'], $integrationArray);
            } catch (\Exception $e) {
                $order->addLog('Integration Error: ' . $integrationArray['provider'], $e->getMessage(), 'error');
            }
        }
    }

    public function initOrderAsyncRunner($actionId)
    {
        $action = ScheduledAction::query()->where('id', $actionId)
            ->first();

        if (!$action || $action->action !== 'fluent_cart/integration/run_order_actions' || $action->status !== 'pending') {
            return;
        }

        $action->status = 'running';
        $action->save();

        $srcHook = Arr::get($action->data, 'hook', '');
        $integrationActions = Arr::get($action->data, 'actions', []);

        $globalIds = array_map(function ($integrationAction) {
            if ($integrationAction['scope'] !== 'global') {
                return null;
            }
            return Arr::get($integrationAction, 'integration_id');
        }, $integrationActions);

        $globalIds = array_filter($globalIds);

        $productBasedIds = array_map(function ($action) {
            if ($action['scope'] !== 'product') {
                return null;
            }
            return Arr::get($action, 'integration_id');
        }, $integrationActions);

        $formattedIntegrationActions = [];
        $addOns = apply_filters('fluent_cart/integration/order_integrations', []);
        $addOns = array_filter($addOns, function ($addon) {
            return Arr::get($addon, 'enabled', false);
        });

        if ($productBasedIds) {
            $productActions = ProductMeta::query()
                ->whereIn('id', $productBasedIds)
                ->where('object_type', 'product_integration')
                ->get();

            foreach ($productActions as $productAction) {
                $formatted = $this->formatIntegrationFeed($productAction, $srcHook, $addOns, 'product', $action->object_id);
                if ($formatted) {
                    $formattedIntegrationActions[] = $formatted;
                }
            }
        }

        if ($globalIds) {
            $globalActions = Meta::query()
                ->whereIn('id', $globalIds)
                ->where('object_type', 'order_integration')
                ->get();

            foreach ($globalActions as $globalAction) {
                $formatted = $this->formatIntegrationFeed($globalAction, $srcHook, $addOns, 'global');
                if ($formatted) {
                    $formattedIntegrationActions[] = $formatted;
                }
            }
        }

        if (!$formattedIntegrationActions) {
            return;
        }

        $order = Order::with(['order_items', 'customer', 'shipping_address', 'billing_address'])
            ->find($action->object_id);

        $eventData = [
            'order'    => $order,
            'customer' => $order->customer,
        ];

        // Let's fire the events now!
        if ($order->type === 'subscription' || $order->type === 'renewal') {
            $subscription = Subscription::query()->where('parent_order_id', $order->id)->first();
            if ($subscription) {
                $eventData['subscription'] = $subscription;
            }
        }

        foreach ($formattedIntegrationActions as $integrationArray) {
            $integrationArray['order'] = $order;
            $integrationArray['event_data'] = $eventData;
            try {
                do_action('fluent_cart/integration/run/' . $integrationArray['provider'], $integrationArray);
            } catch (\Exception $e) {
                $order->addLog('Integration Error: ' . $integrationArray['provider'], $e->getMessage(), 'error');
            }
        }

        $action->status = 'completed';
        $action->response_note = 'Integration actions completed successfully.';
        $action->completed_at = current_time('mysql');
        $action->save();
    }

    public function addJsToReceipt($data)
    {
        $order = Arr::get($data, 'order', null);
        if (!$order) {
            return null;
        }

        $actionUrl = add_query_arg([
            'action'     => 'fluent_cart_run_order_actions',
            'order_hash' => $order->uuid,
        ], admin_url('admin-ajax.php'));

        ?>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function () {
                var actionUrl = '<?php echo esc_url_raw($actionUrl); ?>';
                // send a get post request to the action URL. With Javascriptip XHR
                var xhr = new XMLHttpRequest();
                xhr.open('POST', actionUrl, true);
                xhr.setRequestHeader('Content-Type', 'application/json;charset=UTF-8');
                xhr.send();
            });
        </script>
        <?php
    }

    public function runOrderActionsAjax()
    {
        $orderHash = App::request()->get('order_hash');
        if (!$orderHash) {
            return wp_send_json([
                'message' => __('Order hash is required', 'fluent-cart')
            ]);
        }

        $order = Order::query()->where('uuid', $orderHash)->first();

        if (!$order) {
            return wp_send_json([
                'message' => __('Order not found', 'fluent-cart')
            ]);
        }

        if ($order->type != 'renewal') {
            do_action('fluent_cart/order_paid_ansyc_private_handle', [
                'order_id' => $order->id
            ]);
        }

        $action = ScheduledAction::query()->where('object_id', $order->id)
            ->where('object_type', 'order')
            ->where('action', 'fluent_cart/integration/run_order_actions')
            ->first();

        if (!$action || $action->status !== 'pending') {
            return wp_send_json([
                'message' => __('No pending actions found for this order', 'fluent-cart')
            ]);
        }

        $this->initOrderAsyncRunner($action->id);

        return wp_send_json([
            'message' => __('Integration actions are being processed', 'fluent-cart')
        ]);
    }

    private function formatIntegrationFeed($feed, $hook, $addOns = null, $scope = 'product', $orderId = null)
    {
        if (!$addOns) {
            $addOns = apply_filters('fluent_cart/integration/order_integrations', []);
        }

        $revokedHooks = [
            'subscription_expired_validity',
            'order_fully_refunded',
            'order_status_changed_to_canceled'
        ];

        $isRevokeHook = in_array($hook, $revokedHooks);
        $integration = $feed->meta_value;
        $enabled = Arr::get($integration, 'enabled') === 'yes';
        $provider = $feed->meta_key;

        if (!$enabled || !Arr::get($addOns, $provider . '.enabled')) {
            return null;
        }


        $watchingEvents = Arr::get($integration, 'event_trigger', []);
        $watchingOnRevoke = Arr::get($integration, 'watch_on_access_revoke', '') === 'yes';
        $willFireRevokeHook = $watchingOnRevoke && $isRevokeHook;

        if (!$willFireRevokeHook && !in_array($hook, $watchingEvents)) {
            return null;
        }

        return [
            'uuid'           => $scope . '_' . $feed->id . '_' . $orderId,
            'priority'       => Arr::get($addOns, $provider . '.priority', 1),
            'integration_id' => $feed->id,
            'scope'          => $scope,
            'provider'       => $provider,
            'trigger'        => $hook,
            'is_revoke_hook' => $willFireRevokeHook ? 'yes' : 'no',
            'feed'           => $integration,
            'async'          => Arr::get($addOns, $provider . '.delay_on_' . $scope . '_action', false)
        ];
    }

}
