<?php
/**
 * Exact-ID Phase 8 fixtures for stock, scheduler, subscription, and licensing.
 *
 * Creation and behavior use real FluentCart/WordPress model entry points.
 * Cleanup is limited to immediately captured primary IDs and verifies exact
 * ownership before model deletion or exact-key related-row cleanup.
 */

use FluentCart\App\Helpers\Status;
use FluentCart\App\Hooks\Scheduler\JobRunner;
use FluentCart\App\Models\ScheduledAction;
use FluentCart\App\Services\Payments\SubscriptionHelper;

class FcPhase8RecordingJobRunner extends JobRunner
{
    /** @var array<int,int> */
    public $selectedIds = [];

    public function runScheduler(ScheduledAction $job): void
    {
        $this->selectedIds[] = (int) $job->id;
    }
}

class FcDomainFixture
{
    /** @var array<string,mixed>|null */
    private static $config = null;

    /** @var array<int,array<string,mixed>> */
    private static $products = [];

    /** @var array<int,array<string,mixed>> */
    private static $productHistory = [];

    /** @var array<int,array<string,mixed>> */
    private static $details = [];

    /** @var array<int,array<string,mixed>> */
    private static $detailHistory = [];

    /** @var array<int,array<string,mixed>> */
    private static $variations = [];

    /** @var array<int,array<string,mixed>> */
    private static $variationHistory = [];

    /** @var array<int,array<string,mixed>> */
    private static $scheduledActions = [];

    /** @var array<int,array<string,mixed>> */
    private static $scheduledHistory = [];

    /** @var array<int,array<string,mixed>> */
    private static $subscriptions = [];

    /** @var array<int,array<string,mixed>> */
    private static $subscriptionHistory = [];

    /** @var array<int,array<string,mixed>> */
    private static $subscriptionMeta = [];

    /** @var array<int,array<string,mixed>> */
    private static $subscriptionMetaHistory = [];

    /** @var array<int,array<string,mixed>> */
    private static $licenses = [];

    /** @var array<int,array<string,mixed>> */
    private static $licenseHistory = [];

    /** @var array<int,array<string,mixed>> */
    private static $activities = [];

    /** @var array<int,array<string,mixed>> */
    private static $activityHistory = [];

    /** @var int|null */
    private static $activityHighWater = null;

    /**
     * Return an exact identity-derived scalar marker.
     *
     * @param string $suffix
     * @return string
     */
    public static function marker($suffix)
    {
        $suffix = preg_replace('/[^a-z0-9-]/', '-', strtolower((string) $suffix));
        if ($suffix === '') {
            throw new InvalidArgumentException('Domain fixture marker suffix cannot be empty.');
        }

        return 'phase8-' . substr(hash('sha256', FcFixture::identity()), 0, 20) . '-' . $suffix;
    }

