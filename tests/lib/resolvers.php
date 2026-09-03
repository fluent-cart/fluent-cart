<?php
/**
 * Read-only smoke fixture resolvers.
 *
 * Phase 1 never creates data. Each resolver selects an existing record and
 * returns placeholder values for the route manifest. A missing record causes
 * the owning case to be reported as a documented runtime skip.
 */

$resolveCustomerContext = static function () {
    static $resolved = false;
    static $context = null;

    if ($resolved) {
        return $context;
    }
    $resolved = true;

    $candidates = \FluentCart\App\Models\Customer::query()
        ->where('user_id', '>', 0)
        ->orderBy('id', 'ASC')
        ->limit(100)
        ->get();

    $userIds = [];
    foreach ($candidates as $candidate) {
        $userIds[] = (int) $candidate->user_id;
    }
    $userIds = array_values(array_unique(array_filter($userIds)));
    if (!$userIds) {
        return null;
    }

    $users = get_users([
        'include' => $userIds,
        'number'  => count($userIds),
    ]);
    $usersById = [];
    $emails = [];
    foreach ($users as $user) {
        $usersById[(int) $user->ID] = $user;
        if ($user->user_email !== '') {
            $emails[] = $user->user_email;
        }
    }

    /*
     * Fetch all possible CustomerResource matches in two bounded queries.
     * Requiring a single matching customer is deliberately conservative: it
     * proves getCurrentCustomer() cannot select a duplicate email row and
     * "repair" its user_id as a side effect during read-only smoke.
     */
    $possible = [];
    foreach (
        \FluentCart\App\Models\Customer::query()
            ->whereIn('user_id', array_keys($usersById))
            ->orderBy('id', 'ASC')
            ->limit(250)
            ->get() as $customer
    ) {
        $possible[(int) $customer->id] = $customer;
    }
    if ($emails) {
        foreach (
            \FluentCart\App\Models\Customer::query()
                ->whereIn('email', array_values(array_unique($emails)))
                ->orderBy('id', 'ASC')
                ->limit(250)
                ->get() as $customer
        ) {
            $possible[(int) $customer->id] = $customer;
        }
    }

    foreach ($candidates as $candidate) {
        $userId = (int) $candidate->user_id;
        if (!isset($usersById[$userId])) {
            continue;
        }
        $user = $usersById[$userId];

        // Mirror CustomerResource::getCurrentCustomer() without calling it:
        // that method repairs mismatched user_id values and would make smoke
        // mutate production data. Only accept an already-stable match.
        $matches = array_filter($possible, function ($possibleCustomer) use ($user) {
            return (int) $possibleCustomer->user_id === (int) $user->ID
                || (
                    $user->user_email !== ''
                    && (string) $possibleCustomer->email === (string) $user->user_email
                );
        });
        if (
            count($matches) === 1
            && isset($matches[(int) $candidate->id])
            && (int) $candidate->user_id === (int) $user->ID
        ) {
            $context = [
                'customer' => $candidate,
                'user_id'  => (int) $user->ID,
            ];
            return $context;
        }
    }

    return null;
};

