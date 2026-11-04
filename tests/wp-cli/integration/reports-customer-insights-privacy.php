<?php
/**
 * A role that can read reports but not customers gets the customer insight totals, never customer names or emails
 * (the Top Customers list). An admin still gets the list.
 */

use FluentCart\App\Services\Permission\PermissionManager;

return [
    [
        'id'            => 'reports-customer-insights-privacy',
        'name'          => 'Report lists leave out customer names and emails for a role without customer access',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            if (!\FluentCart\App\App::isProActive()) {
                return; // Custom roles are a FluentCart Pro feature
            }
            $role = 'fct_test_reports_only';
            $addRole = function ($roles) use ($role) {
                $roles[$role] = ['title' => 'Reports only', 'descriptions' => '', 'permissions' => ['reports/view']];

                return $roles;
            };
            add_filter('fluent_cart/permission/all_roles', $addRole);
            $adminId = get_current_user_id();
            $userId = wp_insert_user([
                'user_login' => 'fct_reports_only_' . wp_generate_password(6, false),
                'user_pass'  => wp_generate_password(24),
                'user_email' => 'fct-reports-only-' . wp_generate_password(6, false) . '@example.invalid',
                'role'       => 'subscriber',
            ]);
            try {
                if (is_wp_error($userId)) {
                    throw new RuntimeException('Could not create the test user: ' . $userId->get_error_message());
                }
                PermissionManager::attachRole($userId, $role);
                $request = function () {
                    $request = new WP_REST_Request('GET', '/fluent-cart/v2/reports/customer-insights');
                    $request->set_param('params', ['startDate' => '2000-01-01', 'endDate' => gmdate('Y-m-d')]);

                    return rest_do_request($request);
                };

                wp_set_current_user($userId);
                FcTest::assertSame([true, false], [PermissionManager::hasPermission('reports/view'), PermissionManager::hasPermission('customers/view')],
                    'The test role reads reports but not customers');
                $response = $request();
                $data = $response->get_data();
                FcTest::assertSame([200, [], true], [$response->get_status(), $data['top_customers'] ?? null, $data['customers_hidden'] ?? null],
                    'No customer list (names, emails) for a role without customer access');
                FcTest::assert(isset($data['summary']), 'The totals stay');

                // Every report list that names customers: installment plans at risk, failed orders, recent orders
                $get = function ($route, array $query = []) {
                    $request = new WP_REST_Request('GET', '/fluent-cart/v2/reports/' . $route);
                    foreach ($query as $key => $value) {
                        $request->set_param($key, $value);
                    }

                    return rest_do_request($request)->get_data();
                };
                $people = function (array $rows, array $keys) {
                    $found = [];
                    foreach ($rows as $row) {
                        foreach ($keys as $key) {
                            if ((string) ($row[$key] ?? '') !== '') {
                                $found[] = $key;
                            }
                        }
                    }

                    return $found;
                };
                $atRisk = $get('installments/at-risk', ['search' => '@']);
                $installments = $get('installments', ['params' => ['at_risk_limit' => 5]]);
                $failed = $get('failed-orders', ['params' => ['startDate' => '2000-01-01', 'endDate' => gmdate('Y-m-d')]]);
                $recent = $get('get-recent-orders');
                FcTest::assertSame([true, true, true, true], [$atRisk['customers_hidden'] ?? null, $installments['customers_hidden'] ?? null,
                    $failed['customers_hidden'] ?? null, $recent['customers_hidden'] ?? null], 'Each list says the names are left out');
                FcTest::assertSame([], array_merge(
                    $people($atRisk['data'] ?? [], ['customer_name', 'email']),
                    $people($installments['at_risk'] ?? [], ['customer_name', 'email']),
                    $people($failed['orders'] ?? [], ['customer_name']),
                    $people($recent['recentOrders'] ?? [], ['customer_name'])
                ), 'No customer name or email in any list');
                // The MCP installments report is the same list for an AI client
                $mcp = \FluentCart\App\Modules\MCP\Tools\ReportTools::getInstallmentReport(['at_risk_limit' => 5]);
                $mcpData = is_array($mcp) ? ($mcp['data'] ?? []) : [];
                FcTest::assertSame([true, []], [$mcpData['customers_hidden'] ?? null, $people($mcpData['at_risk'] ?? [], ['customer_name', 'email'])],
                    'The MCP installments report leaves out customer names and emails too');

                wp_set_current_user($adminId);
                $data = $request()->get_data();
                FcTest::assertSame(false, $data['customers_hidden'] ?? null, 'An admin still gets the customer list');
            } finally {
                wp_set_current_user($adminId);
                remove_filter('fluent_cart/permission/all_roles', $addRole);
                if (!is_wp_error($userId) && $userId) {
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                    wp_delete_user($userId);
                }
            }
        },
    ],
];
