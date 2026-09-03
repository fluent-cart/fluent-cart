<?php
/**
 * Phase 10 read-only input-validation and ownership regressions.
 *
 * No case creates or mutates data. Dynamic cases query pre-existing rows only
 * after proving the selected shape exists; otherwise they skip explicitly.
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

$validationInventory = require dirname(__DIR__) . '/validation/inventory.php';

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

$paginator = function (array $result, $key) use ($responsePayload, $normalise) {
    $payload = $responsePayload($result);
    if (!is_array($payload) || !array_key_exists($key, $payload)) {
        return null;
    }
    $value = $normalise($payload[$key]);
    return is_array($value) ? $value : null;
};

$rowIds = function (array $page) {
    $ids = [];
    foreach (isset($page['data']) && is_array($page['data']) ? $page['data'] : [] as $row) {
        $row = is_object($row) && method_exists($row, 'toArray')
            ? $row->toArray()
            : $row;
        if (!is_array($row)) {
            continue;
        }
        $id = isset($row['ID']) ? $row['ID'] : (isset($row['id']) ? $row['id'] : null);
        if ($id !== null) {
            $ids[] = (int) $id;
        }
    }
    return $ids;
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

$subscriberCustomerWithOrder = function () {
    global $wpdb;

    $tableCustomers = $wpdb->prefix . 'fct_customers';
    $tableOrders = $wpdb->prefix . 'fct_orders';
    $candidates = $wpdb->get_results(
        "SELECT c.id, c.user_id, MIN(o.uuid) AS own_uuid
         FROM {$tableCustomers} c
         INNER JOIN {$tableOrders} o
            ON o.customer_id = c.id
           AND o.uuid IS NOT NULL
           AND o.uuid != ''
         WHERE c.user_id IS NOT NULL
           AND c.user_id > 0
         GROUP BY c.id, c.user_id
         ORDER BY c.id ASC
         LIMIT 500"
    );

    foreach ($candidates as $candidate) {
        $user = get_user_by('id', (int) $candidate->user_id);
        if ($user && !in_array('administrator', (array) $user->roles, true)) {
            return $candidate;
        }
    }

    return null;
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
        'id'            => 'validation-list-ordering-select-shaped-fallback',
        'name'          => 'Shared list ordering rejects SELECT-shaped column and direction input',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($paginator, $rowIds) {
            $surfaces = [
                ['route' => 'products', 'key' => 'products'],
                ['route' => 'orders', 'key' => 'orders'],
                ['route' => 'customers', 'key' => 'customers'],
                ['route' => 'activity', 'key' => 'activities'],
            ];

            foreach ($surfaces as $surface) {
                $baseline = FcTest::rest('GET', $surface['route'], ['per_page' => 3]);
                $probe = FcTest::rest('GET', $surface['route'], [
                    'per_page' => 3,
                    'sort_by'   => 'id DESC, (SELECT 1)',
                    'sort_type' => 'ASC, (SELECT 1)',
                ]);
                FcTest::assertHealthy($baseline, 'safe ordering baseline /' . $surface['route']);
                FcTest::assertHealthy($probe, 'SELECT-shaped ordering probe /' . $surface['route']);

                $baselinePage = $paginator($baseline, $surface['key']);
                $probePage = $paginator($probe, $surface['key']);
                FcTest::assert(
                    is_array($baselinePage) && is_array($probePage),
                    '/' . $surface['route'] . ' returns auditable paginator payloads'
                );
                if (!is_array($baselinePage) || !is_array($probePage)) {
                    continue;
                }
                FcTest::assertSame(
                    $rowIds($baselinePage),
                    $rowIds($probePage),
                    '/' . $surface['route']
                        . ' rejects SELECT-shaped sort input and preserves safe fallback order'
                );
            }
        },
    ],
    [
        'id'            => 'validation-list-pagination-boundaries',
        'name'          => 'Shared list pagination clamps invalid and oversized boundary values',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($paginator) {
            $surfaces = [
                ['route' => 'products', 'key' => 'products'],
                ['route' => 'orders', 'key' => 'orders'],
                ['route' => 'customers', 'key' => 'customers'],
                ['route' => 'activity', 'key' => 'activities'],
            ];
            $boundaries = [
                ['input' => -25, 'expected' => 10],
                ['input' => 0, 'expected' => 10],
                ['input' => 199, 'expected' => 199],
                ['input' => 200, 'expected' => 10],
                ['input' => 100000, 'expected' => 10],
            ];

            foreach ($surfaces as $surface) {
                foreach ($boundaries as $boundary) {
                    $result = FcTest::rest('GET', $surface['route'], [
                        'per_page' => $boundary['input'],
                        'page'     => -999,
                    ]);
                    FcTest::assertHealthy(
                        $result,
                        '/' . $surface['route'] . ' pagination boundary '
                            . $boundary['input']
                    );
                    $page = $paginator($result, $surface['key']);
                    FcTest::assert(
                        is_array($page),
                        '/' . $surface['route'] . ' exposes paginator metadata'
                    );
                    if (!is_array($page)) {
                        continue;
                    }
                    FcTest::assertSame(
                        $boundary['expected'],
                        (int) $page['per_page'],
                        '/' . $surface['route'] . ' clamps per_page='
                            . $boundary['input']
                    );
                    FcTest::assertSame(
                        1,
                        (int) $page['current_page'],
                        '/' . $surface['route'] . ' clamps negative page to one'
                    );
                }
            }
        },
    ],
    [
        'id'            => 'validation-search-text-sanitizer',
        'name'          => 'Shared list search input reaches sanitize_text_field',
        'kind'          => 'behavior',
        'known_failure' => true,
        'run'           => function () use ($safeSearchSpecs, $paginator, $rowIds) {
            $specs = $safeSearchSpecs();
            foreach ($specs as $spec) {
                if (!is_string($spec['value']) || trim($spec['value']) === '') {
                    FcTest::skip(
                        'No stable pre-existing searchable value for /' . $spec['route']
                    );
                    return;
                }
            }

            $observed = [];
            $allCorrect = true;
            $documentedDefect = true;
            foreach ($specs as $spec) {
                $safe = FcTest::rest('GET', $spec['route'], [
                    'search'   => $spec['value'],
                    'per_page' => 10,
                ]);
                $tagged = FcTest::rest('GET', $spec['route'], [
                    'search'   => '<b>' . $spec['value'] . '</b>',
                    'per_page' => 10,
                ]);
                FcTest::assertHealthy($safe, '/' . $spec['route'] . ' plain search');
                FcTest::assertHealthy($tagged, '/' . $spec['route'] . ' tagged search');
                $safePage = $paginator($safe, $spec['key']);
                $taggedPage = $paginator($tagged, $spec['key']);
                if (!is_array($safePage) || !is_array($taggedPage)) {
                    FcTest::fail('/' . $spec['route'] . ' search lacks paginator payload');
                    return;
                }

                $safeIds = $rowIds($safePage);
                $taggedIds = $rowIds($taggedPage);
                if (!$safeIds) {
                    FcTest::skip(
                        'Selected pre-existing /' . $spec['route']
                            . ' value is no longer returned by its own plain search'
                    );
                    return;
                }
                $correct = $safeIds === $taggedIds
                    && (int) $safePage['total'] === (int) $taggedPage['total'];
                $defect = (int) $taggedPage['total'] === 0;
                $allCorrect = $allCorrect && $correct;
                $documentedDefect = $documentedDefect && $defect;
                $observed[$spec['route']] = [
                    'plain_total'  => (int) $safePage['total'],
                    'tagged_total' => (int) $taggedPage['total'],
                ];
            }

            if ($allCorrect) {
                FcTest::fail(
                    'KNOWN-FAILURE unexpectedly passed; reclassify shared search sanitization.'
                );
            } elseif ($documentedDefect) {
                FcTest::skip(
                    'KNOWN-FAILURE — BaseFilter.php:231 assigns raw search input; '
                    . 'tag-wrapped values do not reach sanitize_text_field. observed='
                    . wp_json_encode($observed)
                );
            } else {
                FcTest::fail(
                    'KNOWN-FAILURE behavior drifted from the documented raw-search defect.'
                    . "\n  observed: " . wp_json_encode($observed)
                );
            }
        },
    ],
    [
        'id'            => 'validation-like-wildcards-are-literal',
        'name'          => 'Shared list LIKE searches treat SQL wildcard input as literal text',
        'kind'          => 'behavior',
        'known_failure' => true,
        'run'           => function () use ($paginator) {
            global $wpdb;

            $literalPattern = '%' . $wpdb->esc_like('%') . '%';
            $posts = $wpdb->posts;
            $variations = $wpdb->prefix . 'fct_product_variations';
            $orders = $wpdb->prefix . 'fct_orders';
            $customers = $wpdb->prefix . 'fct_customers';
            $items = $wpdb->prefix . 'fct_order_items';
            $activity = $wpdb->prefix . 'fct_activity';

            $expected = [
                'products' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT p.ID)
                     FROM {$posts} p
                     WHERE p.post_type = 'fluent-products'
                       AND (
                           p.post_title LIKE %s
                           OR EXISTS (
                               SELECT 1
                               FROM {$variations} v
                               WHERE v.post_id = p.ID
                                 AND v.variation_title LIKE %s
                           )
                       )",
                    $literalPattern,
                    $literalPattern
                )),
                'orders' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT o.id)
                     FROM {$orders} o
                     LEFT JOIN {$customers} c ON c.id = o.customer_id
                     WHERE o.invoice_no LIKE %s
                        OR c.email LIKE %s
                        OR CONCAT(c.first_name, ' ', c.last_name) LIKE %s
                        OR EXISTS (
                            SELECT 1
                            FROM {$items} oi
                            WHERE oi.order_id = o.id
                              AND (oi.title LIKE %s OR oi.post_title LIKE %s)
                        )",
                    $literalPattern,
                    $literalPattern,
                    $literalPattern,
                    $literalPattern,
                    $literalPattern
                )),
                'customers' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$customers}
                     WHERE email LIKE %s
                        OR first_name LIKE %s
                        OR last_name LIKE %s",
                    $literalPattern,
                    $literalPattern,
                    $literalPattern
                )),
                'activity' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$activity}
                     WHERE title LIKE %s
                        OR content LIKE %s
                        OR module_name LIKE %s",
                    $literalPattern,
                    $literalPattern,
                    $literalPattern
                )),
            ];

            $surfaces = [
                'products'  => 'products',
                'orders'    => 'orders',
                'customers' => 'customers',
                'activity'  => 'activities',
            ];
            $actual = [];
            foreach ($surfaces as $route => $key) {
                $result = FcTest::rest('GET', $route, [
                    'search'   => '%',
                    'per_page' => 1,
                ]);
                FcTest::assertHealthy($result, '/' . $route . ' literal wildcard probe');
                $page = $paginator($result, $key);
                if (!is_array($page)) {
                    FcTest::fail('/' . $route . ' wildcard probe lacks paginator payload');
                    return;
                }
                $actual[$route] = (int) $page['total'];
            }

            if ($actual === $expected) {
                FcTest::fail(
                    'KNOWN-FAILURE unexpectedly passed; reclassify LIKE wildcard escaping.'
                );
            } else {
                $documented = true;
                foreach ($actual as $route => $total) {
                    $documented = $documented && $total > $expected[$route];
                }
                if ($documented) {
                    FcTest::skip(
                        'KNOWN-FAILURE — CanSearch.php:139-160 and direct Filter LIKE '
                        . 'clauses concatenate raw wildcards instead of $wpdb->esc_like(). '
                        . 'expected_literal=' . wp_json_encode($expected)
                        . ' observed=' . wp_json_encode($actual)
                    );
                } else {
                    FcTest::fail(
                        'KNOWN-FAILURE behavior drifted from the documented wildcard expansion.'
                        . "\n  expected literal totals: " . wp_json_encode($expected)
                        . "\n  actual route totals: " . wp_json_encode($actual)
                    );
                }
            }
        },
    ],
    [
        'id'            => 'validation-bypass-pagination-boundaries',
        'name'          => 'Input-taking list endpoints outside BaseFilter clamp per_page',
        'kind'          => 'behavior',
        'known_failure' => true,
        'run'           => function () use ($collectKey) {
            $observed = [];
            $public = FcTest::rest('GET', 'public/products', [
                'per_page' => 100000,
                'page'     => 1,
            ]);
            FcTest::assertHealthy($public, '/public/products oversized per_page');
            $publicValues = array_map(
                'intval',
                $collectKey($public['data'], 'per_page')
            );
            $observed['public/products'] = $publicValues;

            $allClamped = !empty($observed);
            $documented = !empty($observed);
            foreach ($observed as $route => $values) {
                if (!$values) {
                    FcTest::fail('/' . $route . ' does not expose pagination metadata');
                    return;
                }
                foreach ($values as $value) {
                    $allClamped = $allClamped && $value > 0 && $value < 200;
                    $documented = $documented && $value === 100000;
                }
            }

            if ($allClamped) {
                FcTest::fail(
                    'KNOWN-FAILURE unexpectedly passed; reclassify bypass pagination clamps.'
                );
            } elseif ($documented) {
                FcTest::skip(
                    'KNOWN-FAILURE — ShopResource.php:260-262 passes raw per_page '
                    . 'to its paginator; CustomerOrderController.php:62-82 has the '
                    . 'same source-checked bypass but its installed-data list probe '
                    . 'is excluded for the documented Order.php:356 diagnostic. '
                    . 'observed=' . wp_json_encode($observed)
                );
            } else {
                FcTest::fail(
                    'KNOWN-FAILURE behavior drifted from the documented per_page=100000 bypass.'
                    . "\n  observed: " . wp_json_encode($observed)
                );
            }
        },
    ],
    [
        'id'            => 'validation-destructive-routes-have-policy-methods',
        'name'          => 'Every destructive route resolves a concrete policy method',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $pluginDir = dirname(__DIR__, 2);
            $config = require $pluginDir . '/tests/suite.config.php';
            require_once $pluginDir . '/tests/lib/permission-route-source.php';
            $inventory = FcPermissionRouteSource::discover($pluginDir, $config);
            FcTest::assertSame([], $inventory['errors'], 'permission source parser is clean');

            $deletes = array_filter($inventory['declarations'], function ($route) {
                return $route['verb'] === 'DELETE';
            });
            // 18 → 17: e7b731971 removed DELETE customers/{id}/address, one of
            // the six frontend routes shadowed by the admin customers group.
            FcTest::assertSame(
                17,
                count($deletes),
                'all 17 destructive route declarations are inventoried'
            );

            foreach ($deletes as $route) {
                $policy = isset($route['policy']) ? (string) $route['policy'] : '';
                FcTest::assert(
                    $policy !== '',
                    $route['source_file'] . ':' . $route['source_line']
                        . ' DELETE /' . $route['route'] . ' resolves a policy'
                );
                if ($policy === '') {
                    continue;
                }
                $policyFile = $pluginDir . '/app/Http/Policies/' . $policy . '.php';
                $source = is_file($policyFile) ? file_get_contents($policyFile) : false;
                FcTest::assert(
                    is_string($source) && strpos($source, 'function verifyRequest(') !== false,
                    $policy . ' exposes verifyRequest() for DELETE /' . $route['route']
                );
            }
        },
    ],
    [
        'id'            => 'validation-id-scoped-admin-routes-consult-policy',
        'name'          => 'ID-scoped admin GET routes refuse a subscriber before controller data access',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($subscriberCustomerWithOrder, $setTestUser) {
            global $wpdb;

            $actor = $subscriberCustomerWithOrder();
            $productId = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_type = %s
                     ORDER BY ID ASC LIMIT 1",
                    'fluent-products'
                )
            );
            if (!$actor || !$productId) {
                FcTest::skip('No subscriber/customer/product tuple exists for policy probing.');
                return;
            }

            $adminId = get_current_user_id();
            try {
                $setTestUser((int) $actor->user_id);
                $routes = [
                    'orders/' . (int) $wpdb->get_var(
                        "SELECT id FROM {$wpdb->prefix}fct_orders ORDER BY id ASC LIMIT 1"
                    ),
                    'customers/' . (int) $actor->id,
                    'products/' . $productId,
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
            }
        },
    ],
    [
        'id'            => 'validation-cross-customer-order-is-refused',
        'name'          => 'Customer-profile order detail refuses a different customer object',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use (
            $subscriberCustomerWithOrder,
            $responsePayload,
            $setTestUser
        ) {
            global $wpdb;

            $actor = $subscriberCustomerWithOrder();
            if (!$actor) {
                FcTest::skip('No non-admin linked customer with an Order exists.');
                return;
            }
            $foreignUuid = $wpdb->get_var($wpdb->prepare(
                "SELECT uuid
                 FROM {$wpdb->prefix}fct_orders
                 WHERE customer_id != %d
                   AND uuid IS NOT NULL
                   AND uuid != ''
                 ORDER BY id ASC
                 LIMIT 1",
                $actor->id
            ));
            if (!$foreignUuid) {
                FcTest::skip('No foreign pre-existing Order UUID exists.');
                return;
            }

            $adminId = get_current_user_id();
            try {
                $setTestUser((int) $actor->user_id);
                $own = FcTest::rest(
                    'GET',
                    'customer-profile/orders/' . $actor->own_uuid
                );
                $foreign = FcTest::rest(
                    'GET',
                    'customer-profile/orders/' . $foreignUuid
                );
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
            }
        },
    ],
    [
        'id'            => 'validation-stored-activity-markup-is-escaped',
        'name'          => 'Stored Activity markup is escaped before it reaches the REST response',
        'kind'          => 'behavior',
        'known_failure' => true,
        'run'           => function () use ($paginator) {
            global $wpdb;

            $table = $wpdb->prefix . 'fct_activity';
            $row = $wpdb->get_row(
                "SELECT id, content
                 FROM {$table}
                 WHERE content LIKE '%<%'
                 ORDER BY id DESC
                 LIMIT 1"
            );
            if (!$row) {
                FcTest::skip('No pre-existing Activity row contains markup.');
                return;
            }

            $result = FcTest::rest('GET', 'activity', [
                'search'   => '#' . (int) $row->id,
                'per_page' => 10,
            ]);
            FcTest::assertHealthy($result, '/activity stored markup probe');
            $page = $paginator($result, 'activities');
            if (!is_array($page)) {
                FcTest::fail('/activity stored markup probe lacks paginator payload');
                return;
            }

            $returned = null;
            foreach ($page['data'] as $activity) {
                if ((int) $activity['id'] === (int) $row->id) {
                    $returned = (string) $activity['content'];
                    break;
                }
            }
            if ($returned === null) {
                FcTest::fail('Exact pre-existing Activity ID was not returned by #ID search.');
                return;
            }

            $escaped = esc_html((string) $row->content);
            if ($returned === $escaped && $returned !== (string) $row->content) {
                FcTest::fail(
                    'KNOWN-FAILURE unexpectedly passed; reclassify Activity output escaping.'
                );
            } elseif ($returned === (string) $row->content) {
                FcTest::skip(
                    'KNOWN-FAILURE — ActivityController.php:11-16 returns stored content '
                    . 'unchanged, and Activity.vue:18 renders it with v-html; exact row '
                    . (int) $row->id . ' remained raw.'
                );
            } else {
                FcTest::fail(
                    'KNOWN-FAILURE behavior drifted from raw or correctly escaped Activity content.'
                    . "\n  stored hash: " . hash('sha256', (string) $row->content)
                    . "\n  returned hash: " . hash('sha256', $returned)
                    . "\n  escaped hash: " . hash('sha256', $escaped)
                );
            }
        },
    ],
    [
        'id'            => 'validation-v-html-user-data-sinks',
        'name'          => 'Stored and row-derived user data is not bound directly to v-html',
        'kind'          => 'behavior',
        'known_failure' => true,
        'run'           => function () use ($validationInventory) {
            $pluginDir = dirname(__DIR__, 2);
            $present = [];
            foreach ($validationInventory['v_html_sinks'] as $sink) {
                $lines = file($pluginDir . '/' . $sink['source_file']);
                $line = is_array($lines)
                    ? (isset($lines[$sink['source_line'] - 1])
                        ? $lines[$sink['source_line'] - 1]
                        : '')
                    : '';
                if (strpos($line, $sink['needle']) !== false) {
                    $present[] = $sink['source_file'] . ':' . $sink['source_line'];
                }
            }

            if (!$present) {
                FcTest::fail(
                    'KNOWN-FAILURE unexpectedly passed; reclassify direct v-html user-data sinks.'
                );
            } elseif (count($present) === count($validationInventory['v_html_sinks'])) {
                FcTest::skip(
                    'KNOWN-FAILURE — 15 source-pinned stored/row-derived values remain '
                    . 'bound directly to v-html; examples='
                    . wp_json_encode(array_slice($present, 0, 4))
                );
            } else {
                FcTest::fail(
                    'KNOWN-FAILURE inventory partially drifted; re-audit every direct v-html sink.'
                    . "\n  expected: " . count($validationInventory['v_html_sinks'])
                    . "\n  present: " . count($present)
                );
            }
        },
    ],
];
