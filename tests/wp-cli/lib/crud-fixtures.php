<?php
/**
 * Exact-ID Phase 9 fixtures and full physical-row comparison helpers.
 *
 * Route-created rows are registered immediately from the REST response.
 * Cleanup always reloads the captured primary ID, verifies its immutable
 * ownership fields, and deletes only that exact row through the real model.
 */

class FcCrudFixture
{
    /** @var array<string,array<int,array<string,mixed>>> */
    private static $rows = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    private static $history = [];

    /**
     * Return a per-run marker suitable for stored scalar ownership.
     *
     * @param string $suffix
     * @return string
     */
    public static function marker($suffix)
    {
        $suffix = preg_replace('/[^a-z0-9-]/', '-', strtolower((string) $suffix));
        if ($suffix === '') {
            throw new InvalidArgumentException('CRUD fixture marker suffix cannot be empty.');
        }

        return 'phase9-' . substr(hash('sha256', FcFixture::identity()), 0, 20)
            . '-' . $suffix;
    }

    /**
     * Return the unique route-created Customer email.
     *
     * @return string
     */
    public static function customerEmail()
    {
        return self::marker('customer') . '@example.invalid';
    }

    /**
     * Fail fast on an unhealthy REST result so a later extraction cannot hide
     * the route-level error behind an incidental undefined-index diagnostic.
     *
     * @param array<string,mixed> $result
     * @param string              $label
     * @return void
     */
    public static function requireHealthy(array $result, $label)
    {
        FcTest::assertHealthy($result, $label);
        if (
            $result['db_error'] !== ''
            || $result['is_exception']
            || !in_array($result['status'], [200, 201, 204], true)
        ) {
            throw new RuntimeException(
                $label . ' was unhealthy; status=' . $result['status']
                . ' db=' . $result['db_error']
                . ' message=' . $result['message']
            );
        }
    }

    /**
     * Extract a positive model ID from the standard Resource success envelope.
     *
     * @param array<string,mixed> $result
     * @param string              $label
     * @return int
     */
    public static function createdId(array $result, $label)
    {
        $id = isset($result['data']['data']['id'])
            ? (int) $result['data']['data']['id']
            : 0;
        if ($id <= 0) {
            throw new RuntimeException(
                $label . ' did not return data.data.id: ' . wp_json_encode($result['data'])
            );
        }

        return $id;
    }

    /**
     * Register one route-created row immediately after its response.
     *
     * @param string              $kind
     * @param int                 $id
     * @param array<string,mixed> $ownership
     * @return object
     */
    public static function capture($kind, $id, array $ownership)
    {
        $id = (int) $id;
        if ($id <= 0) {
            throw new RuntimeException('Cannot capture non-positive ' . $kind . ' ID.');
        }

        $config = self::rowConfig($kind);
        $modelClass = $config['model_class'];
        $row = $modelClass::query()->find($id);
        if (!$row) {
            throw new RuntimeException(
                'Route response identified missing ' . $kind . ' ID ' . $id . '.'
            );
        }

        $owned = array_merge(['id' => $id], $ownership);
        self::assertOwnership($kind, $row, $owned);
        self::$rows[$kind][$id] = $owned;
        self::$history[$kind][$id] = $owned;

        return $row;
    }

    /**
     * Capture the exact LabelRelationship created by a bound Label route.
     *
     * @param int    $labelId
     * @param int    $labelableId
     * @param string $labelableType
     * @return object
     */
    public static function captureLabelRelationship($labelId, $labelableId, $labelableType)
    {
        $config = self::rowConfig('label_relationship');
        $modelClass = $config['model_class'];
        $rows = $modelClass::query()
            ->where('label_id', (int) $labelId)
            ->where('labelable_id', (int) $labelableId)
            ->where('labelable_type', (string) $labelableType)
            ->get();
        if ($rows->count() !== 1) {
            throw new RuntimeException(
                'Expected exactly one owned LabelRelationship, found ' . $rows->count() . '.'
            );
        }

        $row = $rows->first();
        return self::capture('label_relationship', (int) $row->id, [
            'label_id'       => (int) $labelId,
            'labelable_id'   => (int) $labelableId,
            'labelable_type' => (string) $labelableType,
        ]);
    }

