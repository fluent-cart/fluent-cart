<?php
/**
 * Phase 10 input-validation and ownership regressions.
 *
 * Request-shape cases read pre-existing rows. The two policy cases create their
 * own owned customers, orders and product, and delete them by exact ID.
 */

use FluentCart\App\App;
use FluentCart\App\Http\Requests\CouponRequest;
use FluentCart\App\Http\Requests\CustomerRequest;
use FluentCart\App\Http\Requests\LabelRequest;
use FluentCart\App\Http\Requests\OrderRequest;
use FluentCart\App\Http\Requests\ProductCreateRequest;
use FluentCart\App\Http\Requests\ProductUpdateRequest;
use FluentCart\App\Http\Requests\ProductVariationRequest;
use FluentCart\Framework\Http\Request\Request;

$validationInventory = require dirname(__DIR__) . '/inventories/validation.php';

$normalise = function ($value) use (&$normalise) {
    if (is_object($value) && method_exists($value, 'toArray')) {
        $value = $value->toArray();
    } elseif ($value instanceof \JsonSerializable) {
        $value = $value->jsonSerialize();
    }

    if (!is_array($value)) {
        return $value;
    }

    $normalised = [];
    foreach ($value as $key => $item) {
        $normalised[$key] = $normalise($item);
    }
    return $normalised;
};

$responsePayload = function (array $result) use ($normalise) {
    $data = $normalise($result['data']);
    if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
        return $data['data'];
    }
    return $data;
};

$collectKey = function ($value, $wantedKey) use (&$collectKey, $normalise) {
    $value = $normalise($value);
    if (!is_array($value)) {
        return [];
    }

    $found = [];
    foreach ($value as $key => $item) {
        if ((string) $key === (string) $wantedKey) {
            $found[] = $item;
        }
        if (is_array($item) || is_object($item)) {
            $found = array_merge($found, $collectKey($item, $wantedKey));
        }
    }
    return $found;
};

$setTestUser = function ($userId) {
    wp_set_current_user((int) $userId);
    \FluentCart\App\Helpers\Helper::getCurrentUser(true);
};

$safeSearchSpecs = function () {
    global $wpdb;

    $posts = $wpdb->posts;
    $orders = $wpdb->prefix . 'fct_orders';
    $customers = $wpdb->prefix . 'fct_customers';
    $activity = $wpdb->prefix . 'fct_activity';

    return [
        [
            'route' => 'products',
            'key'   => 'products',
            'value' => $wpdb->get_var(
                "SELECT post_title
                 FROM {$posts}
                 WHERE post_type = 'fluent-products'
                   AND post_title != ''
                   AND post_title NOT LIKE '%<%'
                   AND post_title NOT LIKE '%\\%%'
                 ORDER BY ID ASC
                 LIMIT 1"
            ),
        ],
        [
            'route' => 'orders',
            'key'   => 'orders',
            'value' => $wpdb->get_var(
                "SELECT invoice_no
                 FROM {$orders}
                 WHERE invoice_no IS NOT NULL
                   AND invoice_no != ''
                   AND invoice_no NOT LIKE '%<%'
                   AND invoice_no NOT LIKE '%\\%%'
                 ORDER BY id ASC
                 LIMIT 1"
            ),
        ],
        [
            'route' => 'customers',
            'key'   => 'customers',
            'value' => $wpdb->get_var(
                "SELECT email
                 FROM {$customers}
                 WHERE email IS NOT NULL
                   AND email != ''
                   AND email NOT LIKE '%<%'
                   AND email NOT LIKE '%\\%%'
                 ORDER BY id ASC
                 LIMIT 1"
            ),
        ],
        [
            'route' => 'activity',
            'key'   => 'activities',
            'value' => $wpdb->get_var(
                "SELECT title
                 FROM {$activity}
                 WHERE title IS NOT NULL
                   AND title != ''
                   AND title NOT LIKE '%<%'
                   AND title NOT LIKE '%\\%%'
                 ORDER BY id ASC
                 LIMIT 1"
            ),
        ],
    ];
};

