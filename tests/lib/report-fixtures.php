<?php
/**
 * Deterministic Phase 7 report dataset built from exact owned real-model rows.
 */

class FcReportFixture
{
    /**
     * Create one current-period paid Order without invoking any payment path.
     *
     * Global/deprecated report cases take their read-only baseline first, then
     * use this exact owned delta. A concurrent real write makes the assertion
     * fail closed; the suite never compensates by touching that real row.
     *
     * @param array<string,mixed> $orderAttributes
     * @return array<string,mixed>
     */
    public static function createCurrentOrderDataset(array $orderAttributes = [])
    {
        [$productId, $variationId] = self::unusedProductKeys();
        $createdAt = gmdate('Y-m-d H:i:s');
        $customer = FcFixture::customer([
            'first_name' => 'Phase Seven',
            'last_name'  => 'Current Delta',
        ]);
        $order = FcFixture::reportOrder(array_merge([
            'status'               => 'completed',
            'payment_status'       => 'paid',
            'payment_method'       => 'phase7-current',
            'payment_method_title' => 'Phase 7 Current Delta',
            'currency'             => 'USD',
            'subtotal'             => 40120,
            'shipping_tax'         => 10,
            'shipping_total'       => 100,
            'tax_total'            => 210,
            'total_amount'         => 40000,
            'total_paid'           => 43210,
            'total_refund'         => 0,
            'completed_at'         => $createdAt,
            'created_at'           => $createdAt,
            'updated_at'           => $createdAt,
            'type'                 => 'payment',
            'mode'                 => 'test',
        ], $orderAttributes));
        $item = FcFixture::reportOrderItem((int) $order->id, [
            'post_id'    => $productId,
            'object_id'  => $variationId,
            'quantity'   => 4,
            'unit_price' => 40000,
            'subtotal'   => 40000,
            'line_total' => 40000,
            'created_at' => $createdAt,
        ]);
        $address = FcFixture::reportOrderAddress((int) $order->id, [
            'country' => 'AQ',
        ]);

        return [
            'customer'   => $customer,
            'order'      => $order,
            'item'       => $item,
            'address'    => $address,
            'created_at' => $createdAt,
            'expected'   => [
                'gross'        => 43210,
                'net'          => 42990,
                'total_amount' => 40000,
                'orders'       => 1,
                'items'        => 1,
            ],
        ];
    }

