<?php
/**
 * Phase 18 customer-portal UUID ownership and no-change regressions.
 */

use FluentCart\App\Models\Order;

$inventory = require dirname(__DIR__) . '/inventories/public.php';

$normalise = function ($value) use (&$normalise) {
    if (is_object($value) && method_exists($value, 'toArray')) {
        $value = $value->toArray();
    } elseif ($value instanceof \JsonSerializable) {
        $value = $value->jsonSerialize();
    }
    if (!is_array($value)) {
        return $value;
    }

    $result = [];
    foreach ($value as $key => $item) {
        $result[$key] = $normalise($item);
    }
    return $result;
};

$targetUuid = function (array $actor, $entity) {
    $key = (string) $entity . '_uuid';
    if (empty($actor[$key])) {
        throw new RuntimeException('Phase 18 fixture lacks UUID for ' . $entity . '.');
    }
    return (string) $actor[$key];
};

$assertNoExposure = function (
    array $result,
    array $foreign,
    $label
) use ($normalise) {
    FcTest::assertSame('', $result['db_error'], $label . ' has no database error');
    FcTest::assertSame(false, $result['is_exception'], $label . ' has no plugin exception');

    $payload = wp_json_encode($normalise($result['data']));
    foreach ([
        $foreign['email'],
        $foreign['customer_uuid'],
        $foreign['order_uuid'],
        $foreign['transaction_uuid'],
        $foreign['subscription_uuid'],
        $foreign['order_marker'],
        $foreign['transaction_marker'],
        $foreign['subscription_marker'],
    ] as $secret) {
        FcTest::assert(
            $secret === '' || strpos((string) $payload, (string) $secret) === false,
            $label . ' exposes none of the foreign fixture identity'
        );
    }
};

$publicCases = [];
foreach ($inventory['routes'] as $index => $route) {
    $case = $route;
    $caseIndex = $index + 1;

    $publicCases[] = [
        'id'            => $case['coverage'],
        'name'          => strtoupper($case['verb']) . ' ' . $case['path']
            . ' rejects other, wrong, and malformed UUIDs without change',
        'kind'          => 'behavior',
        'phase'         => 18,
        'run'           => function () use (
            $case,
            $caseIndex,
            $targetUuid,
            $assertNoExposure
        ) {
            $originalUserId = get_current_user_id();
            try {
                $pair = FcPublicSurfaceFixture::pair(
                    'guard-' . str_pad((string) $caseIndex, 2, '0', STR_PAD_LEFT)
                );
                FcPublicSurfaceFixture::login($pair['a']);

                $beforeA = FcPublicSurfaceFixture::snapshot($pair['a']);
                $beforeB = FcPublicSurfaceFixture::snapshot($pair['b']);
                $foreignUuid = $targetUuid($pair['b'], $case['entity']);
                $variants = [
                    'other-customer' => sprintf($case['path'], $foreignUuid),
                    'wrong' => sprintf(
                        $case['path'],
                        $case['entity'] === 'order'
                            ? 'PHASE18MISS'
                            : '18deadbeef18deadbeef18deadbeef18'
                    ),
                    'malformed' => sprintf($case['path'], 'bad!uuid'),
                ];

                $results = [];
                foreach ($variants as $variant => $path) {
                    $results[$variant] = FcTest::rest(
                        $case['verb'],
                        $path,
                        $case['params']
                    );
                    $assertNoExposure(
                        $results[$variant],
                        $pair['b'],
                        $variant . ' UUID on ' . $case['id']
                    );
                    if ($variant === 'other-customer') {
                        FcTest::assertSame(
                            422,
                            (int) $results[$variant]['status'],
                            $case['id'] . ' rejects another customer before its protected path'
                        );
                    } elseif ($variant === 'wrong') {
                        FcTest::assert(
                            in_array((int) $results[$variant]['status'], [404, 405, 422], true),
                            $case['id'] . ' rejects a syntactically valid unknown UUID'
                        );
                    } elseif ($variant === 'malformed') {
                        FcTest::assert(
                            in_array((int) $results[$variant]['status'], [404, 405], true),
                            $case['id'] . ' route constraint refuses malformed UUID syntax'
                        );
                    }
                }

                FcTest::assertSame(
                    $beforeA,
                    FcPublicSurfaceFixture::snapshot($pair['a']),
                    $case['id'] . ' changes none of customer A records or relations'
                );
                FcTest::assertSame(
                    $beforeB,
                    FcPublicSurfaceFixture::snapshot($pair['b']),
                    $case['id'] . ' changes none of customer B records or relations'
                );

            } finally {
                wp_set_current_user($originalUserId);
                \FluentCart\App\Helpers\Helper::getCurrentUser(true);
                \FluentCart\Api\Resource\CustomerResource::resetCurrentCustomerRuntimeCache();
                FcPublicSurfaceFixture::cleanupAll();
            }
        },
    ];
}