return [
    [
        'id'            => 'validation-request-guard-wiring',
        'name'          => 'Admin CRUD request guards keep text, integer, and email sanitizers wired',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $app = App::getInstance();
            $raw = [
                'id'          => '17 OR (SELECT 1)',
                'ID'          => '18 OR (SELECT 1)',
                'user_id'     => '19 OR (SELECT 1)',
                'customer_id' => '20 OR (SELECT 1)',
                'post_title'  => '<b>Read only product</b>',
                'first_name'  => '<b>Read only customer</b>',
                'note'        => '<b>Read only order</b>',
                'title'       => '<b>Read only coupon</b>',
                'value'       => '<b>Read only label</b>',
                'email'       => " validation@example.invalid\n",
                'variants'    => [
                    'id'              => '21 OR (SELECT 1)',
                    'post_id'         => '22 OR (SELECT 1)',
                    'variation_title' => '<b>Read only variation</b>',
                ],
            ];

            $guards = [
                CustomerRequest::class,
                OrderRequest::class,
                ProductCreateRequest::class,
                ProductUpdateRequest::class,
                ProductVariationRequest::class,
                CouponRequest::class,
                LabelRequest::class,
            ];
            $maps = [];
            foreach ($guards as $guardClass) {
                $request = new Request($app, [], $raw);
                $guard = new $guardClass();
                $guard->setRequestInstance($request);
                $maps[$guardClass] = [
                    'map'  => $guard->sanitize(),
                    'safe' => $request->getSafe($guard->sanitize()),
                ];
            }

            FcTest::assertSame(
                19,
                (int) $maps[CustomerRequest::class]['safe']['user_id'],
                'CustomerRequest applies intval to user_id'
            );
            FcTest::assertSame(
                sanitize_email($raw['email']),
                $maps[CustomerRequest::class]['safe']['email'],
                'CustomerRequest applies sanitize_email to email'
            );
            FcTest::assertSame(
                sanitize_text_field($raw['first_name']),
                $maps[CustomerRequest::class]['safe']['first_name'],
                'CustomerRequest applies sanitize_text_field to first_name'
            );
            FcTest::assertSame(
                17,
                (int) $maps[OrderRequest::class]['safe']['id'],
                'OrderRequest applies intval to id'
            );
            FcTest::assertSame(
                20,
                (int) $maps[OrderRequest::class]['safe']['customer_id'],
                'OrderRequest applies intval to customer_id'
            );
            FcTest::assertSame(
                sanitize_text_field($raw['note']),
                $maps[OrderRequest::class]['safe']['note'],
                'OrderRequest applies sanitize_text_field to note'
            );
            FcTest::assertSame(
                sanitize_text_field($raw['post_title']),
                $maps[ProductCreateRequest::class]['safe']['post_title'],
                'ProductCreateRequest applies sanitize_text_field to post_title'
            );
            FcTest::assertSame(
                18,
                (int) $maps[ProductUpdateRequest::class]['safe']['ID'],
                'ProductUpdateRequest applies intval to ID'
            );
            FcTest::assertSame(
                21,
                (int) $maps[ProductVariationRequest::class]['safe']['variants']['id'],
                'ProductVariationRequest applies intval to variants.id'
            );
            FcTest::assertSame(
                sanitize_text_field($raw['variants']['variation_title']),
                $maps[ProductVariationRequest::class]['safe']['variants']['variation_title'],
                'ProductVariationRequest sanitizes variation_title'
            );
            FcTest::assertSame(
                sanitize_text_field($raw['title']),
                $maps[CouponRequest::class]['safe']['title'],
                'CouponRequest applies sanitize_text_field to title'
            );
            FcTest::assertSame(
                sanitize_text_field($raw['value']),
                $maps[LabelRequest::class]['safe']['value'],
                'LabelRequest applies sanitize_text_field to value'
            );
        },
    ],
    [
        'id'            => 'validation-id-scoped-admin-routes-consult-policy',
        'name'          => 'ID-scoped admin GET routes refuse a subscriber before controller data access',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($setTestUser) {
            $adminId = get_current_user_id();
            try {
                $actor = FcPublicSurfaceFixture::pair('validation-policy')['a'];
                $product = FcDomainFixture::product();
                $setTestUser((int) $actor['user_id']);
                $routes = [
                    'orders/' . (int) $actor['order_id'],
                    'customers/' . (int) $actor['customer_id'],
                    'products/' . (int) $product['post']->ID,
                ];
                foreach ($routes as $route) {
                    $result = FcTest::rest('GET', $route);
                    FcTest::assertSame(
                        403,
                        (int) $result['status'],
                        'subscriber is refused by policy on GET /' . $route
                    );
                    FcTest::assertSame(
                        '',
                        $result['db_error'],
                        'policy refusal on GET /' . $route . ' has no database error'
                    );
                }
            } finally {
                $setTestUser($adminId);
                FcDomainFixture::cleanupAll();
                FcPublicSurfaceFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'validation-cross-customer-order-is-refused',
        'name'          => 'Customer-profile order detail refuses a different customer object',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use (
            $responsePayload,
            $setTestUser
        ) {
            $adminId = get_current_user_id();
            try {
                $pair = FcPublicSurfaceFixture::pair('validation-foreign');
                $foreignUuid = $pair['b']['order_uuid'];
                FcPublicSurfaceFixture::login($pair['a']);
                [$own, $foreign] = FcTest::withVerifiedCustomer(function () use ($pair, $foreignUuid) {
                    return [
                        FcTest::rest('GET', 'customer-profile/orders/' . $pair['a']['order_uuid']),
                        FcTest::rest('GET', 'customer-profile/orders/' . $foreignUuid),
                    ];
                });
                FcTest::assertHealthy($own, 'own customer-profile Order');
                $ownPayload = $responsePayload($own);
                FcTest::assert(
                    is_array($ownPayload) && isset($ownPayload['order']),
                    'own Order is returned to its customer'
                );
                FcTest::assertSame(
                    422,
                    (int) $foreign['status'],
                    'foreign Order UUID is refused'
                );
                $foreignPayload = $responsePayload($foreign);
                FcTest::assert(
                    !is_array($foreignPayload) || !isset($foreignPayload['order']),
                    'foreign response exposes no Order object'
                );
                FcTest::assertSame(
                    '',
                    $foreign['db_error'],
                    'foreign-object refusal has no database error'
                );
            } finally {
                $setTestUser($adminId);
                \FluentCart\Api\Resource\CustomerResource::resetCurrentCustomerRuntimeCache();
                FcPublicSurfaceFixture::cleanupAll();
            }
        },
    ],
];