    /**
     * Create one product post, ProductDetail, and ProductVariation.
     *
     * @param array<string,mixed> $variationAttributes
     * @return array<string,object>
     */
    public static function product(array $variationAttributes = [])
    {
        if (self::$products || self::productMarkerCount() !== 0) {
            throw new LogicException('This process already owns a Phase 8 product fixture.');
        }

        $title = self::marker('product');
        $productClass = self::domainConfig()['product_model_class'];
        $product = $productClass::query()->create([
            'post_title'         => $title,
            'post_name'          => sanitize_title($title),
            'post_status'        => 'publish',
            'post_type'          => self::domainConfig()['product_post_type'],
            'post_content'       => '',
            'post_excerpt'       => '',
            'post_author'        => 0,
            'post_date'          => '2001-02-03 04:05:06',
            'post_date_gmt'      => '2001-02-03 04:05:06',
            'post_modified'      => '2001-02-03 04:05:06',
            'post_modified_gmt'  => '2001-02-03 04:05:06',
            'comment_status'     => 'closed',
            'ping_status'        => 'closed',
            'post_password'      => '',
            'to_ping'            => '',
            'pinged'             => '',
            'post_content_filtered' => '',
            'post_parent'        => 0,
            'menu_order'         => 0,
            'post_mime_type'     => '',
            'guid'               => '',
            'comment_count'      => 0,
        ]);
        $postId = isset($product->ID) ? (int) $product->ID : 0;
        if ($postId <= 0) {
            throw new RuntimeException('Product fixture model create returned no positive ID.');
        }
        self::$products[$postId] = [
            'id'    => $postId,
            'title' => $title,
            'type'  => self::domainConfig()['product_post_type'],
        ];
        self::$productHistory[$postId] = self::$products[$postId];

        $detailClass = self::rowConfig('product_detail')['model_class'];
        $detail = $detailClass::query()->create([
            'post_id'              => $postId,
            'fulfillment_type'     => $variationAttributes['fulfillment_type'] ?? 'digital',
            'min_price'            => $variationAttributes['item_price'] ?? 1000,
            'max_price'            => $variationAttributes['item_price'] ?? 1000,
            'default_variation_id' => 0,
            'variation_type'       => 'simple',
            'stock_availability'   => 'in-stock',
            'other_info'           => [],
            'default_media'        => [],
            'manage_stock'         => 1,
            'manage_downloadable'  => 0,
        ]);
        $detailId = isset($detail->id) ? (int) $detail->id : 0;
        if ($detailId > 0) {
            self::$details[$detailId] = ['id' => $detailId, 'post_id' => $postId];
            self::$detailHistory[$detailId] = self::$details[$detailId];
        }
        if ($detailId <= 0) {
            throw new RuntimeException('ProductDetail fixture create returned no positive ID.');
        }

        $totalStock = isset($variationAttributes['total_stock'])
            ? (int) $variationAttributes['total_stock']
            : 10;
        $defaults = [
            'post_id'              => $postId,
            'media_id'             => 0,
            'serial_index'         => 1,
            'sold_individually'    => 0,
            'variation_title'      => self::marker('variation'),
            'variation_identifier' => self::marker('variation-id'),
            'sku'                  => strtoupper(substr(hash('sha256', FcFixture::identity()), 0, 24)),
            'manage_stock'         => 1,
            'payment_type'         => 'onetime',
            'stock_status'         => $totalStock > 0 ? 'in-stock' : 'out-of-stock',
            'backorders'           => 0,
            'total_stock'          => $totalStock,
            'available'            => $totalStock,
            'committed'            => 0,
            'on_hold'              => 0,
            'fulfillment_type'     => 'digital',
            'item_status'          => 'active',
            'manage_cost'          => 0,
            'item_price'           => 1000,
            'item_cost'            => 0,
            'compare_price'        => 0,
            'other_info'           => [],
            'downloadable'         => 0,
            'shipping_class'       => null,
        ];
        $variationClass = self::rowConfig('product_variation')['model_class'];
        $variation = $variationClass::query()->create(array_merge($defaults, $variationAttributes, [
            'post_id' => $postId,
        ]));
        $variationId = isset($variation->id) ? (int) $variation->id : 0;
        if ($variationId > 0) {
            self::$variations[$variationId] = [
                'id'      => $variationId,
                'post_id' => $postId,
            ];
            self::$variationHistory[$variationId] = self::$variations[$variationId];
        }
        if ($variationId <= 0) {
            throw new RuntimeException('ProductVariation fixture create returned no positive ID.');
        }

        $detail->default_variation_id = $variationId;
        $detail->min_price = $variation->item_price;
        $detail->max_price = $variation->item_price;
        $detail->save();

        return [
            'post'      => get_post($postId),
            'detail'    => $detailClass::query()->find($detailId),
            'variation' => $variationClass::query()->find($variationId),
        ];
    }

    /**
     * Create an owned inert Order with one real OrderItem.
     *
     * @param int                 $productId
     * @param int                 $variationId
     * @param int                 $quantity
     * @param array<string,mixed> $orderAttributes
     * @return object
     */
    public static function orderWithItem($productId, $variationId, $quantity, array $orderAttributes = [])
    {
        if (!self::$products || !isset(self::$variations[(int) $variationId])) {
            throw new LogicException('Create the owned Phase 8 product before its OrderItem.');
        }
        if (!$quantity || (int) $quantity < 1) {
            throw new InvalidArgumentException('Domain OrderItem quantity must be positive.');
        }

        if (!self::ownedCustomerExists()) {
            FcFixture::customer();
        }
        $order = FcFixture::order(array_merge([
            'config' => ['fixture_case' => 'phase8-domain'],
        ], $orderAttributes));
        $variationClass = self::rowConfig('product_variation')['model_class'];
        $variation = $variationClass::query()->find((int) $variationId);
        if (!$variation) {
            throw new RuntimeException('Owned variation disappeared before OrderItem creation.');
        }

        FcFixture::reportOrderItem((int) $order->id, [
            'post_id'          => (int) $productId,
            'object_id'        => (int) $variationId,
            'post_title'       => self::marker('product'),
            'title'            => self::marker('variation'),
            'quantity'         => (int) $quantity,
            'unit_price'       => (int) $variation->item_price,
            'subtotal'         => (int) $variation->item_price * (int) $quantity,
            'line_total'       => (int) $variation->item_price * (int) $quantity,
            'payment_type'     => (string) $variation->payment_type,
            'fulfillment_type' => (string) $variation->fulfillment_type,
        ]);

        return FcFixture::reloadOrder((int) $order->id);
    }