$publicCases[] = [
    'id'            => 'public-uuid-entropy',
    'name'          => 'Customer, Order, Transaction, and Subscription UUIDs are non-sequential handles',
    'kind'          => 'behavior',
    'known_failure' => false,
    'phase'         => 18,
    'run'           => function () {
        try {
            $pair = FcPublicSurfaceFixture::pair('entropy');
            foreach (['customer', 'transaction', 'subscription'] as $entity) {
                $a = (string) $pair['a'][$entity . '_uuid'];
                $b = (string) $pair['b'][$entity . '_uuid'];
                FcTest::assert(
                    preg_match('/^[a-f0-9]{32}$/', $a) === 1
                        && preg_match('/^[a-f0-9]{32}$/', $b) === 1,
                    $entity . ' UUID uses a 128-bit hexadecimal handle shape'
                );
                FcTest::assert($a !== $b, $entity . ' UUIDs are distinct');
                FcTest::assert(
                    !ctype_digit($a) && !ctype_digit($b),
                    $entity . ' UUIDs are not sequential numeric IDs'
                );
            }

            $orderA = (string) $pair['a']['order_uuid'];
            $orderB = (string) $pair['b']['order_uuid'];
            FcTest::assert(
                preg_match('/^[A-Z0-9]{12}$/', $orderA) === 1
                    && preg_match('/^[A-Z0-9]{12}$/', $orderB) === 1,
                'Order UUIDs use the 36^12 random handle shape'
            );
            FcTest::assert($orderA !== $orderB, 'Order UUIDs are distinct');
            FcTest::assert(
                !ctype_digit($orderA) && !ctype_digit($orderB),
                'Order UUIDs are not sequential numeric IDs'
            );
        } finally {
            FcPublicSurfaceFixture::cleanupAll();
        }
    },
];

$publicCases[] = [
    'id'            => 'public-deleted-order-uuid',
    'name'          => 'An exactly deleted Order UUID no longer resolves for its former customer',
    'kind'          => 'behavior',
    'known_failure' => false,
    'phase'         => 18,
    'run'           => function () use ($assertNoExposure) {
        $originalUserId = get_current_user_id();
        try {
            $pair = FcPublicSurfaceFixture::pair('deleted');
            $order = Order::query()->find((int) $pair['b']['order_id']);
            if (
                !$order
                || (string) $order->note !== (string) $pair['b']['order_marker']
                || (int) $order->customer_id !== (int) $pair['b']['customer_id']
            ) {
                throw new RuntimeException('Refusing Phase 18 exact Order deletion.');
            }
            FcTest::assertSame(true, $order->delete(), 'owned Order delete is confirmed');

            FcPublicSurfaceFixture::login($pair['b']);
            $result = FcTest::rest(
                'GET',
                'customer-profile/orders/' . $pair['b']['order_uuid']
            );
            FcTest::assertSame(422, (int) $result['status'], 'deleted UUID is refused');
            $assertNoExposure($result, $pair['b'], 'deleted Order UUID');
        } finally {
            wp_set_current_user($originalUserId);
            \FluentCart\App\Helpers\Helper::getCurrentUser(true);
            \FluentCart\Api\Resource\CustomerResource::resetCurrentCustomerRuntimeCache();
            FcPublicSurfaceFixture::cleanupAll();
        }
    },
];


return $publicCases;