    /**
     * Create the shared dataset used by report value cases.
     *
     * @return array<string,mixed>
     */
    public static function createDataset()
    {
        FcFixture::assertReportRangesEmpty();
        $window = FcFixture::reportWindow();
        [$productId, $variationId] = self::unusedProductKeys();

        $customer = FcFixture::customer([
            'first_name'          => 'Phase Seven',
            'last_name'           => 'Reports',
            'first_purchase_date' => '2001-02-04 10:00:00',
            'last_purchase_date'  => '2001-02-05 23:59:59',
            'purchase_count'      => 2,
            'ltv'                 => 20000,
            'aov'                 => 10000,
        ]);
        $customer = FcFixture::prepareReportCustomer([
            'created_at'          => '2001-02-04 09:00:00',
            'updated_at'          => '2001-02-05 23:59:59',
            'first_purchase_date' => '2001-02-04 10:00:00',
            'last_purchase_date'  => '2001-02-05 23:59:59',
            'purchase_count'      => 2,
            'ltv'                 => 20000,
            'aov'                 => 10000,
        ]);

        $paidA = FcFixture::reportOrder([
            'status'               => 'completed',
            'payment_status'       => 'paid',
            'payment_method'       => 'phase7-card',
            'payment_method_title' => 'Phase 7 Card',
            'currency'             => $window['currency'],
            'subtotal'             => 11845,
            'shipping_tax'         => 55,
            'shipping_total'       => 500,
            'tax_total'            => 345,
            'total_amount'         => 10000,
            'total_paid'           => 12345,
            'total_refund'         => 0,
            'completed_at'         => '2001-02-04 15:00:00',
            'created_at'           => '2001-02-04 10:00:00',
            'updated_at'           => '2001-02-04 15:00:00',
            'type'                 => 'payment',
            'mode'                 => 'test',
        ]);
        $paidB = FcFixture::reportOrder([
            'status'               => 'completed',
            'payment_status'       => 'paid',
            'payment_method'       => 'phase7-card',
            'payment_method_title' => 'Phase 7 Card',
            'currency'             => $window['currency'],
            'subtotal'             => 7455,
            'shipping_tax'         => 0,
            'shipping_total'       => 200,
            'tax_total'            => 100,
            'total_amount'         => 20000,
            'total_paid'           => 7655,
            'total_refund'         => 0,
            'completed_at'         => '2001-02-06 02:59:59',
            'created_at'           => '2001-02-05 23:59:59',
            'updated_at'           => '2001-02-06 02:59:59',
            'type'                 => 'payment',
            'mode'                 => 'test',
        ]);
        $pendingDecoy = FcFixture::reportOrder([
            'status'               => 'completed',
            'payment_status'       => 'pending',
            'payment_method'       => 'phase7-decoy',
            'payment_method_title' => 'Phase 7 Pending Decoy',
            'currency'             => $window['currency'],
            'subtotal'             => 99999,
            'shipping_tax'         => 999,
            'shipping_total'       => 999,
            'tax_total'            => 999,
            'total_amount'         => 99999,
            'total_paid'           => 99999,
            'total_refund'         => 0,
            'completed_at'         => '2001-02-04 13:00:00',
            'created_at'           => '2001-02-04 12:00:00',
            'updated_at'           => '2001-02-04 13:00:00',
            'type'                 => 'payment',
            'mode'                 => 'test',
        ]);
        $futureDecoy = FcFixture::reportOrder([
            'status'               => 'completed',
            'payment_status'       => 'paid',
            'payment_method'       => 'phase7-future',
            'payment_method_title' => 'Phase 7 Future Decoy',
            'currency'             => $window['currency'],
            'subtotal'             => 99999,
            'shipping_tax'         => 999,
            'shipping_total'       => 999,
            'tax_total'            => 999,
            'total_amount'         => 99999,
            'total_paid'           => 99999,
            'total_refund'         => 0,
            'completed_at'         => '2099-01-02 13:00:00',
            'created_at'           => $window['future'],
            'updated_at'           => '2099-01-02 13:00:00',
            'type'                 => 'payment',
            'mode'                 => 'test',
        ]);

        $orders = [
            'paid_a'        => $paidA,
            'paid_b'        => $paidB,
            'pending_decoy' => $pendingDecoy,
            'future_decoy'  => $futureDecoy,
        ];
        $itemSpecs = [
            'paid_a'        => [2, 12345, '2001-02-04 10:00:00'],
            'paid_b'        => [3, 7655, '2001-02-05 23:59:59'],
            'pending_decoy' => [99, 99999, '2001-02-04 12:00:00'],
            'future_decoy'  => [88, 99999, $window['future']],
        ];
        $items = [];
        foreach ($itemSpecs as $key => $spec) {
            $items[$key] = FcFixture::reportOrderItem((int) $orders[$key]->id, [
                'post_id'    => $productId,
                'object_id'  => $variationId,
                'quantity'   => $spec[0],
                'unit_price' => $spec[1],
                'subtotal'   => $spec[1],
                'line_total' => $spec[1],
                'created_at' => $spec[2],
            ]);
        }

        $addresses = [];
        foreach ($orders as $key => $order) {
            $addresses[$key . '_billing'] = FcFixture::reportOrderAddress((int) $order->id, [
                'country' => in_array($key, ['paid_a', 'paid_b'], true) ? 'BD' : 'US',
            ]);
            $addresses[$key . '_shipping'] = FcFixture::reportOrderAddress((int) $order->id, [
                'type'    => 'shipping',
                'country' => in_array($key, ['paid_a', 'paid_b'], true) ? 'BD' : 'US',
            ]);
        }

        $operations = [];
        foreach ($orders as $key => $order) {
            $operations[$key] = FcFixture::reportOrderOperation((int) $order->id, [
                'utm_source'   => FcFixture::reportMarker('source'),
                'utm_campaign' => FcFixture::reportMarker('campaign'),
                'utm_medium'   => 'integration',
            ]);
        }

        $activity = FcFixture::activity(
            (int) $futureDecoy->id,
            'FluentCart\\App\\Models\\Order',
            'report-recent-activity'
        );
        $activity = FcFixture::prepareReportActivity(
            (int) $activity->id,
            '2099-01-02 14:00:00'
        );

        return [
            'window'       => $window,
            'customer'     => $customer,
            'orders'       => $orders,
            'items'        => $items,
            'addresses'    => $addresses,
            'operations'   => $operations,
            'activity'     => $activity,
            'product_id'   => $productId,
            'variation_id' => $variationId,
            'expected'     => [
                'order_count'  => 2,
                'gross'        => 200.0,
                'net'          => 195.0,
                'shipping'     => 7.0,
                'tax'          => 5.0,
                'items'        => 5,
                'customer_count'=> 1,
            ],
        ];
    }