    /**
     * Create one exact ScheduledAction.
     *
     * @param array<string,mixed> $attributes
     * @return object
     */
    public static function scheduledAction(array $attributes = [])
    {
        $class = self::rowConfig('scheduled_action')['model_class'];
        $action = self::marker(
            isset($attributes['action_suffix'])
                ? (string) $attributes['action_suffix']
                : 'scheduled-action'
        );
        unset($attributes['action_suffix']);
        if ($class::query()->where('action', $action)->count() !== 0) {
            throw new RuntimeException('ScheduledAction fixture action already exists.');
        }

        $defaults = [
            'scheduled_at' => '2001-02-03 04:05:06',
            'action'       => $action,
            'status'       => Status::SCHEDULE_PENDING,
            'group'        => self::marker('scheduled-group'),
            'object_id'    => 0,
            'object_type'  => 'phase8-test',
            'completed_at' => null,
            'retry_count'  => 0,
            'data'         => ['fixture_identity' => FcFixture::identity()],
            'response_note'=> '',
        ];
        $row = $class::query()->create(array_merge($defaults, $attributes, [
            'action' => $action,
            'data'   => array_merge(
                $defaults['data'],
                isset($attributes['data']) && is_array($attributes['data'])
                    ? $attributes['data']
                    : []
            ),
        ]));
        $id = isset($row->id) ? (int) $row->id : 0;
        if ($id > 0) {
            self::$scheduledActions[$id] = [
                'id'     => $id,
                'group'  => (string) $row->group,
                'action' => $action,
            ];
            self::$scheduledHistory[$id] = self::$scheduledActions[$id];
        }
        if ($id <= 0) {
            throw new RuntimeException('ScheduledAction fixture create returned no positive ID.');
        }

        return $row;
    }

    /**
     * Enroll an exact owned queue row through JobRunner::addQueue().
     *
     * @param array<string,mixed> $attributes
     * @return object
     */
    public static function jobRunnerQueue(array $attributes = [])
    {
        $class = self::rowConfig('scheduled_action')['model_class'];
        $action = self::marker('job-runner-action');
        if ($class::query()->where('action', $action)->count() !== 0) {
            throw new RuntimeException('JobRunner fixture action already exists.');
        }

        $defaults = [
            'scheduled_at' => '2001-02-03 04:05:06',
            'action'       => $action,
            'group'        => 'integration',
            'object_id'    => 0,
            'object_type'  => 'phase14-test',
            'data'         => wp_json_encode(['fixture_identity' => FcFixture::identity()]),
            'response_note'=> '',
        ];
        $queueData = isset($attributes['data']) ? $attributes['data'] : $defaults['data'];
        if (is_array($queueData)) {
            $queueData = wp_json_encode($queueData);
        }
        $data = array_merge($defaults, $attributes, [
            'action' => $action,
            'data'   => $queueData,
        ]);
        $id = (int) (new JobRunner())->addQueue($data);
        if ($id <= 0) {
            throw new RuntimeException('JobRunner::addQueue returned no positive ID.');
        }

        $row = $class::query()->find($id);
        if (!$row || (string) $row->action !== $action) {
            throw new RuntimeException('JobRunner queue row could not be read back exactly.');
        }
        self::$scheduledActions[$id] = [
            'id'     => $id,
            'group'  => (string) $row->group,
            'action' => $action,
        ];
        self::$scheduledHistory[$id] = self::$scheduledActions[$id];

        return $row;
    }