    /**
     * Capture exact Activity IDs emitted for a newly created Coupon.
     *
     * @param int $couponId
     * @return array<int,int>
     */
    public static function captureCouponActivities($couponId)
    {
        $config = self::rowConfig('activity');
        $modelClass = $config['model_class'];
        $rows = $modelClass::query()
            ->where('module_id', (int) $couponId)
            ->where('module_name', 'coupon')
            ->get();

        $ids = [];
        foreach ($rows as $row) {
            $captured = self::capture('activity', (int) $row->id, [
                'module_id'   => (int) $couponId,
                'module_name' => 'coupon',
                'title'       => (string) $row->title,
            ]);
            $ids[] = (int) $captured->id;
        }

        return $ids;
    }

    /**
     * Return the current Activity primary-key high-water mark.
     *
     * @return int
     */
    public static function activityHighWater()
    {
        $config = self::rowConfig('activity');
        $modelClass = $config['model_class'];

        return (int) $modelClass::query()->max('id');
    }

    /**
     * Capture product/variation deletion logs by high-water plus unique marker.
     *
     * @param int    $highWater
     * @param string $marker
     * @param string $moduleType
     * @return array<int,int>
     */
    public static function captureMarkerActivitiesAfter($highWater, $marker, $moduleType)
    {
        $config = self::rowConfig('activity');
        $modelClass = $config['model_class'];
        $rows = $modelClass::query()
            ->where('id', '>', (int) $highWater)
            ->where('module_type', (string) $moduleType)
            ->where('content', 'like', '%' . $marker . '%')
            ->get();

        $ids = [];
        foreach ($rows as $row) {
            $captured = self::capture('activity', (int) $row->id, [
                'module_id'   => (int) $row->module_id,
                'module_type' => (string) $moduleType,
                'title'       => (string) $row->title,
                'marker'      => (string) $marker,
            ]);
            $ids[] = (int) $captured->id;
        }

        return $ids;
    }