return function ($needs) use ($resolveCustomerContext) {
    switch ($needs) {
        case 'product':
            $row = \FluentCart\App\Models\Product::query()->first();
            return $row ? [
                'id'         => $row->ID,
                'product'    => $row->ID,
                'productId'  => $row->ID,
                'product_id' => $row->ID,
                'postId'     => $row->ID,
            ] : null;

        case 'variation':
            $row = \FluentCart\App\Models\ProductVariation::query()
                ->whereHas('product')
                ->first();
            return $row ? [
                'id'           => $row->id,
                'variantId'    => $row->id,
                'variant_id'   => $row->id,
                'variation_id' => $row->id,
                'productId'    => $row->post_id,
                'product_id'   => $row->post_id,
                'productIds'   => [$row->post_id],
                'variationIds' => [$row->id],
            ] : null;

        case 'product_download':
            $row = \FluentCart\App\Models\ProductDownload::query()->first();
            return $row ? [
                'downloadableId' => $row->id,
                'product_id'     => $row->post_id,
            ] : null;

        case 'attribute_group':
            $row = \FluentCart\App\Models\AttributeGroup::query()->first();
            return $row ? ['group_id' => $row->id] : null;

        case 'order':
            $row = \FluentCart\App\Models\Order::query()->first();
            return $row ? [
                'id'       => $row->id,
                'order'    => $row->id,
                'order_id' => $row->id,
                'order_uuid' => $row->uuid,
            ] : null;

        case 'order_transaction':
            $row = \FluentCart\App\Models\OrderTransaction::query()
                ->whereHas('order')
                ->first();
            return $row ? [
                'id'               => $row->order_id,
                'order'            => $row->order_id,
                'order_id'         => $row->order_id,
                'transaction_id'   => $row->id,
                'transaction_uuid' => $row->uuid,
            ] : null;

        case 'renewal':
            $row = \FluentCart\App\Models\Order::query()
                ->where('type', 'renewal')
                ->first();
            return $row ? ['id' => $row->id] : null;

        case 'customer':
            $row = \FluentCart\App\Models\Customer::query()->first();
            return $row ? [
                'customer'   => $row->id,
                'customerId' => $row->id,
            ] : null;

        case 'coupon':
            $row = \FluentCart\App\Models\Coupon::query()->first();
            return $row ? ['id' => $row->id] : null;

        case 'notification':
            $notifications = \FluentCart\App\Services\Email\EmailNotifications::getNotifications();
            if (!$notifications) {
                return null;
            }
            $name = (string) array_key_first($notifications);
            return $name !== '' ? ['notification' => $name] : null;

        case 'cart':
            $row = \FluentCart\App\Models\Cart::query()->first();
            return $row ? ['fct_cart_hash' => $row->cart_hash] : null;

        case 'local_storage':
            $drivers = (new \FluentCart\Api\StorageDrivers())->getActive();
            return array_key_exists('local', (array) $drivers) ? ['driver' => 'local'] : null;

        case 'report':
            $row = \FluentCart\App\Models\Order::query()
                ->orderBy('created_at', 'ASC')
                ->first();
            return [
                'report_currency' => $row && $row->currency ? $row->currency : 'USD',
                'report_start'    => $row && $row->created_at
                    ? $row->created_at->format('Y-m-d 00:00:00')
                    : gmdate('Y-m-d 00:00:00', strtotime('-30 days')),
                'report_end'      => gmdate('Y-m-d 23:59:59'),
            ];

        case 'customer_context':
            $context = $resolveCustomerContext();
            if (!$context) {
                return null;
            }
            return [
                'customerId' => $context['customer']->id,
                'user_id'    => $context['user_id'],
            ];

        case 'customer_order':
            $context = $resolveCustomerContext();
            if (!$context) {
                return null;
            }
            $row = \FluentCart\App\Models\Order::query()
                ->where('customer_id', $context['customer']->id)
                ->first();
            if (!$row) {
                return null;
            }
            return [
                'customerId' => $context['customer']->id,
                'order_uuid' => $row->uuid,
                'user_id'    => $context['user_id'],
            ];

        case 'customer_upgrade':
            $context = $resolveCustomerContext();
            if (!$context) {
                return null;
            }
            $order = \FluentCart\App\Models\Order::query()
                ->where('customer_id', $context['customer']->id)
                ->first();
            $variation = \FluentCart\App\Models\ProductVariation::query()->first();
            if (!$order || !$variation) {
                return null;
            }
            return [
                'order_uuid'   => $order->uuid,
                'variation_id' => $variation->id,
                'user_id'      => $context['user_id'],
            ];

        case 'customer_transaction':
            $context = $resolveCustomerContext();
            if (!$context) {
                return null;
            }
            $row = \FluentCart\App\Models\OrderTransaction::query()
                ->whereHas('order', function ($query) use ($context) {
                    $query->where('customer_id', $context['customer']->id);
                })
                ->first();
            if (!$row) {
                return null;
            }
            return [
                'transaction_uuid' => $row->uuid,
                'user_id'          => $context['user_id'],
            ];

        case 'review':
            $row = \FluentCart\App\Models\ProductReview::query()
                ->topLevel()
                ->approved()
                ->whereHas('product', function ($query) {
                    $query->where('post_status', 'publish');
                })
                ->first();
            return $row ? [
                'id'       => $row->comment_ID,
                'reviewId' => $row->comment_ID,
                'postId'   => $row->comment_post_ID,
                'post_id'  => $row->comment_post_ID,
            ] : null;

        case 'customer_address':
            $context = $resolveCustomerContext();
            if (!$context) {
                return null;
            }
            $row = \FluentCart\App\Models\CustomerAddresses::query()
                ->where('customer_id', $context['customer']->id)
                ->first();
            if (!$row) {
                return null;
            }
            return [
                'customerAddressId' => $row->id,
                'user_id'           => $context['user_id'],
            ];

        case 'product_integration':
            $product = \FluentCart\App\Models\Product::query()->first();
            $integrations = apply_filters('fluent_cart/integration/order_integrations', []);
            foreach ((array) $integrations as $name => $integration) {
                $scopes = isset($integration['scopes']) ? (array) $integration['scopes'] : [];
                if ($product && in_array('product', $scopes, true) && !empty($integration['enabled'])) {
                    return [
                        'productId'        => $product->ID,
                        'product_id'       => $product->ID,
                        'integration_name' => $name,
                    ];
                }
            }
            return null;

        default:
            return null;
    }
};