    /**
     * Create one automatic Subscription eligible for deterministic expiry.
     *
     * @param object $order
     * @param int    $productId
     * @param int    $variationId
     * @param string $status
     * @param string $suffix
     * @param array<string,mixed> $attributes
     * @return object
     */
    public static function subscription(
        $order,
        $productId,
        $variationId,
        $status,
        $suffix,
        array $attributes = []
    )
    {
        $class = self::rowConfig('subscription')['model_class'];
        $vendorId = 'p8s-' . substr(
            hash('sha256', FcFixture::identity() . '|' . (string) $suffix),
            0,
            32
        );
        if ($class::query()->where('vendor_subscription_id', $vendorId)->count() !== 0) {
            throw new RuntimeException('Subscription fixture marker already exists: ' . $vendorId);
        }

        $defaults = [
            'customer_id'           => (int) $order->customer_id,
            'parent_order_id'       => (int) $order->id,
            'product_id'            => (int) $productId,
            'item_name'             => self::marker('subscription-item-' . $suffix),
            'variation_id'          => (int) $variationId,
            'billing_interval'      => 'monthly',
            'signup_fee'            => 0,
            'quantity'              => 1,
            'recurring_amount'      => 1000,
            'recurring_tax_total'   => 0,
            'recurring_total'       => 1000,
            'bill_times'            => 0,
            'bill_count'            => 1,
            'expire_at'             => null,
            'trial_ends_at'         => null,
            'canceled_at'           => $status === Status::SUBSCRIPTION_CANCELED
                ? '2001-02-02 00:00:00'
                : null,
            'restored_at'           => null,
            'collection_method'     => 'automatic',
            'trial_days'            => 0,
            'vendor_customer_id'    => '',
            'vendor_plan_id'        => '',
            'vendor_subscription_id'=> $vendorId,
            'next_billing_date'     => '2001-02-03 04:05:06',
            'status'                => $status,
            'original_plan'         => '[]',
            'vendor_response'       => '[]',
            'current_payment_method'=> '',
            'config'                => ['fixture_identity' => FcFixture::identity()],
        ];
        $row = $class::query()->create(array_merge($defaults, $attributes, [
            'customer_id'            => (int) $order->customer_id,
            'parent_order_id'        => (int) $order->id,
            'product_id'             => (int) $productId,
            'variation_id'           => (int) $variationId,
            'vendor_subscription_id' => $vendorId,
            'status'                 => $status,
            'config'                 => array_merge(
                $defaults['config'],
                isset($attributes['config']) && is_array($attributes['config'])
                    ? $attributes['config']
                    : []
            ),
        ]));
        $id = isset($row->id) ? (int) $row->id : 0;
        if ($id > 0) {
            self::$subscriptions[$id] = [
                'id'        => $id,
                'order_id'  => (int) $order->id,
                'vendor_id' => $vendorId,
            ];
            self::$subscriptionHistory[$id] = self::$subscriptions[$id];
        }
        if ($id <= 0) {
            throw new RuntimeException('Subscription fixture create returned no positive ID.');
        }

        return $row;
    }

    /**
     * Return the exact IDs selected by the production expiry predicate.
     *
     * @return array<int,int>
     */
    public static function expiryCandidateIds()
    {
        $currentTime = time();
        $now = gmdate('Y-m-d H:i:s', $currentTime);
        $gracePeriodDays = SubscriptionHelper::getSubscriptionsGracePeriodDays();
        $cutoffDates = [];
        foreach ($gracePeriodDays as $interval => $days) {
            $cutoffDates[$interval] = gmdate(
                'Y-m-d H:i:s',
                $currentTime - ((int) $days * DAY_IN_SECONDS)
            );
        }
        $defaultCutoff = gmdate('Y-m-d H:i:s', $currentTime - (7 * DAY_IN_SECONDS));
        $knownIntervals = array_keys($cutoffDates);
        $class = self::rowConfig('subscription')['model_class'];

        $ids = $class::query()
            ->whereIn('status', [
                Status::SUBSCRIPTION_ACTIVE,
                Status::SUBSCRIPTION_TRIALING,
                Status::SUBSCRIPTION_CANCELED,
                Status::SUBSCRIPTION_EXPIRING,
                Status::SUBSCRIPTION_PAST_DUE,
            ])
            ->whereNotIn('collection_method', ['manual', 'system'])
            ->whereNotNull('next_billing_date')
            ->where('next_billing_date', '>', '0000-00-00 00:00:00')
            ->where(function ($query) use ($now, $cutoffDates, $knownIntervals, $defaultCutoff) {
                $query->where(function ($subQuery) use ($cutoffDates, $knownIntervals, $defaultCutoff) {
                    $subQuery->whereIn('status', [
                        Status::SUBSCRIPTION_ACTIVE,
                        Status::SUBSCRIPTION_TRIALING,
                        Status::SUBSCRIPTION_EXPIRING,
                        Status::SUBSCRIPTION_PAST_DUE,
                    ])->where(function ($dateQuery) use ($cutoffDates, $knownIntervals, $defaultCutoff) {
                        $index = 0;
                        foreach ($cutoffDates as $interval => $cutoff) {
                            $method = $index === 0 ? 'where' : 'orWhere';
                            $dateQuery->{$method}(function ($intervalQuery) use ($interval, $cutoff) {
                                $intervalQuery->where('billing_interval', $interval)
                                    ->where('next_billing_date', '<', $cutoff);
                            });
                            $index++;
                        }
                        $dateQuery->orWhere(function ($intervalQuery) use ($knownIntervals, $defaultCutoff) {
                            $intervalQuery->where(function ($unknownQuery) use ($knownIntervals) {
                                $unknownQuery->whereNotIn('billing_interval', $knownIntervals)
                                    ->orWhereNull('billing_interval');
                            })->where('next_billing_date', '<', $defaultCutoff);
                        });
                    });
                })->orWhere(function ($subQuery) use ($now) {
                    $subQuery->where('status', Status::SUBSCRIPTION_CANCELED)
                        ->where('next_billing_date', '<', $now);
                });
            })
            ->orderBy('id', 'ASC')
            ->pluck('id')
            ->toArray();

        return array_map('intval', $ids);
    }