    /**
     * Production-service parameters for the exact report window.
     *
     * @param string $groupKey
     * @return array<string,mixed>
     */
    public static function params($groupKey = 'daily')
    {
        $window = FcFixture::reportWindow();
        $dateClass = 'FluentCart\\App\\Services\\DateTime\\DateTime';

        return [
            'startDate'     => new $dateClass($window['start']),
            'endDate'       => new $dateClass($window['end']),
            'groupKey'      => (string) $groupKey,
            'currency'      => $window['currency'],
            'paymentStatus' => [$window['payment_status']],
            'orderStatus'   => ['on-hold', 'failed'],
            'orderTypes'    => null,
            'variationIds'  => [],
            'comparePeriod' => null,
        ];
    }

    /**
     * Query parameters used by real REST/controller entry points.
     *
     * @return array<string,mixed>
     */
    public static function requestParams()
    {
        $window = FcFixture::reportWindow();

        return [
            'orderStatus'   => ['all'],
            'paymentStatus' => ['paid'],
            'currency'      => $window['currency'],
            'startDate'     => $window['start'],
            'endDate'       => $window['end'],
            'rangeKey'      => 'Custom',
            'variation_ids' => [],
            'filterMode'    => 'test',
            'compareType'   => 'no_comparison',
            'compareDate'   => $window['start'],
        ];
    }

    /**
     * Return one row from a grouped report by its exact group label.
     *
     * @param array<int,mixed> $rows
     * @param string           $group
     * @return array<string,mixed>
     */
    public static function groupRow(array $rows, $group)
    {
        foreach ($rows as $row) {
            $row = (array) $row;
            if (isset($row['group']) && (string) $row['group'] === (string) $group) {
                return $row;
            }
        }

        throw new RuntimeException('Report output is missing exact group: ' . $group);
    }

    /**
     * Use high identity-derived product keys only after proving no global row
     * already uses either value.
     *
     * @return array{0:int,1:int}
     */
    private static function unusedProductKeys()
    {
        $base = 1500000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 10000000);
        $productId = (int) $base;
        $variationId = (int) ($base + 1);
        $db = \FluentCart\App\App::db();

        $productCollision = (int) $db->table('fct_order_items')
            ->where('post_id', $productId)
            ->count();
        $variationCollision = (int) $db->table('fct_order_items')
            ->where('object_id', $variationId)
            ->count();
        if ($productCollision !== 0 || $variationCollision !== 0) {
            throw new RuntimeException(
                'Identity-derived report product keys collide with existing OrderItems: '
                . 'post_id=' . $productCollision . ' object_id=' . $variationCollision
            );
        }

        return [$productId, $variationId];
    }
}