    /**
     * Read an uncast full physical row for lossless before/after comparison.
     *
     * @param string $kind
     * @param int    $id
     * @return array<string,string|null>|null
     */
    public static function snapshot($kind, $id)
    {
        global $wpdb;

        $config = self::rowConfig($kind);
        $table = self::validatedIdentifier($wpdb->prefix . $config['table'], 'CRUD table');
        $primaryKey = self::validatedIdentifier($config['primary_key'], 'CRUD primary key');
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE `{$primaryKey}` = %d",
                (int) $id
            ),
            ARRAY_A
        );
        if ($wpdb->last_error !== '') {
            throw new RuntimeException(
                'CRUD full-row snapshot failed for ' . $kind . ': ' . $wpdb->last_error
            );
        }

        return is_array($row) ? $row : null;
    }

    /**
     * Return every physical-column difference between two snapshots.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string,array{before:mixed,after:mixed}>
     */
    public static function rowDifferences(array $before, array $after)
    {
        $differences = [];
        $columns = array_values(array_unique(array_merge(
            array_keys($before),
            array_keys($after)
        )));
        sort($columns);

        foreach ($columns as $column) {
            $beforeValue = array_key_exists($column, $before) ? $before[$column] : null;
            $afterValue = array_key_exists($column, $after) ? $after[$column] : null;
            if ($beforeValue !== $afterValue) {
                $differences[$column] = [
                    'before' => $beforeValue,
                    'after'  => $afterValue,
                ];
            }
        }

        return $differences;
    }

    /**
     * Assert one requested field changed and every other physical value survived.
     *
     * `updated_at` is the only permitted automatic bookkeeping column. It is
     * still compared explicitly and must not move backwards.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param string              $field
     * @param mixed               $expectedValue
     * @param string              $label
     * @return void
     */
    public static function assertOnlyFieldChanged(
        array $before,
        array $after,
        $field,
        $expectedValue,
        $label
    ) {
        FcTest::assert(
            array_key_exists($field, $before) && array_key_exists($field, $after),
            $label . ' snapshots contain target field ' . $field
        );
        FcTest::assert(
            $before[$field] !== $after[$field],
            $label . ' target field ' . $field . ' actually changes'
        );
        FcTest::assertSame(
            $expectedValue,
            $after[$field],
            $label . ' stores the exact requested ' . $field
        );

        $expectedAfter = $before;
        $expectedAfter[$field] = $expectedValue;
        if (array_key_exists('updated_at', $before) && array_key_exists('updated_at', $after)) {
            FcTest::assert(
                (string) $after['updated_at'] >= (string) $before['updated_at'],
                $label . ' updated_at never moves backwards'
            );
            $expectedAfter['updated_at'] = $after['updated_at'];
        }

        FcTest::assertSame(
            $expectedAfter,
            $after,
            $label . ' preserves every other full-row value'
        );
    }

    /**
     * Delete all captured route rows, children before parents.
     *
     * @return void
     */
    public static function cleanupAll()
    {
        self::recoverOwnedActivities();

        foreach (self::crudConfig()['cleanup_order'] as $kind) {
            $ids = isset(self::$rows[$kind]) ? array_keys(self::$rows[$kind]) : [];
            rsort($ids, SORT_NUMERIC);
            foreach ($ids as $id) {
                self::cleanupRow($kind, (int) $id);
            }
        }

        $residue = self::residueCounts();
        if (array_sum($residue) !== 0) {
            throw new RuntimeException(
                'Phase 9 exact-ID residue remains: ' . wp_json_encode($residue)
            );
        }
        $markers = self::markerResidueCounts();
        if (array_sum($markers) !== 0) {
            throw new RuntimeException(
                'Phase 9 exact marker residue remains: ' . wp_json_encode($markers)
            );
        }
    }

    /**
     * Count captured historical primary IDs still present by kind.
     *
     * @return array<string,int>
     */
    public static function residueCounts()
    {
        global $wpdb;

        $counts = [];
        foreach (self::crudConfig()['rows'] as $kind => $config) {
            $ids = array_map('intval', array_keys(self::$history[$kind] ?? []));
            if (!$ids) {
                $counts[$kind] = 0;
                continue;
            }
            $table = self::validatedIdentifier(
                $wpdb->prefix . $config['table'],
                'CRUD residue table'
            );
            $primaryKey = self::validatedIdentifier(
                $config['primary_key'],
                'CRUD residue primary key'
            );
            $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$primaryKey}` IN ("
                . implode(',', array_fill(0, count($ids), '%d')) . ')';
            $counts[$kind] = (int) $wpdb->get_var(call_user_func_array(
                [$wpdb, 'prepare'],
                array_merge([$sql], $ids)
            ));
        }

        return $counts;
    }

    /**
     * Count unique Phase 9 markers without using them as delete selectors.
     *
     * @return array<string,int>
     */
    public static function markerResidueCounts()
    {
        global $wpdb;

        $checks = [
            'customer' => ['email', self::customerEmail(), '='],
            'coupon' => ['code', strtoupper(self::marker('coupon')), '='],
            'label' => ['value', self::marker('label'), '='],
            'product_variation' => [
                'variation_title',
                '%' . $wpdb->esc_like(self::marker('route-variant')) . '%',
                'LIKE',
            ],
            'activity' => [
                'content',
                '%' . $wpdb->esc_like('phase9-' . substr(
                    hash('sha256', FcFixture::identity()),
                    0,
                    20
                )) . '%',
                'LIKE',
            ],
        ];

        $counts = [];
        foreach ($checks as $kind => $check) {
            $config = self::rowConfig($kind);
            $table = self::validatedIdentifier(
                $wpdb->prefix . $config['table'],
                'CRUD marker table'
            );
            $column = self::validatedIdentifier($check[0], 'CRUD marker column');
            $operator = $check[2] === 'LIKE' ? 'LIKE' : '=';
            $counts[$kind] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` {$operator} %s",
                $check[1]
            ));
            if ($wpdb->last_error !== '') {
                throw new RuntimeException(
                    'Phase 9 marker residue query failed for ' . $kind
                    . ': ' . $wpdb->last_error
                );
            }
        }

        return $counts;
    }

    /**
     * @param string $kind
     * @param int    $id
     * @return void
     */
    private static function cleanupRow($kind, $id)
    {
        $owned = self::$rows[$kind][$id];
        $config = self::rowConfig($kind);
        $modelClass = $config['model_class'];
        $row = $modelClass::query()->find($id);

        if ($row) {
            self::assertOwnership($kind, $row, $owned);
            if ($kind === 'customer') {
                $orderCount = $row->orders()->count();
                if ($orderCount !== 0) {
                    throw new RuntimeException(
                        'Refusing route Customer cleanup with owned-ID order count='
                        . $orderCount . '.'
                    );
                }
            }
            $deleted = $row->delete();
            if ($deleted !== true) {
                throw new RuntimeException(
                    'Model did not confirm exact CRUD delete for ' . $kind . ' ID ' . $id . '.'
                );
            }
        }

        if (self::snapshot($kind, $id) !== null) {
            throw new RuntimeException(
                'CRUD cleanup left exact ' . $kind . ' ID ' . $id . '.'
            );
        }
        unset(self::$rows[$kind][$id]);
    }

    /**
     * Recover Coupon and marker-based product deletion Activities if a route
     * returned after inserting the log but before the case captured its ID.
     *
     * @return void
     */
    private static function recoverOwnedActivities()
    {
        $activityClass = self::rowConfig('activity')['model_class'];
        foreach (self::$history['coupon'] ?? [] as $coupon) {
            $rows = $activityClass::query()
                ->where('module_id', (int) $coupon['id'])
                ->where('module_name', 'coupon')
                ->get();
            foreach ($rows as $row) {
                $id = (int) $row->id;
                if (!isset(self::$rows['activity'][$id])) {
                    self::capture('activity', $id, [
                        'module_id'   => (int) $coupon['id'],
                        'module_name' => 'coupon',
                        'title'       => (string) $row->title,
                    ]);
                }
            }
        }

        $markerPrefix = 'phase9-' . substr(hash('sha256', FcFixture::identity()), 0, 20);
        $rows = $activityClass::query()
            ->where('content', 'like', '%' . $markerPrefix . '%')
            ->get();
        foreach ($rows as $row) {
            $id = (int) $row->id;
            if (!isset(self::$rows['activity'][$id])) {
                self::capture('activity', $id, [
                    'module_id'   => (int) $row->module_id,
                    'module_type' => (string) $row->module_type,
                    'title'       => (string) $row->title,
                    'marker'      => $markerPrefix,
                ]);
            }
        }
    }

    /**
     * @param string              $kind
     * @param object              $row
     * @param array<string,mixed> $owned
     * @return void
     */
    private static function assertOwnership($kind, $row, array $owned)
    {
        foreach ($owned as $column => $expected) {
            if ($column === 'marker') {
                if (strpos((string) $row->content, (string) $expected) === false) {
                    throw new LogicException(
                        'Refusing Activity cleanup because its marker changed.'
                    );
                }
                continue;
            }
            $actual = $column === 'id' ? (int) $row->id : $row->{$column};
            if (is_int($expected)) {
                $actual = (int) $actual;
            } else {
                $actual = (string) $actual;
                $expected = (string) $expected;
            }
            if ($actual !== $expected) {
                throw new LogicException(
                    'Refusing exact ' . $kind . ' cleanup because '
                    . $column . ' changed.'
                );
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function crudConfig()
    {
        $config = require dirname(__DIR__) . '/suite.config.php';
        return $config['integration_fixture']['crud'];
    }

    /**
     * @param string $kind
     * @return array<string,mixed>
     */
    private static function rowConfig($kind)
    {
        $config = self::crudConfig();
        if (!isset($config['rows'][$kind])) {
            throw new InvalidArgumentException('Unknown CRUD fixture kind: ' . $kind);
        }

        return $config['rows'][$kind];
    }

    /**
     * @param string $identifier
     * @param string $label
     * @return string
     */
    private static function validatedIdentifier($identifier, $label)
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $identifier)) {
            throw new InvalidArgumentException('Unsafe ' . $label . ': ' . $identifier);
        }

        return (string) $identifier;
    }
}