    /**
     * Capture the Activity high-water mark immediately before expiry dispatch.
     *
     * @return void
     */
    public static function beginActivityCapture()
    {
        $class = self::rowConfig('activity')['model_class'];
        self::$activityHighWater = (int) $class::query()->max('id');
    }

    /**
     * Capture and validate every Activity inserted since beginActivityCapture().
     *
     * @return array<int,object>
     */
    public static function captureExpiryActivities()
    {
        if (self::$activityHighWater === null) {
            throw new LogicException('Activity capture was not started.');
        }

        $class = self::rowConfig('activity')['model_class'];
        $rows = $class::query()
            ->where('id', '>', self::$activityHighWater)
            ->orderBy('id', 'ASC')
            ->get();
        $ownedSubscriptionIds = array_map('intval', array_keys(self::$subscriptions));

        foreach ($rows as $row) {
            $isOwnedSubscription = $row->module_type === 'FluentCart\\App\\Models\\Subscription'
                && in_array((int) $row->module_id, $ownedSubscriptionIds, true);
            $isOwnedSummary = (string) $row->title === 'Subscription Validity Expiration Check'
                && (int) $row->module_id === 0;
            if (!$isOwnedSubscription && !$isOwnedSummary) {
                throw new RuntimeException(
                    'Concurrent/unowned Activity appeared during Phase 8 capture: ID '
                    . (int) $row->id
                );
            }

            $id = (int) $row->id;
            self::$activities[$id] = [
                'id'          => $id,
                'module_id'   => (int) $row->module_id,
                'module_type' => (string) $row->module_type,
                'title'       => (string) $row->title,
            ];
            self::$activityHistory[$id] = self::$activities[$id];
        }

        return $rows->all();
    }

    /**
     * Capture all exact SubscriptionMeta rows for owned Subscription IDs.
     *
     * @return void
     */
    public static function captureSubscriptionMeta()
    {
        if (!self::$subscriptions) {
            return;
        }
        $class = self::rowConfig('subscription_meta')['model_class'];
        $rows = $class::query()
            ->whereIn('subscription_id', array_keys(self::$subscriptions))
            ->get();
        foreach ($rows as $row) {
            $id = (int) $row->id;
            self::$subscriptionMeta[$id] = [
                'id'              => $id,
                'subscription_id' => (int) $row->subscription_id,
                'meta_key'        => (string) $row->meta_key,
            ];
            self::$subscriptionMetaHistory[$id] = self::$subscriptionMeta[$id];
        }
    }

    /**
     * Capture every exact License belonging to an owned Order.
     *
     * @return array<int,object>
     */
    public static function captureLicensesForOrder($orderId)
    {
        $class = self::rowConfig('license')['model_class'];
        if (!class_exists($class)) {
            return [];
        }

        $rows = $class::query()->where('order_id', (int) $orderId)->orderBy('id', 'ASC')->get();
        foreach ($rows as $row) {
            $id = (int) $row->id;
            self::$licenses[$id] = [
                'id'       => $id,
                'order_id' => (int) $orderId,
            ];
            self::$licenseHistory[$id] = self::$licenses[$id];
        }

        return $rows->all();
    }

    /**
     * Return configured Pro licensing handler class, or null if unavailable.
     *
     * @return string|null
     */
    public static function licenseHandlerClass()
    {
        $class = self::domainConfig()['license_handler_class'];

        return class_exists($class) ? $class : null;
    }

    /**
     * Reload one owned variation.
     *
     * @param int $id
     * @return object
     */
    public static function reloadVariation($id)
    {
        if (!isset(self::$variationHistory[(int) $id])) {
            throw new LogicException('Variation ID is not owned by this process.');
        }
        $class = self::rowConfig('product_variation')['model_class'];
        $row = $class::query()->find((int) $id);
        if (!$row) {
            throw new RuntimeException('Owned variation disappeared: ' . (int) $id);
        }

        return $row;
    }

    /**
     * Return exact owned Subscription IDs in ascending order.
     *
     * @return array<int,int>
     */
    public static function ownedSubscriptionIds()
    {
        $ids = array_map('intval', array_keys(self::$subscriptions));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * Recapture an expected provider-ID transition on one exact owned Subscription.
     *
     * Gateway lifecycle tests may intentionally replace vendor_subscription_id.
     * Cleanup remains fail-closed: the prior registry value, current row identity,
     * owned parent Order, and fixture config must all still match before the new
     * provider identity becomes the cleanup canary.
     *
     * @param int    $subscriptionId
     * @param string $previousVendorId
     * @return object
     */
    public static function recaptureSubscriptionVendorId($subscriptionId, $previousVendorId)
    {
        $subscriptionId = (int) $subscriptionId;
        if (!isset(self::$subscriptions[$subscriptionId])) {
            throw new LogicException('Cannot recapture an unowned Subscription ID.');
        }
        if ((string) self::$subscriptions[$subscriptionId]['vendor_id'] !== (string) $previousVendorId) {
            throw new LogicException('Subscription provider-ID recapture prior identity mismatch.');
        }

        $class = self::rowConfig('subscription')['model_class'];
        $row = $class::query()->find($subscriptionId);
        $owned = self::$subscriptions[$subscriptionId];
        if (
            !$row
            || (int) $row->parent_order_id !== (int) $owned['order_id']
            || (string) $row->vendor_subscription_id === ''
            || !is_array($row->config)
            || (string) ($row->config['fixture_identity'] ?? '') !== FcFixture::identity()
        ) {
            throw new LogicException('Subscription provider-ID recapture ownership checks failed.');
        }

        $newVendorId = (string) $row->vendor_subscription_id;
        self::$subscriptions[$subscriptionId]['vendor_id'] = $newVendorId;
        self::$subscriptionHistory[$subscriptionId]['vendor_id'] = $newVendorId;

        return $row;
    }

    /**
     * Return exact owned IDs grouped by physical domain kind.
     *
     * @return array<string,array<int,int>>
     */
    public static function ownedIds()
    {
        return [
            'product_post'      => array_map('intval', array_keys(self::$productHistory)),
            'product_detail'    => array_map('intval', array_keys(self::$detailHistory)),
            'product_variation' => array_map('intval', array_keys(self::$variationHistory)),
            'scheduled_action'  => array_map('intval', array_keys(self::$scheduledHistory)),
            'subscription'      => array_map('intval', array_keys(self::$subscriptionHistory)),
            'subscription_meta' => array_map('intval', array_keys(self::$subscriptionMetaHistory)),
            'license'           => array_map('intval', array_keys(self::$licenseHistory)),
            'activity'          => array_map('intval', array_keys(self::$activityHistory)),
        ];
    }

    /**
     * Count residue for every captured exact primary ID.
     *
     * @return array<string,int>
     */
    public static function residueCounts()
    {
        global $wpdb;

        $productIds = array_map('intval', array_keys(self::$productHistory));
        $productSql = "SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE `ID` IN ("
            . implode(',', array_fill(0, count($productIds), '%d')) . ')';
        $counts = [
            'product_post' => $productIds
                ? (int) $wpdb->get_var(call_user_func_array(
                    [$wpdb, 'prepare'],
                    array_merge([$productSql], $productIds)
                ))
                : 0,
        ];

        $histories = [
            'product_detail'    => [self::$detailHistory, 'product_detail'],
            'product_variation' => [self::$variationHistory, 'product_variation'],
            'scheduled_action'  => [self::$scheduledHistory, 'scheduled_action'],
            'subscription'      => [self::$subscriptionHistory, 'subscription'],
            'subscription_meta' => [self::$subscriptionMetaHistory, 'subscription_meta'],
            'license'           => [self::$licenseHistory, 'license'],
            'activity'          => [self::$activityHistory, 'activity'],
        ];
        foreach ($histories as $name => $definition) {
            [$history, $kind] = $definition;
            $table = $wpdb->prefix . self::rowConfig($kind)['table'];
            $ids = array_map('intval', array_keys($history));
            $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `id` IN ("
                . implode(',', array_fill(0, count($ids), '%d')) . ')';
            $counts[$name] = $ids
                ? (int) $wpdb->get_var(call_user_func_array(
                    [$wpdb, 'prepare'],
                    array_merge([$sql], $ids)
                ))
                : 0;
        }

        return $counts;
    }

    /**
     * Read-only marker counts used before and after the complete tier.
     *
     * @return array<string,int>
     */
    public static function markerResidueCounts()
    {
        global $wpdb;

        $posts = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE `post_title` = %s AND `post_type` = %s",
            self::marker('product'),
            self::domainConfig()['product_post_type']
        ));
        $scheduledTable = $wpdb->prefix . self::rowConfig('scheduled_action')['table'];
        $scheduledPrefix = substr(
            self::marker('scheduled-action'),
            0,
            -strlen('scheduled-action')
        ) . '%';
        $scheduled = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$scheduledTable}` WHERE `action` LIKE %s",
            $scheduledPrefix
        ));

        return [
            'product_post'     => $posts,
            'scheduled_action' => $scheduled,
        ];
    }

    /**
     * Delete every exact owned domain row, then the shared Order/Customer rows.
     *
     * @return void
     */
    public static function cleanupAll()
    {
        self::recoverOwnedChildren();

        self::cleanupModelRows('activity', self::$activities, self::$activityHistory);
        self::cleanupModelRows('license', self::$licenses, self::$licenseHistory);
        self::cleanupModelRows(
            'subscription_meta',
            self::$subscriptionMeta,
            self::$subscriptionMetaHistory
        );
        self::cleanupModelRows('subscription', self::$subscriptions, self::$subscriptionHistory);
        self::cleanupModelRows(
            'scheduled_action',
            self::$scheduledActions,
            self::$scheduledHistory
        );

        FcFixture::cleanupAll();

        self::cleanupModelRows(
            'product_variation',
            self::$variations,
            self::$variationHistory
        );
        self::cleanupModelRows('product_detail', self::$details, self::$detailHistory);

        $postIds = array_keys(self::$products);
        rsort($postIds, SORT_NUMERIC);
        foreach ($postIds as $postId) {
            global $wpdb;

            $owned = self::$products[$postId];
            // A Phase 9 Product delete goes through the plugin ORM rather than
            // wp_delete_post(), so this long-running process may still hold the
            // pre-delete WP_Post object. Refresh that read-only ownership check
            // before deciding whether the exact database row needs fallback
            // cleanup.
            clean_post_cache((int) $postId);
            $post = get_post((int) $postId);
            if ($post) {
                if (
                    (string) $post->post_title !== $owned['title']
                    || (string) $post->post_type !== $owned['type']
                ) {
                    throw new LogicException(
                        'Refusing to delete Product post because ownership changed: '
                        . (int) $postId
                    );
                }

                // wp_delete_post() always attempts to clear publish_future_post
                // cron state. Phase 8 forbids cron mutation, so exact-key cleanup
                // removes only this owned post and its two WP-owned child tables.
                $deletedMeta = $wpdb->delete(
                    $wpdb->postmeta,
                    ['post_id' => (int) $postId],
                    ['%d']
                );
                $deletedTerms = $wpdb->delete(
                    $wpdb->term_relationships,
                    ['object_id' => (int) $postId],
                    ['%d']
                );
                $deleted = $wpdb->delete($wpdb->posts, ['ID' => (int) $postId], ['%d']);
                if ($deletedMeta === false || $deletedTerms === false) {
                    throw new RuntimeException(
                        'Exact Product child-row cleanup failed: ' . (int) $postId
                    );
                }
                if ($deleted !== 1) {
                    throw new RuntimeException(
                        'Exact Product post delete failed: ' . (int) $postId
                    );
                }
                clean_post_cache((int) $postId);
            }
            unset(self::$products[$postId]);
        }

        $residue = self::residueCounts();
        if (array_sum($residue) !== 0) {
            throw new RuntimeException(
                'Phase 8 exact-ID residue remains: ' . wp_json_encode($residue)
            );
        }
        $markers = self::markerResidueCounts();
        if (array_sum($markers) !== 0) {
            throw new RuntimeException(
                'Phase 8 exact marker residue remains: ' . wp_json_encode($markers)
            );
        }
    }

    /**
     * Recover child IDs if a lifecycle method threw after inserting a row.
     *
     * Queries remain constrained by exact owned parent IDs/high-water.
     *
     * @return void
     */
    private static function recoverOwnedChildren()
    {
        if (self::$subscriptions) {
            self::captureSubscriptionMeta();
            $class = self::rowConfig('activity')['model_class'];
            $rows = $class::query()
                ->where('module_type', 'FluentCart\\App\\Models\\Subscription')
                ->whereIn('module_id', array_keys(self::$subscriptions))
                ->get();
            foreach ($rows as $row) {
                $id = (int) $row->id;
                self::$activities[$id] = [
                    'id'          => $id,
                    'module_id'   => (int) $row->module_id,
                    'module_type' => (string) $row->module_type,
                    'title'       => (string) $row->title,
                ];
                self::$activityHistory[$id] = self::$activities[$id];
            }
        }

        foreach (FcFixture::ownedOrderIds() as $orderId) {
            self::captureLicensesForOrder((int) $orderId);
        }
    }

    /**
     * Delete registered rows through their real models after ownership checks.
     *
     * @param string                   $kind
     * @param array<int,array<string,mixed>> $active
     * @param array<int,array<string,mixed>> $history
     * @return void
     */
    private static function cleanupModelRows($kind, array &$active, array $history)
    {
        $class = self::rowConfig($kind)['model_class'];
        $ids = array_keys($active);
        rsort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $owned = $active[$id];
            $row = $class::query()->find((int) $id);
            if ($row) {
                self::assertRowOwnership($kind, $row, $owned);
                $deleted = $row->delete();
                if ($deleted !== true) {
                    throw new RuntimeException(
                        'Model did not confirm exact delete for ' . $kind . ' ID ' . (int) $id
                    );
                }
            }
            unset($active[$id]);
        }
    }

    /**
     * @param string              $kind
     * @param object              $row
     * @param array<string,mixed> $owned
     * @return void
     */
    private static function assertRowOwnership($kind, $row, array $owned)
    {
        if ((int) $row->id !== (int) $owned['id']) {
            throw new LogicException('Owned primary ID changed for ' . $kind . '.');
        }
        if ($kind === 'product_detail' || $kind === 'product_variation') {
            if ((int) $row->post_id !== (int) $owned['post_id']) {
                throw new LogicException('Owned Product parent changed for ' . $kind . '.');
            }
        } elseif ($kind === 'scheduled_action') {
            if (
                (string) $row->group !== (string) $owned['group']
                || (string) $row->action !== (string) $owned['action']
            ) {
                throw new LogicException('Owned ScheduledAction discriminator changed.');
            }
        } elseif ($kind === 'subscription') {
            if ((string) $row->vendor_subscription_id !== (string) $owned['vendor_id']) {
                throw new LogicException('Owned Subscription marker changed.');
            }
        } elseif ($kind === 'subscription_meta') {
            if ((int) $row->subscription_id !== (int) $owned['subscription_id']) {
                throw new LogicException('Owned SubscriptionMeta parent changed.');
            }
        } elseif ($kind === 'license') {
            if ((int) $row->order_id !== (int) $owned['order_id']) {
                throw new LogicException('Owned License parent changed.');
            }
        } elseif ($kind === 'activity') {
            if (
                (int) $row->module_id !== (int) $owned['module_id']
                || (string) $row->module_type !== (string) $owned['module_type']
                || (string) $row->title !== (string) $owned['title']
            ) {
                throw new LogicException('Owned Activity discriminator changed.');
            }
        }
    }

    /**
     * @return bool
     */
    private static function ownedCustomerExists()
    {
        $residue = FcFixture::residueCounts();

        return $residue['customer'] === 1;
    }

    /**
     * @return int
     */
    private static function productMarkerCount()
    {
        return self::markerResidueCounts()['product_post'];
    }

    /**
     * @param string $kind
     * @return array<string,string>
     */
    private static function rowConfig($kind)
    {
        $domain = self::domainConfig();
        if (empty($domain['rows'][$kind]['model_class']) || empty($domain['rows'][$kind]['table'])) {
            throw new RuntimeException('Domain fixture row configuration is incomplete: ' . $kind);
        }

        return $domain['rows'][$kind];
    }

    /**
     * @return array<string,mixed>
     */
    private static function domainConfig()
    {
        if (self::$config === null) {
            $suite = require dirname(__DIR__) . '/suite.config.php';
            if (
                empty($suite['integration_fixture']['domain'])
                || !is_array($suite['integration_fixture']['domain'])
            ) {
                throw new RuntimeException('integration_fixture.domain is missing.');
            }
            self::$config = $suite['integration_fixture']['domain'];
        }

        return self::$config;
    }
}
